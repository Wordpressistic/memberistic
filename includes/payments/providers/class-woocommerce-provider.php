<?php
/**
 * WooCommerce adapter.
 *
 * A WooCommerce order transition is not an unauthenticated internet request:
 * it comes from code running inside this site, and there is no signature to
 * check because there is no remote party to have signed anything. What it
 * shares with a Stripe webhook is everything that happens after
 * authentication — the same order hook can fire twice, an order can be edited
 * after the fact, and the membership id on an order is a piece of stored data
 * that may no longer describe the membership it once did.
 *
 * So the same gate runs over both. The differences are confined to this file:
 * authenticate() has nothing to verify and says so, and "fetch provider truth"
 * means re-reading the order from the database rather than crossing the
 * network.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic\Payments\Providers;

use WordPressistic\Memberistic\Integrations\WooCommerce_Bridge;
use WordPressistic\Memberistic\Payments\Payment_Clock;
use WordPressistic\Memberistic\Payments\Subscription_State_Machine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WooCommerce_Provider implements Payment_Provider {
	/**
	 * {@inheritDoc}
	 */
	public static function key() {
		return 'woocommerce';
	}

	/**
	 * {@inheritDoc}
	 */
	public static function is_configured() {
		return WooCommerce_Bridge::is_enabled() && function_exists( 'wc_get_order' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * WooCommerce orders are local records, not remote claims, so there is no
	 * test/live split to police. Reporting a single environment keeps the
	 * gate's environment check a no-op here rather than a special case in the
	 * gate.
	 */
	public static function environment() {
		return 'live';
	}

	/**
	 * {@inheritDoc}
	 */
	public static function expected_account_id() {
		return '';
	}

	/**
	 * {@inheritDoc}
	 *
	 * Nothing to authenticate: these events are constructed by this plugin,
	 * in-process, from a database row. The method exists because the gate
	 * calls it for webhook-shaped entry points, and WooCommerce events do not
	 * arrive that way — they go through process_event() directly.
	 */
	public static function authenticate( $payload, array $headers ) {
		return new \WP_Error(
			'memberistic_woocommerce_no_webhook',
			__( 'WooCommerce events are raised locally and are not accepted over the webhook endpoint.', 'memberistic' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public static function normalize_event( array $raw, $payload ) {
		return new \WP_Error(
			'memberistic_woocommerce_no_webhook',
			__( 'WooCommerce events are raised locally.', 'memberistic' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Build a normalised event from a WooCommerce order.
	 *
	 * The event id is derived from the order and the transition rather than
	 * generated, and that is what makes these idempotent: WooCommerce fires
	 * status hooks more than once in ordinary operation — a manual status
	 * change, a re-save from the admin screen, a second call from a payment
	 * gateway plugin — and each one produces the same id, which the ledger's
	 * unique key recognises as a duplicate. A random id would let every
	 * re-fire write another payment row.
	 *
	 * @param int    $order_id   WooCommerce order id.
	 * @param string $transition One of `completed`, `refunded`.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function event_from_order( $order_id, $transition ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return new \WP_Error( 'memberistic_woocommerce_missing', __( 'WooCommerce is not active.', 'memberistic' ) );
		}

		$order_id   = absint( $order_id );
		$transition = sanitize_key( $transition );
		$order      = wc_get_order( $order_id );

		if ( ! $order ) {
			return new \WP_Error( 'memberistic_woocommerce_order_missing', __( 'WooCommerce order not found.', 'memberistic' ) );
		}

		$intent = 'refunded' === $transition
			? self::INTENT_CANCELLATION
			: self::INTENT_ACTIVATION;

		$membership_id = absint( $order->get_meta( '_memberistic_membership_id' ) );
		$created       = $order->get_date_modified();
		$timestamp     = $created ? (int) $created->getTimestamp() : Payment_Clock::timestamp();

		$payload = wp_json_encode(
			array(
				'order'      => $order_id,
				'transition' => $transition,
				'total'      => (string) $order->get_total(),
				'status'     => $order->get_status(),
			)
		);

		return array(
			'provider'                 => self::key(),
			'provider_account_id'      => '',
			'environment'              => self::environment(),
			'event_id'                 => sprintf( 'wc_order_%d_%s', $order_id, $transition ),
			'event_type'               => 'woocommerce.order.' . $transition,
			'created_timestamp'        => $timestamp,
			'provider_created_at'      => Payment_Clock::from_timestamp( $timestamp ),
			'payload_hash'             => hash( 'sha256', (string) $payload ),
			'intent'                   => $intent,
			'object'                   => array(
				'order_id'    => $order_id,
				'status'      => $order->get_status(),
				'total'       => (float) $order->get_total(),
				'currency'    => $order->get_currency(),
				'customer_id' => (int) $order->get_customer_id(),
				'plan_ids'    => self::plan_ids_for_order( $order ),
			),
			'provider_customer_id'     => (string) $order->get_customer_id(),
			'provider_subscription_id' => '',
			'provider_transaction_id'  => 'wc_order_' . $order_id,
			'amount'                   => (float) $order->get_total(),
			'currency'                 => strtoupper( (string) $order->get_currency() ),
			'membership_hint'          => $membership_id,
			'billing_reason'           => $transition,
			'woo_order_id'             => $order_id,
			// Provider-specific columns the gate writes verbatim on a verified
			// activation. Keeps WooCommerce's own bookkeeping working without
			// the gate having to know what a `woo_customer_id` is; the
			// repository's field allowlist is what decides whether a key here
			// is accepted.
			'membership_fields'        => array(
				'woo_customer_id' => (int) $order->get_customer_id(),
			),
		);
	}

	/**
	 * The Memberistic plans an order's line items map to.
	 *
	 * An order that contains no Memberistic product should not be able to
	 * grant a membership, however inviting the `_memberistic_membership_id`
	 * meta on it looks — that meta says which membership the order was created
	 * *for*, not what was actually bought.
	 *
	 * @param \WC_Order $order WooCommerce order.
	 * @return array<int>
	 */
	public static function plan_ids_for_order( $order ) {
		$plan_ids = array();

		foreach ( $order->get_items() as $item ) {
			if ( ! is_callable( array( $item, 'get_product' ) ) ) {
				continue;
			}

			$product = $item->get_product();

			if ( ! $product ) {
				continue;
			}

			$plan_id = absint( $product->get_meta( '_memberistic_plan_id' ) );

			if ( $plan_id ) {
				$plan_ids[] = $plan_id;
			}
		}

		return array_values( array_unique( $plan_ids ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * WooCommerce Subscriptions, when present. Absent it, a plain order has no
	 * subscription and the gate treats the membership's own state as truth.
	 */
	public static function fetch_subscription( $subscription_id ) {
		if ( ! function_exists( 'wcs_get_subscription' ) ) {
			return new \WP_Error( 'memberistic_woocommerce_no_subscriptions', __( 'WooCommerce Subscriptions is not active.', 'memberistic' ) );
		}

		$subscription = wcs_get_subscription( absint( $subscription_id ) );

		if ( ! $subscription ) {
			return new \WP_Error( 'memberistic_woocommerce_subscription_missing', __( 'WooCommerce subscription not found.', 'memberistic' ) );
		}

		return array(
			'id'     => (int) $subscription->get_id(),
			'status' => (string) $subscription->get_status(),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * Re-reads the order. That is the local equivalent of asking the provider
	 * for current truth: the order may have been refunded, edited or cancelled
	 * between the hook firing and this running.
	 */
	public static function fetch_invoice( $invoice_id ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return new \WP_Error( 'memberistic_woocommerce_missing', __( 'WooCommerce is not active.', 'memberistic' ) );
		}

		$order_id = absint( str_replace( 'wc_order_', '', (string) $invoice_id ) );
		$order    = wc_get_order( $order_id );

		if ( ! $order ) {
			return new \WP_Error( 'memberistic_woocommerce_order_missing', __( 'WooCommerce order not found.', 'memberistic' ) );
		}

		return array(
			'id'          => 'wc_order_' . $order_id,
			'status'      => $order->get_status(),
			'paid'        => $order->is_paid(),
			'amount_paid' => (float) $order->get_total(),
			'currency'    => $order->get_currency(),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public static function billing_state_for_subscription( array $subscription ) {
		$status = isset( $subscription['status'] ) ? (string) $subscription['status'] : '';

		return Subscription_State_Machine::from_provider_state( self::key(), $status );
	}
}
