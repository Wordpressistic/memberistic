<?php
/**
 * Waivers archive — stored, searchable history of every signed waiver
 * (imported from Ottertext/OtterWaiver and going forward), independent of
 * membership. Powers "does this person have a waiver on file?" lookups by
 * email or name at booking / check-in time.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic\Waivers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Waivers_Archive {

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'memberistic_waivers_archive';
	}

	/**
	 * Stable de-dupe key for a person (so repeat signings collapse to one
	 * "current" waiver). Email wins; falls back to name+dob.
	 */
	public static function dedupe_key( $email, $first, $last, $dob ) {
		$email = strtolower( trim( (string) $email ) );
		if ( '' !== $email ) {
			return 'e:' . $email;
		}
		return 'n:' . strtolower( trim( $first . '|' . $last . '|' . $dob ) );
	}

	/**
	 * Insert a waiver row. Caller passes already-sanitized values.
	 *
	 * @return int Inserted id (0 on failure).
	 */
	public static function insert( array $data ) {
		global $wpdb;
		$row = wp_parse_args( $data, array(
			'first_name'       => '',
			'last_name'        => '',
			'email'            => '',
			'phone'            => '',
			'dob'              => null,
			'signed_at'        => null,
			'source'           => '',
			'participant_type' => '',
			'external_url'     => '',
			'attachment_id'    => null,
			'local_path'       => '',
			'minor_name'       => '',
			'minor_age'        => '',
			'emergency_name'   => '',
			'emergency_phone'  => '',
			'matched_user_id'  => null,
			'is_current'       => 1,
			'dedupe_key'       => '',
			'raw_json'         => '',
			'import_batch'     => '',
			'created_at'       => current_time( 'mysql' ),
		) );
		$ok = $wpdb->insert( self::table(), $row );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Mark all rows sharing a de-dupe key as not-current except $keep_id, so
	 * each person resolves to exactly one current waiver (the latest signed).
	 */
	public static function set_current( $dedupe_key, $keep_id ) {
		global $wpdb;
		$table = self::table();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET is_current = 0 WHERE dedupe_key = %s AND id <> %d", $dedupe_key, (int) $keep_id ) );
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET is_current = 1 WHERE id = %d", (int) $keep_id ) );
		// phpcs:enable
	}

	/**
	 * The current, NOT-EXPIRED waiver row for a person, looked up by email
	 * first, then by last name + DOB, then by full name. Returns null when
	 * nothing valid is on file.
	 *
	 * This archive table has no explicit expires_at column (it's populated
	 * largely from historical Ottertext/OtterWaiver imports), so "current"
	 * previously meant only "the newest of possibly several re-signs" with
	 * no age limit at all — a waiver signed years ago would satisfy this
	 * lookup forever. We now apply the same validity window Memberistic uses
	 * for member waivers elsewhere (Waiver_Service::validity_days(), default
	 * 365 days) against signed_at, so a stale signature no longer silently
	 * satisfies the booking/check-in gate. A row with no signed_at at all is
	 * treated as unverifiable-age and excluded rather than trusted forever.
	 *
	 * @param string $email
	 * @param string $name  "First Last" (optional).
	 * @param string $dob   Y-m-d (optional, sharpens the name match).
	 * @return array|null
	 */
	public static function find_on_file( $email, $name = '', $dob = '' ) {
		global $wpdb;
		$table  = self::table();
		$email  = strtolower( trim( (string) $email ) );
		$cutoff = wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( Waiver_Service::validity_days() * DAY_IN_SECONDS ) );

		// A re-consent waiver version raises the bar: signatures older than
		// its effective date no longer satisfy the gate, even if unexpired.
		if ( class_exists( '\WordPressistic\Memberistic\Waivers\Waiver_Versions' ) ) {
			$boundary = Waiver_Versions::reconsent_boundary();
			if ( '' !== $boundary && $boundary > $cutoff ) {
				$cutoff = $boundary;
			}
		}

		if ( '' !== $email ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE email = %s AND is_current = 1 AND signed_at IS NOT NULL AND signed_at >= %s ORDER BY signed_at DESC LIMIT 1", $email, $cutoff ), ARRAY_A );
			if ( $row ) {
				return $row;
			}
		}

		$name = trim( (string) $name );
		if ( '' !== $name ) {
			$parts = preg_split( '/\s+/', $name );
			$last  = array_pop( $parts );
			$first = implode( ' ', $parts );
			$dob   = trim( (string) $dob );

			if ( '' !== $dob ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE last_name = %s AND dob = %s AND is_current = 1 AND signed_at IS NOT NULL AND signed_at >= %s ORDER BY signed_at DESC LIMIT 1", $last, $dob, $cutoff ), ARRAY_A );
				if ( $row ) {
					return $row;
				}
			}
			if ( '' !== $first ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE last_name = %s AND first_name = %s AND is_current = 1 AND signed_at IS NOT NULL AND signed_at >= %s ORDER BY signed_at DESC LIMIT 1", $last, $first, $cutoff ), ARRAY_A );
				if ( $row ) {
					return $row;
				}
			}
		}
		return null;
	}

	/** Convenience boolean. Only true when a non-expired waiver is on file. */
	public static function has_on_file( $email, $name = '', $dob = '' ) {
		return null !== self::find_on_file( $email, $name, $dob );
	}

	/**
	 * Mirror a new e-signature (a memberistic_waiver_signatures row) into this
	 * archive so the booking/check-in "waiver on file?" lookup sees people who
	 * signed AFTER the Ottertext migration, not just the historical import.
	 *
	 * Idempotent: keyed on import_batch = "sig:{signature_id}", so re-running
	 * (live signing + the backfill migration) never duplicates a row.
	 *
	 * @param array|null $sig Signature row (id, signer_name, signer_email,
	 *                        source, signed_at, user_id).
	 * @return int Archive row id (existing or new), 0 when unmatchable.
	 */
	public static function upsert_from_signature( $sig ) {
		if ( ! is_array( $sig ) || empty( $sig['id'] ) ) {
			return 0;
		}
		$email = strtolower( trim( (string) ( $sig['signer_email'] ?? '' ) ) );
		$name  = trim( (string) ( $sig['signer_name'] ?? '' ) );
		if ( '' === $email && '' === $name ) {
			return 0;
		}

		global $wpdb;
		$table = self::table();
		$batch = 'sig:' . (int) $sig['id'];
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE import_batch = %s LIMIT 1", $batch ) );
		if ( $existing ) {
			return (int) $existing;
		}

		// Same "First … Last" split convention as find_on_file().
		$parts = preg_split( '/\s+/', $name );
		$last  = (string) array_pop( $parts );
		$first = implode( ' ', $parts );

		// First minor (the archive schema models one) — the signature row can
		// carry several in minors_json; all names are kept, first DOB wins.
		$minor_name = '';
		$minor_age  = '';
		if ( ! empty( $sig['minors_json'] ) ) {
			$minors = (array) json_decode( (string) $sig['minors_json'], true );
			$names  = array();
			foreach ( $minors as $m ) {
				$n = trim( (string) ( $m['name'] ?? '' ) );
				if ( '' !== $n ) {
					$names[] = $n;
					if ( '' === $minor_age && ! empty( $m['dob'] ) ) {
						$minor_age = substr( (string) $m['dob'], 0, 20 );
					}
				}
			}
			$minor_name = substr( implode( ', ', $names ), 0, 191 );
		}

		$dob = ! empty( $sig['dob'] ) ? substr( (string) $sig['dob'], 0, 10 ) : '';
		$key = self::dedupe_key( $email, $first, $last, $dob );
		$id  = self::insert(
			array(
				'first_name'      => $first,
				'last_name'       => $last,
				'email'           => $email,
				'phone'           => ! empty( $sig['phone'] ) ? (string) $sig['phone'] : '',
				'dob'             => '' !== $dob ? $dob : null,
				'signed_at'       => ! empty( $sig['signed_at'] ) ? (string) $sig['signed_at'] : null,
				'source'          => 'esign' . ( ! empty( $sig['source'] ) ? ':' . sanitize_key( (string) $sig['source'] ) : '' ),
				'minor_name'      => $minor_name,
				'minor_age'       => $minor_age,
				'emergency_name'  => ! empty( $sig['emergency_name'] ) ? (string) $sig['emergency_name'] : '',
				'emergency_phone' => ! empty( $sig['emergency_phone'] ) ? (string) $sig['emergency_phone'] : '',
				'matched_user_id' => ! empty( $sig['user_id'] ) ? (int) $sig['user_id'] : null,
				'dedupe_key'      => $key,
				'raw_json'        => (string) wp_json_encode( array( 'signature_id' => (int) $sig['id'] ) ),
				'import_batch'    => $batch,
			)
		);
		if ( $id > 0 ) {
			self::set_current_newest( $key );
		}
		return $id;
	}

	/**
	 * Recompute which row is "current" for a de-dupe key: the newest signed_at
	 * wins (rows with no signed_at lose to any dated row).
	 */
	public static function set_current_newest( $dedupe_key ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$keep = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE dedupe_key = %s ORDER BY ( signed_at IS NULL ), signed_at DESC, id DESC LIMIT 1", $dedupe_key ) );
		if ( $keep ) {
			self::set_current( $dedupe_key, (int) $keep );
		}
	}

	/**
	 * The staff URL to view a waiver document for an archive row.
	 *
	 * Always the capability + nonce gated streaming endpoint — never a raw
	 * media-library URL (public even inside a .htaccess-denied directory on
	 * Nginx hosts) and never the external media.otterwaiver.com link (dead
	 * once the Ottertext account closes; a third-party PII exposure before
	 * that). The endpoint itself resolves local mirror → attachment →
	 * external redirect, in that order.
	 */
	public static function pdf_url( array $row ) {
		$id = isset( $row['id'] ) ? (int) $row['id'] : 0;
		if ( $id <= 0 ) {
			return '';
		}
		if ( ! empty( $row['local_path'] ) || ! empty( $row['attachment_id'] ) || ! empty( $row['external_url'] ) ) {
			return self::stream_url( $id );
		}
		// E-sign mirror rows carry no file of their own — the generated PDF
		// lives in the private document store, linked from the signature row.
		if ( ! empty( $row['raw_json'] ) ) {
			$raw = json_decode( (string) $row['raw_json'], true );
			$sid = is_array( $raw ) && ! empty( $raw['signature_id'] ) ? (int) $raw['signature_id'] : 0;
			if ( $sid > 0 && class_exists( '\WordPressistic\Memberistic\Waivers\Waiver_Service' ) ) {
				$sig = Waiver_Service::get_signature( $sid );
				if ( $sig && ! empty( $sig['attachment_id'] ) && class_exists( '\WordPressistic\Memberistic\Waivers\Documents' ) ) {
					return Documents::download_url( (int) $sig['attachment_id'] );
				}
			}
		}
		return '';
	}

	/** Nonced, staff-only streaming URL for an archive row's document. */
	public static function stream_url( $id ) {
		$id = (int) $id;
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'memberistic_waiver_pdf',
					'id'     => $id,
				),
				admin_url( 'admin-post.php' )
			),
			'memberistic_waiver_pdf_' . $id
		);
	}

	/** Aggregate stats for the admin screen. */
	public static function stats() {
		global $wpdb;
		$table = self::table();
		return array(
			'total'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ), // phpcs:ignore
			'current'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE is_current = 1" ), // phpcs:ignore
			'with_pdf'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE attachment_id IS NOT NULL" ), // phpcs:ignore
			'matched'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE matched_user_id IS NOT NULL" ), // phpcs:ignore
		);
	}
}
