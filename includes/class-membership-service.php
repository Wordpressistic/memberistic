<?php
/**
 * Membership Service — the single public authority for membership state.
 *
 * Every other plugin (booking engine, WooCommerce bridge, POS, admin screens)
 * asks THIS class about membership status, plan ownership, booking
 * entitlement and payment requirements. Nothing outside Memberistic may make
 * its own membership assumptions, and nothing may read stale user-meta
 * shortcuts such as `memberistic_active_plan_id` (written on activation but
 * never cleared on expiry/cancellation).
 *
 * This is a FACADE, not a second rulebook: lane entitlement rules live in
 * Integrations\Entitlement_Service and are delegated to, never duplicated.
 *
 * Plan assignment is deliberately restrictive — see assign_plan_after_payment()
 * and assign_plan_manually(). A booking, a registration, a WooCommerce
 * customer record or a pending/failed payment can NEVER assign a plan.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic;

use WordPressistic\Memberistic\Database\Activity_Repository;
use WordPressistic\Memberistic\Database\Memberships_Repository;
use WordPressistic\Memberistic\Database\People_Repository;
use WordPressistic\Memberistic\Database\Plans_Repository;
use WordPressistic\Memberistic\Integrations\Entitlement_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Membership_Service {

	/** Statuses that mean "this membership is live right now". */
	const LIVE_STATUSES = array( 'active', 'comped' );

	/**
	 * Structured membership status for a user.
	 *
	 * Resolved live from the memberships table — never from user meta.
	 * Covers both primary members and linked/family members who are
	 * authenticated through their own account.
	 *
	 * @param int $user_id WP user id.
	 * @return array{
	 *     user_id:int, has_membership:bool, is_live:bool, membership_id:int,
	 *     plan_id:int, plan_slug:string, plan_name:string, status:string,
	 *     role:string, start_date:string, renewal_date:string, expired:bool
	 * }
	 */
	public static function get_user_membership_status( $user_id ) {
		$user_id = absint( $user_id );
		$out     = array(
			'user_id'        => $user_id,
			'has_membership' => false,
			'is_live'        => false,
			'membership_id'  => 0,
			'plan_id'        => 0,
			'plan_slug'      => '',
			'plan_name'      => '',
			'status'         => '',
			'role'           => '',
			'start_date'     => '',
			'renewal_date'   => '',
			'expired'        => false,
		);

		if ( ! $user_id ) {
			return $out;
		}

		$found = self::find_membership_for_user( $user_id );
		if ( ! $found ) {
			return $out;
		}

		$membership = $found['membership'];
		$plan       = ! empty( $membership['plan_id'] ) ? Plans_Repository::get( (int) $membership['plan_id'] ) : null;

		$out['has_membership'] = true;
		$out['membership_id']  = (int) ( $membership['id'] ?? 0 );
		$out['plan_id']        = (int) ( $membership['plan_id'] ?? 0 );
		$out['plan_slug']      = is_array( $plan ) ? sanitize_key( (string) ( $plan['slug'] ?? '' ) ) : '';
		$out['plan_name']      = is_array( $plan ) ? (string) ( $plan['name'] ?? '' ) : '';
		$out['status']         = sanitize_key( (string) ( $membership['status'] ?? '' ) );
		$out['role']           = (string) $found['role'];
		$out['start_date']     = (string) ( $membership['start_date'] ?? '' );
		$out['renewal_date']   = (string) ( $membership['renewal_date'] ?? '' );
		$out['expired']        = self::membership_expired( $membership );
		$out['is_live']        = in_array( $out['status'], self::live_statuses(), true ) && ! $out['expired'];

		return $out;
	}

	/**
	 * True when the user holds a LIVE membership on the given plan slug.
	 *
	 * @param int    $user_id   WP user id.
	 * @param string $plan_slug Stable plan slug (e.g. 'individual'). Empty = any live plan.
	 * @return bool
	 */
	public static function user_has_active_plan( $user_id, $plan_slug = '' ) {
		$status = self::get_user_membership_status( $user_id );
		if ( ! $status['is_live'] ) {
			return false;
		}
		$plan_slug = sanitize_key( (string) $plan_slug );
		return '' === $plan_slug || $plan_slug === $status['plan_slug'];
	}

	/**
	 * The user's current plan row, or null when they have no live membership.
	 *
	 * @param int $user_id WP user id.
	 * @return array|null
	 */
	public static function get_user_plan( $user_id ) {
		$status = self::get_user_membership_status( $user_id );
		if ( ! $status['is_live'] || ! $status['plan_id'] ) {
			return null;
		}
		$plan = Plans_Repository::get( $status['plan_id'] );
		return is_array( $plan ) ? $plan : null;
	}

	/**
	 * True when the user has no live membership (a guest / range customer).
	 *
	 * A Range Guest is NOT a member: they hold no membership row at all, only
	 * a customer segment recorded by the booking engine.
	 *
	 * @param int $user_id WP user id.
	 * @return bool
	 */
	public static function is_guest_user( $user_id ) {
		return ! self::get_user_membership_status( $user_id )['is_live'];
	}

	/* ─────────────────────────────────────────────────────────────────
	 * Booking entitlement — delegated to Entitlement_Service.
	 * ───────────────────────────────────────────────────────────────── */

	/**
	 * May this AUTHENTICATED user reserve a lane using a membership benefit?
	 *
	 * Delegates to Entitlement_Service so the included-plan / eligible-status
	 * rules live in exactly one place.
	 *
	 * @param int   $user_id         WP user id (0 = anonymous → always false).
	 * @param array $booking_context Optional context (booking_type, resource…).
	 * @return bool
	 */
	public static function can_book_lane( $user_id, $booking_context = array() ) {
		$entitlement = self::lane_entitlement( $user_id, $booking_context );
		return ! empty( $entitlement['eligible'] );
	}

	/**
	 * Must this user pay for the booking described by $booking_context?
	 *
	 * True for every guest/non-eligible user — the booking engine turns this
	 * into a mandatory online checkout. False only when a live, eligible
	 * membership includes the reservation.
	 *
	 * @param int   $user_id         WP user id.
	 * @param array $booking_context Optional context.
	 * @return bool
	 */
	public static function requires_payment_for_booking( $user_id, $booking_context = array() ) {
		return ! self::can_book_lane( $user_id, $booking_context );
	}

	/**
	 * The full structured entitlement snapshot (see Entitlement_Service).
	 *
	 * @param int   $user_id         WP user id.
	 * @param array $booking_context Optional context, passed to filters.
	 * @return array
	 */
	public static function lane_entitlement( $user_id, $booking_context = array() ) {
		$entitlement = Entitlement_Service::resolve_for_user( absint( $user_id ) );
		return (array) apply_filters( 'memberistic_lane_entitlement', $entitlement, absint( $user_id ), $booking_context );
	}

	/* ─────────────────────────────────────────────────────────────────
	 * Plan assignment — payment-verified or admin-authorised ONLY.
	 * ───────────────────────────────────────────────────────────────── */

	/**
	 * Assign a plan after a VERIFIED successful payment.
	 *
	 * Refuses without payment evidence: a caller must supply an order/
	 * transaction reference that has already been confirmed by the gateway or
	 * WooCommerce. Booking creation, registration, customer-record creation
	 * and pending/failed payments must never reach this method.
	 *
	 * @param int        $user_id  WP user id receiving the plan.
	 * @param int|string $plan     Plan id or stable plan slug.
	 * @param array      $evidence {
	 *     Payment evidence. At least one reference is required.
	 *     @type int    $order_id       WooCommerce order id.
	 *     @type string $transaction_id Gateway transaction/subscription id.
	 *     @type string $source         'woocommerce'|'stripe'|'pos'…
	 *     @type bool   $payment_verified Must be true.
	 *     @type string $billing_cycle  'monthly'|'annual'.
	 * }
	 * @return int|\WP_Error Membership id on success.
	 */
	public static function assign_plan_after_payment( $user_id, $plan, $evidence = array() ) {
		$user_id = absint( $user_id );
		if ( ! $user_id || ! get_userdata( $user_id ) ) {
			return new \WP_Error( 'memberistic_bad_user', __( 'A valid user is required to assign a plan.', 'memberistic' ) );
		}

		$order_id       = absint( $evidence['order_id'] ?? 0 );
		$transaction_id = sanitize_text_field( (string) ( $evidence['transaction_id'] ?? '' ) );
		$verified       = ! empty( $evidence['payment_verified'] );

		if ( ! $verified || ( ! $order_id && '' === $transaction_id ) ) {
			return new \WP_Error(
				'memberistic_payment_evidence_required',
				__( 'A plan can only be assigned with verified payment evidence (a confirmed order or gateway transaction).', 'memberistic' )
			);
		}

		$plan_row = self::resolve_plan( $plan );
		if ( ! $plan_row ) {
			return new \WP_Error( 'memberistic_bad_plan', __( 'Unknown membership plan.', 'memberistic' ) );
		}

		return self::write_membership(
			$user_id,
			$plan_row,
			array(
				'billing_cycle'  => sanitize_key( (string) ( $evidence['billing_cycle'] ?? 'monthly' ) ),
				'payment_source' => sanitize_key( (string) ( $evidence['source'] ?? 'woocommerce' ) ),
				'reason'         => sprintf(
					/* translators: 1: plan name, 2: order/transaction reference */
					__( 'Plan %1$s assigned after verified payment (%2$s).', 'memberistic' ),
					(string) $plan_row['name'],
					$order_id ? '#' . $order_id : $transaction_id
				),
				'order_id'       => $order_id,
				'actor_id'       => 0,
			)
		);
	}

	/**
	 * Assign a plan by explicit ADMIN action.
	 *
	 * Requires an actor with the membership-management capability and a
	 * non-empty reason, both of which are written to the audit trail.
	 *
	 * @param int        $user_id  WP user id.
	 * @param int|string $plan     Plan id or slug.
	 * @param string     $reason   Why this was assigned (required).
	 * @param int        $actor_id Admin performing the action (0 = current user).
	 * @return int|\WP_Error Membership id on success.
	 */
	public static function assign_plan_manually( $user_id, $plan, $reason, $actor_id = 0 ) {
		$user_id  = absint( $user_id );
		$actor_id = $actor_id ? absint( $actor_id ) : get_current_user_id();
		$reason   = trim( sanitize_text_field( (string) $reason ) );

		if ( ! $actor_id || ! user_can( $actor_id, self::manage_capability() ) ) {
			return new \WP_Error( 'memberistic_forbidden', __( 'You do not have permission to assign memberships.', 'memberistic' ) );
		}
		if ( '' === $reason ) {
			return new \WP_Error( 'memberistic_reason_required', __( 'A reason is required when assigning a membership manually.', 'memberistic' ) );
		}
		if ( ! $user_id || ! get_userdata( $user_id ) ) {
			return new \WP_Error( 'memberistic_bad_user', __( 'A valid user is required to assign a plan.', 'memberistic' ) );
		}

		$plan_row = self::resolve_plan( $plan );
		if ( ! $plan_row ) {
			return new \WP_Error( 'memberistic_bad_plan', __( 'Unknown membership plan.', 'memberistic' ) );
		}

		return self::write_membership(
			$user_id,
			$plan_row,
			array(
				'billing_cycle'  => 'annual',
				'payment_source' => 'admin_manual',
				'reason'         => $reason,
				'order_id'       => 0,
				'actor_id'       => $actor_id,
			)
		);
	}

	/**
	 * Remove (expire) a user's plan with an audited reason.
	 *
	 * The membership row is preserved — history, payments, waivers and
	 * bookings must survive. Only the entitlement stops.
	 *
	 * @param int    $user_id  WP user id.
	 * @param int    $plan_id  Plan id being removed (0 = whatever they hold).
	 * @param string $reason   Audited reason (required).
	 * @param int    $actor_id Admin performing the action (0 = system/current).
	 * @return bool|\WP_Error
	 */
	public static function remove_plan( $user_id, $plan_id = 0, $reason = '', $actor_id = 0 ) {
		$user_id  = absint( $user_id );
		$actor_id = $actor_id ? absint( $actor_id ) : get_current_user_id();
		$reason   = trim( sanitize_text_field( (string) $reason ) );

		if ( '' === $reason ) {
			return new \WP_Error( 'memberistic_reason_required', __( 'A reason is required when removing a membership.', 'memberistic' ) );
		}
		if ( $actor_id && ! user_can( $actor_id, self::manage_capability() ) ) {
			return new \WP_Error( 'memberistic_forbidden', __( 'You do not have permission to remove memberships.', 'memberistic' ) );
		}

		$status = self::get_user_membership_status( $user_id );
		if ( ! $status['membership_id'] ) {
			return new \WP_Error( 'memberistic_no_membership', __( 'This user has no membership to remove.', 'memberistic' ) );
		}
		if ( $plan_id && (int) $plan_id !== $status['plan_id'] ) {
			return new \WP_Error( 'memberistic_plan_mismatch', __( 'That plan is not the one this user holds.', 'memberistic' ) );
		}

		Memberships_Repository::change_status( $status['membership_id'], 'expired' );

		Activity_Repository::log( array(
			'membership_id' => $status['membership_id'],
			'user_id'       => $user_id,
			'activity_type' => 'membership_expired',
			'title'         => __( 'Membership removed', 'memberistic' ),
			'description'   => $reason,
			'created_by'    => $actor_id ?: null,
		) );

		self::audit( 'membership_removed', array(
			'user_id'       => $user_id,
			'membership_id' => $status['membership_id'],
			'plan_id'       => $status['plan_id'],
			'reason'        => $reason,
			'actor_id'      => $actor_id,
		) );

		return true;
	}

	/* ─────────────────────────────────────────────────────────────────
	 * Internals
	 * ───────────────────────────────────────────────────────────────── */

	/**
	 * Statuses treated as live. Mirrors the lane-entitlement setting so the
	 * two never drift apart.
	 *
	 * @return string[]
	 */
	private static function live_statuses() {
		$statuses = Entitlement_Service::eligible_statuses();
		return $statuses ? $statuses : self::LIVE_STATUSES;
	}

	/**
	 * Capability required to assign/remove memberships by hand.
	 *
	 * @return string
	 */
	public static function manage_capability() {
		return (string) apply_filters( 'memberistic_manage_memberships_capability', 'manage_options' );
	}

	/**
	 * Find the membership a user may exercise: primary first, then a linked
	 * person row bound to their own account.
	 *
	 * @param int $user_id WP user id.
	 * @return array{membership:array,role:string}|null
	 */
	private static function find_membership_for_user( $user_id ) {
		$membership = Memberships_Repository::get_by_user_id( $user_id );
		if ( is_array( $membership ) && ! empty( $membership['id'] ) ) {
			return array( 'membership' => $membership, 'role' => 'primary' );
		}

		global $wpdb;
		$people = People_Repository::table();
		$person = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$people} WHERE wp_user_id = %d AND status = 'active' ORDER BY id ASC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$user_id
			),
			ARRAY_A
		);
		if ( ! is_array( $person ) || empty( $person['membership_id'] ) ) {
			return null;
		}

		$membership = Memberships_Repository::get( (int) $person['membership_id'] );
		if ( ! is_array( $membership ) || empty( $membership['id'] ) ) {
			return null;
		}

		return array( 'membership' => $membership, 'role' => (string) ( $person['role'] ?? 'linked' ) );
	}

	/**
	 * Renewal date in the past (end of that day, site timezone)?
	 *
	 * @param array $membership Membership row.
	 * @return bool
	 */
	private static function membership_expired( array $membership ) {
		$renewal = (string) ( $membership['renewal_date'] ?? '' );
		if ( '' === $renewal || 0 === strpos( $renewal, '0000-00-00' ) ) {
			return false;
		}
		$end_of_day = \DateTime::createFromFormat( 'Y-m-d H:i:s', substr( $renewal, 0, 10 ) . ' 23:59:59', wp_timezone() );
		if ( ! $end_of_day ) {
			return false;
		}
		return $end_of_day->getTimestamp() < time();
	}

	/**
	 * Plan row from an id or a stable slug.
	 *
	 * @param int|string $plan Plan id or slug.
	 * @return array|null
	 */
	private static function resolve_plan( $plan ) {
		if ( is_numeric( $plan ) ) {
			$row = Plans_Repository::get( (int) $plan );
			return is_array( $row ) ? $row : null;
		}
		$row = Plans_Repository::get_by_slug( sanitize_key( (string) $plan ) );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Create or upgrade the membership row + primary person, then audit it.
	 *
	 * @param int   $user_id  WP user id.
	 * @param array $plan_row Plan row.
	 * @param array $args     billing_cycle, payment_source, reason, order_id, actor_id.
	 * @return int|\WP_Error Membership id.
	 */
	private static function write_membership( $user_id, array $plan_row, array $args ) {
		$user     = get_userdata( $user_id );
		$existing = Memberships_Repository::get_by_user_id( $user_id );
		$now      = current_time( 'mysql' );

		if ( is_array( $existing ) && ! empty( $existing['id'] ) ) {
			$membership_id = (int) $existing['id'];
			$updated       = Memberships_Repository::update( $membership_id, array(
				'plan_id'        => (int) $plan_row['id'],
				'status'         => 'active',
				'billing_cycle'  => $args['billing_cycle'],
				'payment_source' => $args['payment_source'],
				'start_date'     => ! empty( $existing['start_date'] ) ? $existing['start_date'] : $now,
			) );
			if ( ! $updated ) {
				return new \WP_Error( 'memberistic_membership_update_failed', __( 'Could not update the membership.', 'memberistic' ) );
			}
		} else {
			$membership_id = Memberships_Repository::create( array(
				'membership_uuid' => Memberships_Repository::generate_membership_uuid(),
				'primary_user_id' => $user_id,
				'plan_id'         => (int) $plan_row['id'],
				'billing_cycle'   => $args['billing_cycle'],
				'status'          => 'active',
				'start_date'      => $now,
				'payment_source'  => $args['payment_source'],
				'created_by'      => absint( $args['actor_id'] ),
			) );
			if ( ! $membership_id ) {
				return new \WP_Error( 'memberistic_membership_create_failed', __( 'Could not create the membership.', 'memberistic' ) );
			}

			People_Repository::create( array(
				'membership_id' => $membership_id,
				'wp_user_id'    => $user_id,
				'role'          => 'primary',
				'full_name'     => $user ? $user->display_name : '',
				'email'         => $user ? $user->user_email : '',
				'waiver_status' => 'missing',
				'status'        => 'active',
			) );
		}

		Activity_Repository::log( array(
			'membership_id'       => $membership_id,
			'user_id'             => $user_id,
			'activity_type'       => 'membership_activated',
			'title'               => __( 'Membership plan assigned', 'memberistic' ),
			'description'         => $args['reason'],
			'related_object_type' => $args['order_id'] ? 'wc_order' : 'admin_action',
			'related_object_id'   => (string) ( $args['order_id'] ?: $args['actor_id'] ),
			'created_by'          => absint( $args['actor_id'] ) ?: null,
		) );

		self::audit( 'membership_assigned', array(
			'user_id'        => $user_id,
			'membership_id'  => $membership_id,
			'plan_id'        => (int) $plan_row['id'],
			'plan_slug'      => (string) $plan_row['slug'],
			'payment_source' => $args['payment_source'],
			'order_id'       => $args['order_id'],
			'reason'         => $args['reason'],
			'actor_id'       => absint( $args['actor_id'] ),
		) );

		return $membership_id;
	}

	/**
	 * Append to the Memberistic audit log.
	 *
	 * @param string $event   Event slug.
	 * @param array  $context Context payload.
	 */
	private static function audit( $event, array $context ) {
		global $wpdb;
		$table = $wpdb->prefix . 'memberistic_logs';
		$wpdb->insert(
			$table,
			array(
				'level'      => 'info',
				'source'     => 'membership_service',
				'message'    => sanitize_key( $event ),
				'context'    => wp_json_encode( $context ),
				'created_at' => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);
	}
}
