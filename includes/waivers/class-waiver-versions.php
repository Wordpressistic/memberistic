<?php
/**
 * Waiver versions — immutable, versioned waiver text.
 *
 * Publishing a change creates a NEW version row instead of mutating the
 * legacy memberistic_waiver_text option (which is kept in sync for
 * back-compat readers). Every signature records the version it was signed
 * against (waiver_signatures.waiver_version_id), and a version can be
 * published with "require re-consent": everyone signed before it takes
 * effect must sign again — enforced by flipping their person rows to
 * needs_review AND by a re-consent boundary applied to the archive's
 * waiver-on-file lookup (so booking/check-in auto-satisfy stops too).
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic\Waivers;

use WordPressistic\Memberistic\Database\People_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Waiver_Versions {

	/** @var array|false|null Per-request cache of the current version row. */
	private static $current_cache = null;

	/** @var string|null Per-request cache of the re-consent boundary. */
	private static $boundary_cache = null;

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'memberistic_waiver_versions';
	}

	/** Reset per-request caches (after publish). */
	public static function flush_cache() {
		self::$current_cache  = null;
		self::$boundary_cache = null;
	}

	/**
	 * The version in effect right now: newest effective_from <= now.
	 *
	 * @return array|false Row or false when no versions exist yet.
	 */
	public static function current() {
		if ( null !== self::$current_cache ) {
			return self::$current_cache;
		}
		global $wpdb;
		$table = self::table();
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			self::$current_cache = false;
			return false;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE effective_from <= %s ORDER BY effective_from DESC, id DESC LIMIT 1", current_time( 'mysql' ) ), ARRAY_A );
		self::$current_cache = $row ?: false;
		return self::$current_cache;
	}

	/** Id of the version in effect (0 when none). */
	public static function current_id() {
		$row = self::current();
		return $row ? (int) $row['id'] : 0;
	}

	public static function get( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id ), ARRAY_A ) ?: null;
	}

	/** Newest-first version history for the admin screen. */
	public static function all( $limit = 20 ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' ORDER BY effective_from DESC, id DESC LIMIT %d', max( 1, (int) $limit ) ), ARRAY_A ) ?: array();
	}

	/**
	 * The re-consent boundary: the effective_from of the newest in-effect
	 * version published with "require re-consent". Signatures older than
	 * this no longer satisfy the waiver gate anywhere.
	 *
	 * @return string MySQL datetime, or '' when no re-consent version exists.
	 */
	public static function reconsent_boundary() {
		if ( null !== self::$boundary_cache ) {
			return self::$boundary_cache;
		}
		global $wpdb;
		$table = self::table();
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			self::$boundary_cache = '';
			return '';
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$b = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(effective_from) FROM {$table} WHERE requires_reconsent = 1 AND effective_from <= %s", current_time( 'mysql' ) ) );
		self::$boundary_cache = $b ? (string) $b : '';
		return self::$boundary_cache;
	}

	/**
	 * Publish a new waiver version, effective immediately.
	 *
	 * @param string $body               The waiver text.
	 * @param string $title              Optional label ("2026 insurance update").
	 * @param bool   $requires_reconsent Everyone signed before this must re-sign.
	 * @param int    $user_id            Publishing admin.
	 * @return int New version id (0 on failure/empty body).
	 */
	public static function publish( $body, $title = '', $requires_reconsent = false, $user_id = 0 ) {
		$body = trim( (string) $body );
		if ( '' === $body ) {
			return 0;
		}
		global $wpdb;
		$now = current_time( 'mysql' );
		$ok  = $wpdb->insert(
			self::table(),
			array(
				'title'              => sanitize_text_field( (string) $title ),
				'body'               => $body,
				'text_hash'          => hash( 'sha256', $body ),
				'requires_reconsent' => $requires_reconsent ? 1 : 0,
				'effective_from'     => $now,
				'created_by'         => $user_id > 0 ? (int) $user_id : null,
				'created_at'         => $now,
			),
			array( '%s', '%s', '%s', '%d', '%s', '%d', '%s' )
		);
		if ( ! $ok ) {
			return 0;
		}
		$id = (int) $wpdb->insert_id;

		// Keep the legacy option in sync for anything still reading it.
		update_option( 'memberistic_waiver_text', $body );
		self::flush_cache();

		if ( $requires_reconsent ) {
			// Everyone currently marked signed signed an older version — flip
			// them to needs_review so every surface (member page, staff
			// dashboards, corporate check-in) prompts a re-sign. The archive
			// lookup is covered separately by reconsent_boundary().
			$people = People_Repository::table();
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "UPDATE {$people} SET waiver_status = 'needs_review' WHERE waiver_status = 'signed'" );
		}

		return $id;
	}

	/**
	 * Seed version 1 from the legacy option if the table is empty. Called by
	 * the 1.8.0 migration; effective_from is backdated so existing
	 * signatures aren't retroactively invalidated.
	 */
	public static function maybe_seed_initial() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) > 0 ) {
			return;
		}
		$body = trim( (string) get_option( 'memberistic_waiver_text', '' ) );
		if ( '' === $body && class_exists( '\WordPressistic\Memberistic\Waivers\Waiver_Service' ) ) {
			$body = (string) Waiver_Service::waiver_text();
		}
		if ( '' === $body ) {
			return;
		}
		$wpdb->insert(
			$table,
			array(
				'title'              => __( 'Initial version (migrated)', 'memberistic' ),
				'body'               => $body,
				'text_hash'          => hash( 'sha256', $body ),
				'requires_reconsent' => 0,
				'effective_from'     => '2000-01-01 00:00:00',
				'created_by'         => null,
				'created_at'         => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%d', '%s', '%d', '%s' )
		);
		self::flush_cache();
	}
}
