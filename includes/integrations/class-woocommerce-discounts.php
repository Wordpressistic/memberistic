<?php
/**
 * Automatic WooCommerce member discounts.
 *
 * The original WooCommerce bridge only *sells* memberships as Woo products;
 * this module makes an ACTIVE membership worth something across the rest of
 * the catalog:
 *
 *   - each plan carries its own discount % with category include/exclude
 *     rules and an "exclude sale items" switch (configured on the
 *     Integrations screen — nothing hardcoded);
 *   - the discount is applied at cart-calculation time as a single labeled
 *     negative fee ("Member discount — <plan name> (10%)"), so it composes
 *     cleanly with coupons, taxes, and shipping;
 *   - the applied saving is stamped onto the order
 *     (`_memberistic_member_discount`) which feeds the "You've saved $X
 *     with your membership" total on the member dashboard;
 *   - logged-in members see their member price on product pages.
 *
 * Membership products themselves (anything carrying `_memberistic_plan_id`)
 * are never discounted — memberships can't discount their own renewals.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic\Integrations;

use function WordPressistic\Memberistic\memberistic_get_setting;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WooCommerce_Discounts {

	const OPTION = 'memberistic_woo_discounts';

	/** @var array|null Per-request cache of the current user's plan context. */
	private static $context_cache = null;

	public static function register() {
		if ( ! class_exists( '\\WooCommerce' ) ) {
			return;
		}
		if ( 'yes' !== memberistic_get_setting( 'woocommerce_enabled', 'no' ) ) {
			return;
		}
		if ( 'yes' !== memberistic_get_setting( 'woo_member_discounts_enabled', 'yes' ) ) {
			return;
		}

		add_action( 'woocommerce_cart_calculate_fees', array( self::class, 'apply_cart_discount' ), 20 );
		add_action( 'woocommerce_checkout_create_order', array( self::class, 'stamp_order' ), 20, 1 );
		add_filter( 'woocommerce_get_price_html', array( self::class, 'member_price_html' ), 20, 2 );
	}

	/* ─────────────────────────── config ──────────────────────────── */

	/**
	 * Discount rules per plan id.
	 *
	 * @return array<int,array{percent:float,mode:string,categories:int[],exclude_sale:string}>
	 */
	public static function rules() {
		$raw = get_option( self::OPTION, array() );
		$out = array();
		foreach ( (array) $raw as $plan_id => $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}
			$out[ (int) $plan_id ] = array(
				'percent'      => isset( $rule['percent'] ) ? max( 0.0, min( 100.0, (float) $rule['percent'] ) ) : 0.0,
				'mode'         => in_array( $rule['mode'] ?? 'all', array( 'all', 'include', 'exclude' ), true ) ? $rule['mode'] : 'all',
				'categories'   => isset( $rule['categories'] ) && is_array( $rule['categories'] ) ? array_map( 'intval', $rule['categories'] ) : array(),
				'exclude_sale' => ( $rule['exclude_sale'] ?? 'no' ) === 'yes' ? 'yes' : 'no',
			);
		}
		return $out;
	}

	/**
	 * The current user's active plan + discount rule, or null.
	 *
	 * @param int|null $user_id Defaults to the logged-in user.
	 * @return array{plan_id:int,plan_name:string,percent:float,mode:string,categories:int[],exclude_sale:string}|null
	 */
	public static function context( $user_id = null ) {
		$user_id = null === $user_id ? get_current_user_id() : (int) $user_id;
		if ( $user_id <= 0 ) {
			return null;
		}
		if ( null !== self::$context_cache && ( self::$context_cache['user_id'] ?? 0 ) === $user_id ) {
			return self::$context_cache['ctx'];
		}

		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT m.plan_id, p.name AS plan_name
			 FROM {$wpdb->prefix}memberistic_memberships m
			 LEFT JOIN {$wpdb->prefix}memberistic_people pe ON pe.membership_id = m.id
			 LEFT JOIN {$wpdb->prefix}memberistic_plans p ON p.id = m.plan_id
			 WHERE ( m.primary_user_id = %d OR pe.wp_user_id = %d )
			   AND m.status IN ('active','trial','past_due')
			 ORDER BY m.id DESC LIMIT 1",
			$user_id,
			$user_id
		), ARRAY_A );

		$ctx = null;
		if ( $row ) {
			$rules = self::rules();
			$rule  = $rules[ (int) $row['plan_id'] ] ?? null;
			if ( $rule && $rule['percent'] > 0 ) {
				$ctx = array_merge( $rule, array(
					'plan_id'   => (int) $row['plan_id'],
					'plan_name' => (string) ( $row['plan_name'] ?: 'Member' ),
				) );
			}
		}

		self::$context_cache = array( 'user_id' => $user_id, 'ctx' => $ctx );
		return $ctx;
	}

	/**
	 * Does this product qualify for the member discount under a rule?
	 *
	 * @param \WC_Product $product Product (or variation).
	 * @param array       $rule    Rule from context().
	 * @return bool
	 */
	public static function product_eligible( $product, array $rule ) {
		if ( ! $product instanceof \WC_Product ) {
			return false;
		}
		// Never discount membership products themselves.
		$parent_id = $product->get_parent_id() ?: $product->get_id();
		if ( get_post_meta( $product->get_id(), '_memberistic_plan_id', true ) || get_post_meta( $parent_id, '_memberistic_plan_id', true ) ) {
			return false;
		}
		if ( 'yes' === $rule['exclude_sale'] && $product->is_on_sale() ) {
			return false;
		}
		if ( 'all' === $rule['mode'] || empty( $rule['categories'] ) ) {
			return true;
		}
		$in_list = has_term( $rule['categories'], 'product_cat', $parent_id );
		return 'include' === $rule['mode'] ? $in_list : ! $in_list;
	}

	/* ───────────────────────── cart & order ──────────────────────── */

	/**
	 * Apply the member discount as a single negative fee across eligible
	 * cart lines.
	 *
	 * @param \WC_Cart $cart Cart instance.
	 */
	public static function apply_cart_discount( $cart ) {
		if ( ! $cart instanceof \WC_Cart || $cart->is_empty() ) {
			return;
		}
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}
		$ctx = self::context();
		if ( ! $ctx ) {
			return;
		}

		$eligible = 0.0;
		foreach ( $cart->get_cart() as $item ) {
			$product = $item['data'] ?? null;
			if ( $product instanceof \WC_Product && self::product_eligible( $product, $ctx ) ) {
				$eligible += (float) $item['line_subtotal'];
			}
		}
		if ( $eligible <= 0 ) {
			return;
		}

		$amount = round( $eligible * $ctx['percent'] / 100, wc_get_price_decimals() );
		if ( $amount <= 0 ) {
			return;
		}

		$label = sprintf(
			/* translators: 1: plan name, 2: percentage */
			__( 'Member discount — %1$s (%2$s%%)', 'memberistic' ),
			$ctx['plan_name'],
			rtrim( rtrim( number_format( $ctx['percent'], 2 ), '0' ), '.' )
		);

		$cart->add_fee( $label, -$amount, true );
	}

	/**
	 * Record the applied member discount on the order so the dashboard's
	 * savings total (and future reporting) can read it without re-deriving.
	 *
	 * @param \WC_Order $order Order being created at checkout.
	 */
	public static function stamp_order( $order ) {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$ctx = self::context( $order->get_customer_id() ?: get_current_user_id() );
		if ( ! $ctx ) {
			return;
		}
		$saved = 0.0;
		foreach ( $order->get_fees() as $fee ) {
			if ( false !== strpos( (string) $fee->get_name(), __( 'Member discount', 'memberistic' ) ) ) {
				$saved += abs( (float) $fee->get_total() );
			}
		}
		if ( $saved > 0 ) {
			$order->update_meta_data( '_memberistic_member_discount', wc_format_decimal( $saved ) );
			$order->update_meta_data( '_memberistic_member_plan', $ctx['plan_name'] );
		}
	}

	/**
	 * Lifetime member savings for a user, summed from stamped orders.
	 *
	 * @param int $user_id WP user id.
	 * @return float
	 */
	public static function total_savings( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 || ! function_exists( 'wc_get_orders' ) ) {
			return 0.0;
		}
		$orders = wc_get_orders( array(
			'customer_id' => $user_id,
			'limit'       => 200,
			'status'      => array( 'wc-completed', 'wc-processing', 'wc-on-hold' ),
			'return'      => 'objects',
		) );
		$total = 0.0;
		foreach ( (array) $orders as $order ) {
			$total += (float) $order->get_meta( '_memberistic_member_discount' );
		}
		return $total;
	}

	/* ───────────────────────── product page ──────────────────────── */

	/**
	 * Show the member price next to the regular price for logged-in members
	 * on eligible products.
	 *
	 * @param string      $html    Price HTML.
	 * @param \WC_Product $product Product.
	 * @return string
	 */
	public static function member_price_html( $html, $product ) {
		if ( is_admin() || ! is_user_logged_in() ) {
			return $html;
		}
		// Only decorate real storefront surfaces.
		if ( ! function_exists( 'is_product' ) || ( ! is_product() && ! is_shop() && ! is_product_taxonomy() ) ) {
			return $html;
		}
		$ctx = self::context();
		if ( ! $ctx || ! $product instanceof \WC_Product || ! self::product_eligible( $product, $ctx ) ) {
			return $html;
		}
		$price = (float) wc_get_price_to_display( $product );
		if ( $price <= 0 ) {
			return $html;
		}
		$member_price = $price * ( 1 - $ctx['percent'] / 100 );
		$badge        = sprintf(
			/* translators: 1: formatted member price, 2: plan name */
			__( 'Your %2$s price: %1$s', 'memberistic' ),
			wc_price( $member_price ),
			esc_html( $ctx['plan_name'] )
		);
		return $html . '<span class="memberistic-member-price" style="display:block;font-size:.85em;color:#C9A84C;">' . $badge . '</span>';
	}
}
