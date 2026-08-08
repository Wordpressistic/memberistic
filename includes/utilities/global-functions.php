<?php
/**
 * Global helper wrappers.
 *
 * @package Memberistic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'memberistic_get_setting' ) ) {
	/**
	 * Get a Memberistic setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Default value.
	 */
	function memberistic_get_setting( $key, $default = null ) {
		return WordPressistic\Memberistic\memberistic_get_setting( $key, $default );
	}
}

if ( ! function_exists( 'memberistic_get_brand_label' ) ) {
	/**
	 * Get the filtered Memberistic brand label.
	 */
	function memberistic_get_brand_label() {
		return WordPressistic\Memberistic\memberistic_get_brand_label();
	}
}

if ( ! function_exists( 'memberistic_member_display_id' ) ) {
	/**
	 * Get the display-only member card ID for a membership row.
	 *
	 * @param array $membership Membership row.
	 * @return string
	 */
	function memberistic_member_display_id( $membership ) {
		return WordPressistic\Memberistic\memberistic_member_display_id( $membership );
	}
}

if ( ! function_exists( 'memberistic_secret_setting_keys' ) ) {
	function memberistic_secret_setting_keys() {
		return WordPressistic\Memberistic\memberistic_secret_setting_keys();
	}
}

if ( ! function_exists( 'memberistic_setting_is_locked_by_constant' ) ) {
	function memberistic_setting_is_locked_by_constant( $key ) {
		return WordPressistic\Memberistic\memberistic_setting_is_locked_by_constant( $key );
	}
}

if ( ! function_exists( 'memberistic_mask_secret' ) ) {
	function memberistic_mask_secret( $value ) {
		return WordPressistic\Memberistic\memberistic_mask_secret( $value );
	}
}

if ( ! function_exists( 'memberistic_user_has_membership' ) ) {
	/**
	 * Whether a WP user has an active (or trial) Memberistic membership,
	 * either as the primary owner or as a linked person on someone else's
	 * plan (for example a linked family member).
	 *
	 * This was previously called from class-memberships-controller.php's
	 * member_self_permissions_check() but never actually defined anywhere —
	 * function_exists() silently short-circuited that branch, which made the
	 * REST permission check fall through to an unrelated, always-true WP
	 * core capability check. It's now real.
	 *
	 * @param int $user_id WP user id.
	 * @return bool
	 */
	function memberistic_user_has_membership( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return false;
		}

		global $wpdb;
		$active_statuses = array( 'active', 'trial' );
		$placeholders     = implode( ',', array_fill( 0, count( $active_statuses ), '%s' ) );

		$row = $wpdb->get_var( $wpdb->prepare(
			"SELECT m.id
			 FROM {$wpdb->prefix}memberistic_memberships m
			 LEFT JOIN {$wpdb->prefix}memberistic_people p ON p.membership_id = m.id
			 WHERE ( m.primary_user_id = %d OR p.wp_user_id = %d )
			   AND m.status IN ( {$placeholders} )
			 LIMIT 1",
			array_merge( array( $user_id, $user_id ), $active_statuses )
		) );

		return null !== $row;
	}
}

if ( ! function_exists( 'memberistic_get_membership_status' ) ) {
	/**
	 * Structured membership status for a user. The canonical public lookup —
	 * other plugins must use this instead of reading membership tables or the
	 * stale `memberistic_active_plan_id` user meta directly.
	 *
	 * @param int $user_id WP user id.
	 * @return array See Membership_Service::get_user_membership_status().
	 */
	function memberistic_get_membership_status( $user_id ) {
		return \WordPressistic\Memberistic\Membership_Service::get_user_membership_status( $user_id );
	}
}

if ( ! function_exists( 'memberistic_user_has_active_membership' ) ) {
	/**
	 * True when the user holds a LIVE membership (active/comped, not expired).
	 *
	 * Unlike memberistic_user_has_membership(), this excludes trials and
	 * lapsed rows, so it is the correct check for gating benefits.
	 *
	 * @param int    $user_id   WP user id.
	 * @param string $plan_slug Optional plan slug to require.
	 * @return bool
	 */
	function memberistic_user_has_active_membership( $user_id, $plan_slug = '' ) {
		return \WordPressistic\Memberistic\Membership_Service::user_has_active_plan( $user_id, $plan_slug );
	}
}

if ( ! function_exists( 'memberistic_can_user_book' ) ) {
	/**
	 * Entitlement decision for the booking engine: may this authenticated
	 * user reserve using a membership benefit (i.e. without paying)?
	 *
	 * @param int   $user_id         WP user id.
	 * @param array $booking_context Optional booking context.
	 * @return bool
	 */
	function memberistic_can_user_book( $user_id, $booking_context = array() ) {
		return \WordPressistic\Memberistic\Membership_Service::can_book_lane( $user_id, $booking_context );
	}
}

if ( ! function_exists( 'memberistic_booking_requires_payment' ) ) {
	/**
	 * True when the booking must be paid online before it can be confirmed.
	 *
	 * @param int   $user_id         WP user id.
	 * @param array $booking_context Optional booking context.
	 * @return bool
	 */
	function memberistic_booking_requires_payment( $user_id, $booking_context = array() ) {
		return \WordPressistic\Memberistic\Membership_Service::requires_payment_for_booking( $user_id, $booking_context );
	}
}

if ( ! function_exists( 'memberistic_is_guest_user' ) ) {
	/**
	 * True when the user holds no live membership (guest / range customer).
	 *
	 * @param int $user_id WP user id.
	 * @return bool
	 */
	function memberistic_is_guest_user( $user_id ) {
		return \WordPressistic\Memberistic\Membership_Service::is_guest_user( $user_id );
	}
}

if ( ! function_exists( 'memberistic_waiver_on_file' ) ) {
	/**
	 * Public lookup: the current waiver-on-file for a person, by email (then
	 * name + optional DOB). Returns the archive row array, or null. Safe to call
	 * from other plugins (e.g. the booking engine) to auto-satisfy a waiver step.
	 *
	 * @param string $email
	 * @param string $name "First Last"
	 * @param string $dob  Y-m-d
	 * @return array|null
	 */
	function memberistic_waiver_on_file( $email, $name = '', $dob = '' ) {
		if ( ! class_exists( '\\WordPressistic\\Memberistic\\Waivers\\Waivers_Archive' ) ) {
			return null;
		}
		return \WordPressistic\Memberistic\Waivers\Waivers_Archive::find_on_file( $email, $name, $dob );
	}
}
