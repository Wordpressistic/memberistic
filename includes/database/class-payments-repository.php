<?php
/**
 * Payments repository.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic\Database;

use function WordPressistic\Memberistic\memberistic_db_formats;
use function WordPressistic\Memberistic\memberistic_sanitize_price;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Payments_Repository {
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'memberistic_payments';
	}

	public static function get_by_membership( $membership_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE membership_id = %d ORDER BY created_at DESC', $membership_id ), ARRAY_A ) ?: array();
	}

	/**
	 * Lookup a payment row by its gateway transaction id (e.g. Stripe
	 * payment_intent / invoice id). Used by the Stripe service to
	 * suppress duplicate Payments_Repository::create() inserts when the
	 * same charge is reported twice (e.g. checkout.session.completed +
	 * invoice.payment_succeeded both fire for the first invoice).
	 *
	 * @param string $txn_id Gateway transaction id.
	 * @return array<string,mixed>|null
	 */
	public static function get_by_gateway_transaction_id( $txn_id ) {
		global $wpdb;
		$txn_id = trim( (string) $txn_id );
		if ( '' === $txn_id ) {
			return null;
		}
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE gateway_transaction_id = %s LIMIT 1', $txn_id ),
			ARRAY_A
		);
		return $row ?: null;
	}

	public static function get_all( $args = array() ) {
		global $wpdb;

		$table       = self::table();
		$memberships = $wpdb->prefix . 'memberistic_memberships';
		$people      = $wpdb->prefix . 'memberistic_people';
		$where       = array();
		$where_args  = array();

		if ( ! empty( $args['status'] ) ) {
			$where[]      = 'p.status = %s';
			$where_args[] = sanitize_key( (string) $args['status'] );
		}

		if ( ! empty( $args['membership_id'] ) ) {
			$where[]      = 'p.membership_id = %d';
			$where_args[] = absint( $args['membership_id'] );
		}

		if ( ! empty( $args['payment_gateway'] ) ) {
			$where[]      = 'p.payment_gateway = %s';
			$where_args[] = sanitize_key( (string) $args['payment_gateway'] );
		}

		if ( ! empty( $args['from'] ) ) {
			$where[]      = 'p.paid_at >= %s';
			$where_args[] = sanitize_text_field( (string) $args['from'] );
		}

		if ( ! empty( $args['to'] ) ) {
			$where[]      = 'p.paid_at <= %s';
			$where_args[] = sanitize_text_field( (string) $args['to'] );
		}

		if ( ! empty( $args['search'] ) ) {
			$like         = '%' . $wpdb->esc_like( sanitize_text_field( (string) $args['search'] ) ) . '%';
			$where[]      = '(m.membership_uuid LIKE %s OR pe.full_name LIKE %s OR p.gateway_transaction_id LIKE %s)';
			$where_args[] = $like;
			$where_args[] = $like;
			$where_args[] = $like;
		}

		$where_sql = $where ? ' WHERE ' . implode( ' AND ', $where ) : '';
		$limit     = isset( $args['limit'] ) ? max( 1, min( 500, (int) $args['limit'] ) ) : 100;
		$offset    = isset( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;

		$sql = "
			SELECT p.*, m.membership_uuid, pe.full_name
			FROM {$table} p
			LEFT JOIN {$memberships} m ON m.id = p.membership_id
			LEFT JOIN {$people} pe ON pe.membership_id = m.id AND pe.role = 'primary'
			{$where_sql}
			ORDER BY p.created_at DESC
			LIMIT {$limit} OFFSET {$offset}";

		if ( $where_args ) {
			$sql = $wpdb->prepare( $sql, $where_args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		return $wpdb->get_results( $sql, ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Total matching payments — for pagination counts. Accepts the same
	 * filter args as get_all().
	 */
	public static function count_all( $args = array() ) {
		global $wpdb;

		$table       = self::table();
		$memberships = $wpdb->prefix . 'memberistic_memberships';
		$people      = $wpdb->prefix . 'memberistic_people';
		$where       = array();
		$where_args  = array();

		if ( ! empty( $args['status'] ) ) {
			$where[]      = 'p.status = %s';
			$where_args[] = sanitize_key( (string) $args['status'] );
		}
		if ( ! empty( $args['membership_id'] ) ) {
			$where[]      = 'p.membership_id = %d';
			$where_args[] = absint( $args['membership_id'] );
		}
		if ( ! empty( $args['payment_gateway'] ) ) {
			$where[]      = 'p.payment_gateway = %s';
			$where_args[] = sanitize_key( (string) $args['payment_gateway'] );
		}
		if ( ! empty( $args['from'] ) ) {
			$where[]      = 'p.paid_at >= %s';
			$where_args[] = sanitize_text_field( (string) $args['from'] );
		}
		if ( ! empty( $args['to'] ) ) {
			$where[]      = 'p.paid_at <= %s';
			$where_args[] = sanitize_text_field( (string) $args['to'] );
		}
		if ( ! empty( $args['search'] ) ) {
			$like         = '%' . $wpdb->esc_like( sanitize_text_field( (string) $args['search'] ) ) . '%';
			$where[]      = '(m.membership_uuid LIKE %s OR pe.full_name LIKE %s OR p.gateway_transaction_id LIKE %s)';
			$where_args[] = $like;
			$where_args[] = $like;
			$where_args[] = $like;
		}

		$where_sql = $where ? ' WHERE ' . implode( ' AND ', $where ) : '';
		$sql       = "
			SELECT COUNT(*) FROM {$table} p
			LEFT JOIN {$memberships} m ON m.id = p.membership_id
			LEFT JOIN {$people} pe ON pe.membership_id = m.id AND pe.role = 'primary'
			{$where_sql}
		";

		if ( $where_args ) {
			$sql = $wpdb->prepare( $sql, $where_args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * KPI summary for the Payments page:
	 *  - revenue this month + last month (for growth %)
	 *  - new-member payment count (first completed payment for that membership)
	 *  - renewal payment count (second-or-later completed payment)
	 *  - lifetime totals
	 *
	 * @return array<string, mixed>
	 */
	public static function stats_summary() {
		global $wpdb;
		$table   = self::table();
		// Site-local (wp_date) anchored on current_time('timestamp') so the
		// "this month" / "last month" KPIs match the calendar the staff see in
		// the dashboard. Was UTC-via-gmdate, which silently rolled to the
		// "next" month around 5pm at Mesa AZ (UTC-7) and other non-UTC sites.
		$now_ts  = current_time( 'timestamp' );
		$start_m = wp_date( 'Y-m-01 00:00:00', $now_ts );
		$end_m   = wp_date( 'Y-m-t 23:59:59', $now_ts );
		$prev_ts = strtotime( '-1 month', (int) strtotime( $start_m ) );
		$start_p = wp_date( 'Y-m-01 00:00:00', $prev_ts );
		$end_p   = wp_date( 'Y-m-t 23:59:59', $prev_ts );

		$rev_this = (float) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(amount), 0) FROM {$table} WHERE status = %s AND paid_at BETWEEN %s AND %s",
			'completed',
			$start_m,
			$end_m
		) );
		$rev_prev = (float) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(amount), 0) FROM {$table} WHERE status = %s AND paid_at BETWEEN %s AND %s",
			'completed',
			$start_p,
			$end_p
		) );
		$rev_total = (float) $wpdb->get_var(
			$wpdb->prepare( "SELECT COALESCE(SUM(amount), 0) FROM {$table} WHERE status = %s", 'completed' )
		);

		// Per-membership ordinal — payment #1 is "new member", #2+ is "renewal".
		$new_member = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM (
				SELECT MIN(id) AS first_id FROM {$table} WHERE status = 'completed' GROUP BY membership_id
			) firsts"
		);
		$completed_total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'completed'" );
		$renewal         = max( 0, $completed_total - $new_member );

		$failed   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'failed'" );
		$refunded = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'refunded'" );

		$growth = null;
		if ( $rev_prev > 0 ) {
			$growth = round( ( ( $rev_this - $rev_prev ) / $rev_prev ) * 100, 1 );
		} elseif ( $rev_this > 0 ) {
			$growth = 100.0;
		}

		return array(
			'revenue_total'        => round( $rev_total, 2 ),
			'revenue_this_month'   => round( $rev_this, 2 ),
			'revenue_prev_month'   => round( $rev_prev, 2 ),
			'revenue_growth_pct'   => $growth,
			'new_member_payments'  => $new_member,
			'renewal_payments'     => $renewal,
			'completed_payments'   => $completed_total,
			'failed_payments'      => $failed,
			'refunded_payments'    => $refunded,
		);
	}

	public static function create( $data ) {
		global $wpdb;

		$clean = array(
			'membership_id'          => absint( isset( $data['membership_id'] ) ? $data['membership_id'] : 0 ),
			'amount'                 => memberistic_sanitize_price( isset( $data['amount'] ) ? $data['amount'] : 0 ),
			'currency'               => sanitize_text_field( (string) ( isset( $data['currency'] ) ? $data['currency'] : 'USD' ) ),
			'payment_method'         => isset( $data['payment_method'] ) ? sanitize_key( (string) $data['payment_method'] ) : 'manual',
			'payment_gateway'        => isset( $data['payment_gateway'] ) ? sanitize_key( (string) $data['payment_gateway'] ) : 'manual',
			'gateway_transaction_id' => isset( $data['gateway_transaction_id'] ) ? sanitize_text_field( (string) $data['gateway_transaction_id'] ) : '',
			'woo_order_id'           => isset( $data['woo_order_id'] ) ? absint( $data['woo_order_id'] ) : null,
			'pos_order_id'           => isset( $data['pos_order_id'] ) ? sanitize_text_field( (string) $data['pos_order_id'] ) : '',
			'status'                 => sanitize_key( (string) ( isset( $data['status'] ) ? $data['status'] : 'pending' ) ),
			'paid_at'                => ! empty( $data['paid_at'] ) ? \WordPressistic\Memberistic\Database\Memberships_Repository::sanitize_datetime( $data['paid_at'] ) : null,
			'raw_response'           => isset( $data['raw_response'] ) ? wp_json_encode( $data['raw_response'] ) : '',
			'created_at'             => current_time( 'mysql' ),
		);

		if ( empty( $clean['membership_id'] ) ) {
			return false;
		}

		$inserted = $wpdb->insert( self::table(), $clean, memberistic_db_formats( $clean ) );
		return false === $inserted ? false : (int) $wpdb->insert_id;
	}

	public static function sum_paid_by_cycle( $cycle ) {
		global $wpdb;

		$memberships = $wpdb->prefix . 'memberistic_memberships';
		$payments    = self::table();

		return round(
			(float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(p.amount), 0) FROM {$payments} p INNER JOIN {$memberships} m ON m.id = p.membership_id WHERE p.status = %s AND m.billing_cycle = %s",
				'completed',
				$cycle
			)
			),
			2
		);
	}

	public static function count_by_status( $status ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE status = %s', sanitize_key( $status ) ) );
	}

	public static function revenue_by_plan() {
		global $wpdb;
		$payments    = self::table();
		$memberships = $wpdb->prefix . 'memberistic_memberships';
		$plans       = $wpdb->prefix . 'memberistic_plans';

		return $wpdb->get_results(
			"SELECT pl.name AS plan_name, COUNT(DISTINCT m.id) AS memberships, COALESCE(SUM(p.amount), 0) AS revenue
			FROM {$plans} pl
			LEFT JOIN {$memberships} m ON m.plan_id = pl.id
			LEFT JOIN {$payments} p ON p.membership_id = m.id AND p.status = 'completed'
			GROUP BY pl.id
			ORDER BY revenue DESC",
			ARRAY_A
		) ?: array();
	}

	/**
	 * Monthly revenue series for the dashboard chart.
	 *
	 * Returns an array of { month: 'YYYY-MM', label: 'Mon YY', revenue: float }
	 * covering the last $months months, oldest first, with empty months zero-filled.
	 *
	 * @param int $months Number of months to include (1-36).
	 * @return array<int, array<string, mixed>>
	 */
	public static function revenue_history( $months = 12 ) {
		global $wpdb;

		$months = max( 1, min( 36, (int) $months ) );
		$table  = self::table();
		$now_ts = current_time( 'timestamp' );
		$start  = wp_date( 'Y-m-01 00:00:00', strtotime( '-' . ( $months - 1 ) . ' months', $now_ts ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE_FORMAT(paid_at, '%%Y-%%m') AS month, COALESCE(SUM(amount), 0) AS revenue
				FROM {$table}
				WHERE status = %s AND paid_at IS NOT NULL AND paid_at >= %s
				GROUP BY month
				ORDER BY month ASC",
				'completed',
				$start
			),
			ARRAY_A
		) ?: array();

		$by_month = array();
		foreach ( $rows as $row ) {
			$by_month[ $row['month'] ] = (float) $row['revenue'];
		}

		$series = array();
		for ( $i = $months - 1; $i >= 0; $i-- ) {
			$ts    = strtotime( '-' . $i . ' months', $now_ts );
			$key   = wp_date( 'Y-m', $ts );
			$label = wp_date( 'M y', $ts );

			$series[] = array(
				'month'   => $key,
				'label'   => $label,
				'revenue' => isset( $by_month[ $key ] ) ? round( $by_month[ $key ], 2 ) : 0.0,
			);
		}

		return $series;
	}
}
