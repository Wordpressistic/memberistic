<?php
/**
 * Booking entitlement policy — the single authority that decides whether an
 * authenticated user's membership includes a booking at no charge.
 *
 * Business rules (see docs/entitlements.md):
 *  - Only the configured "included" plan slugs qualify. Empty by default:
 *    nothing is included until an operator says which plans qualify. Guest
 *    passes, WooCommerce customers, subscribers, and every other
 *    classification pay the public price.
 *  - Only the configured statuses qualify (default: active, comped). Trial,
 *    past_due, suspended, expired, cancelled and needs_review never do unless
 *    a site explicitly widens the documented setting.
 *  - Entitlement is resolved from an AUTHENTICATED user id only. A typed email
 *    address never grants (or reveals) anything.
 *  - Linked/family members qualify through their own associated account: the
 *    people row must reference their wp_user_id and be active.
 *
 * A mapped booking engine consumes this through its own
 * `<prefix>_lane_entitlement` filter (see Booking_Adapter) and receives a
 * structured snapshot, never a bare boolean.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic\Integrations;

use WordPressistic\Memberistic\Database\Memberships_Repository;
use WordPressistic\Memberistic\Database\People_Repository;
use WordPressistic\Memberistic\Database\Plans_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Entitlement_Service {

	/* Eligibility reason codes — stable strings, safe to log and assert on. */
	const REASON_INCLUDED          = 'member_included';
	const REASON_NOT_AUTHENTICATED = 'not_authenticated';
	const REASON_NO_MEMBERSHIP     = 'no_membership';
	const REASON_STATUS_INELIGIBLE = 'status_not_eligible';
	const REASON_PLAN_INELIGIBLE   = 'plan_not_eligible';
	const REASON_EXPIRED           = 'membership_expired';
	const REASON_PERSON_INACTIVE   = 'linked_person_inactive';

	public static function register() {
		// Memberistic's own contract — always available.
		add_filter( 'memberistic_entitlement_snapshot', array( self::class, 'filter_lane_entitlement' ), 10, 2 );

		// Mirror it onto the mapped booking engine's filter, when one is
		// mapped, so that plugin can consume the same snapshot unchanged.
		$hook = Booking_Adapter::hook( 'lane_entitlement' );

		if ( '' !== $hook ) {
			add_filter( $hook, array( self::class, 'filter_lane_entitlement' ), 10, 2 );
		}
	}

	/**
	 * Plan slugs whose membership INCLUDES bookings at no charge.
	 *
	 * Documented business setting: `memberistic_lane_included_plan_slugs`
	 * (array of stable plan slugs).
	 *
	 * Empty by default, and an empty result is respected rather than being
	 * replaced by a fallback. Which plans give away otherwise-paid booking
	 * inventory is a commercial decision, and a default that guesses it
	 * wrong fails in the expensive direction — silently, and only visible
	 * in the accounts much later. No plan qualifies until an operator says
	 * so.
	 *
	 * @since 2.0.0 No built-in default; empty means "nothing is included".
	 *
	 * @return string[]
	 */
	public static function included_plan_slugs() {
		$slugs = get_option( 'memberistic_lane_included_plan_slugs', array() );
		$slugs = is_array( $slugs ) ? $slugs : array();
		$slugs = array_values( array_filter( array_map( 'sanitize_key', $slugs ) ) );

		/**
		 * Filters the plan slugs whose membership includes bookings at no charge.
		 *
		 * @since 1.0.0
		 *
		 * @param string[] $slugs Stable plan slugs. Empty by default.
		 */
		$slugs = (array) apply_filters( 'memberistic_lane_included_plan_slugs', $slugs );

		/**
		 * Filters plan slugs that can never include free bookings.
		 *
		 * A guest pass may be an intentionally sold product, but it is a
		 * paid single visit — it must never resolve to a free booking. This
		 * exclusion is applied after the allowlist filter so that no setting
		 * typo, import, or third-party filter can leak it back in.
		 *
		 * @since 2.0.0
		 *
		 * @param string[] $excluded Slugs that never qualify.
		 */
		$excluded = (array) apply_filters( 'memberistic_never_included_plan_slugs', array( 'guest-pass' ) );

		return array_values( array_diff( array_map( 'sanitize_key', $slugs ), array_map( 'sanitize_key', $excluded ) ) );
	}

	/**
	 * Membership statuses that keep the benefit usable.
	 *
	 * Documented business setting: `memberistic_lane_eligible_statuses`.
	 * Default: active, comped. Everything else is excluded by policy.
	 *
	 * @return string[]
	 */
	public static function eligible_statuses() {
		$statuses = get_option( 'memberistic_lane_eligible_statuses', array( 'active', 'comped' ) );
		$statuses = is_array( $statuses ) ? $statuses : array();
		$statuses = array_values( array_filter( array_map( 'sanitize_key', $statuses ) ) );
		if ( ! $statuses ) {
			$statuses = array( 'active', 'comped' );
		}
		return (array) apply_filters( 'memberistic_lane_eligible_statuses', $statuses );
	}

	/**
	 * Filter bridge for the booking engine.
	 *
	 * @param array $entitlement Seed entitlement array from the booking engine.
	 * @param int   $user_id     AUTHENTICATED user id (0 for guests).
	 * @return array
	 */
	public static function filter_lane_entitlement( $entitlement, $user_id ) {
		$resolved = self::resolve_for_user( (int) $user_id );

		// Never downgrade a structured result the site may have layered earlier;
		// Memberistic answers authoritatively only when it finds an eligible
		// membership, otherwise it annotates the reason and leaves eligible=false.
		if ( is_array( $entitlement ) && ! empty( $entitlement['eligible'] ) && empty( $resolved['eligible'] ) ) {
			return $entitlement;
		}

		return $resolved;
	}

	/**
	 * Resolve the structured lane entitlement for an authenticated user.
	 *
	 * @param int $user_id Authenticated WP user id. 0 = anonymous.
	 * @return array{
	 *     user_id:int, membership_id:int, plan_id:int, plan_slug:string,
	 *     membership_status:string, eligible:bool, reason:string,
	 *     pricing_type:string, amount_due:float, allowed_gateway:string,
	 *     plan_name:string, checked_at:string
	 * }
	 */
	public static function resolve_for_user( $user_id ) {
		$user_id = absint( $user_id );
		$result  = array(
			'user_id'           => $user_id,
			'membership_id'     => 0,
			'plan_id'           => 0,
			'plan_slug'         => '',
			'plan_name'         => '',
			'membership_status' => '',
			'eligible'          => false,
			'reason'            => self::REASON_NOT_AUTHENTICATED,
			'pricing_type'      => 'public_full_price',
			'amount_due'        => null, // booking engine fills in the server-calculated price.
			'allowed_gateway'   => 'online',
			'checked_at'        => current_time( 'mysql' ),
		);

		if ( ! $user_id ) {
			return $result;
		}

		$candidate = self::membership_for_authenticated_user( $user_id );

		if ( is_string( $candidate ) ) { // A reason code — the lookup failed in a specific way.
			$result['reason'] = $candidate;
			return $result;
		}

		if ( ! is_array( $candidate ) || empty( $candidate['membership'] ) ) {
			$result['reason'] = self::REASON_NO_MEMBERSHIP;
			return $result;
		}

		$membership = $candidate['membership'];

		$result['membership_id']     = (int) ( $membership['id'] ?? 0 );
		$result['plan_id']           = (int) ( $membership['plan_id'] ?? 0 );
		$result['membership_status'] = sanitize_key( (string) ( $membership['status'] ?? '' ) );

		$plan = $result['plan_id'] ? Plans_Repository::get( $result['plan_id'] ) : null;
		if ( is_array( $plan ) ) {
			$result['plan_slug'] = sanitize_key( (string) ( $plan['slug'] ?? '' ) );
			$result['plan_name'] = (string) ( $plan['name'] ?? '' );
		}

		if ( ! in_array( $result['membership_status'], self::eligible_statuses(), true ) ) {
			$result['reason'] = self::REASON_STATUS_INELIGIBLE;
			return $result;
		}

		if ( self::membership_expired( $membership ) ) {
			$result['reason'] = self::REASON_EXPIRED;
			return $result;
		}

		if ( ! in_array( $result['plan_slug'], self::included_plan_slugs(), true ) ) {
			$result['reason'] = self::REASON_PLAN_INELIGIBLE;
			return $result;
		}

		$result['eligible']        = true;
		$result['reason']          = self::REASON_INCLUDED;
		$result['pricing_type']    = 'member_included';
		$result['amount_due']      = 0.0;
		$result['allowed_gateway'] = 'member_included';

		return $result;
	}

	/**
	 * Find the membership an AUTHENTICATED user may exercise:
	 *  1. a membership where they are the primary user; else
	 *  2. a membership where a people row references their wp_user_id
	 *     (linked/family member through their own account) and is active.
	 *
	 * No email matching — an unauthenticated visitor typing a member's email
	 * must never reach this code path with that member's user id.
	 *
	 * @param int $user_id Authenticated user id.
	 * @return array{membership:array}|string|null Candidate, reason code, or null.
	 */
	private static function membership_for_authenticated_user( $user_id ) {
		$membership = Memberships_Repository::get_by_user_id( $user_id );
		if ( is_array( $membership ) && ! empty( $membership['id'] ) ) {
			return array( 'membership' => $membership );
		}

		global $wpdb;
		$people = People_Repository::table();
		$person = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$people} WHERE wp_user_id = %d ORDER BY id ASC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$user_id
			),
			ARRAY_A
		);

		if ( ! is_array( $person ) || empty( $person['membership_id'] ) ) {
			return null;
		}

		if ( 'active' !== sanitize_key( (string) ( $person['status'] ?? '' ) ) ) {
			return self::REASON_PERSON_INACTIVE;
		}

		$membership = Memberships_Repository::get( (int) $person['membership_id'] );
		if ( is_array( $membership ) && ! empty( $membership['id'] ) ) {
			return array( 'membership' => $membership );
		}

		return null;
	}

	/**
	 * True when the membership's renewal date has passed (end of that day,
	 * site timezone). Empty/zero renewal dates mean non-expiring.
	 *
	 * @param array $membership Membership row.
	 * @return bool
	 */
	private static function membership_expired( $membership ) {
		$renewal_raw = (string) ( $membership['renewal_date'] ?? '' );
		if ( '' === $renewal_raw || 0 === strpos( $renewal_raw, '0000-00-00' ) ) {
			return false;
		}

		$date_part = substr( $renewal_raw, 0, 10 );
		$renewal   = \DateTime::createFromFormat( 'Y-m-d H:i:s', $date_part . ' 23:59:59', wp_timezone() );
		if ( ! $renewal ) {
			return false;
		}

		return $renewal->getTimestamp() < time();
	}
}
