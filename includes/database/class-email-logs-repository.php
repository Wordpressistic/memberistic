<?php
/**
 * Email logs repository.
 *
 * Records every transactional email Memberistic dispatches so staff have a
 * permanent audit trail for renewals, payment-failure follow-ups, waiver
 * reminders, and manual sends.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic\Database;

use function WordPressistic\Memberistic\memberistic_db_formats;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Email_Logs_Repository {
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'memberistic_email_logs';
	}

	public static function log( $data ) {
		global $wpdb;

		$clean = array(
			'membership_id' => isset( $data['membership_id'] ) ? absint( $data['membership_id'] ) : null,
			'person_id'     => isset( $data['person_id'] ) ? absint( $data['person_id'] ) : null,
			'template'      => sanitize_key( (string) ( $data['template'] ?? '' ) ),
			'recipient'     => sanitize_email( (string) ( $data['recipient'] ?? '' ) ),
			'subject'       => isset( $data['subject'] ) ? sanitize_text_field( (string) $data['subject'] ) : '',
			'status'        => isset( $data['status'] ) ? sanitize_key( (string) $data['status'] ) : 'sent',
			'error_message' => isset( $data['error_message'] ) ? sanitize_textarea_field( (string) $data['error_message'] ) : '',
			'sent_at'       => current_time( 'mysql' ),
		);

		if ( '' === $clean['template'] || '' === $clean['recipient'] ) {
			return false;
		}

		$inserted = $wpdb->insert( self::table(), $clean, memberistic_db_formats( $clean ) );
		return false === $inserted ? false : (int) $wpdb->insert_id;
	}

	public static function get_by_membership( $membership_id, $limit = 50 ) {
		global $wpdb;
		$limit = max( 1, min( 500, (int) $limit ) );
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE membership_id = %d ORDER BY sent_at DESC LIMIT %d',
				absint( $membership_id ),
				$limit
			),
			ARRAY_A
		) ?: array();
	}

	public static function get_recent( $limit = 100 ) {
		global $wpdb;
		$limit = max( 1, min( 500, (int) $limit ) );
		return $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' ORDER BY sent_at DESC LIMIT %d', $limit ),
			ARRAY_A
		) ?: array();
	}

	/**
	 * True when a 'sent' (or 'suppressed') log row exists for the
	 * given (membership_id, template) combination since $since_mysql.
	 * Used by the renewal-reminder cron to dedupe across cron runs:
	 * if the 30-day reminder went out yesterday and cron skipped
	 * today, we don't re-send it tomorrow.
	 */
	public static function was_sent_for_membership( $membership_id, $template, $since_mysql ) {
		global $wpdb;
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . self::table() . ' WHERE membership_id = %d AND template = %s AND status IN (\'sent\',\'suppressed\') AND sent_at >= %s',
				absint( $membership_id ),
				sanitize_key( $template ),
				(string) $since_mysql
			)
		);
		return $count > 0;
	}

	/**
	 * Prune log rows older than N days. Called by the daily
	 * maintenance cron in Scheduler.
	 */
	public static function prune_older_than( $days ) {
		global $wpdb;
		$days = max( 30, (int) $days );
		$cut  = wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - $days * DAY_IN_SECONDS );
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . self::table() . ' WHERE sent_at < %s',
				$cut
			)
		);
	}
}
