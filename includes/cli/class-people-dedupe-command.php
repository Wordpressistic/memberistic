<?php
/**
 * WP-CLI command: preview (and optionally apply) the memberistic_people
 * email dedupe that the 1.12.0 migration runs automatically on upgrade
 * (G2A-CRIT-004). Lets an operator see exactly what a pending upgrade
 * will do to production data before it happens, on demand.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic\CLI;

use WordPressistic\Memberistic\Database\People_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
final class People_Dedupe_Command {

	public static function register() {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}
		\WP_CLI::add_command( 'memberistic people-dedupe-audit', array( self::class, 'run' ) );
	}

	/**
	 * Preview (and optionally apply) the memberistic_people email dedupe.
	 *
	 * memberistic_people.email has no uniqueness enforcement before 1.12.0,
	 * so get_by_email() can silently resolve to whichever duplicate row
	 * has the highest id (G2A-CRIT-004). This command groups rows by
	 * email, reports every duplicate group, and — with --apply — clears
	 * the email on every row in a group except the one with the highest
	 * id (the row get_by_email() already resolves to today, so nothing
	 * observable changes for existing lookups). No person row is ever
	 * deleted and no field besides email is touched; every clear is
	 * logged to the Activity Repository against that person.
	 *
	 * Dry-run is the default. The 1.12.0 migration calls the exact same
	 * `People_Repository::dedupe_by_email( true )` automatically the next
	 * time wp-admin loads after upgrade — this command exists so an
	 * operator can see the report first, on their own schedule.
	 *
	 * ## OPTIONS
	 *
	 * [--apply]
	 * : Actually clear the losing duplicates' email and log each change.
	 * Without this flag the command only reports what it would do.
	 *
	 * [--batch-size=<n>]
	 * : Duplicate-email groups written per DB transaction when --apply is
	 * used. Default 200.
	 *
	 * [--format=<format>]
	 * : Report output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Preview only — writes nothing.
	 *     wp memberistic people-dedupe-audit
	 *
	 *     # Apply: clear losing duplicates' email, log each change.
	 *     wp memberistic people-dedupe-audit --apply
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Associative args.
	 */
	public static function run( $args, $assoc_args ) {
		$apply      = ! empty( $assoc_args['apply'] );
		$batch_size = isset( $assoc_args['batch-size'] ) ? max( 1, min( 1000, (int) $assoc_args['batch-size'] ) ) : 200;
		$format     = isset( $assoc_args['format'] ) ? sanitize_key( (string) $assoc_args['format'] ) : 'table';

		\WP_CLI::log( $apply ? 'Applying dedupe (writes will be logged to the Activity feed)...' : 'Dry run — pass --apply to actually write changes.' );

		$report = People_Repository::dedupe_by_email( $apply, $batch_size );

		\WP_CLI::log( '' );
		\WP_CLI::log( sprintf( 'Empty-string emails %s NULL: %d', $apply ? 'normalized to' : 'that would be normalized to', $report['normalized_empty'] ) );
		\WP_CLI::log( sprintf( 'Duplicate email groups found: %d', $report['duplicate_groups'] ) );
		\WP_CLI::log( sprintf( 'Rows %s: %d', $apply ? 'cleared' : 'that would be cleared', $report['rows_cleared'] ) );
		\WP_CLI::log( '' );

		if ( empty( $report['groups'] ) ) {
			\WP_CLI::success( 'No duplicate emails found. The email column is safe to convert to UNIQUE.' );
			return;
		}

		if ( 'json' === $format ) {
			\WP_CLI::log( wp_json_encode( $report['groups'], JSON_PRETTY_PRINT ) );
		} else {
			$rows = array();
			foreach ( $report['groups'] as $group ) {
				$rows[] = array(
					'email'                  => $group['email'],
					'kept_person_id'         => $group['keep_id'],
					'cleared_person_ids'     => implode( ',', $group['cleared_ids'] ),
					'cleared_count'          => count( $group['cleared_ids'] ),
				);
			}
			\WP_CLI\Utils\format_items( 'table', $rows, array( 'email', 'kept_person_id', 'cleared_person_ids', 'cleared_count' ) );
		}

		\WP_CLI::log( '' );
		if ( $apply ) {
			\WP_CLI::success( sprintf( 'Cleared the email on %d duplicate row(s) across %d group(s). The email column is now safe to convert to UNIQUE — the 1.12.0 migration will do that automatically.', $report['rows_cleared'], $report['duplicate_groups'] ) );
		} else {
			\WP_CLI::warning( sprintf( '%d duplicate group(s) found. Re-run with --apply before upgrading if you want to review this exact report first — otherwise the 1.12.0 migration applies the identical dedupe automatically on the next wp-admin load after upgrade.', $report['duplicate_groups'] ) );
		}
	}
}
