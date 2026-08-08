<?php
/**
 * Check-ins repository.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic\Database;

use function WordPressistic\Memberistic\memberistic_db_formats;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Checkins_Repository {
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'memberistic_checkins';
	}

	public static function get_all( $args = array() ) {
		global $wpdb;

		$table       = self::table();
		$memberships = $wpdb->prefix . 'memberistic_memberships';
		$people      = $wpdb->prefix . 'memberistic_people';
		$where       = array();
		$where_args  = array();

		if ( ! empty( $args['membership_id'] ) ) {
			$where[]      = 'c.membership_id = %d';
			$where_args[] = absint( $args['membership_id'] );
		}

		if ( ! empty( $args['checkin_type'] ) ) {
			$where[]      = 'c.checkin_type = %s';
			$where_args[] = sanitize_key( (string) $args['checkin_type'] );
		}

		if ( ! empty( $args['from'] ) ) {
			$where[]      = 'c.checked_in_at >= %s';
			$where_args[] = sanitize_text_field( (string) $args['from'] );
		}

		if ( ! empty( $args['to'] ) ) {
			$where[]      = 'c.checked_in_at <= %s';
			$where_args[] = sanitize_text_field( (string) $args['to'] );
		}

		if ( ! empty( $args['search'] ) ) {
			$like         = '%' . $wpdb->esc_like( sanitize_text_field( (string) $args['search'] ) ) . '%';
			$where[]      = '(m.membership_uuid LIKE %s OR pe.full_name LIKE %s)';
			$where_args[] = $like;
			$where_args[] = $like;
		}

		$where_sql = $where ? ' WHERE ' . implode( ' AND ', $where ) : '';
		$limit     = isset( $args['limit'] ) ? max( 1, min( 500, (int) $args['limit'] ) ) : 100;

		$sql = "
			SELECT c.*, m.membership_uuid, pe.full_name
			FROM {$table} c
			LEFT JOIN {$memberships} m ON m.id = c.membership_id
			LEFT JOIN {$people} pe ON pe.id = c.person_id
			{$where_sql}
			ORDER BY c.checked_in_at DESC
			LIMIT " . $limit;

		if ( $where_args ) {
			$sql = $wpdb->prepare( $sql, $where_args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		return $wpdb->get_results( $sql, ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function get_by_membership( $membership_id ) {
		return self::get_all( array( 'membership_id' => $membership_id ) );
	}

	public static function create( $data ) {
		global $wpdb;

		$clean = array(
			'membership_id' => absint( isset( $data['membership_id'] ) ? $data['membership_id'] : 0 ),
			'person_id'     => absint( isset( $data['person_id'] ) ? $data['person_id'] : 0 ),
			'booking_id'    => isset( $data['booking_id'] ) ? absint( $data['booking_id'] ) : null,
			'pos_order_id'  => isset( $data['pos_order_id'] ) ? sanitize_text_field( (string) $data['pos_order_id'] ) : '',
			'checkin_type'  => isset( $data['checkin_type'] ) ? sanitize_key( (string) $data['checkin_type'] ) : 'walk_in',
			'status'        => isset( $data['status'] ) ? sanitize_key( (string) $data['status'] ) : 'checked_in',
			'checked_in_by' => get_current_user_id(),
			'checked_in_at' => current_time( 'mysql' ),
			'notes'         => isset( $data['notes'] ) ? sanitize_textarea_field( (string) $data['notes'] ) : '',
		);

		if ( empty( $clean['membership_id'] ) || empty( $clean['person_id'] ) ) {
			return false;
		}

		$inserted = $wpdb->insert( self::table(), $clean, memberistic_db_formats( $clean ) );
		return false === $inserted ? false : (int) $wpdb->insert_id;
	}

	public static function count_today() {
		global $wpdb;
		$today = current_time( 'Y-m-d' );
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE DATE(checked_in_at) = %s', $today ) );
	}
}
