<?php
/**
 * Discount code redemptions repository — the "who used this code, when,
 * for how much" audit trail the Discount Codes addon tracks.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
final class Discount_Redemptions_Repository {

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'memberistic_discount_redemptions';
	}

	public static function create( array $data ) {
		global $wpdb;
		$now = current_time( 'mysql' );
		$row = array(
			'discount_code_id'           => absint( $data['discount_code_id'] ?? 0 ),
			'membership_id'              => isset( $data['membership_id'] ) ? absint( $data['membership_id'] ) : null,
			'plan_id'                    => isset( $data['plan_id'] ) ? absint( $data['plan_id'] ) : null,
			'email'                      => isset( $data['email'] ) ? sanitize_email( (string) $data['email'] ) : null,
			'billing_cycle'              => isset( $data['billing_cycle'] ) ? sanitize_key( (string) $data['billing_cycle'] ) : null,
			'discount_type'              => isset( $data['discount_type'] ) ? sanitize_key( (string) $data['discount_type'] ) : null,
			'discount_value'             => isset( $data['discount_value'] ) ? (float) $data['discount_value'] : null,
			'amount_before'              => isset( $data['amount_before'] ) ? (float) $data['amount_before'] : null,
			'amount_discounted'          => isset( $data['amount_discounted'] ) ? (float) $data['amount_discounted'] : null,
			'amount_after'               => isset( $data['amount_after'] ) ? (float) $data['amount_after'] : null,
			'currency'                   => isset( $data['currency'] ) ? strtoupper( sanitize_text_field( (string) $data['currency'] ) ) : 'USD',
			'stripe_checkout_session_id' => isset( $data['stripe_checkout_session_id'] ) ? sanitize_text_field( (string) $data['stripe_checkout_session_id'] ) : null,
			'redeemed_at'                => $now,
			'created_at'                 => $now,
		);

		if ( empty( $row['discount_code_id'] ) ) {
			return false;
		}

		$inserted = $wpdb->insert(
			self::table(),
			$row,
			array( '%d', '%d', '%d', '%s', '%s', '%s', '%f', '%f', '%f', '%f', '%s', '%s', '%s', '%s' )
		);

		return false === $inserted ? false : (int) $wpdb->insert_id;
	}

	/**
	 * True if this checkout session already has a recorded redemption —
	 * webhook retries must never double-count a redemption, matching the
	 * plugin-wide "webhook retries can't duplicate" principle already used
	 * for email delivery and the payment ledger.
	 */
	public static function exists_for_session( $stripe_checkout_session_id ) {
		global $wpdb;
		$stripe_checkout_session_id = (string) $stripe_checkout_session_id;
		if ( '' === $stripe_checkout_session_id ) {
			return false;
		}
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . self::table() . ' WHERE stripe_checkout_session_id = %s',
				$stripe_checkout_session_id
			)
		);
		return $count > 0;
	}

	public static function get_by_code( $discount_code_id, $limit = 200 ) {
		global $wpdb;
		$limit = max( 1, min( 1000, (int) $limit ) );
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE discount_code_id = %d ORDER BY redeemed_at DESC LIMIT %d',
				(int) $discount_code_id,
				$limit
			),
			ARRAY_A
		) ?: array();
	}

	public static function get_by_membership( $membership_id ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE membership_id = %d ORDER BY redeemed_at DESC',
				(int) $membership_id
			),
			ARRAY_A
		) ?: array();
	}

	/** How many times this email has already redeemed this code — the per-user cap check. */
	public static function count_by_code_and_email( $discount_code_id, $email ) {
		global $wpdb;
		$email = sanitize_email( (string) $email );
		if ( '' === $email ) {
			return 0;
		}
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . self::table() . ' WHERE discount_code_id = %d AND email = %s',
				(int) $discount_code_id,
				$email
			)
		);
	}

	/** Aggregate stats for one code — total redemptions and total discount given, in dollars. */
	public static function stats_for_code( $discount_code_id ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT COUNT(*) AS redemptions, COALESCE(SUM(amount_discounted),0) AS total_discounted FROM ' . self::table() . ' WHERE discount_code_id = %d',
				(int) $discount_code_id
			),
			ARRAY_A
		);
		return array(
			'redemptions'      => (int) ( $row['redemptions'] ?? 0 ),
			'total_discounted' => (float) ( $row['total_discounted'] ?? 0 ),
		);
	}
}
