<?php
/**
 * Point-of-sale bridge.
 *
 * Plugs Memberistic into an on-premise POS's membership resolution chain,
 * so the counter sees the same membership state as the member portal:
 *
 *  - `<prefix>_membership_lookup`   → live member status (plan, expiry,
 *    discounts) for the customer the cashier has on screen. The POS treats
 *    a non-null array from this filter as authoritative, so Memberistic
 *    becomes the membership provider of record at the counter.
 *  - `<prefix>_membership_bookings` → the customer's upcoming bookings
 *    (when a booking engine is mapped) for the POS dashboard / check-in.
 *  - Membership lifecycle hooks     → keep `pos_customer_id` on the
 *    membership row stamped with the WP user id the POS CRM keys on.
 *  - `<prefix>_membership_sold`     → mirror a counter-sold membership into
 *    Memberistic (member portal, waiver tracking, QR verification,
 *    corporate-group tooling) instead of it being invisible to this plugin.
 *
 * `<prefix>` comes from {@see POS_Bridge::adapter()}, which resolves the
 * connected POS plugin's hook namespace. Like the booking integration,
 * the third-party identifiers live in exactly one preset rather than being
 * spread through the class, and nothing is registered until a POS is both
 * detected and explicitly enabled.
 *
 * Gated by the "POS Bridge" toggle on Memberistic → Integrations.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic\Integrations;

use WordPressistic\Memberistic\Database\Memberships_Repository;
use WordPressistic\Memberistic\Database\People_Repository;
use WordPressistic\Memberistic\Database\Plans_Repository;
use WordPressistic\Memberistic\Database\Activity_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class POS_Bridge {

	/**
	 * Bundled POS presets, keyed by id.
	 *
	 * `hook_prefix` is the connected POS plugin's own hook namespace, and
	 * `detect` is how we tell it is active. Add another entry, or use the
	 * `memberistic_pos_adapter` filter, to connect a different POS.
	 *
	 * @var array<string,array>
	 */
	private const PRESETS = array(
		// Compatibility preset for the POS Memberistic 1.x was originally
		// built against. Retained so existing installs keep working.
		'legacy_pos_core' => array(
			'label'       => 'POS Core (legacy)',
			'hook_prefix' => 'g2a_pos',
			'detect'      => array(
				'constants' => array( 'G2A_POS_CORE_VERSION' ),
				'classes'   => array( '\\G2A\\POS\\Core\\Plugin' ),
			),
		),
	);

	/**
	 * Cached resolved adapter for this request.
	 *
	 * @var array|null|false False when not yet resolved, null when none.
	 */
	private static $adapter = false;

	/**
	 * Resolve the connected POS adapter.
	 *
	 * @return array|null Adapter config, or null when no POS is connected.
	 */
	public static function adapter() {
		if ( false !== self::$adapter ) {
			return self::$adapter;
		}

		$adapter = null;

		foreach ( self::PRESETS as $preset ) {
			foreach ( (array) $preset['detect']['constants'] as $constant ) {
				if ( defined( $constant ) ) {
					$adapter = $preset;
					break 2;
				}
			}
			foreach ( (array) $preset['detect']['classes'] as $class ) {
				if ( class_exists( $class ) ) {
					$adapter = $preset;
					break 2;
				}
			}
		}

		/**
		 * Filters the point-of-sale adapter configuration.
		 *
		 * Return an array with `hook_prefix` and `label` to connect a POS
		 * plugin, or null to disable the bridge.
		 *
		 * @since 2.0.0
		 *
		 * @param array|null $adapter Auto-detected adapter, or null if none matched.
		 */
		$adapter = apply_filters( 'memberistic_pos_adapter', $adapter );

		if ( is_array( $adapter ) ) {
			$prefix = preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) ( $adapter['hook_prefix'] ?? '' ) ) );

			$adapter = '' === $prefix
				? null
				: array(
					'label'       => (string) ( $adapter['label'] ?? __( 'Point of sale', 'memberistic' ) ),
					'hook_prefix' => $prefix,
				);
		} else {
			$adapter = null;
		}

		self::$adapter = $adapter;

		return self::$adapter;
	}

	/**
	 * Is a POS plugin connected?
	 *
	 * @return bool
	 */
	public static function is_available() {
		return null !== self::adapter();
	}

	/**
	 * Build a fully-qualified hook name for the connected POS.
	 *
	 * @param string $suffix Hook suffix, e.g. 'membership_lookup'.
	 * @return string Prefixed hook name, or '' when no POS is connected.
	 */
	public static function hook( $suffix ) {
		$adapter = self::adapter();
		return null === $adapter ? '' : $adapter['hook_prefix'] . '_' . ltrim( (string) $suffix, '_' );
	}

	/**
	 * Reset the per-request cache. Test seam.
	 *
	 * @return void
	 */
	public static function reset() {
		self::$adapter = false;
	}

	public static function register() {
		if ( ! Integrations_Registry::is_enabled( 'pos_bridge' ) ) {
			return;
		}

		if ( ! self::is_available() ) {
			return;
		}

		add_filter( self::hook( 'membership_lookup' ), array( self::class, 'membership_lookup' ), 10, 2 );
		add_filter( self::hook( 'membership_bookings' ), array( self::class, 'membership_bookings' ), 10, 3 );
		add_action( self::hook( 'membership_sold' ), array( self::class, 'membership_sold' ), 10, 2 );
		add_action( 'memberistic_membership_created', array( self::class, 'stamp_pos_customer' ), 10, 1 );
		add_action( 'memberistic_membership_activated', array( self::class, 'stamp_pos_customer' ), 10, 1 );
	}

	/**
	 * Resolve a POS customer (WP user id) to their Memberistic membership.
	 *
	 * @param array|null $result      Earlier filter result.
	 * @param int        $customer_id WP user id from the POS CRM.
	 * @return array|null Shape consumed by RangeMembership::normalize().
	 */
	public static function membership_lookup( $result, $customer_id ) {
		if ( null !== $result ) {
			return $result; // Another provider already answered.
		}

		$membership = self::find_membership_for_user( (int) $customer_id );
		if ( ! $membership ) {
			return null;
		}

		$plan      = self::plan_row( (int) $membership['plan_id'] );
		$is_active = in_array( (string) $membership['status'], array( 'active', 'past_due' ), true );
		$benefits  = array();
		if ( $plan && ! empty( $plan['benefits'] ) ) {
			$decoded  = json_decode( (string) $plan['benefits'], true );
			$benefits = is_array( $decoded ) ? $decoded : array();
		}

		return array(
			'active'     => $is_active,
			'plan'       => $plan ? (string) $plan['name'] : '',
			'plan_slug'  => $plan ? (string) $plan['slug'] : '',
			'expires_at' => $membership['end_date'] ?: $membership['renewal_date'],
			'member_id'  => (int) $membership['id'],
			'discounts'  => $benefits,
			'source'     => 'memberistic',
		);
	}

	/**
	 * Upcoming bookings for the POS dashboard, sourced from the booking engine.
	 *
	 * @param array|null $result      Earlier filter result.
	 * @param int        $customer_id WP user id.
	 * @param int        $days_ahead  Lookahead window.
	 * @return array|null
	 */
	public static function membership_bookings( $result, $customer_id, $days_ahead = 30 ) {
		if ( null !== $result ) {
			return $result;
		}
		$bookings_table  = Booking_Adapter::table( 'bookings' );
		$resources_table = Booking_Adapter::table( 'resources' );

		if ( '' === $bookings_table || '' === $resources_table ) {
			return null; // No booking engine mapped — let the POS fall back.
		}

		global $wpdb;
		$customer_id = (int) $customer_id;
		$days_ahead  = max( 1, min( 365, (int) $days_ahead ) );
		$user        = get_user_by( 'id', $customer_id );
		$email       = $user ? (string) $user->user_email : '';

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT b.uuid, b.start_at, b.end_at, b.status, b.party_size, r.name AS resource_name
			 FROM {$bookings_table} b
			 LEFT JOIN {$resources_table} r ON r.id = b.resource_id
			 WHERE ( b.user_id = %d OR ( %s <> '' AND b.customer_email = %s ) )
			   AND b.status NOT IN ( 'cancelled', 'no_show', 'expired' )
			   AND b.start_at BETWEEN %s AND %s
			 ORDER BY b.start_at ASC
			 LIMIT 25",
			$customer_id,
			$email,
			$email,
			current_time( 'mysql' ),
			wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) + $days_ahead * DAY_IN_SECONDS )
		), ARRAY_A );

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'id'       => (string) $row['uuid'],
				'title'    => (string) ( $row['resource_name'] ?: __( 'Booking', 'memberistic' ) ),
				'start'    => (string) $row['start_at'],
				'end'      => (string) $row['end_at'],
				'status'   => (string) $row['status'],
				'party'    => (int) $row['party_size'],
				'source'   => 'booking-engine',
			);
		}
		return $out;
	}

	/**
	 * Mirror the POS customer key (WP user id) onto the membership row so
	 * staff tools can join both systems without per-request lookups.
	 *
	 * @param int $membership_id Membership row id.
	 */
	public static function stamp_pos_customer( $membership_id ) {
		global $wpdb;
		$membership_id = (int) $membership_id;
		if ( $membership_id <= 0 ) {
			return;
		}
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT primary_user_id, pos_customer_id FROM {$wpdb->prefix}memberistic_memberships WHERE id = %d",
			$membership_id
		), ARRAY_A );
		if ( ! $row || empty( $row['primary_user_id'] ) || ! empty( $row['pos_customer_id'] ) ) {
			return;
		}
		$wpdb->update(
			$wpdb->prefix . 'memberistic_memberships',
			array( 'pos_customer_id' => (string) (int) $row['primary_user_id'] ),
			array( 'id' => $membership_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Mirror a counter-sold POS membership into Memberistic so it shows up
	 * in the member portal, waiver tracking, QR verification, corporate
	 * groups, and billing — previously nothing listened for a POS sale at
	 * all, so these memberships were permanently invisible outside the POS.
	 *
	 * @param int   $pos_membership_id Membership row id in the POS's own table.
	 * @param array $body              Raw payload POS passed to its own repository's create().
	 */
	public static function membership_sold( $pos_membership_id, $body ) {
		$body        = is_array( $body ) ? $body : array();
		$customer_id = isset( $body['customer_id'] ) ? absint( $body['customer_id'] ) : 0;
		if ( $customer_id <= 0 ) {
			return; // POS sale with no linked WP customer — nothing to mirror against.
		}

		// Don't create a second Memberistic membership if this person already
		// has one (e.g. they already subscribed on the website and also
		// bought a walk-in pass at the counter) — just make sure the POS key
		// is stamped on the existing row instead.
		$existing = self::find_membership_for_user( $customer_id );
		if ( $existing ) {
			// stamp_pos_customer() re-fetches pos_customer_id itself and is a
			// no-op if it's already stamped, so it's safe to call unconditionally.
			self::stamp_pos_customer( (int) $existing['id'] );
			return;
		}

		$user = get_user_by( 'id', $customer_id );
		if ( ! $user ) {
			return;
		}

		$tier_code  = isset( $body['tier_code'] ) ? sanitize_key( (string) $body['tier_code'] ) : '';
		$tier_label = isset( $body['tier_label'] ) ? sanitize_text_field( (string) $body['tier_label'] ) : '';

		$plan    = $tier_code ? Plans_Repository::get_by_slug( $tier_code ) : null;
		$unmapped_note = '';
		if ( ! $plan && '' !== $tier_label ) {
			foreach ( (array) Plans_Repository::get_all() as $candidate ) {
				if ( 0 === strcasecmp( (string) $candidate['name'], $tier_label ) ) {
					$plan = $candidate;
					break;
				}
			}
		}
		if ( ! $plan ) {
			// Don't silently drop the sale — create the record so it isn't
			// lost/invisible, but flag it clearly for staff to fix the plan
			// mapping by hand (e.g. via Memberistic → Plans → rename/alias).
			$unmapped_note = sprintf(
				/* translators: 1: POS tier code, 2: POS tier label */
				__( 'Needs plan mapping — imported from POS tier "%1$s" (%2$s). Set the correct plan manually.', 'memberistic' ),
				$tier_label ?: $tier_code,
				$tier_code ?: 'n/a'
			);
		}

		$billing_cycle = isset( $body['billing_cycle'] ) ? sanitize_key( (string) $body['billing_cycle'] ) : 'monthly';
		$start_date    = current_time( 'mysql' );

		$membership_id = Memberships_Repository::create(
			array(
				'primary_user_id' => $customer_id,
				'plan_id'         => $plan ? (int) $plan['id'] : 0,
				'billing_cycle'   => $billing_cycle,
				'status'          => 'active',
				'start_date'      => $start_date,
				'payment_source'  => 'pos',
				'pos_customer_id' => (string) $customer_id,
				'billing_amount'  => isset( $body['price_amount'] ) ? (float) $body['price_amount'] : null,
				'notes'           => $unmapped_note,
			)
		);

		if ( ! $membership_id ) {
			return;
		}

		$person_id = People_Repository::create(
			array(
				'membership_id' => $membership_id,
				'wp_user_id'    => $customer_id,
				'role'          => 'primary',
				'full_name'     => (string) $user->display_name,
				'email'         => (string) $user->user_email,
				'phone'         => (string) get_user_meta( $customer_id, 'billing_phone', true ),
				'status'        => 'active',
			)
		);

		Activity_Repository::log(
			array(
				'membership_id' => $membership_id,
				'person_id'     => $person_id ?: null,
				'activity_type' => 'membership_created',
				'title'         => __( 'Membership sold at POS counter', 'memberistic' ),
			)
		);
	}

	/**
	 * Best membership for a WP user: prefer the one they own, fall back to
	 * any membership they're linked to as a person. Active rows win.
	 *
	 * @return array|null
	 */
	private static function find_membership_for_user( $user_id ) {
		if ( $user_id <= 0 ) {
			return null;
		}
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT m.id, m.plan_id, m.status, m.renewal_date, m.end_date
			 FROM {$wpdb->prefix}memberistic_memberships m
			 LEFT JOIN {$wpdb->prefix}memberistic_people p ON p.membership_id = m.id
			 WHERE m.primary_user_id = %d OR p.wp_user_id = %d
			 ORDER BY ( m.status = 'active' ) DESC, m.id DESC
			 LIMIT 1",
			$user_id,
			$user_id
		), ARRAY_A );
	}

	/** @return array|null */
	private static function plan_row( $plan_id ) {
		if ( $plan_id <= 0 ) {
			return null;
		}
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT id, name, slug, benefits FROM {$wpdb->prefix}memberistic_plans WHERE id = %d",
			$plan_id
		), ARRAY_A );
	}
}
