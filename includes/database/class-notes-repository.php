<?php
/**
 * Notes repository.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic\Database;

use function WordPressistic\Memberistic\memberistic_db_formats;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Notes_Repository {
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'memberistic_notes';
	}

	public static function get_by_membership( $membership_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE membership_id = %d ORDER BY created_at DESC LIMIT 100', $membership_id ), ARRAY_A ) ?: array();
	}

	public static function create( $data ) {
		global $wpdb;

		$clean = array(
			'membership_id' => absint( isset( $data['membership_id'] ) ? $data['membership_id'] : 0 ),
			'person_id'     => ! empty( $data['person_id'] ) ? absint( $data['person_id'] ) : null,
			'note'          => isset( $data['note'] ) ? sanitize_textarea_field( (string) $data['note'] ) : '',
			'visibility'    => isset( $data['visibility'] ) ? sanitize_key( (string) $data['visibility'] ) : 'staff_only',
			'created_by'    => get_current_user_id(),
			'created_at'    => current_time( 'mysql' ),
		);

		if ( empty( $clean['membership_id'] ) || '' === $clean['note'] ) {
			return false;
		}

		$inserted = $wpdb->insert( self::table(), $clean, memberistic_db_formats( $clean ) );
		return false === $inserted ? false : (int) $wpdb->insert_id;
	}
}
