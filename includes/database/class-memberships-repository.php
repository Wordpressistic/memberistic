<?php
/**
 * Memberships repository.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic\Database;

use function WordPressistic\Memberistic\memberistic_db_formats;
use function WordPressistic\Memberistic\memberistic_sanitize_textarea;
use function WordPressistic\Memberistic\memberistic_validate_billing_cycle;
use function WordPressistic\Memberistic\memberistic_validate_status;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Memberships_Repository {
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'memberistic_memberships';
	}

	public static function get_all( $args = array() ) {
		global $wpdb;

		$table      = self::table();
		$where      = array();
		$where_args = array();

		if ( ! empty( $args['status'] ) ) {
			$where[]      = 'm.status = %s';
			$where_args[] = sanitize_key( (string) $args['status'] );
		}

		if ( ! empty( $args['plan_id'] ) ) {
			$where[]      = 'm.plan_id = %d';
			$where_args[] = absint( $args['plan_id'] );
		}

		if ( ! empty( $args['billing_cycle'] ) ) {
			$where[]      = 'm.billing_cycle = %s';
			$where_args[] = sanitize_key( (string) $args['billing_cycle'] );
		}

		if ( ! empty( $args['waiver_status'] ) ) {
			$having_waiver = 'MIN(ap.waiver_status) = %s';
			$having_waiver_arg = sanitize_key( (string) $args['waiver_status'] );
		}

		if ( ! empty( $args['expiring_in_days'] ) ) {
			$days  = absint( $args['expiring_in_days'] );
			$now   = current_time( 'mysql' );
			$end   = gmdate( 'Y-m-d H:i:s', strtotime( '+' . $days . ' days', time() ) );

			$where[]      = 'm.renewal_date BETWEEN %s AND %s AND m.status = %s';
			$where_args[] = $now;
			$where_args[] = $end;
			$where_args[] = 'active';
		}

		if ( ! empty( $args['checked_in_today'] ) ) {
			$today = current_time( 'Y-m-d' );
			$where[] = 'm.id IN ( SELECT membership_id FROM ' . $wpdb->prefix . 'memberistic_checkins WHERE DATE(checked_in_at) = %s )';
			$where_args[] = $today;
		}

		if ( ! empty( $args['created_from'] ) ) {
			$where[]      = 'm.created_at >= %s';
			$where_args[] = sanitize_text_field( (string) $args['created_from'] );
		}

		if ( ! empty( $args['created_to'] ) ) {
			$where[]      = 'm.created_at <= %s';
			$where_args[] = sanitize_text_field( (string) $args['created_to'] );
		}

		if ( ! empty( $args['search'] ) ) {
			$like         = '%' . $wpdb->esc_like( sanitize_text_field( (string) $args['search'] ) ) . '%';
			$where[]      = '(m.membership_uuid LIKE %s OR p.full_name LIKE %s OR p.email LIKE %s OR p.phone LIKE %s OR ap.full_name LIKE %s OR m.stripe_customer_id LIKE %s OR m.woo_customer_id LIKE %s OR m.pos_customer_id LIKE %s)';
			$where_args[] = $like;
			$where_args[] = $like;
			$where_args[] = $like;
			$where_args[] = $like;
			$where_args[] = $like;
			$where_args[] = $like;
			$where_args[] = $like;
			$where_args[] = $like;
		}

		$where_sql = $where ? ' WHERE ' . implode( ' AND ', $where ) : '';
		$plans     = $wpdb->prefix . 'memberistic_plans';
		$people    = $wpdb->prefix . 'memberistic_people';
		$having_sql = '';

		if ( isset( $having_waiver, $having_waiver_arg ) ) {
			$having_sql   = ' HAVING ' . $having_waiver;
			$where_args[] = $having_waiver_arg;
		}

		$limit_clause  = isset( $args['limit'] ) ? max( 1, min( 500, (int) $args['limit'] ) ) : 100;
		$offset_clause = isset( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;

		$sql = "
			SELECT m.*, pl.name AS plan_name, p.full_name, p.email, p.phone,
				COUNT(ap.id) AS people_count,
				MIN(ap.waiver_status) AS waiver_status
			FROM {$table} m
			LEFT JOIN {$plans} pl ON pl.id = m.plan_id
			LEFT JOIN {$people} p ON p.membership_id = m.id AND p.role = 'primary'
			LEFT JOIN {$people} ap ON ap.membership_id = m.id AND ap.status = 'active'
			{$where_sql}
			GROUP BY m.id{$having_sql}
			ORDER BY m.created_at DESC
			LIMIT {$limit_clause} OFFSET {$offset_clause}
		";

		if ( $where_args ) {
			$sql = $wpdb->prepare( $sql, $where_args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		return $wpdb->get_results( $sql, ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Count matching memberships for pagination. Accepts the same filter
	 * args as get_all() (search, status, plan_id, billing_cycle, dates).
	 */
	public static function count_all( $args = array() ) {
		global $wpdb;

		$table      = self::table();
		$plans      = $wpdb->prefix . 'memberistic_plans';
		$people     = $wpdb->prefix . 'memberistic_people';
		$where      = array();
		$where_args = array();

		if ( ! empty( $args['status'] ) ) {
			$where[]      = 'm.status = %s';
			$where_args[] = sanitize_key( (string) $args['status'] );
		}
		if ( ! empty( $args['plan_id'] ) ) {
			$where[]      = 'm.plan_id = %d';
			$where_args[] = absint( $args['plan_id'] );
		}
		if ( ! empty( $args['billing_cycle'] ) ) {
			$where[]      = 'm.billing_cycle = %s';
			$where_args[] = sanitize_key( (string) $args['billing_cycle'] );
		}
		if ( ! empty( $args['expiring_in_days'] ) ) {
			$days = absint( $args['expiring_in_days'] );
			$now  = current_time( 'mysql' );
			$end  = gmdate( 'Y-m-d H:i:s', strtotime( '+' . $days . ' days', time() ) );

			$where[]      = 'm.renewal_date BETWEEN %s AND %s AND m.status = %s';
			$where_args[] = $now;
			$where_args[] = $end;
			$where_args[] = 'active';
		}
		if ( ! empty( $args['checked_in_today'] ) ) {
			$today        = current_time( 'Y-m-d' );
			$where[]      = 'm.id IN ( SELECT membership_id FROM ' . $wpdb->prefix . 'memberistic_checkins WHERE DATE(checked_in_at) = %s )';
			$where_args[] = $today;
		}
		if ( ! empty( $args['created_from'] ) ) {
			$where[]      = 'm.created_at >= %s';
			$where_args[] = sanitize_text_field( (string) $args['created_from'] );
		}
		if ( ! empty( $args['created_to'] ) ) {
			$where[]      = 'm.created_at <= %s';
			$where_args[] = sanitize_text_field( (string) $args['created_to'] );
		}
		if ( ! empty( $args['search'] ) ) {
			$like         = '%' . $wpdb->esc_like( sanitize_text_field( (string) $args['search'] ) ) . '%';
			$where[]      = '(m.membership_uuid LIKE %s OR p.full_name LIKE %s OR p.email LIKE %s OR p.phone LIKE %s OR ap.full_name LIKE %s OR m.stripe_customer_id LIKE %s OR m.woo_customer_id LIKE %s OR m.pos_customer_id LIKE %s)';
			for ( $i = 0; $i < 8; $i++ ) {
				$where_args[] = $like;
			}
		}

		$where_sql = $where ? ' WHERE ' . implode( ' AND ', $where ) : '';

		$sql = "
			SELECT COUNT(DISTINCT m.id)
			FROM {$table} m
			LEFT JOIN {$plans} pl ON pl.id = m.plan_id
			LEFT JOIN {$people} p ON p.membership_id = m.id AND p.role = 'primary'
			LEFT JOIN {$people} ap ON ap.membership_id = m.id AND ap.status = 'active'
			{$where_sql}
		";

		if ( $where_args ) {
			$sql = $wpdb->prepare( $sql, $where_args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Member counts grouped by status (used for the Members KPI cards).
	 *
	 * @return array<string, int>
	 */
	public static function counts_by_status() {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT status, COUNT(*) AS c FROM ' . self::table() . ' GROUP BY status', ARRAY_A ) ?: array();
		$out  = array(
			'total'        => 0,
			'active'       => 0,
			'pending'      => 0,
			'past_due'     => 0,
			'expired'      => 0,
			'cancelled'    => 0,
			'paused'       => 0,
			'comped'       => 0,
			'trial'        => 0,
			'suspended'    => 0,
			'needs_review' => 0,
		);
		foreach ( $rows as $row ) {
			$key = (string) $row['status'];
			if ( ! isset( $out[ $key ] ) ) {
				$out[ $key ] = 0;
			}
			$out[ $key ] += (int) $row['c'];
			$out['total'] += (int) $row['c'];
		}
		return $out;
	}

	/**
	 * Count memberships created in a given month (UTC-safe range).
	 *
	 * @param int $year  Four-digit year.
	 * @param int $month 1-12.
	 */
	public static function count_created_in_month( $year, $month ) {
		global $wpdb;
		$year  = max( 1970, (int) $year );
		$month = max( 1, min( 12, (int) $month ) );
		$start = sprintf( '%04d-%02d-01 00:00:00', $year, $month );
		$ts    = strtotime( $start );
		$end   = gmdate( 'Y-m-t 23:59:59', $ts );
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE created_at >= %s AND created_at <= %s', $start, $end ) );
	}

	/**
	 * Per-plan membership totals + breakdown by status. Used by the Plans
	 * page to render member counts inside each plan card.
	 *
	 * @return array<int, array{plan_id:int, total:int, active:int, statuses:array<string,int>}>
	 */
	public static function counts_per_plan() {
		global $wpdb;
		$rows = $wpdb->get_results(
			'SELECT plan_id, status, COUNT(*) AS c FROM ' . self::table() . ' GROUP BY plan_id, status',
			ARRAY_A
		) ?: array();

		$by_plan = array();
		foreach ( $rows as $row ) {
			$pid = (int) $row['plan_id'];
			if ( ! isset( $by_plan[ $pid ] ) ) {
				$by_plan[ $pid ] = array(
					'plan_id'  => $pid,
					'total'    => 0,
					'active'   => 0,
					'statuses' => array(),
				);
			}
			$status = (string) $row['status'];
			$count  = (int) $row['c'];
			$by_plan[ $pid ]['total']             += $count;
			$by_plan[ $pid ]['statuses'][ $status ] = $count;
			if ( 'active' === $status ) {
				$by_plan[ $pid ]['active'] += $count;
			}
		}
		return array_values( $by_plan );
	}

	public static function get_with_summary( $id ) {
		global $wpdb;

		$table  = self::table();
		$plans  = $wpdb->prefix . 'memberistic_plans';
		$people = $wpdb->prefix . 'memberistic_people';

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"
				SELECT m.*, pl.name AS plan_name, pl.included_people,
					p.full_name, p.email, p.phone, p.waiver_status,
					COUNT(ap.id) AS people_count
				FROM {$table} m
				LEFT JOIN {$plans} pl ON pl.id = m.plan_id
				LEFT JOIN {$people} p ON p.membership_id = m.id AND p.role = 'primary'
				LEFT JOIN {$people} ap ON ap.membership_id = m.id AND ap.status = 'active'
				WHERE m.id = %d
				GROUP BY m.id
				LIMIT 1
				",
				$id
			),
			ARRAY_A
		);

		return $row ?: null;
	}

	public static function get( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d LIMIT 1', $id ), ARRAY_A );
		return $row ?: null;
	}

	public static function get_by_uuid( $uuid ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE membership_uuid = %s LIMIT 1', sanitize_text_field( $uuid ) ), ARRAY_A );
		return $row ?: null;
	}

	/**
	 * Find a pending membership belonging to the primary person with the
	 * given email. Used by the Stripe checkout handler to detect a
	 * refresh / back-button / double-submit on the public checkout form and
	 * reuse the existing pending row instead of creating a duplicate.
	 *
	 * @param string $email Primary person email.
	 * @return array<string,mixed>|null
	 */
	public static function get_pending_by_person_email( $email ) {
		global $wpdb;
		$email = sanitize_email( (string) $email );
		if ( '' === $email ) {
			return null;
		}
		$people = $wpdb->prefix . 'memberistic_people';
		$m      = self::table();
		$row    = $wpdb->get_row( $wpdb->prepare(
			"SELECT m.* FROM {$m} m
			INNER JOIN {$people} p ON p.membership_id = m.id AND p.role = 'primary'
			WHERE m.status = %s AND p.email = %s
			ORDER BY m.created_at DESC
			LIMIT 1",
			'pending',
			$email
		), ARRAY_A );
		return $row ?: null;
	}

	public static function get_by_stripe_subscription_id( $subscription_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE stripe_subscription_id = %s LIMIT 1', sanitize_text_field( $subscription_id ) ), ARRAY_A );
		return $row ?: null;
	}

	public static function get_by_stripe_checkout_session_id( $session_id ) {
		global $wpdb;
		$session_id = sanitize_text_field( (string) $session_id );
		if ( '' === $session_id ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE stripe_checkout_session_id = %s LIMIT 1', $session_id ), ARRAY_A );
		return $row ?: null;
	}

	public static function get_by_user_id( $user_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE primary_user_id = %d ORDER BY created_at DESC LIMIT 1', absint( $user_id ) ), ARRAY_A );
		return $row ?: null;
	}

	/**
	 * Active memberships missing a renewal date.
	 *
	 * Used by the "set renewal dates from activation" maintenance tool so
	 * imported members (and any row created without a renewal) get a correct
	 * next-renewal anchored on their start date.
	 *
	 * @param int $limit Max rows.
	 * @return array<int, array<string,mixed>>
	 */
	public static function get_active_missing_renewal( $limit = 200 ) {
		global $wpdb;
		$limit = max( 1, min( 500, (int) $limit ) );
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, billing_cycle, start_date, created_at FROM ' . self::table() . " WHERE status IN ( 'active', 'trial' ) AND ( renewal_date IS NULL OR renewal_date = '' OR renewal_date = '0000-00-00 00:00:00' ) ORDER BY id ASC LIMIT %d",
				$limit
			),
			ARRAY_A
		) ?: array();
	}

	/**
	 * Count of active memberships missing a renewal date (for UI totals).
	 */
	public static function count_active_missing_renewal() {
		global $wpdb;
		return (int) $wpdb->get_var(
			'SELECT COUNT(*) FROM ' . self::table() . " WHERE status IN ( 'active', 'trial' ) AND ( renewal_date IS NULL OR renewal_date = '' OR renewal_date = '0000-00-00 00:00:00' )"
		);
	}

	/**
	 * Backfill renewal dates onto active/trial memberships missing one.
	 *
	 * Anchors each on its start_date (or created_at) using the plan's billing
	 * cycle. Idempotent and batched. Used by the daily scheduler and the admin
	 * maintenance tool so existing + imported members converge on a real
	 * next-renewal without manual editing.
	 *
	 * @param int $limit Max rows per run.
	 * @return int Rows updated.
	 */
	public static function backfill_missing_renewals( $limit = 200 ) {
		$rows    = self::get_active_missing_renewal( $limit );
		$updated = 0;
		foreach ( $rows as $row ) {
			$cycle = ! empty( $row['billing_cycle'] ) ? (string) $row['billing_cycle'] : 'monthly';
			$start = ! empty( $row['start_date'] ) ? (string) $row['start_date']
				: ( ! empty( $row['created_at'] ) ? (string) $row['created_at'] : current_time( 'mysql' ) );
			if ( self::update( (int) $row['id'], array( 'renewal_date' => self::compute_renewal_from_cycle( $cycle, $start ) ) ) ) {
				$updated++;
			}
		}
		return $updated;
	}

	/**
	 * Count of memberships with a Stripe subscription id but no customer id.
	 */
	public static function count_needing_customer_backfill() {
		global $wpdb;
		return (int) $wpdb->get_var(
			'SELECT COUNT(*) FROM ' . self::table() . " WHERE stripe_subscription_id <> '' AND stripe_subscription_id IS NOT NULL AND ( stripe_customer_id = '' OR stripe_customer_id IS NULL )"
		);
	}

	/**
	 * Memberships that have a Stripe subscription id but no Stripe customer id.
	 *
	 * Used by the customer-id backfill tool: imported (PMPro) members carry the
	 * subscription id but not the cus_… id the billing portal needs, so we look
	 * each up against Stripe and store it.
	 *
	 * @param int $limit Max rows to return.
	 * @return array<int, array<string,mixed>>
	 */
	public static function get_needing_customer_backfill( $limit = 50 ) {
		global $wpdb;
		$limit = max( 1, min( 200, (int) $limit ) );
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, stripe_subscription_id FROM ' . self::table() . " WHERE stripe_subscription_id <> '' AND stripe_subscription_id IS NOT NULL AND ( stripe_customer_id = '' OR stripe_customer_id IS NULL ) ORDER BY id ASC LIMIT %d",
				$limit
			),
			ARRAY_A
		) ?: array();
	}

	public static function get_by_person_email( $email ) {
		global $wpdb;
		$email = sanitize_email( $email );

		if ( ! is_email( $email ) ) {
			return null;
		}

		$people = $wpdb->prefix . 'memberistic_people';
		$row    = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT m.* FROM " . self::table() . " m INNER JOIN {$people} p ON p.membership_id = m.id WHERE p.email = %s ORDER BY m.created_at DESC LIMIT 1",
				$email
			),
			ARRAY_A
		);

		return $row ?: null;
	}

	public static function create( $data ) {
		global $wpdb;

		// Guest passes and manually-added members were being saved without a
		// next-renewal, so the dashboard read "—" while the waiver/detail view
		// derived a date on the fly — an inconsistency. Anchor a real
		// renewal_date from the plan's billing cycle whenever an active/trial
		// membership is created without one, so every surface agrees.
		$data               = self::ensure_renewal_date( $data );
		$data               = self::sanitize_data( $data );
		$data['created_at'] = current_time( 'mysql' );

		$inserted = $wpdb->insert( self::table(), $data, memberistic_db_formats( $data ) );

		if ( false === $inserted ) {
			return false;
		}

		$membership_id = (int) $wpdb->insert_id;
		do_action( 'memberistic_membership_created', $membership_id );

		return $membership_id;
	}

	public static function update( $id, $data ) {
		global $wpdb;

		$data = self::sanitize_data( $data, false );

		if ( empty( $data ) ) {
			return true;
		}

		$data['updated_at'] = current_time( 'mysql' );

		return false !== $wpdb->update( self::table(), $data, array( 'id' => $id ), memberistic_db_formats( $data ), array( '%d' ) );
	}

	public static function delete( $id ) {
		global $wpdb;
		return false !== $wpdb->delete( self::table(), array( 'id' => $id ), array( '%d' ) );
	}

	public static function change_status( $id, $status ) {
		$status = memberistic_validate_status( $status, 'pending' );
		$update = array( 'status' => $status );

		// When a guest pass / pending member is switched to active (or trial),
		// give it a renewal date anchored on its start date + plan cycle if it
		// doesn't already have one. Matches the "active member needs a renewal
		// date with their plan" requirement.
		if ( in_array( $status, array( 'active', 'trial' ), true ) ) {
			$row = self::get( $id );
			if ( is_array( $row ) && self::renewal_is_empty( $row['renewal_date'] ?? '' ) ) {
				$cycle = ! empty( $row['billing_cycle'] ) ? (string) $row['billing_cycle'] : 'monthly';
				$start = ! empty( $row['start_date'] ) ? (string) $row['start_date']
					: ( ! empty( $row['created_at'] ) ? (string) $row['created_at'] : current_time( 'mysql' ) );
				$update['renewal_date'] = self::compute_renewal_from_cycle( $cycle, $start );
			}
		}

		$ok = self::update( $id, $update );

		if ( $ok ) {
			/**
			 * Fires after a membership's status is changed through the
			 * canonical path. Integrations (coreSTORE bridge, role sync,
			 * marketing) key off this instead of polling.
			 *
			 * @param int    $id     Membership id.
			 * @param string $status New status.
			 */
			do_action( 'memberistic_membership_status_changed', (int) $id, $status );
		}

		return $ok;
	}

	/**
	 * True when a renewal_date value is effectively unset.
	 *
	 * @param mixed $value Raw renewal_date.
	 * @return bool
	 */
	private static function renewal_is_empty( $value ) {
		$value = trim( (string) $value );
		return '' === $value || '0000-00-00 00:00:00' === $value || '0000-00-00' === $value;
	}

	/**
	 * Ensure an active/trial membership row carries a renewal date.
	 *
	 * No-op for any other status (pending/cancelled/expired) and for rows that
	 * already have a renewal. Reads status + billing_cycle + start_date from
	 * the supplied row and fills renewal_date from the plan's billing cycle.
	 *
	 * @param array $data Membership row data.
	 * @return array
	 */
	private static function ensure_renewal_date( $data ) {
		$status = isset( $data['status'] ) ? strtolower( trim( (string) $data['status'] ) ) : '';
		if ( ! in_array( $status, array( 'active', 'trial' ), true ) ) {
			return $data;
		}
		if ( ! self::renewal_is_empty( $data['renewal_date'] ?? '' ) ) {
			return $data;
		}
		$cycle = isset( $data['billing_cycle'] ) && '' !== trim( (string) $data['billing_cycle'] )
			? (string) $data['billing_cycle']
			: 'monthly';
		$start = ! empty( $data['start_date'] ) ? (string) $data['start_date'] : current_time( 'mysql' );
		$data['renewal_date'] = self::compute_renewal_from_cycle( $cycle, $start );
		return $data;
	}

	/**
	 * Next renewal from a billing cycle, anchored on a start datetime.
	 *
	 * Delegates to the shared WooCommerce_Bridge helper so the Stripe,
	 * WooCommerce, import and manual paths all agree; falls back to a local
	 * +1 month / +1 year calculation if that class isn't loaded.
	 *
	 * @param string $cycle       'monthly' | 'annual' (anything else => monthly).
	 * @param string $start_mysql Site-local mysql datetime.
	 * @return string Site-local mysql datetime.
	 */
	private static function compute_renewal_from_cycle( $cycle, $start_mysql ) {
		$bridge = '\WordPressistic\Memberistic\Integrations\WooCommerce_Bridge';
		if ( class_exists( $bridge ) && is_callable( array( $bridge, 'compute_next_renewal' ) ) ) {
			return $bridge::compute_next_renewal( strtolower( (string) $cycle ), $start_mysql );
		}
		$interval = ( in_array( strtolower( (string) $cycle ), array( 'annual', 'yearly' ), true ) ) ? '+1 year' : '+1 month';
		$base     = strtotime( (string) $start_mysql );
		return wp_date( 'Y-m-d H:i:s', strtotime( $interval, $base ?: time() ) );
	}

	public static function count_expiring_soon( $days = 30 ) {
		global $wpdb;
		$now = current_time( 'mysql' );
		$end = gmdate( 'Y-m-d H:i:s', strtotime( '+' . absint( $days ) . ' days', time() ) );
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE renewal_date BETWEEN %s AND %s AND status = %s', $now, $end, 'active' ) );
	}

	public static function get_expiring_soon( $days = 30, $limit = 20 ) {
		global $wpdb;
		$people = $wpdb->prefix . 'memberistic_people';
		$plans  = $wpdb->prefix . 'memberistic_plans';
		$now    = current_time( 'mysql' );
		$end    = gmdate( 'Y-m-d H:i:s', strtotime( '+' . absint( $days ) . ' days', time() ) );
		$limit  = max( 1, min( 100, absint( $limit ) ) );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.*, p.full_name, p.email, pl.name AS plan_name FROM " . self::table() . " m LEFT JOIN {$people} p ON p.membership_id = m.id AND p.role = 'primary' LEFT JOIN {$plans} pl ON pl.id = m.plan_id WHERE m.renewal_date BETWEEN %s AND %s AND m.status = %s ORDER BY m.renewal_date ASC LIMIT %d",
				$now,
				$end,
				'active',
				$limit
			),
			ARRAY_A
		) ?: array();
	}

	/**
	 * Memberships whose renewal_date falls on the day exactly $days from
	 * today, in the active status — feeds the daily reminder cron.
	 *
	 * @param int $days Window in days.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_renewing_in_days( $days ) {
		global $wpdb;

		// Audit B18 / A7: the original implementation matched
		// renewal_date against a SINGLE-DAY window
		// (today+$days 00:00:00 .. today+$days 23:59:59), so if
		// the daily cron skipped a day (host downtime, no traffic
		// at run-cron time, container restart) the 30/7/1-day
		// reminders for that calendar day were silently lost
		// forever.
		//
		// Fix: query the entire window UP TO today+$days. The
		// caller (Scheduler::run_renewal_reminders) is responsible
		// for deduping against the email_logs table so members
		// don't get re-spammed if cron catches up.
		$days = max( 0, (int) $days );
		$end  = wp_date( 'Y-m-d 23:59:59', current_time( 'timestamp' ) + ( $days * DAY_IN_SECONDS ) );

		// Only look at rows whose renewal_date is in the future
		// (anything past has already expired and is handled by
		// run_auto_expire). For the 30-day window we additionally
		// require >= today so the same membership doesn't appear
		// in both 30 and 7 day buckets.
		$start = wp_date( 'Y-m-d 00:00:00', current_time( 'timestamp' ) );

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, billing_cycle, renewal_date FROM ' . self::table() . ' WHERE status = %s AND renewal_date BETWEEN %s AND %s ORDER BY renewal_date ASC',
				'active',
				$start,
				$end
			),
			ARRAY_A
		) ?: array();
	}

	/**
	 * Active memberships whose renewal_date has already passed — used by
	 * the daily auto-expire cron.
	 *
	 * @param int $limit Cap per run so a single cron pass cannot stall.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_active_expired( $limit = 200 ) {
		global $wpdb;

		$limit = max( 1, min( 1000, (int) $limit ) );
		$now   = current_time( 'mysql' );

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, renewal_date, payment_source, stripe_subscription_id FROM ' . self::table() . ' WHERE status = %s AND renewal_date IS NOT NULL AND renewal_date < %s ORDER BY renewal_date ASC LIMIT %d',
				'active',
				$now,
				$limit
			),
			ARRAY_A
		) ?: array();
	}

	public static function count_waiver_missing() {
		global $wpdb;
		$people = $wpdb->prefix . 'memberistic_people';
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$people} WHERE status = 'active' AND waiver_status IN ('missing','expired','needs_review')" );
	}

	public static function generate_membership_uuid() {
		return 'MBR-' . strtoupper( wp_generate_password( 24, false, false ) );
	}

	public static function count_by_status( $status ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE status = %s', sanitize_key( $status ) ) );
	}

	public static function count_new_this_month() {
		global $wpdb;
		// Site-local month cutoff (was UTC gmdate, which mis-rolled in the
		// evening on non-UTC sites and dropped late-day signups out of
		// "this month").
		$start = wp_date( 'Y-m-01 00:00:00', current_time( 'timestamp' ) );
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE created_at >= %s', $start ) );
	}

	private static function sanitize_data( $data, $creating = true ) {
		$clean = array();

		if ( $creating || isset( $data['membership_uuid'] ) ) {
			$clean['membership_uuid'] = empty( $data['membership_uuid'] ) ? self::generate_membership_uuid() : sanitize_text_field( (string) $data['membership_uuid'] );
		}
		if ( $creating || isset( $data['primary_user_id'] ) ) {
			$clean['primary_user_id'] = ! empty( $data['primary_user_id'] ) ? absint( $data['primary_user_id'] ) : null;
		}
		if ( $creating || isset( $data['plan_id'] ) ) {
			$clean['plan_id'] = absint( $data['plan_id'] ?? 0 );
		}
		if ( $creating || isset( $data['billing_cycle'] ) ) {
			$clean['billing_cycle'] = memberistic_validate_billing_cycle( $data['billing_cycle'] ?? 'monthly' );
		}
		if ( $creating || isset( $data['status'] ) ) {
			$clean['status'] = memberistic_validate_status( $data['status'] ?? 'pending', 'pending' );
		}

		$text_fields = array(
			'payment_source',
			'stripe_customer_id',
			'stripe_subscription_id',
			'stripe_checkout_session_id',
			'pos_customer_id',
		);

		foreach ( $text_fields as $field ) {
			if ( isset( $data[ $field ] ) ) {
				$clean[ $field ] = sanitize_text_field( (string) $data[ $field ] );
			}
		}

		$date_fields = array( 'start_date', 'renewal_date', 'end_date', 'cancelled_at', 'stripe_checkout_expires_at' );
		foreach ( $date_fields as $field ) {
			if ( isset( $data[ $field ] ) ) {
				$clean[ $field ] = self::sanitize_datetime( $data[ $field ] );
			}
		}

		if ( isset( $data['billing_amount'] ) ) {
			$clean['billing_amount'] = '' === $data['billing_amount'] || null === $data['billing_amount']
				? null
				: round( (float) $data['billing_amount'], 2 );
		}

		$int_fields = array( 'woo_customer_id', 'woo_subscription_id', 'created_by' );
		foreach ( $int_fields as $field ) {
			if ( isset( $data[ $field ] ) ) {
				$clean[ $field ] = ! empty( $data[ $field ] ) ? absint( $data[ $field ] ) : null;
			}
		}

		if ( isset( $data['notes'] ) ) {
			$clean['notes'] = memberistic_sanitize_textarea( $data['notes'] );
		}

		return $clean;
	}

	public static function sanitize_datetime( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return null;
		}

		$timestamp = strtotime( $value );

		if ( ! $timestamp ) {
			return null;
		}

		return gmdate( 'Y-m-d H:i:s', $timestamp );
	}
}
