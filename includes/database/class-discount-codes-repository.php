<?php
/**
 * Discount codes repository.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
final class Discount_Codes_Repository {

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'memberistic_discount_codes';
	}

	public static function get( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d LIMIT 1', $id ), ARRAY_A );
		return $row ? self::unpack( $row ) : null;
	}

	/**
	 * Case-insensitive lookup by the code the customer typed.
	 */
	public static function get_by_code( $code ) {
		global $wpdb;
		$code = self::normalize_code( $code );
		if ( '' === $code ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE code = %s LIMIT 1', $code ), ARRAY_A );
		return $row ? self::unpack( $row ) : null;
	}

	/**
	 * @param array<string, mixed> $args status, search.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_all( $args = array() ) {
		global $wpdb;
		$where      = array();
		$where_args = array();

		if ( ! empty( $args['status'] ) ) {
			$where[]      = 'status = %s';
			$where_args[] = sanitize_key( (string) $args['status'] );
		}
		if ( ! empty( $args['search'] ) ) {
			$like         = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$where[]      = '(code LIKE %s OR description LIKE %s)';
			$where_args[] = $like;
			$where_args[] = $like;
		}

		$where_sql = $where ? ' WHERE ' . implode( ' AND ', $where ) : '';
		$sql       = 'SELECT * FROM ' . self::table() . $where_sql . ' ORDER BY id DESC';
		if ( $where_args ) {
			$sql = $wpdb->prepare( $sql, $where_args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		$rows = $wpdb->get_results( $sql, ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array_map( array( self::class, 'unpack' ), $rows );
	}

	public static function create( array $data ) {
		global $wpdb;
		$clean = self::pack( self::sanitize_data( $data ) );
		if ( empty( $clean['code'] ) ) {
			return false;
		}
		$clean['created_at'] = current_time( 'mysql' );

		$inserted = $wpdb->insert( self::table(), $clean, self::formats( $clean ) );
		if ( false === $inserted ) {
			return false;
		}
		return (int) $wpdb->insert_id;
	}

	public static function update( $id, array $data ) {
		global $wpdb;
		$clean = self::pack( self::sanitize_data( $data, false ) );
		if ( empty( $clean ) ) {
			return true;
		}
		$clean['updated_at'] = current_time( 'mysql' );
		return false !== $wpdb->update( self::table(), $clean, array( 'id' => (int) $id ), self::formats( $clean ), array( '%d' ) );
	}

	/**
	 * Atomically claim one redemption slot. The WHERE clause is the
	 * concurrency guard — matches the booking engine's database-level
	 * locking pattern (docs/FEATURES.md) so two simultaneous confirmed
	 * payments can't both claim a slot past max_redemptions.
	 *
	 * @return bool True if a slot was claimed.
	 */
	public static function claim_redemption( $id ) {
		global $wpdb;
		$table = self::table();
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET current_redemptions = current_redemptions + 1, updated_at = %s" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				. " WHERE id = %d AND status = 'active' AND (max_redemptions IS NULL OR current_redemptions < max_redemptions)",
				current_time( 'mysql' ),
				(int) $id
			)
		);
		return $wpdb->rows_affected > 0;
	}

	private static function normalize_code( $code ) {
		return strtoupper( preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $code ) );
	}

	private static function sanitize_data( array $data, $apply_defaults = true ) {
		$clean = array();

		if ( isset( $data['code'] ) ) {
			$clean['code'] = self::normalize_code( $data['code'] );
		}
		if ( isset( $data['description'] ) ) {
			$clean['description'] = sanitize_text_field( (string) $data['description'] );
		}
		if ( isset( $data['discount_type'] ) ) {
			$clean['discount_type'] = in_array( $data['discount_type'], array( 'percent', 'fixed' ), true ) ? $data['discount_type'] : 'percent';
		}
		if ( isset( $data['discount_value'] ) ) {
			$clean['discount_value'] = max( 0, (float) $data['discount_value'] );
		}
		if ( isset( $data['applies_to'] ) ) {
			$clean['applies_to'] = in_array( $data['applies_to'], array( 'all', 'specific_plans' ), true ) ? $data['applies_to'] : 'all';
		}
		if ( array_key_exists( 'plan_ids', $data ) ) {
			$clean['plan_ids'] = is_array( $data['plan_ids'] ) ? array_values( array_unique( array_map( 'intval', $data['plan_ids'] ) ) ) : array();
		}
		if ( isset( $data['billing_cycles'] ) ) {
			$clean['billing_cycles'] = in_array( $data['billing_cycles'], array( 'all', 'monthly', 'annual' ), true ) ? $data['billing_cycles'] : 'all';
		}
		if ( isset( $data['duration_type'] ) ) {
			$clean['duration_type'] = in_array( $data['duration_type'], array( 'once', 'repeating', 'forever' ), true ) ? $data['duration_type'] : 'once';
		}
		if ( array_key_exists( 'duration_in_months', $data ) ) {
			$clean['duration_in_months'] = ( '' === $data['duration_in_months'] || null === $data['duration_in_months'] ) ? null : max( 1, (int) $data['duration_in_months'] );
		}
		if ( array_key_exists( 'max_redemptions', $data ) ) {
			$clean['max_redemptions'] = ( '' === $data['max_redemptions'] || null === $data['max_redemptions'] ) ? null : max( 1, (int) $data['max_redemptions'] );
		}
		if ( isset( $data['max_redemptions_per_user'] ) ) {
			$clean['max_redemptions_per_user'] = max( 1, (int) $data['max_redemptions_per_user'] );
		}
		if ( array_key_exists( 'starts_at', $data ) ) {
			$clean['starts_at'] = self::sanitize_datetime( $data['starts_at'] );
		}
		if ( array_key_exists( 'expires_at', $data ) ) {
			$clean['expires_at'] = self::sanitize_datetime( $data['expires_at'] );
		}
		if ( isset( $data['status'] ) ) {
			$clean['status'] = in_array( $data['status'], array( 'active', 'inactive' ), true ) ? $data['status'] : 'inactive';
		}
		if ( array_key_exists( 'stripe_coupon_id', $data ) ) {
			$clean['stripe_coupon_id'] = $data['stripe_coupon_id'] ? sanitize_text_field( (string) $data['stripe_coupon_id'] ) : null;
		}
		if ( array_key_exists( 'stripe_promotion_code_id', $data ) ) {
			$clean['stripe_promotion_code_id'] = $data['stripe_promotion_code_id'] ? sanitize_text_field( (string) $data['stripe_promotion_code_id'] ) : null;
		}
		if ( array_key_exists( 'sync_error', $data ) ) {
			$clean['sync_error'] = $data['sync_error'] ? sanitize_textarea_field( (string) $data['sync_error'] ) : null;
		}
		if ( isset( $data['created_by'] ) ) {
			$clean['created_by'] = absint( $data['created_by'] );
		}

		if ( $apply_defaults ) {
			$clean += array(
				'discount_type'            => 'percent',
				'discount_value'           => 0,
				'applies_to'               => 'all',
				'plan_ids'                 => array(),
				'billing_cycles'           => 'all',
				'duration_type'            => 'once',
				'max_redemptions_per_user' => 1,
				'status'                   => 'active',
			);
		}

		return $clean;
	}

	private static function sanitize_datetime( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return null;
		}
		$ts = strtotime( $value );
		return $ts ? gmdate( 'Y-m-d H:i:s', $ts ) : null;
	}

	/** JSON-encode plan_ids for storage. */
	private static function pack( array $clean ) {
		if ( array_key_exists( 'plan_ids', $clean ) ) {
			$clean['plan_ids'] = wp_json_encode( array_values( (array) $clean['plan_ids'] ) );
		}
		return $clean;
	}

	/** JSON-decode plan_ids and cast numerics on read. */
	private static function unpack( array $row ) {
		$row['plan_ids']                 = array_map( 'intval', (array) ( json_decode( (string) ( $row['plan_ids'] ?? '' ), true ) ?: array() ) );
		$row['discount_value']           = (float) $row['discount_value'];
		$row['duration_in_months']       = null !== $row['duration_in_months'] ? (int) $row['duration_in_months'] : null;
		$row['max_redemptions']          = null !== $row['max_redemptions'] ? (int) $row['max_redemptions'] : null;
		$row['max_redemptions_per_user'] = (int) $row['max_redemptions_per_user'];
		$row['current_redemptions']      = (int) $row['current_redemptions'];
		return $row;
	}

	private static function formats( array $data ) {
		$int_fields   = array( 'duration_in_months', 'max_redemptions', 'max_redemptions_per_user', 'current_redemptions', 'created_by' );
		$float_fields = array( 'discount_value' );
		$formats      = array();
		foreach ( $data as $key => $value ) {
			if ( in_array( $key, $int_fields, true ) ) {
				$formats[] = '%d';
			} elseif ( in_array( $key, $float_fields, true ) ) {
				$formats[] = '%f';
			} else {
				$formats[] = '%s';
			}
		}
		return $formats;
	}
}
