<?php
/**
 * Booking ↔ waiver bridge.
 *
 * Answers the mapped booking engine's `<prefix>_waiver_satisfied` filter
 * (see Booking_Adapter): when a booking type requires a waiver and the
 * customer didn't tick the form checkbox, we satisfy it automatically if
 * they already have a signed waiver on file — so returning customers never
 * re-sign at booking time.
 *
 * SECURITY: the booking form is public and its fields are attacker-
 * controlled, so the match here is by EMAIL ONLY. The earlier name
 * fallback meant any guest who typed a name matching any prior signer had
 * the waiver requirement waived. Name/DOB matching remains available to
 * the authenticated staff lookup screens via Waivers_Archive directly.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic\Waivers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Waiver_Booking_Bridge {

	public static function register() {
		$hook = \WordPressistic\Memberistic\Integrations\Booking_Adapter::hook( 'waiver_satisfied' );

		if ( '' === $hook ) {
			return; // No booking engine mapped — nothing to answer.
		}

		add_filter( $hook, array( __CLASS__, 'satisfy' ), 10, 3 );
	}

	/**
	 * @param bool  $ok           Whether the booking form's waiver checkbox was ticked.
	 * @param array $fields       Submitted booking fields.
	 * @param mixed $booking_type Booking type row (unused).
	 * @return bool
	 */
	public static function satisfy( $ok, $fields, $booking_type ) {
		if ( $ok ) {
			return true;
		}
		$fields = is_array( $fields ) ? $fields : array();
		$email  = isset( $fields['customer_email'] ) ? sanitize_email( (string) $fields['customer_email'] ) : '';
		if ( '' === $email || ! is_email( $email ) ) {
			return (bool) $ok;
		}
		return Waivers_Archive::has_on_file( $email ) ? true : (bool) $ok;
	}
}
