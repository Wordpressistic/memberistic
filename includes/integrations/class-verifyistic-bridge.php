<?php
/**
 * Verifyistic ↔ Memberistic integration (membership side).
 *
 * Complements the Booking Engine's Verifyistic module (which handles
 * booking-time waiver/age gating). This bridge does the MEMBERSHIP-side work:
 *
 *   - Stamps the member's WP user with their verified age / DOB / token when a
 *     membership is created or activated (read from the visitor's
 *     `verifyistic_verified` cookie → wp_verifyistic_logs).
 *   - Exposes the verified status (helper + filter + a line on the verify card).
 *   - Treats verification as required for membership signup by default
 *     (surfaced as a filter other code can honor; set
 *     integration_verifyistic_require_signup = no to opt out).
 *
 * Entirely gated by the Integrations toggle (integration_verifyistic_enabled)
 * and a no-op unless the Verifyistic plugin is active.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic\Integrations;

use WordPressistic\Memberistic\Database\Memberships_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Verifyistic_Bridge {

	const COOKIE_NAME = 'verifyistic_verified';

	public static function register() {
		if ( ! Integrations_Registry::is_enabled( 'verifyistic' ) ) {
			return;
		}

		// Stamp the member record on the membership lifecycle events.
		add_action( 'memberistic_membership_activated', array( self::class, 'stamp_from_membership' ), 20, 1 );
		add_action( 'memberistic_membership_created', array( self::class, 'stamp_from_membership' ), 20, 1 );
		add_action( 'memberistic_person_added', array( self::class, 'stamp_from_person' ), 20, 2 );

		// Show verified status under the member verification card.
		add_action( 'memberistic_verify_card_after', array( self::class, 'render_verified_line' ), 10, 2 );

		// Expose "verification required for signup" as a filterable gate
		// (default ON — set integration_verifyistic_require_signup = no to opt out).
		add_filter( 'memberistic_signup_requires_verification', array( self::class, 'require_signup_filter' ), 10, 1 );
	}

	/** True only when the Verifyistic plugin is installed. */
	public static function plugin_active() {
		return class_exists( '\\Verifyistic_DB' ) || class_exists( '\\Verifyistic_Frontend' );
	}

	/**
	 * Read the current visitor's passing verification record (cookie → log).
	 * Mirrors the Booking Engine module's lookup so both stay consistent.
	 *
	 * @return object|null Row from wp_verifyistic_logs (or a minimal object for
	 *                     cookie-only verification), or null.
	 */
	public static function get_current_verification() {
		if ( ! self::plugin_active() ) {
			return null;
		}
		$token = isset( $_COOKIE[ self::COOKIE_NAME ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) ) : '';
		// SECURITY (compliance): reject empty cookies and the legacy literal "1"
		// sentinel. The previous code path treated cookie="1" as a passed
		// verification with no DB lookup — trivially spoofable via DevTools.
		if ( '' === $token || strlen( $token ) < 16 || ! preg_match( '/^[A-Za-z0-9_\-]+$/', $token ) ) {
			return null;
		}

		global $wpdb;
		$table  = $wpdb->prefix . 'verifyistic_logs';
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			return null;
		}
		// Compliance TTL: stale verifications must not unlock the gate forever.
		// Tries the namespaced setting helper first (UI surface) then falls
		// back to a stand-alone option so this works even before the helper
		// is in scope.
		$max_age_days = 0;
		if ( function_exists( 'WordPressistic\\Memberistic\\memberistic_get_setting' ) ) {
			$max_age_days = (int) \WordPressistic\Memberistic\memberistic_get_setting( 'verifyistic_max_age_days', 30 );
		}
		if ( $max_age_days <= 0 ) {
			$max_age_days = (int) get_option( 'memberistic_verifyistic_max_age_days', 30 );
		}
		if ( $max_age_days <= 0 ) {
			$max_age_days = 30;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE verify_token = %s AND status = 'passed' AND verified_at >= DATE_SUB(NOW(), INTERVAL %d DAY) ORDER BY id DESC LIMIT 1",
			$token,
			$max_age_days
		) );
		return $row ?: null;
	}

	/** Stored verification meta for a user (or null). */
	public static function get_user_verification( $user_id ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return null;
		}
		$token = get_user_meta( $user_id, 'memberistic_verify_token', true );
		if ( '' === $token ) {
			return null;
		}
		return array(
			'token'       => $token,
			'age'         => (int) get_user_meta( $user_id, 'memberistic_verified_age', true ),
			'dob'         => (string) get_user_meta( $user_id, 'memberistic_verified_dob', true ),
			'verified_at' => (string) get_user_meta( $user_id, 'memberistic_verified_at', true ),
		);
	}

	/** Stamp a user from the current visitor's verification, if any. */
	public static function stamp_user( $user_id ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return;
		}
		// Respect an explicit "auto-stamp off" preference.
		if ( 'no' === memberistic_get_setting( 'integration_verifyistic_autostamp', 'yes' ) ) {
			return;
		}
		// Don't overwrite an existing stamp.
		if ( get_user_meta( $user_id, 'memberistic_verify_token', true ) ) {
			return;
		}
		$v = self::get_current_verification();
		if ( ! $v || empty( $v->verify_token ) ) {
			return;
		}
		update_user_meta( $user_id, 'memberistic_verify_token', sanitize_text_field( (string) $v->verify_token ) );
		update_user_meta( $user_id, 'memberistic_verified_age', (int) ( $v->age_at_verify ?? 0 ) );
		update_user_meta( $user_id, 'memberistic_verified_dob', sanitize_text_field( (string) ( $v->dob ?? '' ) ) );
		update_user_meta( $user_id, 'memberistic_verified_at', sanitize_text_field( (string) ( $v->verified_at ?? current_time( 'mysql' ) ) ) );

		/** Fires after a member record is stamped with verification data. */
		do_action( 'memberistic_member_verified', $user_id, $v );
	}

	/** Lifecycle: membership_id → primary user → stamp. */
	public static function stamp_from_membership( $membership_id ) {
		$membership = Memberships_Repository::get( (int) $membership_id );
		if ( ! $membership ) {
			return;
		}
		$user_id = (int) ( $membership['primary_user_id'] ?? 0 );
		if ( $user_id ) {
			self::stamp_user( $user_id );
		}
	}

	/** Lifecycle: a person was added to a membership. */
	public static function stamp_from_person( $person_id, $membership_id ) {
		$membership = Memberships_Repository::get( (int) $membership_id );
		if ( ! $membership ) {
			return;
		}
		$user_id = (int) ( $membership['primary_user_id'] ?? 0 );
		if ( $user_id ) {
			self::stamp_user( $user_id );
		}
	}

	/** Render an "Age verified" line on the member verification card. */
	public static function render_verified_line( $user, $membership ) {
		$user_id = is_object( $user ) ? (int) $user->ID : (int) $user;
		$v       = self::get_user_verification( $user_id );
		if ( ! $v ) {
			return;
		}
		$when = $v['verified_at'] ? mysql2date( get_option( 'date_format' ), $v['verified_at'] ) : '';
		printf(
			'<div class="memberistic-verify-flag" style="margin-top:8px;font-size:13px;color:#0a7c3f;">%s%s</div>',
			esc_html__( '✓ Age verified', 'memberistic' ),
			$when ? esc_html( ' · ' . $when ) : ''
		);
	}

	/** Filter: require verification for signup (default on). */
	public static function require_signup_filter( $required ) {
		return 'no' !== memberistic_get_setting( 'integration_verifyistic_require_signup', 'yes' ) ? true : $required;
	}

	/**
	 * Should the current public signup be blocked for lack of age
	 * verification? Authoritative gate used by the checkout flow.
	 *
	 * Reads the setting directly (so it's correct regardless of hook order),
	 * then lets the `memberistic_signup_requires_verification` filter override.
	 * Fails OPEN when the integration is off or the Verifyistic plugin is
	 * unavailable — a verification outage should not lock out every signup.
	 * Staff (manage_memberistic_settings) always bypass.
	 *
	 * @return bool True = block the signup.
	 */
	public static function signup_blocked() {
		if ( ! Integrations_Registry::is_enabled( 'verifyistic' ) ) {
			return false;
		}
		$required = 'no' !== memberistic_get_setting( 'integration_verifyistic_require_signup', 'yes' );
		$required = (bool) apply_filters( 'memberistic_signup_requires_verification', $required );
		if ( ! $required ) {
			return false;
		}
		if ( function_exists( 'current_user_can' ) && current_user_can( 'manage_memberistic_settings' ) ) {
			return false; // staff/admin testing the flow
		}
		// Verified right now (cookie) — allow.
		if ( self::get_current_verification() ) {
			return false;
		}
		// Logged-in member already stamped from a prior verification — allow.
		$uid = get_current_user_id();
		if ( $uid && self::get_user_verification( $uid ) ) {
			return false;
		}
		return true;
	}
}
