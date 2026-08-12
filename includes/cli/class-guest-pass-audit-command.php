<?php
/**
 * WP-CLI Guest Pass audit / cleanup command.
 *
 * Classifies every membership on the hidden 'guest-pass' plan into
 * auto_created / legitimate / ambiguous buckets and (with --apply)
 * expires the auto-created rows the removed booking hook left behind.
 * Fully documented in docs/guest-pass-audit.md.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic\CLI;

use WordPressistic\Memberistic\Database\Activity_Repository;
use WordPressistic\Memberistic\Database\Memberships_Repository;
use WordPressistic\Memberistic\Database\Plans_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Guest_Pass_Audit_Command {

	const PLAN_SLUG = 'guest-pass';

	/** Source tag written to memberistic_logs for every mutation. */
	const LOG_SOURCE = 'guest_pass_audit';

	/** Idempotency marker appended to the membership notes on expire. */
	const PROCESSED_FLAG = 'memberistic_gpa_processed';

	/** Marker left in notes after a rollback restore. */
	const ROLLED_BACK_FLAG = 'memberistic_gpa_rolled_back';

	/** Booking correlation window: membership created within ±10 minutes of a booking row. */
	const BOOKING_WINDOW_MINUTES = 10;

	/**
	 * User meta added to auto-created guests on --apply.
	 *
	 * Namespaced to this plugin so the tag is Memberistic's own record of
	 * what it reclassified, and cannot collide with, or be mistaken for,
	 * a segment value owned by another plugin.
	 */
	const SEGMENT_META_KEY   = '_memberistic_customer_segment';
	const SEGMENT_META_VALUE = 'auto_created_guest';

	public static function register() {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}
		\WP_CLI::add_command( 'memberistic guest-pass-audit', array( self::class, 'run' ) );
	}

	/**
	 * Audit Guest Pass memberships auto-created from bookings.
	 *
	 * Classifies every membership on the 'guest-pass' plan into buckets
	 * (auto_created / legitimate / ambiguous / already_processed) with a
	 * confidence score and per-record evidence. Dry-run is the DEFAULT:
	 * nothing is written to the database unless --apply is passed.
	 *
	 * With --apply, `auto_created` records ONLY are expired (status set to
	 * 'expired', row never deleted), a note + activity row + audit-log row
	 * are written, and the primary user is tagged with the
	 * _memberistic_customer_segment=auto_created_guest user meta. WP users,
	 * bookings, payments, waiver records and QR/verification data are left
	 * untouched.
	 *
	 * ## OPTIONS
	 *
	 * [--apply]
	 * : Actually perform mutations. Without this flag the command runs in
	 * dry-run mode and writes nothing to the database.
	 *
	 * [--rollback]
	 * : Restore memberships previously expired by this command. Reads the
	 * audit-log rows (source=guest_pass_audit) this command wrote and
	 * restores each record's previous status. Combine with --apply to
	 * execute; without --apply it is a dry-run preview of the rollback.
	 *
	 * [--format=<format>]
	 * : Report file format written to the uploads directory.
	 * ---
	 * default: csv
	 * options:
	 *   - csv
	 *   - json
	 * ---
	 *
	 * [--batch-size=<n>]
	 * : Rows processed (and, with --apply, wrapped in one DB transaction)
	 * per batch. Default 100.
	 *
	 * [--resume-from=<membership_id>]
	 * : Resume processing at this membership id (inclusive). Records are
	 * always processed in ascending membership-id order, so a failed run
	 * can be resumed from the id reported in the failure message.
	 *
	 * ## EXAMPLES
	 *
	 *     # Preview (dry-run is the default), CSV report echoed at the end.
	 *     wp memberistic guest-pass-audit
	 *
	 *     # Apply: expire auto_created guest passes, JSON report.
	 *     wp memberistic guest-pass-audit --apply --format=json
	 *
	 *     # Resume an interrupted apply run.
	 *     wp memberistic guest-pass-audit --apply --resume-from=1234
	 *
	 *     # Preview then execute a rollback of a previous apply run.
	 *     wp memberistic guest-pass-audit --rollback
	 *     wp memberistic guest-pass-audit --rollback --apply
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Associative args.
	 */
	public static function run( $args, $assoc_args ) {
		$apply       = ! empty( $assoc_args['apply'] );
		$dry_run     = ! $apply; // Dry-run is the default; mutations require an explicit --apply.
		$format      = isset( $assoc_args['format'] ) ? sanitize_key( (string) $assoc_args['format'] ) : 'csv';
		$batch_size  = isset( $assoc_args['batch-size'] ) ? max( 1, min( 1000, (int) $assoc_args['batch-size'] ) ) : 100;
		$resume_from = isset( $assoc_args['resume-from'] ) ? max( 0, (int) $assoc_args['resume-from'] ) : 0;

		if ( ! in_array( $format, array( 'csv', 'json' ), true ) ) {
			\WP_CLI::error( 'Invalid --format. Use csv or json.' );
		}

		if ( ! empty( $assoc_args['rollback'] ) ) {
			self::run_rollback( $dry_run, $format, $batch_size, $resume_from );
			return;
		}

		self::run_audit( $dry_run, $format, $batch_size, $resume_from );
	}

	/**
	 * Main audit / expire pass.
	 *
	 * @param bool   $dry_run     True when no mutations may happen.
	 * @param string $format      csv|json.
	 * @param int    $batch_size  Rows per batch/transaction.
	 * @param int    $resume_from First membership id to process (inclusive).
	 */
	private static function run_audit( $dry_run, $format, $batch_size, $resume_from ) {
		global $wpdb;

		$plan = Plans_Repository::get_by_slug( self::PLAN_SLUG );
		if ( ! is_array( $plan ) || empty( $plan['id'] ) ) {
			\WP_CLI::warning( sprintf( "No plan with slug '%s' found — nothing to audit.", self::PLAN_SLUG ) );
			return;
		}
		$plan_id         = (int) $plan['id'];
		$plan_price_zero = ( 0.0 === (float) $plan['monthly_price'] && 0.0 === (float) $plan['annual_price'] );

		\WP_CLI::line( $dry_run ? 'Mode: DRY-RUN (default — pass --apply to mutate)' : 'Mode: APPLY' );
		\WP_CLI::line( sprintf( 'Plan: %s (id=%d, monthly=%s, annual=%s)', (string) $plan['name'], $plan_id, (string) $plan['monthly_price'], (string) $plan['annual_price'] ) );

		$before_by_status = self::status_counts( $plan_id );

		$m_table  = Memberships_Repository::table();
		$records  = array();
		$buckets  = array(
			'auto_created'      => 0,
			'legitimate'        => 0,
			'ambiguous'         => 0,
			'already_processed' => 0,
		);
		$applied  = 0;
		$cursor   = max( 0, $resume_from - 1 ); // `id > cursor` == `id >= resume_from`.

		while ( true ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$m_table} WHERE plan_id = %d AND id > %d ORDER BY id ASC LIMIT %d",
					$plan_id,
					$cursor,
					$batch_size
				),
				ARRAY_A
			) ?: array();

			if ( empty( $rows ) ) {
				break;
			}

			$batch_first_id = (int) $rows[0]['id'];
			$in_transaction = false;

			if ( ! $dry_run ) {
				// Each batch is atomic: all rows in the batch expire together or
				// not at all, so an interrupted run can resume cleanly.
				$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$in_transaction = true;
			}

			foreach ( $rows as $row ) {
				$cursor = (int) $row['id'];
				$record = self::classify( $row, $plan_price_zero );

				$buckets[ $record['bucket'] ]++;

				if ( 'auto_created' === $record['bucket'] ) {
					if ( $dry_run ) {
						$record['action'] = 'would_expire';
					} else {
						$ok = self::expire_membership( $row, $record );
						if ( ! $ok ) {
							$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
							\WP_CLI::error( sprintf(
								'Failed to expire membership %d — batch rolled back. Re-run with --apply --resume-from=%d after investigating. Last DB error: %s',
								(int) $row['id'],
								$batch_first_id,
								(string) $wpdb->last_error
							) );
						}
						$record['action']       = 'expired';
						$record['status_after'] = 'expired';
						$applied++;
					}
				}

				$records[] = $record;
			}

			if ( $in_transaction ) {
				$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}

			if ( count( $rows ) < $batch_size ) {
				break;
			}
		}

		$after_by_status = self::status_counts( $plan_id );

		$report_path = self::write_report( $records, $format, $dry_run ? 'dry-run' : 'apply', array(
			'mode'             => $dry_run ? 'dry-run' : 'apply',
			'plan_id'          => $plan_id,
			'buckets'          => $buckets,
			'applied'          => $applied,
			'status_before'    => $before_by_status,
			'status_after'     => $after_by_status,
		) );

		// ------- Summary output -------
		\WP_CLI::line( '' );
		\WP_CLI::line( 'Classification summary:' );
		$bucket_items = array();
		foreach ( $buckets as $bucket => $count ) {
			$bucket_items[] = array(
				'bucket' => $bucket,
				'count'  => $count,
			);
		}
		\WP_CLI\Utils\format_items( 'table', $bucket_items, array( 'bucket', 'count' ) );

		\WP_CLI::line( '' );
		\WP_CLI::line( 'Membership status counts (guest-pass plan), before / after:' );
		$status_items = array();
		foreach ( array_unique( array_merge( array_keys( $before_by_status ), array_keys( $after_by_status ) ) ) as $status ) {
			$status_items[] = array(
				'status' => $status,
				'before' => isset( $before_by_status[ $status ] ) ? $before_by_status[ $status ] : 0,
				'after'  => isset( $after_by_status[ $status ] ) ? $after_by_status[ $status ] : 0,
			);
		}
		\WP_CLI\Utils\format_items( 'table', $status_items, array( 'status', 'before', 'after' ) );

		\WP_CLI::line( '' );
		\WP_CLI::line( 'Report written to: ' . $report_path );

		if ( $dry_run ) {
			\WP_CLI::success( sprintf(
				'Dry-run complete. %d auto_created record(s) WOULD be expired. Review the report, then re-run with --apply.',
				$buckets['auto_created']
			) );
		} else {
			\WP_CLI::success( sprintf( 'Apply complete. %d membership(s) expired.', $applied ) );
		}

		\WP_CLI::line( '' );
		\WP_CLI::line( 'Rollback: every change is journaled to ' . $wpdb->prefix . 'memberistic_logs (source=' . self::LOG_SOURCE . ') with the previous status per record.' );
		\WP_CLI::line( 'To undo an apply run:  wp memberistic guest-pass-audit --rollback          (preview)' );
		\WP_CLI::line( '                       wp memberistic guest-pass-audit --rollback --apply  (restore previous statuses)' );
	}

	/**
	 * Classify one guest-pass membership row.
	 *
	 * Buckets:
	 *  - already_processed: carries the audit note/flag from a previous apply run.
	 *  - legitimate: successful payment OR plan price > 0 OR payment_source not
	 *    'guest_pass' OR staff-issued activity history. Never touched.
	 *  - auto_created: payment_source='guest_pass' AND no successful payment AND
	 *    zero-price plan AND (booking within ±10 min OR created_by empty).
	 *  - ambiguous: everything else. NEVER auto-modified.
	 *
	 * @param array $row             Membership row.
	 * @param bool  $plan_price_zero Whether the guest-pass plan is priced at 0.
	 * @return array Record with bucket, confidence and evidence.
	 */
	private static function classify( $row, $plan_price_zero ) {
		global $wpdb;

		$id       = (int) $row['id'];
		$evidence = array();
		$email    = self::primary_email( $row );

		$record = array(
			'membership_id'   => $id,
			'membership_uuid' => (string) $row['membership_uuid'],
			'primary_user_id' => (int) $row['primary_user_id'],
			'email'           => $email,
			'status_before'   => (string) $row['status'],
			'status_after'    => (string) $row['status'],
			'bucket'          => 'ambiguous',
			'confidence'      => 0.3,
			'evidence'        => array(),
			'action'          => 'none',
		);

		// ---- Idempotency: already expired-with-audit-note by a previous run.
		if ( false !== strpos( (string) $row['notes'], self::PROCESSED_FLAG ) ) {
			$record['bucket']     = 'already_processed';
			$record['confidence'] = 1.0;
			$record['evidence']   = array( 'notes carry the ' . self::PROCESSED_FLAG . ' audit flag' );
			$record['action']     = 'skipped';
			return $record;
		}

		// ---- Signals.
		$payments_table = $wpdb->prefix . 'memberistic_payments';
		$paid_count     = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$payments_table} WHERE membership_id = %d AND status IN ('completed','paid','succeeded')",
			$id
		) );
		$has_payment = $paid_count > 0;

		$payment_source  = (string) $row['payment_source'];
		$source_is_guest = ( 'guest_pass' === $payment_source );
		$created_by      = (int) $row['created_by'];
		$creator_empty   = ( $created_by <= 0 );

		$staff_issued = self::has_staff_activity( $id );

		$booking_match = false;
		$bookings      = self::bookings_table();
		if ( '' !== $bookings && '' !== $email ) {
			$booking_id = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$bookings}
				 WHERE customer_email = %s
				 AND created_at BETWEEN DATE_SUB(%s, INTERVAL %d MINUTE) AND DATE_ADD(%s, INTERVAL %d MINUTE)
				 LIMIT 1",
				$email,
				(string) $row['created_at'],
				self::BOOKING_WINDOW_MINUTES,
				(string) $row['created_at'],
				self::BOOKING_WINDOW_MINUTES
			) );
			if ( $booking_id ) {
				$booking_match = true;
				$evidence[]    = sprintf( 'booking #%d for %s created within ±%d min of membership', (int) $booking_id, $email, self::BOOKING_WINDOW_MINUTES );
			}
		}

		// ---- Legitimate signals (evaluated first — any one of these wins,
		// so a record staff intentionally issued is never auto-expired).
		$legit_signals = 0;
		if ( $has_payment ) {
			$legit_signals++;
			$evidence[] = sprintf( '%d successful payment row(s) in memberistic_payments', $paid_count );
		}
		if ( ! $plan_price_zero ) {
			$legit_signals++;
			$evidence[] = 'guest-pass plan has a non-zero price';
		}
		if ( ! $source_is_guest ) {
			$legit_signals++;
			$evidence[] = sprintf( "payment_source is '%s', not 'guest_pass'", $payment_source );
		}
		if ( $staff_issued ) {
			$legit_signals++;
			$evidence[] = 'activity history contains rows created by a staff user (manually issued)';
		}

		if ( $legit_signals > 0 ) {
			$record['bucket']     = 'legitimate';
			$record['confidence'] = min( 0.95, 0.6 + ( 0.1 * $legit_signals ) );
			$record['evidence']   = $evidence;
			return $record;
		}

		// ---- Auto-created (high confidence): system-created from a booking.
		if ( $source_is_guest && ! $has_payment && $plan_price_zero && ( $booking_match || $creator_empty ) ) {
			$evidence[] = "payment_source is 'guest_pass'";
			$evidence[] = 'no successful row in memberistic_payments';
			$evidence[] = 'plan price is 0';
			if ( $creator_empty ) {
				$evidence[] = 'created_by is 0/NULL (system-created)';
			}
			$record['bucket']     = 'auto_created';
			$record['confidence'] = min( 0.99, 0.8 + ( $booking_match ? 0.1 : 0 ) + ( $creator_empty ? 0.05 : 0 ) );
			$record['evidence']   = $evidence;
			return $record;
		}

		// ---- Everything else: ambiguous, listed for manual review only.
		$evidence[] = 'did not meet auto_created criteria (no booking correlation and created_by set) and no legitimate signal';
		$record['evidence'] = $evidence;
		return $record;
	}

	/**
	 * Expire one auto_created membership (apply mode only). Caller wraps the
	 * batch in a transaction; a false return aborts and rolls back the batch.
	 *
	 * Deliberately writes via $wpdb (not change_status()) so no status-changed
	 * integrations (Stripe cancel propagation, POS sync) fire mid-transaction.
	 *
	 * @param array $row    Membership row (pre-mutation).
	 * @param array $record Classification record (for the audit context).
	 * @return bool
	 */
	private static function expire_membership( $row, $record ) {
		global $wpdb;

		$id          = (int) $row['id'];
		$prev_status = (string) $row['status'];
		$now         = current_time( 'mysql' );
		$date        = wp_date( 'Y-m-d' );

		$note  = sprintf( 'Expired by guest-pass-audit %s: auto-created from booking [%s]', $date, self::PROCESSED_FLAG );
		$notes = trim( (string) $row['notes'] );
		$notes = ( '' === $notes ) ? $note : $notes . "\n" . $note;

		$updated = $wpdb->update(
			Memberships_Repository::table(),
			array(
				'status'     => 'expired',
				'notes'      => $notes,
				'updated_at' => $now,
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
		if ( false === $updated ) {
			return false;
		}

		Activity_Repository::log( array(
			'membership_id'       => $id,
			'user_id'             => (int) $row['primary_user_id'],
			'activity_type'       => 'membership_expired',
			'title'               => 'Guest pass expired by audit',
			'description'         => sprintf( 'Expired by guest-pass-audit %s: auto-created from booking. Previous status: %s. [%s]', $date, $prev_status, self::PROCESSED_FLAG ),
			'related_object_type' => 'guest_pass_audit',
			'related_object_id'   => (string) $id,
			'created_by'          => 0,
		) );

		// Tag the WP user so marketing/reporting can target range guests.
		// The user, bookings, payments, waivers and QR data are untouched.
		$user_meta_set = false;
		$user_id       = (int) $row['primary_user_id'];
		if ( $user_id > 0 && get_user_by( 'id', $user_id ) ) {
			update_user_meta( $user_id, self::SEGMENT_META_KEY, self::SEGMENT_META_VALUE );
			$user_meta_set = true;
		}

		$logged = self::write_log( sprintf( 'Expired auto-created guest pass membership %d (previous status: %s)', $id, $prev_status ), array(
			'action'          => 'expire',
			'membership_id'   => $id,
			'membership_uuid' => (string) $row['membership_uuid'],
			'previous_status' => $prev_status,
			'new_status'      => 'expired',
			'primary_user_id' => $user_id,
			'user_meta_set'   => $user_meta_set,
			'confidence'      => $record['confidence'],
			'evidence'        => $record['evidence'],
		) );

		return false !== $logged;
	}

	/**
	 * Rollback pass: restore memberships this command expired.
	 *
	 * Reads the audit-log journal (source=guest_pass_audit), replays it in
	 * order — an 'expire' entry is cancelled by a later 'rollback' entry —
	 * and restores the previous status for every membership still expired
	 * with the audit flag. Dry-run unless --apply is passed.
	 *
	 * @param bool   $dry_run     True when no mutations may happen.
	 * @param string $format      csv|json.
	 * @param int    $batch_size  Rows per batch/transaction.
	 * @param int    $resume_from First membership id to process (inclusive).
	 */
	private static function run_rollback( $dry_run, $format, $batch_size, $resume_from ) {
		global $wpdb;

		\WP_CLI::line( $dry_run ? 'Mode: ROLLBACK DRY-RUN (pass --apply to restore)' : 'Mode: ROLLBACK APPLY' );

		$logs_table = $wpdb->prefix . 'memberistic_logs';
		$log_rows   = $wpdb->get_results(
			$wpdb->prepare( "SELECT id, context FROM {$logs_table} WHERE source = %s ORDER BY id ASC", self::LOG_SOURCE ),
			ARRAY_A
		) ?: array();

		// Replay the journal: latest expire wins, a later rollback clears it.
		$pending = array();
		foreach ( $log_rows as $log_row ) {
			$ctx = json_decode( (string) $log_row['context'], true );
			if ( ! is_array( $ctx ) || empty( $ctx['membership_id'] ) ) {
				continue;
			}
			$mid = (int) $ctx['membership_id'];
			if ( 'expire' === ( $ctx['action'] ?? '' ) ) {
				$pending[ $mid ] = $ctx;
			} elseif ( 'rollback' === ( $ctx['action'] ?? '' ) ) {
				unset( $pending[ $mid ] );
			}
		}

		if ( empty( $pending ) ) {
			\WP_CLI::success( 'Nothing to roll back — no un-reverted guest_pass_audit expire entries found in ' . $logs_table . '.' );
			return;
		}

		ksort( $pending );
		if ( $resume_from > 0 ) {
			$pending = array_filter( $pending, static function ( $mid ) use ( $resume_from ) {
				return $mid >= $resume_from;
			}, ARRAY_FILTER_USE_KEY );
		}

		$records  = array();
		$restored = 0;
		$skipped  = 0;
		$batches  = array_chunk( $pending, $batch_size, true );

		foreach ( $batches as $batch ) {
			$batch_ids      = array_keys( $batch );
			$batch_first_id = (int) $batch_ids[0];
			$in_transaction = false;

			if ( ! $dry_run ) {
				$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$in_transaction = true;
			}

			foreach ( $batch as $mid => $ctx ) {
				$mid  = (int) $mid;
				$prev = ! empty( $ctx['previous_status'] ) ? (string) $ctx['previous_status'] : 'active';
				$row  = Memberships_Repository::get( $mid );

				$record = array(
					'membership_id'   => $mid,
					'membership_uuid' => is_array( $row ) ? (string) $row['membership_uuid'] : (string) ( $ctx['membership_uuid'] ?? '' ),
					'primary_user_id' => is_array( $row ) ? (int) $row['primary_user_id'] : 0,
					'email'           => is_array( $row ) ? self::primary_email( $row ) : '',
					'status_before'   => is_array( $row ) ? (string) $row['status'] : '',
					'status_after'    => is_array( $row ) ? (string) $row['status'] : '',
					'bucket'          => 'rollback',
					'confidence'      => 1.0,
					'evidence'        => array( sprintf( 'audit log: previous status was %s', $prev ) ),
					'action'          => 'none',
				);

				if ( ! is_array( $row ) ) {
					$record['action'] = 'skipped_missing_row';
					$skipped++;
					$records[] = $record;
					continue;
				}
				if ( 'expired' !== (string) $row['status'] || false === strpos( (string) $row['notes'], self::PROCESSED_FLAG ) ) {
					$record['action'] = 'skipped_changed_since_audit';
					$skipped++;
					$records[] = $record;
					continue;
				}

				if ( $dry_run ) {
					$record['action']       = 'would_restore';
					$record['status_after'] = $prev;
					$records[]              = $record;
					$restored++;
					continue;
				}

				if ( ! self::restore_membership( $row, $prev, $ctx ) ) {
					$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					\WP_CLI::error( sprintf(
						'Failed to restore membership %d — batch rolled back. Re-run with --rollback --apply --resume-from=%d. Last DB error: %s',
						$mid,
						$batch_first_id,
						(string) $wpdb->last_error
					) );
				}
				$record['action']       = 'restored';
				$record['status_after'] = $prev;
				$records[]              = $record;
				$restored++;
			}

			if ( $in_transaction ) {
				$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
		}

		$report_path = self::write_report( $records, $format, $dry_run ? 'rollback-dry-run' : 'rollback-apply', array(
			'mode'     => $dry_run ? 'rollback-dry-run' : 'rollback-apply',
			'restored' => $restored,
			'skipped'  => $skipped,
		) );

		\WP_CLI::line( '' );
		\WP_CLI\Utils\format_items( 'table', array(
			array(
				'result' => $dry_run ? 'would_restore' : 'restored',
				'count'  => $restored,
			),
			array(
				'result' => 'skipped',
				'count'  => $skipped,
			),
		), array( 'result', 'count' ) );

		\WP_CLI::line( '' );
		\WP_CLI::line( 'Report written to: ' . $report_path );

		if ( $dry_run ) {
			\WP_CLI::success( sprintf( 'Rollback dry-run complete. %d membership(s) would be restored. Re-run with --rollback --apply to execute.', $restored ) );
		} else {
			\WP_CLI::success( sprintf( 'Rollback complete. %d membership(s) restored to their previous status.', $restored ) );
		}
	}

	/**
	 * Restore one membership to its pre-audit status (rollback apply mode).
	 *
	 * @param array  $row  Current membership row (status 'expired', flagged).
	 * @param string $prev Status recorded in the audit log before the expire.
	 * @param array  $ctx  Audit-log context of the original expire entry.
	 * @return bool
	 */
	private static function restore_membership( $row, $prev, $ctx ) {
		global $wpdb;

		$id   = (int) $row['id'];
		$now  = current_time( 'mysql' );
		$date = wp_date( 'Y-m-d' );

		$note  = sprintf( 'Status restored to %s by guest-pass-audit --rollback %s [%s]', $prev, $date, self::ROLLED_BACK_FLAG );
		$notes = str_replace( '[' . self::PROCESSED_FLAG . ']', '', (string) $row['notes'] );
		$notes = trim( $notes ) . "\n" . $note;

		$updated = $wpdb->update(
			Memberships_Repository::table(),
			array(
				'status'     => $prev,
				'notes'      => $notes,
				'updated_at' => $now,
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
		if ( false === $updated ) {
			return false;
		}

		Activity_Repository::log( array(
			'membership_id'       => $id,
			'user_id'             => (int) $row['primary_user_id'],
			'activity_type'       => 'membership_status_changed',
			'title'               => 'Guest pass audit rolled back',
			'description'         => sprintf( 'Status restored from expired to %s by guest-pass-audit --rollback %s.', $prev, $date ),
			'related_object_type' => 'guest_pass_audit',
			'related_object_id'   => (string) $id,
			'created_by'          => 0,
		) );

		// Remove the segment meta only when the original expire set it and it
		// still carries our value (never clobber a later manual change).
		$user_id = (int) $row['primary_user_id'];
		if ( $user_id > 0 && ! empty( $ctx['user_meta_set'] )
			&& self::SEGMENT_META_VALUE === (string) get_user_meta( $user_id, self::SEGMENT_META_KEY, true ) ) {
			delete_user_meta( $user_id, self::SEGMENT_META_KEY );
		}

		$logged = self::write_log( sprintf( 'Rolled back guest-pass-audit expire for membership %d (restored status: %s)', $id, $prev ), array(
			'action'          => 'rollback',
			'membership_id'   => $id,
			'membership_uuid' => (string) $row['membership_uuid'],
			'previous_status' => 'expired',
			'new_status'      => $prev,
			'primary_user_id' => $user_id,
		) );

		return false !== $logged;
	}

	// ---------------------------------------------------------------
	// Helpers.
	// ---------------------------------------------------------------

	/**
	 * Status => count map for memberships on a plan.
	 *
	 * @param int $plan_id Plan id.
	 * @return array<string, int>
	 */
	private static function status_counts( $plan_id ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT status, COUNT(*) AS c FROM ' . Memberships_Repository::table() . ' WHERE plan_id = %d GROUP BY status', $plan_id ),
			ARRAY_A
		) ?: array();
		$out = array();
		foreach ( $rows as $row ) {
			$out[ (string) $row['status'] ] = (int) $row['c'];
		}
		ksort( $out );
		return $out;
	}

	/**
	 * Primary person email for a membership (people row first, WP user fallback).
	 *
	 * @param array $row Membership row.
	 * @return string
	 */
	private static function primary_email( $row ) {
		global $wpdb;
		$people = $wpdb->prefix . 'memberistic_people';
		$email  = (string) $wpdb->get_var( $wpdb->prepare(
			"SELECT email FROM {$people} WHERE membership_id = %d AND role = 'primary' LIMIT 1",
			(int) $row['id']
		) );
		if ( '' === $email && ! empty( $row['primary_user_id'] ) ) {
			$user = get_user_by( 'id', (int) $row['primary_user_id'] );
			if ( $user ) {
				$email = (string) $user->user_email;
			}
		}
		return sanitize_email( $email );
	}

	/**
	 * True when the membership has activity rows created by a real staff user —
	 * the signature of a manually-issued pass.
	 *
	 * @param int $membership_id Membership id.
	 * @return bool
	 */
	private static function has_staff_activity( $membership_id ) {
		global $wpdb;
		$creators = $wpdb->get_col( $wpdb->prepare(
			'SELECT DISTINCT created_by FROM ' . Activity_Repository::table() . ' WHERE membership_id = %d AND created_by IS NOT NULL AND created_by > 0',
			(int) $membership_id
		) ) ?: array();
		foreach ( $creators as $creator_id ) {
			if ( self::is_staff_user( (int) $creator_id ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * True when the user exists and holds a Memberistic staff capability.
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	private static function is_staff_user( $user_id ) {
		static $cache = array();
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return false;
		}
		if ( isset( $cache[ $user_id ] ) ) {
			return $cache[ $user_id ];
		}
		$is   = false;
		$user = get_user_by( 'id', $user_id );
		if ( $user ) {
			foreach ( array( 'manage_options', 'manage_memberistic', 'manage_memberistic_members', 'edit_memberistic_members' ) as $cap ) {
				if ( user_can( $user, $cap ) ) {
					$is = true;
					break;
				}
			}
		}
		$cache[ $user_id ] = $is;
		return $is;
	}

	/**
	 * Cached bookings table name for the mapped booking engine, or '' when
	 * no booking engine is mapped or its tables are not installed.
	 *
	 * Booking correlation is an evidence signal only: without it the audit
	 * still runs, it just cannot use "created alongside a booking" as a
	 * classification hint.
	 *
	 * @return string
	 */
	private static function bookings_table() {
		global $wpdb;
		static $resolved = null;

		if ( null !== $resolved ) {
			return $resolved;
		}

		$table = \WordPressistic\Memberistic\Integrations\Booking_Adapter::table( 'bookings' );

		if ( '' === $table ) {
			$resolved = '';
			return $resolved;
		}

		$resolved = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) ? $table : '';

		return $resolved;
	}

	/**
	 * Append one audit row to wp_memberistic_logs (source=guest_pass_audit).
	 *
	 * @param string $message Human-readable line.
	 * @param array  $context Structured context (JSON-encoded), including
	 *                        action + previous_status for rollback.
	 * @return int|false Insert id or false on failure.
	 */
	private static function write_log( $message, $context ) {
		global $wpdb;
		$inserted = $wpdb->insert(
			$wpdb->prefix . 'memberistic_logs',
			array(
				'level'      => 'info',
				'source'     => self::LOG_SOURCE,
				'message'    => sanitize_textarea_field( (string) $message ),
				'context'    => wp_json_encode( $context ),
				'created_at' => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);
		return false === $inserted ? false : (int) $wpdb->insert_id;
	}

	/**
	 * Write the per-record report to the uploads directory.
	 *
	 * @param array  $records Classified records.
	 * @param string $format  csv|json.
	 * @param string $label   File label (dry-run / apply / rollback-*).
	 * @param array  $summary Summary block for the JSON payload.
	 * @return string Absolute file path.
	 */
	private static function write_report( $records, $format, $label, $summary ) {
		$uploads = wp_upload_dir();
		$dir     = trailingslashit( (string) $uploads['basedir'] ) . 'memberistic-audits';
		wp_mkdir_p( $dir );

		$path = $dir . '/guest-pass-audit-' . sanitize_file_name( $label ) . '-' . gmdate( 'Ymd-His' ) . '.' . $format;

		if ( 'json' === $format ) {
			$payload = array(
				'generated_at' => gmdate( 'c' ),
				'summary'      => $summary,
				'records'      => $records,
			);
			file_put_contents( $path, wp_json_encode( $payload, JSON_PRETTY_PRINT ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			return $path;
		}

		$fh = fopen( $path, 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $fh ) {
			\WP_CLI::error( 'Could not open report file for writing: ' . $path );
		}
		fputcsv( $fh, array( 'membership_id', 'membership_uuid', 'primary_user_id', 'email', 'status_before', 'status_after', 'bucket', 'confidence', 'action', 'evidence' ) );
		foreach ( $records as $record ) {
			fputcsv( $fh, array(
				$record['membership_id'],
				$record['membership_uuid'],
				$record['primary_user_id'],
				$record['email'],
				$record['status_before'],
				$record['status_after'],
				$record['bucket'],
				$record['confidence'],
				$record['action'],
				implode( ' | ', (array) $record['evidence'] ),
			) );
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		return $path;
	}
}
