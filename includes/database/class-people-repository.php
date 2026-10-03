<?php
/**
 * People repository.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic\Database;

use function WordPressistic\Memberistic\memberistic_db_formats;
use function WordPressistic\Memberistic\memberistic_validate_email;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class People_Repository {
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'memberistic_people';
	}

	public static function get_by_membership( $membership_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE membership_id = %d ORDER BY role DESC, id ASC', $membership_id ), ARRAY_A ) ?: array();
	}

	public static function get_primary_by_membership( $membership_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE membership_id = %d AND role = %s ORDER BY id ASC LIMIT 1', absint( $membership_id ), 'primary' ), ARRAY_A );
		return $row ?: null;
	}

	public static function get( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d LIMIT 1', $id ), ARRAY_A );
		return $row ?: null;
	}

	public static function create( $data ) {
		global $wpdb;

		$data = self::sanitize_data( $data );

		if ( empty( $data['membership_id'] ) || empty( $data['full_name'] ) ) {
			return false;
		}

		$data['created_at'] = current_time( 'mysql' );

		$inserted = $wpdb->insert( self::table(), $data, memberistic_db_formats( $data ) );

		if ( false === $inserted ) {
			return false;
		}

		$person_id = (int) $wpdb->insert_id;
		do_action( 'memberistic_person_added', $person_id, (int) $data['membership_id'] );

		return $person_id;
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

	/**
	 * Active people whose waiver is still missing or expired — feeds the
	 * daily waiver follow-up cron. Returns one row per membership at most.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_active_missing_waiver() {
		global $wpdb;

		return $wpdb->get_results(
			"SELECT MIN(id) AS id, membership_id FROM " . self::table() . " WHERE status = 'active' AND waiver_status IN ('missing','expired','needs_review') GROUP BY membership_id LIMIT 200",
			ARRAY_A
		) ?: array();
	}

	public static function get_by_email( $email ) {
		global $wpdb;
		$email = memberistic_validate_email( $email );

		if ( '' === $email ) {
			return null;
		}

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE email = %s ORDER BY id DESC LIMIT 1', $email ), ARRAY_A );
		return $row ?: null;
	}

	/**
	 * Normalize empty-string emails and collapse duplicate emails to the
	 * newest row, matching get_by_email()'s existing resolution behavior.
	 * Losing rows are never deleted: only their duplicate email is cleared and
	 * the change is logged for staff review before the unique index is added.
	 *
	 * @param bool $apply      Actually write changes. Default false.
	 * @param int  $batch_size Duplicate groups written per transaction.
	 * @return array{normalized_empty:int,duplicate_groups:int,rows_cleared:int,groups:array}
	 */
	public static function dedupe_by_email( $apply = false, $batch_size = 200 ) {
		global $wpdb;
		$table = self::table();

		$report = array(
			'normalized_empty' => 0,
			'duplicate_groups' => 0,
			'rows_cleared'     => 0,
			'groups'           => array(),
		);

		$empty_count                = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE email = ''" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$report['normalized_empty'] = $empty_count;
		if ( $apply && $empty_count > 0 ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET email = NULL, updated_at = %s WHERE email = ''", current_time( 'mysql' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		$duplicate_emails = $wpdb->get_col( "SELECT email FROM {$table} WHERE email IS NOT NULL AND email <> '' GROUP BY email HAVING COUNT(*) > 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$batch            = array();

		foreach ( (array) $duplicate_emails as $email ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id FROM {$table} WHERE email = %s ORDER BY id DESC", $email ), ARRAY_A );
			if ( count( $rows ) < 2 ) {
				continue;
			}

			$keep_id     = (int) $rows[0]['id'];
			$cleared_ids = array_map( 'intval', array_column( array_slice( $rows, 1 ), 'id' ) );
			++$report['duplicate_groups'];
			$report['rows_cleared'] += count( $cleared_ids );
			$report['groups'][]      = array(
				'email'       => $email,
				'keep_id'     => $keep_id,
				'cleared_ids' => $cleared_ids,
			);

			if ( $apply ) {
				$batch[] = $report['groups'][ count( $report['groups'] ) - 1 ];
				if ( count( $batch ) >= max( 1, (int) $batch_size ) ) {
					self::apply_dedupe_batch( $batch );
					$batch = array();
				}
			}
		}

		if ( $apply && $batch ) {
			self::apply_dedupe_batch( $batch );
		}

		return $report;
	}

	/**
	 * Apply one dedupe batch atomically and leave a per-person audit trail.
	 *
	 * @param array<int, array{email:string,keep_id:int,cleared_ids:int[]}> $batch
	 */
	private static function apply_dedupe_batch( array $batch ) {
		global $wpdb;
		$table = self::table();
		$now   = current_time( 'mysql' );

		$wpdb->query( 'START TRANSACTION' );
		try {
			foreach ( $batch as $group ) {
				foreach ( $group['cleared_ids'] as $id ) {
					$wpdb->update(
						$table,
						array( 'email' => null, 'updated_at' => $now ),
						array( 'id' => $id ),
						array( '%s', '%s' ),
						array( '%d' )
					);
					Activity_Repository::log(
						array(
							'person_id'     => $id,
							'activity_type' => 'person_email_deduped',
							'title'         => __( 'Duplicate email cleared', 'memberistic' ),
							'description'   => sprintf(
								/* translators: 1: email address, 2: canonical person id */
								__( 'The duplicate email "%1$s" was cleared from this person. Person #%2$d remains the canonical record. No other field was changed.', 'memberistic' ),
								$group['email'],
								$group['keep_id']
							),
						)
					);
				}
			}
			$wpdb->query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			throw $e;
		}
	}

	public static function count_active_by_membership( $membership_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE membership_id = %d AND status = %s', $membership_id, 'active' ) );
	}

	public static function can_add_person( $membership_id ) {
		global $wpdb;

		$memberships = $wpdb->prefix . 'memberistic_memberships';
		$plans       = $wpdb->prefix . 'memberistic_plans';
		$limit       = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT p.included_people FROM {$memberships} m INNER JOIN {$plans} p ON p.id = m.plan_id WHERE m.id = %d LIMIT 1",
				$membership_id
			)
		);

		if ( $limit < 1 ) {
			return false;
		}

		return self::count_active_by_membership( $membership_id ) < $limit;
	}

	private static function sanitize_data( $data, $apply_defaults = true ) {
		$clean = array();

		if ( isset( $data['membership_id'] ) ) {
			$clean['membership_id'] = absint( $data['membership_id'] );
		}
		if ( isset( $data['wp_user_id'] ) ) {
			$clean['wp_user_id'] = absint( $data['wp_user_id'] );
		}
		if ( isset( $data['role'] ) ) {
			$clean['role'] = in_array( $data['role'], array( 'primary', 'linked' ), true ) ? $data['role'] : 'linked';
		}
		if ( isset( $data['full_name'] ) ) {
			$clean['full_name'] = sanitize_text_field( (string) $data['full_name'] );
		}
		if ( isset( $data['email'] ) ) {
			$clean['email'] = memberistic_validate_email( $data['email'] );
		}
		if ( isset( $data['phone'] ) ) {
			$clean['phone'] = sanitize_text_field( (string) $data['phone'] );
		}
		if ( isset( $data['date_of_birth'] ) ) {
			$clean['date_of_birth'] = sanitize_text_field( (string) $data['date_of_birth'] );
		}
		if ( isset( $data['relationship'] ) ) {
			$clean['relationship'] = sanitize_text_field( (string) $data['relationship'] );
		}
		if ( isset( $data['waiver_status'] ) ) {
			$status = sanitize_key( (string) $data['waiver_status'] );
			$clean['waiver_status'] = in_array( $status, array( 'missing', 'signed', 'expired', 'needs_review', 'rejected' ), true ) ? $status : 'missing';
		}
		if ( isset( $data['waiver_signed_at'] ) ) {
			$clean['waiver_signed_at'] = \WordPressistic\Memberistic\Database\Memberships_Repository::sanitize_datetime( $data['waiver_signed_at'] );
		}
		if ( isset( $data['waiver_expires_at'] ) ) {
			$clean['waiver_expires_at'] = \WordPressistic\Memberistic\Database\Memberships_Repository::sanitize_datetime( $data['waiver_expires_at'] );
		}
		if ( isset( $data['status'] ) ) {
			$status = sanitize_key( (string) $data['status'] );
			$clean['status'] = in_array( $status, array( 'active', 'inactive', 'removed' ), true ) ? $status : 'active';
		}
		if ( $apply_defaults && ! isset( $clean['status'] ) ) {
			$clean['status'] = 'active';
		}
		if ( $apply_defaults && ! isset( $clean['waiver_status'] ) ) {
			$clean['waiver_status'] = 'missing';
		}
		if ( isset( $data['notes'] ) ) {
			$clean['notes'] = sanitize_textarea_field( (string) $data['notes'] );
		}

		return $clean;
	}
}
