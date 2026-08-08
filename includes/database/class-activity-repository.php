<?php
/**
 * Activity repository.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic\Database;

use function WordPressistic\Memberistic\memberistic_db_formats;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Activity_Repository {
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'memberistic_activity';
	}

	public static function log( $data ) {
		global $wpdb;

		$valid_types = array(
			'membership_created',
			'membership_activated',
			'membership_renewed',
			'membership_cancelled',
			'membership_expired',
			'membership_status_changed',
			'membership_paused',
			'membership_upgraded',
			'membership_downgraded',
			'linked_member_added',
			'linked_member_removed',
			'payment_completed',
			'payment_failed',
			'payment_past_due',
			'payment_receipt',
			'payment_refunded',
			'checkin_created',
			'waiver_signed',
			'waiver_expired',
			'booking_created',
			'booking_completed',
			'booking_cancelled',
			'email_sent',
			'staff_note_added',
		);

		$type = sanitize_key( (string) ( $data['activity_type'] ?? '' ) );
		if ( ! in_array( $type, $valid_types, true ) ) {
			$type = 'membership_created';
		}

		$clean = array(
			'membership_id'       => isset( $data['membership_id'] ) ? absint( $data['membership_id'] ) : null,
			'person_id'           => isset( $data['person_id'] ) ? absint( $data['person_id'] ) : null,
			'user_id'             => isset( $data['user_id'] ) ? absint( $data['user_id'] ) : null,
			'activity_type'       => $type,
			'title'               => isset( $data['title'] ) ? sanitize_text_field( (string) $data['title'] ) : '',
			'description'         => isset( $data['description'] ) ? sanitize_textarea_field( (string) $data['description'] ) : '',
			'related_object_type' => isset( $data['related_object_type'] ) ? sanitize_key( (string) $data['related_object_type'] ) : '',
			'related_object_id'   => isset( $data['related_object_id'] ) ? sanitize_text_field( (string) $data['related_object_id'] ) : '',
			'created_by'          => isset( $data['created_by'] ) ? absint( $data['created_by'] ) : get_current_user_id(),
			'created_at'          => current_time( 'mysql' ),
		);

		$inserted = $wpdb->insert( self::table(), $clean, memberistic_db_formats( $clean ) );
		return false === $inserted ? false : (int) $wpdb->insert_id;
	}

	public static function get_by_membership( $membership_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE membership_id = %d ORDER BY created_at DESC LIMIT 100', $membership_id ), ARRAY_A ) ?: array();
	}

	public static function get_recent( $limit = 20 ) {
		global $wpdb;
		$limit = max( 1, min( 200, $limit ) );
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' ORDER BY created_at DESC LIMIT %d', $limit ), ARRAY_A ) ?: array();
	}

	/**
	 * Filtered activity feed for the admin Activity page.
	 *
	 * @param array<string, mixed> $args Filter args: activity_type, membership_id, search, from, to, limit.
	 */
	public static function get_all( $args = array() ) {
		global $wpdb;

		$table       = self::table();
		$memberships = $wpdb->prefix . 'memberistic_memberships';
		$where       = array();
		$where_args  = array();

		if ( ! empty( $args['activity_type'] ) ) {
			$where[]      = 'a.activity_type = %s';
			$where_args[] = sanitize_key( (string) $args['activity_type'] );
		}

		if ( ! empty( $args['membership_id'] ) ) {
			$where[]      = 'a.membership_id = %d';
			$where_args[] = absint( $args['membership_id'] );
		}

		if ( ! empty( $args['from'] ) ) {
			$where[]      = 'a.created_at >= %s';
			$where_args[] = sanitize_text_field( (string) $args['from'] );
		}

		if ( ! empty( $args['to'] ) ) {
			$where[]      = 'a.created_at <= %s';
			$where_args[] = sanitize_text_field( (string) $args['to'] );
		}

		if ( ! empty( $args['search'] ) ) {
			$like         = '%' . $wpdb->esc_like( sanitize_text_field( (string) $args['search'] ) ) . '%';
			$where[]      = '(a.title LIKE %s OR a.description LIKE %s OR m.membership_uuid LIKE %s)';
			$where_args[] = $like;
			$where_args[] = $like;
			$where_args[] = $like;
		}

		$where_sql = $where ? ' WHERE ' . implode( ' AND ', $where ) : '';
		$limit     = isset( $args['limit'] ) ? max( 1, min( 500, (int) $args['limit'] ) ) : 100;

		$sql = "
			SELECT a.*, m.membership_uuid
			FROM {$table} a
			LEFT JOIN {$memberships} m ON m.id = a.membership_id
			{$where_sql}
			ORDER BY a.created_at DESC
			LIMIT " . $limit;

		if ( $where_args ) {
			$sql = $wpdb->prepare( $sql, $where_args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		return $wpdb->get_results( $sql, ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Allowed activity types — useful for the admin filter dropdown.
	 *
	 * @return array<int, string>
	 */
	public static function types() {
		return array(
			'membership_created', 'membership_activated', 'membership_renewed',
			'membership_cancelled', 'membership_expired', 'membership_status_changed',
			'membership_paused', 'membership_upgraded', 'membership_downgraded',
			'linked_member_added', 'linked_member_removed',
			'payment_completed', 'payment_failed',
			'payment_past_due', 'payment_receipt', 'payment_refunded',
			'checkin_created', 'waiver_signed', 'waiver_expired',
			'booking_created', 'booking_completed', 'booking_cancelled',
			'email_sent', 'staff_note_added',
		);
	}
}
