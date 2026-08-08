<?php
/**
 * Waiver importer — ingests an Ottertext / OtterWaiver CSV export into the
 * waivers archive: normalizes each row, de-dupes to one current waiver per
 * person, mirrors the signed PDF into protected storage (so it survives
 * Ottertext cancellation), and stamps matching members as waiver-signed.
 *
 * Run on the LIVE site (it needs the member DB + network access):
 *   - WP-CLI:  wp memberistic import-waivers /path/Range_Waiver.csv [--no-pdf] [--fresh] [--limit=N]
 *   - Admin:   Memberistic → Waivers → Import (CSV upload)
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic\Waivers;

use WordPressistic\Memberistic\Database\People_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Waiver_Import {

	/** Protected upload subdir (no public access — see harden_dir()). */
	const SUBDIR = 'memberistic-waivers';

	/**
	 * Dry-run match report — reads the CSV, de-dupes to one record per person,
	 * and tallies how many already have a WordPress account / active member
	 * record. Makes ZERO changes (no inserts, no PDF downloads, no stamping).
	 * Run on the live site to see the overlap before importing.
	 *
	 * @param string $path Absolute path to the CSV.
	 * @return array|\WP_Error
	 */
	public static function report( $path ) {
		if ( ! is_readable( $path ) ) {
			return new \WP_Error( 'memberistic_csv_unreadable', __( 'CSV file not found or unreadable.', 'memberistic' ) );
		}
		$rows = self::read_csv( $path );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		$groups = array();
		foreach ( $rows as $r ) {
			$norm = self::normalize_row( $r );
			if ( null === $norm ) {
				continue;
			}
			$groups[ $norm['dedupe_key'] ][] = $norm;
		}

		$stats = array(
			'rows'           => count( $rows ),
			'people'         => count( $groups ),
			'with_email'     => 0,
			'with_pdf_url'   => 0,
			'matched_users'  => 0, // has a WP account
			'matched_members'=> 0, // has a Memberistic person/member record
			'unmatched'      => 0, // walk-ins / guests not in the system
		);
		$has_people_repo = class_exists( 'WordPressistic\\Memberistic\\Database\\People_Repository' );

		foreach ( $groups as $entries ) {
			$e = $entries[0];
			if ( '' !== $e['email'] ) {
				$stats['with_email']++;
			}
			if ( '' !== $e['external_url'] ) {
				$stats['with_pdf_url']++;
			}
			$user = ( '' !== $e['email'] ) ? get_user_by( 'email', $e['email'] ) : false;
			$member = false;
			if ( '' !== $e['email'] && $has_people_repo ) {
				$person = People_Repository::get_by_email( $e['email'] );
				$member = ! empty( $person['id'] );
			}
			if ( $member ) {
				$stats['matched_members']++;
				$stats['matched_users']++;
			} elseif ( $user ) {
				$stats['matched_users']++;
			} else {
				$stats['unmatched']++;
			}
		}

		$stats['match_rate_users']   = $stats['people'] ? round( 100 * $stats['matched_users'] / $stats['people'], 1 ) : 0;
		$stats['match_rate_members'] = $stats['people'] ? round( 100 * $stats['matched_members'] / $stats['people'], 1 ) : 0;
		return $stats;
	}

	/**
	 * Import a CSV file.
	 *
	 * @param string $path    Absolute path to the CSV.
	 * @param array  $opts     download_pdfs (bool), fresh (bool), limit (int), match_members (bool).
	 * @param callable|null $progress  Optional fn( $done, $total ) for CLI ticks.
	 * @return array Stats / WP_Error.
	 */
	public static function import_file( $path, array $opts = array(), $progress = null ) {
		if ( ! is_readable( $path ) ) {
			return new \WP_Error( 'memberistic_csv_unreadable', __( 'CSV file not found or unreadable.', 'memberistic' ) );
		}
		$opts = wp_parse_args( $opts, array(
			'download_pdfs' => true,
			'defer_pdfs'    => false,
			'fresh'         => false,
			'limit'         => 0,
			'match_members' => true,
		) );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		global $wpdb;
		$table = Waivers_Archive::table();
		if ( $opts['fresh'] ) {
			$wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore
		}

		$rows = self::read_csv( $path );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		// Group by person so repeat signings collapse to one current waiver.
		$groups = array();
		foreach ( $rows as $r ) {
			$norm = self::normalize_row( $r );
			if ( null === $norm ) {
				continue;
			}
			$groups[ $norm['dedupe_key'] ][] = $norm;
		}

		$batch = substr( md5( uniqid( 'mwi', true ) ), 0, 12 );
		$stats = array( 'rows' => count( $rows ), 'people' => count( $groups ), 'inserted' => 0, 'skipped' => 0, 'pdfs' => 0, 'pdf_failed' => 0, 'members_matched' => 0 );
		$done  = 0;
		$limit = (int) $opts['limit'];

		foreach ( $groups as $key => $entries ) {
			// Latest signing first.
			usort( $entries, static function ( $a, $b ) {
				return strcmp( (string) $b['signed_at'], (string) $a['signed_at'] );
			} );

			$current_id = 0;
			foreach ( $entries as $i => $entry ) {
				if ( ! $opts['fresh'] && self::already_imported( $entry['external_url'] ) ) {
					$stats['skipped']++;
					continue;
				}
				$is_current = ( 0 === $i ) ? 1 : 0;

				// Mirror the PDF for the current (latest) waiver only — that's
				// the one staff will view; older revisions keep the source URL.
				$attachment_id = null;
				$local_path    = '';
				if ( $is_current && $opts['download_pdfs'] && empty( $opts['defer_pdfs'] ) && $entry['external_url'] ) {
					$mirror = self::mirror_pdf( $entry['external_url'], $entry );
					if ( is_wp_error( $mirror ) ) {
						$stats['pdf_failed']++;
					} else {
						$local_path = $mirror;
						$stats['pdfs']++;
					}
				}

				$matched_user = $opts['match_members'] ? self::match_member( $entry ) : 0;

				$id = Waivers_Archive::insert( array(
					'first_name'       => $entry['first_name'],
					'last_name'        => $entry['last_name'],
					'email'            => $entry['email'],
					'phone'            => $entry['phone'],
					'dob'              => $entry['dob'],
					'signed_at'        => $entry['signed_at'],
					'source'           => $entry['source'],
					'participant_type' => $entry['participant_type'],
					'external_url'     => $entry['external_url'],
					'local_path'       => $local_path,
					'minor_name'       => $entry['minor_name'],
					'minor_age'        => $entry['minor_age'],
					'emergency_name'   => $entry['emergency_name'],
					'emergency_phone'  => $entry['emergency_phone'],
					'matched_user_id'  => $matched_user ?: null,
					'is_current'       => $is_current,
					'dedupe_key'       => $entry['dedupe_key'],
					'raw_json'         => wp_json_encode( $entry['raw'] ),
					'import_batch'     => $batch,
				) );

				if ( $id ) {
					$stats['inserted']++;
					if ( $is_current ) {
						$current_id = $id;
						if ( $matched_user ) {
							self::stamp_member( $matched_user, $entry );
							$stats['members_matched']++;
						}
					}
				}
			}

			if ( $current_id ) {
				Waivers_Archive::set_current( $key, $current_id );
			}

			$done++;
			if ( $progress ) {
				call_user_func( $progress, $done, count( $groups ) );
			}
			if ( $limit && $done >= $limit ) {
				break;
			}
		}

		// Deferred mode (admin web upload): mirror the PDFs in the background so
		// the request can't time out downloading ~1,900 files. CLI runs them
		// inline (no web timeout).
		if ( $opts['download_pdfs'] && ! empty( $opts['defer_pdfs'] ) ) {
			$stats['pdfs_queued'] = self::count_pending_pdfs();
			self::schedule_mirror();
		}

		return $stats;
	}

	/* ------------------------------------------------------------------ */
	/* Background PDF mirroring (cron-driven, batched)                    */
	/* ------------------------------------------------------------------ */

	const CRON_MIRROR = 'memberistic_mirror_waiver_pdfs';

	/** Hook the cron worker. Called from the plugin bootstrap. */
	public static function register() {
		add_action( self::CRON_MIRROR, array( __CLASS__, 'mirror_pending' ) );
	}

	/** Current waivers that still need their PDF mirrored. */
	public static function count_pending_pdfs() {
		global $wpdb;
		$table = Waivers_Archive::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE is_current = 1 AND ( local_path = '' OR local_path IS NULL ) AND external_url <> ''" );
	}

	/** Queue the background mirror worker if not already scheduled. */
	public static function schedule_mirror() {
		if ( ! wp_next_scheduled( self::CRON_MIRROR ) ) {
			wp_schedule_single_event( time() + 20, self::CRON_MIRROR );
		}
	}

	/**
	 * Mirror a batch of pending PDFs, then reschedule until none remain.
	 * Small batch keeps each cron run well under any time limit.
	 */
	public static function mirror_pending( $batch = 20 ) {
		global $wpdb;
		$table = Waivers_Archive::table();
		$batch = max( 1, (int) $batch );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, first_name, last_name, external_url FROM {$table} WHERE is_current = 1 AND ( local_path = '' OR local_path IS NULL ) AND external_url <> '' LIMIT %d", $batch ), ARRAY_A );
		if ( ! $rows ) {
			return;
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		foreach ( $rows as $row ) {
			$mirror = self::mirror_pdf( $row['external_url'], array( 'first_name' => $row['first_name'], 'last_name' => $row['last_name'] ) );
			$local  = is_wp_error( $mirror ) ? 'FAILED' : $mirror; // mark failures so we don't loop forever
			$wpdb->update( $table, array( 'local_path' => $local ), array( 'id' => (int) $row['id'] ) );
		}
		// More to go? Reschedule the next batch.
		if ( self::count_pending_pdfs() > 0 ) {
			wp_schedule_single_event( time() + 30, self::CRON_MIRROR );
		}
	}

	/* ------------------------------------------------------------------ */
	/* CSV + normalization                                                */
	/* ------------------------------------------------------------------ */

	private static function read_csv( $path ) {
		$rows   = array();
		$handle = fopen( $path, 'r' );
		if ( ! $handle ) {
			return new \WP_Error( 'memberistic_csv_open', __( 'Could not open the CSV file.', 'memberistic' ) );
		}
		$header = fgetcsv( $handle );
		if ( ! $header ) {
			fclose( $handle );
			return new \WP_Error( 'memberistic_csv_empty', __( 'The CSV file is empty.', 'memberistic' ) );
		}
		// Strip a UTF-8 BOM from the first header cell if present.
		$header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $header[0] );
		while ( ( $line = fgetcsv( $handle ) ) !== false ) {
			if ( count( $line ) === 1 && '' === trim( (string) $line[0] ) ) {
				continue;
			}
			$rows[] = array_combine( $header, array_pad( $line, count( $header ), '' ) );
		}
		fclose( $handle );
		return $rows;
	}

	/**
	 * Normalize one CSV row → archive fields. Returns null for unusable rows.
	 */
	private static function normalize_row( array $r ) {
		$first = sanitize_text_field( trim( (string) self::col( $r, 'Adult First Name' ) ) );
		$last  = sanitize_text_field( trim( (string) self::col( $r, 'Adult Last Name' ) ) );
		$email = sanitize_email( strtolower( trim( (string) self::col( $r, 'Email' ) ) ) );
		if ( '' === $first && '' === $last && '' === $email ) {
			return null;
		}
		$dob       = self::parse_date( self::col( $r, 'Adult Birthday' ) );
		$signed_at = self::parse_datetime( self::col( $r, 'Date' ) );

		return array(
			'first_name'       => $first,
			'last_name'        => $last,
			'email'            => $email,
			'phone'            => sanitize_text_field( trim( (string) self::col( $r, 'Phone' ) ) ),
			'dob'              => $dob,
			'signed_at'        => $signed_at ?: current_time( 'mysql' ),
			'source'           => sanitize_key( (string) self::col( $r, 'Source' ) ),
			'participant_type' => sanitize_text_field( (string) self::col( $r, 'Participant Type' ) ),
			'external_url'     => esc_url_raw( trim( (string) self::col( $r, 'Document URL' ) ) ),
			'minor_name'       => sanitize_text_field( (string) self::col( $r, 'Minor Name' ) ),
			'minor_age'        => sanitize_text_field( (string) self::col( $r, 'Minor Age' ) ),
			'emergency_name'   => sanitize_text_field( (string) self::col( $r, 'Emergency Contact Name' ) ),
			'emergency_phone'  => sanitize_text_field( (string) self::col( $r, 'Emergency Contact Phone' ) ),
			'dedupe_key'       => Waivers_Archive::dedupe_key( $email, $first, $last, $dob ),
			'raw'              => $r,
		);
	}

	private static function col( array $r, $key ) {
		return isset( $r[ $key ] ) ? $r[ $key ] : '';
	}

	/** MM/DD/YYYY (Ottertext) → Y-m-d, or null. */
	private static function parse_date( $v ) {
		$v = trim( (string) $v );
		if ( '' === $v ) {
			return null;
		}
		$dt = \DateTime::createFromFormat( 'm/d/Y', $v );
		if ( $dt && $dt->format( 'm/d/Y' ) === $v ) {
			return $dt->format( 'Y-m-d' );
		}
		$ts = strtotime( $v );
		return $ts ? gmdate( 'Y-m-d', $ts ) : null;
	}

	/** ISO 8601 (UTC) → site-local mysql datetime, or null. */
	private static function parse_datetime( $v ) {
		$v  = trim( (string) $v );
		if ( '' === $v ) {
			return null;
		}
		$ts = strtotime( $v );
		if ( ! $ts ) {
			return null;
		}
		return get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $ts ) );
	}

	private static function already_imported( $external_url ) {
		if ( '' === $external_url ) {
			return false;
		}
		global $wpdb;
		$table = Waivers_Archive::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE external_url = %s LIMIT 1", $external_url ) );
	}

	/* ------------------------------------------------------------------ */
	/* PDF mirroring (protected storage)                                  */
	/* ------------------------------------------------------------------ */

	/**
	 * Download a waiver PDF into protected uploads storage.
	 *
	 * @return string|\WP_Error  Path relative to the uploads basedir, or error.
	 */
	private static function mirror_pdf( $url, array $entry ) {
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$dir = self::ensure_dir();
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}
		$tmp = download_url( $url, 30 );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}
		$name  = sanitize_file_name( strtolower( $entry['last_name'] . '-' . $entry['first_name'] ) );
		$name  = ( $name ? $name : 'waiver' ) . '-' . substr( md5( $url ), 0, 10 ) . '.pdf';
		$dest  = trailingslashit( $dir['path'] ) . $name;
		if ( ! @copy( $tmp, $dest ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@unlink( $tmp ); // phpcs:ignore
			return new \WP_Error( 'memberistic_pdf_copy', __( 'Could not save the PDF.', 'memberistic' ) );
		}
		@unlink( $tmp ); // phpcs:ignore
		return ltrim( str_replace( wp_upload_dir()['basedir'], '', $dest ), '/\\' );
	}

	/** Create + harden the protected waivers directory. */
	private static function ensure_dir() {
		$up   = wp_upload_dir();
		$path = trailingslashit( $up['basedir'] ) . self::SUBDIR;
		if ( ! wp_mkdir_p( $path ) ) {
			return new \WP_Error( 'memberistic_dir', __( 'Could not create the waivers storage directory.', 'memberistic' ) );
		}
		// Block direct web access to the PII PDFs (Apache + a no-index).
		$ht = trailingslashit( $path ) . '.htaccess';
		if ( ! file_exists( $ht ) ) {
			file_put_contents( $ht, "Require all denied\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n" ); // phpcs:ignore
		}
		$idx = trailingslashit( $path ) . 'index.php';
		if ( ! file_exists( $idx ) ) {
			file_put_contents( $idx, "<?php // Silence is golden.\n" ); // phpcs:ignore
		}
		return array( 'path' => $path );
	}

	/* ------------------------------------------------------------------ */
	/* Member matching                                                    */
	/* ------------------------------------------------------------------ */

	/** @return int Matched WP user id, or 0. */
	private static function match_member( array $entry ) {
		if ( '' !== $entry['email'] ) {
			$user = get_user_by( 'email', $entry['email'] );
			if ( $user ) {
				return (int) $user->ID;
			}
		}
		return 0;
	}

	/** Stamp a matched member's person record as waiver-signed. */
	private static function stamp_member( $user_id, array $entry ) {
		$person = People_Repository::get_by_email( $entry['email'] );
		if ( ! $person || empty( $person['id'] ) ) {
			return;
		}
		$update = array( 'waiver_status' => 'signed' );
		if ( ! empty( $entry['signed_at'] ) ) {
			$update['waiver_signed_at'] = $entry['signed_at'];
		}
		People_Repository::update( (int) $person['id'], $update );
	}

	/* ------------------------------------------------------------------ */
	/* WP-CLI                                                             */
	/* ------------------------------------------------------------------ */

	public static function register_cli() {
		if ( ! defined( 'WP_CLI' ) || ! \WP_CLI ) {
			return;
		}
		\WP_CLI::add_command( 'memberistic import-waivers', function ( $args, $assoc ) {
			$path = isset( $args[0] ) ? $args[0] : '';
			if ( '' === $path ) {
				\WP_CLI::error( 'Usage: wp memberistic import-waivers <file.csv> [--dry-run] [--no-pdf] [--fresh] [--limit=N]' );
			}
			// --dry-run: read-only match report, no changes.
			if ( ! empty( $assoc['dry-run'] ) ) {
				$rep = self::report( $path );
				if ( is_wp_error( $rep ) ) {
					\WP_CLI::error( $rep->get_error_message() );
				}
				\WP_CLI::log( '' );
				\WP_CLI::log( '  Waiver match-rate report (no changes made)' );
				\WP_CLI::log( '  ─────────────────────────────────────────' );
				\WP_CLI::log( sprintf( '  CSV rows ............ %d', $rep['rows'] ) );
				\WP_CLI::log( sprintf( '  Unique people ....... %d', $rep['people'] ) );
				\WP_CLI::log( sprintf( '  With email .......... %d', $rep['with_email'] ) );
				\WP_CLI::log( sprintf( '  With PDF link ....... %d', $rep['with_pdf_url'] ) );
				\WP_CLI::log( sprintf( '  Have a WP account ... %d  (%s%%)', $rep['matched_users'], $rep['match_rate_users'] ) );
				\WP_CLI::log( sprintf( '  Are members ......... %d  (%s%%)', $rep['matched_members'], $rep['match_rate_members'] ) );
				\WP_CLI::log( sprintf( '  Walk-ins / guests ... %d', $rep['unmatched'] ) );
				\WP_CLI::log( '' );
				\WP_CLI::success( 'Report complete. Re-run without --dry-run to import.' );
				return;
			}
			$res = self::import_file( $path, array(
				'download_pdfs' => empty( $assoc['no-pdf'] ),
				'fresh'         => ! empty( $assoc['fresh'] ),
				'limit'         => isset( $assoc['limit'] ) ? (int) $assoc['limit'] : 0,
			), function ( $done, $total ) {
				if ( 0 === $done % 100 || $done === $total ) {
					\WP_CLI::log( "  …{$done}/{$total} people" );
				}
			} );
			if ( is_wp_error( $res ) ) {
				\WP_CLI::error( $res->get_error_message() );
			}
			\WP_CLI::success( sprintf(
				'Imported %d rows for %d people (%d new, %d skipped). PDFs: %d saved, %d failed. Members matched: %d.',
				$res['rows'], $res['people'], $res['inserted'], $res['skipped'], $res['pdfs'], $res['pdf_failed'], $res['members_matched']
			) );
		} );
	}
}
