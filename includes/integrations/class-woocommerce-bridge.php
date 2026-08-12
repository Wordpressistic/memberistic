<?php
/**
 * WooCommerce bridge foundation.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic\Integrations;

use WordPressistic\Memberistic\Database\Activity_Repository;
use WordPressistic\Memberistic\Database\Payments_Repository;
use WordPressistic\Memberistic\Payments\Payment_Integrity_Gate;
use WordPressistic\Memberistic\Payments\Providers\WooCommerce_Provider;
use function WordPressistic\Memberistic\memberistic_get_setting;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WooCommerce_Bridge {
	public static function register() {
		add_action( 'woocommerce_order_status_completed', array( self::class, 'sync_completed_order' ) );
		add_action( 'woocommerce_order_refunded', array( self::class, 'sync_refunded_order' ), 10, 1 );
		add_action( 'woocommerce_order_status_cancelled', array( self::class, 'sync_refunded_order' ) );
		add_filter( 'memberistic_woocommerce_enabled', array( self::class, 'is_enabled' ) );
		add_action( 'memberistic_ensure_woocommerce_products', array( self::class, 'ensure_default_products' ) );
	}

	public static function is_enabled() {
		return 'yes' === memberistic_get_setting( 'woocommerce_enabled', 'no' ) && class_exists( 'WooCommerce' );
	}

	/**
	 * Create or update the hidden virtual products that mirror the plan
	 * catalogue — one per plan, per billing cycle. Safe to call repeatedly:
	 * products are matched by SKU.
	 *
	 * Called manually from the Integrations settings; not run at boot to keep
	 * activation cheap on hosts without WooCommerce.
	 */
	public static function ensure_default_products() {
		if ( ! self::is_enabled() || ! function_exists( 'wc_get_product_id_by_sku' ) ) {
			return array();
		}

		$plans   = \WordPressistic\Memberistic\Database\Plans_Repository::get_all( array( 'status' => 'active' ) );
		$created = array();

		foreach ( $plans as $plan ) {
			foreach ( array( 'monthly' => (float) $plan['monthly_price'], 'annual' => (float) $plan['annual_price'] ) as $cycle => $price ) {
				if ( $price <= 0 ) {
					continue;
				}

				$sku        = sprintf( 'memberistic-%s-%s', sanitize_title( $plan['slug'] ), $cycle );
				$product_id = wc_get_product_id_by_sku( $sku );

				$product = $product_id ? wc_get_product( $product_id ) : new \WC_Product_Simple();
				$product->set_name( sprintf( '%s Membership — %s', $plan['name'], ucfirst( $cycle ) ) );
				$product->set_sku( $sku );
				$product->set_status( 'publish' );
				$product->set_catalog_visibility( 'hidden' );
				$product->set_virtual( true );
				$product->set_regular_price( $price );
				$product->set_price( $price );
				$product->set_meta_data(
					array(
						'_memberistic_plan_id' => (int) $plan['id'],
						'_memberistic_cycle'   => $cycle,
					)
				);
				$product->update_meta_data( '_memberistic_plan_id', (int) $plan['id'] );
				$product->update_meta_data( '_memberistic_cycle', $cycle );

				$created[] = $product->save();
			}
		}

		return $created;
	}

	/**
	 * Refund / cancel an order — flip its membership to cancelled.
	 */
	public static function sync_refunded_order( $order_id ) {
		$result = self::dispatch_order( $order_id, 'refunded' );

		if ( is_wp_error( $result ) || ! is_array( $result ) ) {
			return;
		}

		if ( 'processed' === ( $result['status'] ?? '' ) ) {
			Activity_Repository::log(
				array(
					'membership_id'       => absint( $result['membership_id'] ?? 0 ),
					'activity_type'       => 'membership_cancelled',
					'title'               => __( 'Membership cancelled via WooCommerce refund or cancellation', 'memberistic' ),
					'related_object_type' => 'woo_order',
					'related_object_id'   => absint( $order_id ),
				)
			);
		}
	}

	/**
	 * Put a WooCommerce order transition through the payment integrity gate.
	 *
	 * A local order hook is not an unauthenticated internet request, and this
	 * is not pretending otherwise — there is no signature to check because
	 * there is nobody remote to have signed anything. What it does share with a
	 * webhook is every failure mode that comes *after* authentication:
	 *
	 * - The status hooks fire more than once in ordinary use. A manual status
	 *   change in the admin, a re-save, a second call from a gateway plugin:
	 *   each one previously wrote another payment row and re-ran activation.
	 *   The event id derived from the order makes those duplicates.
	 * - `_memberistic_membership_id` records which membership the order was
	 *   created for. It does not record what was paid for, and the two come
	 *   apart when a customer edits their cart — so the gate now checks that a
	 *   product mapping to this membership's plan was actually bought.
	 * - Nothing checked whether the order was paid, or in what currency, or
	 *   whether the resulting state change was one this membership was allowed
	 *   to make.
	 *
	 * @param int    $order_id   WooCommerce order id.
	 * @param string $transition `completed` or `refunded`.
	 * @return array<string, mixed>|\WP_Error|null
	 */
	private static function dispatch_order( $order_id, $transition ) {
		if ( ! self::is_enabled() || ! function_exists( 'wc_get_order' ) ) {
			return null;
		}

		$event = WooCommerce_Provider::event_from_order( $order_id, $transition );

		if ( is_wp_error( $event ) ) {
			return $event;
		}

		if ( empty( $event['membership_hint'] ) ) {
			// Not a membership order. Nothing to do, and nothing worth
			// recording in the payment ledger.
			return null;
		}

		return Payment_Integrity_Gate::process_event( WooCommerce_Provider::key(), $event );
	}

	public static function sync_completed_order( $order_id ) {
		$result = self::dispatch_order( $order_id, 'completed' );

		if ( is_wp_error( $result ) || ! is_array( $result ) ) {
			return;
		}

		if ( 'processed' !== ( $result['status'] ?? '' ) ) {
			// Rejected, deferred, or a duplicate delivery. The gate has
			// recorded why; repeating the activity log here would suggest a
			// purchase completed when it did not.
			return;
		}

		$membership_id = absint( $result['membership_id'] ?? 0 );

		Activity_Repository::log(
			array(
				'membership_id'       => $membership_id,
				'activity_type'       => 'payment_completed',
				'title'               => __( 'WooCommerce order completed', 'memberistic' ),
				'related_object_type' => 'woo_order',
				'related_object_id'   => absint( $order_id ),
			)
		);

		// The payment row is written by the gate, which owns idempotency for
		// it; this hook keeps its historical signature for integrations that
		// listen to it. The id is looked up rather than assumed, because the
		// gate may have recognised the charge as one already recorded.
		$payment = Payments_Repository::get_by_provider_transaction( WooCommerce_Provider::key(), 'wc_order_' . absint( $order_id ) );

		do_action(
			'memberistic_membership_payment_recorded',
			$membership_id,
			$payment ? (int) $payment['id'] : 0,
			'woocommerce'
		);
	}

	/**
	 * Compute the next renewal date from a billing cycle and start date,
	 * using site-local time (matches Memberships_Repository conventions
	 * and avoids the UTC-vs-local drift that gmdate+strtotime introduces
	 * on non-UTC sites). Shared so the Stripe + WooCommerce + manual
	 * renewal paths agree.
	 *
	 * @param string $billing_cycle 'monthly' / 'annual' / etc.
	 * @param string $start_mysql   Site-local mysql datetime to start from.
	 * @return string Site-local mysql datetime of the next renewal.
	 */
	public static function compute_next_renewal( $billing_cycle, $start_mysql ) {
		$tz       = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
		$interval = ( 'annual' === $billing_cycle || 'yearly' === $billing_cycle ) ? '+1 year' : '+1 month';
		try {
			$dt = new \DateTime( $start_mysql, $tz );
			$dt->modify( $interval );
			return $dt->format( 'Y-m-d H:i:s' );
		} catch ( \Throwable $e ) {
			return wp_date( 'Y-m-d H:i:s', strtotime( $interval, current_time( 'timestamp' ) ) );
		}
	}
}
