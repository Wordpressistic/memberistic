<?php
/**
 * WooCommerce bridge foundation.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic\Integrations;

use WordPressistic\Memberistic\Database\Activity_Repository;
use WordPressistic\Memberistic\Database\Memberships_Repository;
use WordPressistic\Memberistic\Database\Payments_Repository;
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
		if ( ! self::is_enabled() || ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return;
		}

		$membership_id = absint( $order->get_meta( '_memberistic_membership_id' ) );

		if ( ! $membership_id ) {
			return;
		}

		Memberships_Repository::change_status( $membership_id, 'cancelled' );

		Activity_Repository::log(
			array(
				'membership_id'       => $membership_id,
				'activity_type'       => 'membership_cancelled',
				'title'               => __( 'Membership cancelled via WooCommerce refund or cancellation', 'memberistic' ),
				'related_object_type' => 'woo_order',
				'related_object_id'   => $order_id,
			)
		);
	}

	public static function sync_completed_order( $order_id ) {
		if ( ! self::is_enabled() || ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return;
		}

		$membership_id = absint( $order->get_meta( '_memberistic_membership_id' ) );

		if ( ! $membership_id ) {
			return;
		}

		$membership = Memberships_Repository::get( $membership_id );

		if ( ! $membership ) {
			return;
		}

		// Audit C34: previously the bridge only set status='active' but
		// never set start_date or renewal_date, so the cron's
		// get_renewing_in_days() / get_expired() lookups skipped these
		// rows forever — WooCommerce-purchased memberships never
		// auto-expired and never received the 30/7/1-day renewal
		// reminders. Compute both fields the same way the Stripe path
		// does, and also fire memberistic_membership_activated so the
		// member-role sync + activation email run consistently across
		// payment sources.
		$was_already_active = isset( $membership['status'] ) && 'active' === $membership['status'];
		$billing_cycle      = ! empty( $membership['billing_cycle'] ) ? $membership['billing_cycle'] : 'monthly';
		$start_date         = current_time( 'mysql' );
		$renewal_date       = self::compute_next_renewal( $billing_cycle, $start_date );

		Memberships_Repository::update(
			$membership_id,
			array(
				'status'          => 'active',
				'start_date'      => $start_date,
				'renewal_date'    => $renewal_date,
				'woo_customer_id' => $order->get_customer_id(),
			)
		);

		$payment_id = Payments_Repository::create(
			array(
				'membership_id'   => $membership_id,
				'amount'          => $order->get_total(),
				'currency'        => $order->get_currency(),
				'payment_method'  => $order->get_payment_method(),
				'payment_gateway' => 'woocommerce',
				'woo_order_id'    => $order_id,
				'status'          => 'completed',
				'paid_at'         => current_time( 'mysql' ),
				'raw_response'    => array( 'source' => 'woocommerce_order_completed' ),
			)
		);

		Activity_Repository::log(
			array(
				'membership_id'       => $membership_id,
				'activity_type'       => 'payment_completed',
				'title'               => __( 'WooCommerce order completed', 'memberistic' ),
				'related_object_type' => 'woo_order',
				'related_object_id'   => $order_id,
			)
		);

		do_action( 'memberistic_membership_payment_recorded', $membership_id, $payment_id, 'woocommerce' );

		// Only fire activation on the FIRST completed order; on a
		// renewal-order the role-sync + welcome email shouldn't re-run.
		if ( ! $was_already_active ) {
			do_action( 'memberistic_membership_activated', $membership_id );
		}
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
