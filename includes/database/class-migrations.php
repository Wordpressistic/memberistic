<?php
/**
 * Database migration runner.
 *
 * Each migration is keyed by the DB version it advances to. Migrations are
 * idempotent and version-tracked through the `memberistic_db_version` option,
 * so applying twice is a no-op and partial upgrades resume correctly.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Migrations {
	/**
	 * Run any migrations newer than the stored DB version.
	 */
	public static function run() {
		$current = (string) get_option( 'memberistic_db_version', '0.0.0' );
		$target  = MEMBERISTIC_DB_VERSION;

		if ( version_compare( $current, $target, '>=' ) ) {
			return;
		}

		foreach ( self::migrations() as $version => $callback ) {
			if ( version_compare( $current, $version, '<' ) ) {
				$result = call_user_func( $callback );

				if ( false === $result ) {
					return;
				}

				update_option( 'memberistic_db_version', $version, false );
				$current = $version;
			}
		}
	}

	/**
	 * Ordered list of migrations, keyed by the version they advance to.
	 *
	 * @return array<string, callable>
	 */
	private static function migrations() {
		return array(
			'1.1.0' => array( self::class, 'migrate_1_1_0' ),
			'1.2.0' => array( self::class, 'migrate_1_2_0' ),
			'1.3.0' => array( self::class, 'migrate_1_3_0' ),
			'1.4.0' => array( self::class, 'migrate_1_4_0' ),
			'1.5.0' => array( self::class, 'migrate_1_5_0' ),
			'1.6.0' => array( self::class, 'migrate_1_6_0' ),
			'1.7.0' => array( self::class, 'migrate_1_7_0' ),
			'1.8.0' => array( self::class, 'migrate_1_8_0' ),
			'1.9.0' => array( self::class, 'migrate_1_9_0' ),
			'1.10.0' => array( self::class, 'migrate_1_10_0' ),
			'1.11.0' => array( self::class, 'migrate_1_11_0' ),
		);
	}

	/**
	 * 1.9.0 — Kiosk station attribution on waiver signatures.
	 */
	/**
	 * 1.11.0 - durable public checkout rate-limit fallback table.
	 */
	public static function migrate_1_11_0() {
		Schema::create_tables();
		return true;
	}

	public static function migrate_1_10_0() {
		global $wpdb;

		$table = $wpdb->prefix . 'memberistic_memberships';

		self::add_column_if_missing(
			$table,
			'stripe_checkout_session_id',
			'VARCHAR(191) NULL AFTER stripe_subscription_id'
		);

		self::add_column_if_missing(
			$table,
			'stripe_checkout_expires_at',
			'DATETIME NULL AFTER stripe_checkout_session_id'
		);

		self::add_index_if_missing( $table, 'stripe_checkout_session_id', 'stripe_checkout_session_id' );

		return true;
	}

	public static function migrate_1_9_0() {
		global $wpdb;

		self::add_column_if_missing(
			$wpdb->prefix . 'memberistic_waiver_signatures',
			'station',
			'VARCHAR(100) NULL AFTER waiver_version_id'
		);

		return true;
	}

	/**
	 * 1.8.0 — Waiver versioning + renewal reminders.
	 *
	 * Creates memberistic_waiver_versions (via dbDelta), seeds version 1
	 * from the legacy memberistic_waiver_text option (backdated so existing
	 * signatures stay valid), and adds the per-person renewal-reminder
	 * bookkeeping column.
	 */
	public static function migrate_1_8_0() {
		global $wpdb;

		Schema::create_tables();

		if ( class_exists( '\WordPressistic\Memberistic\Waivers\Waiver_Versions' ) ) {
			\WordPressistic\Memberistic\Waivers\Waiver_Versions::maybe_seed_initial();
		}

		self::add_column_if_missing(
			$wpdb->prefix . 'memberistic_people',
			'waiver_renewal_reminded_at',
			'DATETIME NULL AFTER waiver_expires_at'
		);

		return true;
	}

	/**
	 * 1.7.0 — Signing-parity columns on waiver signatures.
	 *
	 * New e-sign fields (DOB, phone, emergency contact, minors signed for by
	 * a guardian) plus the waiver_version_id groundwork for versioned waiver
	 * text. Matches the columns the old OtterWaiver export carried.
	 */
	public static function migrate_1_7_0() {
		global $wpdb;

		$table = $wpdb->prefix . 'memberistic_waiver_signatures';
		self::add_column_if_missing( $table, 'dob', 'DATE NULL AFTER attachment_id' );
		self::add_column_if_missing( $table, 'phone', 'VARCHAR(60) NULL AFTER dob' );
		self::add_column_if_missing( $table, 'emergency_name', 'VARCHAR(191) NULL AFTER phone' );
		self::add_column_if_missing( $table, 'emergency_phone', 'VARCHAR(60) NULL AFTER emergency_name' );
		self::add_column_if_missing( $table, 'minors_json', 'LONGTEXT NULL AFTER emergency_phone' );
		self::add_column_if_missing( $table, 'waiver_version_id', 'BIGINT UNSIGNED NULL AFTER minors_json' );

		return true;
	}

	/**
	 * 1.6.0 — Backfill e-signatures into the waivers archive.
	 *
	 * New waiver signatures now mirror into memberistic_waivers_archive at
	 * signing time (so the booking/check-in on-file lookup sees them). This
	 * migration copies every signature recorded BEFORE that change. Idempotent
	 * via the per-signature import_batch key inside upsert_from_signature().
	 */
	public static function migrate_1_6_0() {
		global $wpdb;

		if ( ! class_exists( '\WordPressistic\Memberistic\Waivers\Waivers_Archive' ) ) {
			return true;
		}

		$sig_table = $wpdb->prefix . 'memberistic_waiver_signatures';
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $sig_table ) ) ) {
			return true;
		}

		$offset = 0;
		do {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT id, user_id, signer_name, signer_email, source, signed_at FROM {$sig_table} ORDER BY id ASC LIMIT 200 OFFSET %d", $offset ),
				ARRAY_A
			);
			foreach ( (array) $rows as $row ) {
				\WordPressistic\Memberistic\Waivers\Waivers_Archive::upsert_from_signature( $row );
			}
			$offset += 200;
		} while ( is_array( $rows ) && 200 === count( $rows ) );

		return true;
	}

	/**
	 * 1.5.0 — Imported waivers archive (Ottertext / range-waiver history).
	 *
	 * Re-runs dbDelta() so existing installs gain the new
	 * memberistic_waivers_archive table idempotently.
	 */
	public static function migrate_1_5_0() {
		Schema::create_tables();
		return true;
	}

	/**
	 * 1.4.0 — Waiver signature audit log + member documents tables.
	 *
	 * Re-runs dbDelta() (the CREATE TABLE statements live in Schema) so
	 * existing installs gain the two new tables idempotently.
	 */
	public static function migrate_1_4_0() {
		Schema::create_tables();
		return true;
	}

	/**
	 * 1.3.0 — Add the per-membership billing_amount column.
	 *
	 * Preserves the legacy/grandfathered price each member actually pays
	 * (carried over from a PMPro import) so the account "Next charge" display
	 * matches reality instead of falling back to the plan's standard price.
	 */
	public static function migrate_1_3_0() {
		global $wpdb;

		self::add_column_if_missing(
			$wpdb->prefix . 'memberistic_memberships',
			'billing_amount',
			'DECIMAL(10,2) NULL AFTER payment_source'
		);

		return true;
	}

	/**
	 * 1.1.0 — Backfill indexes on hot Stripe/Woo lookup columns.
	 */
	public static function migrate_1_1_0() {
		global $wpdb;

		$memberships = $wpdb->prefix . 'memberistic_memberships';
		$payments    = $wpdb->prefix . 'memberistic_payments';

		self::add_index_if_missing( $memberships, 'stripe_subscription_id', 'stripe_subscription_id' );
		self::add_index_if_missing( $payments, 'gateway_transaction_id', 'gateway_transaction_id' );

		return true;
	}

	/**
	 * 1.2.0 — Add the email_logs + integrations tables introduced in v1.7.0.
	 *
	 * The actual CREATE TABLE statements live in Schema::create_tables(); this
	 * migration simply re-runs dbDelta() so existing installs gain the new
	 * tables idempotently.
	 */
	public static function migrate_1_2_0() {
		Schema::create_tables();
		return true;
	}

	/**
	 * Add an index to a table if it does not already exist.
	 */
	private static function add_index_if_missing( $table, $index_name, $column ) {
		global $wpdb;

		$exists = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(1) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = %s AND index_name = %s',
				$table,
				$index_name
			)
		);

		if ( $exists > 0 ) {
			return;
		}

		$table      = esc_sql( $table );
		$index_name = esc_sql( $index_name );
		$column     = esc_sql( $column );

		$wpdb->query( "ALTER TABLE `{$table}` ADD INDEX `{$index_name}` (`{$column}`)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Add a column to a table if it does not already exist.
	 *
	 * @param string $table       Fully-qualified table name.
	 * @param string $column      Column name.
	 * @param string $definition  Column definition (type + modifiers).
	 */
	private static function add_column_if_missing( $table, $column, $definition ) {
		global $wpdb;

		$exists = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(1) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = %s AND column_name = %s',
				$table,
				$column
			)
		);

		if ( $exists > 0 ) {
			return;
		}

		$table      = esc_sql( $table );
		$column     = esc_sql( $column );
		$definition = esc_sql( $definition );

		$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
