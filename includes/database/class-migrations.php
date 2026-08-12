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
			'1.12.0' => array( self::class, 'migrate_1_12_0' ),
		);
	}

	/**
	 * Option recording payment rows that share a provider transaction id.
	 *
	 * Written by the 1.12.0 migration when it cannot safely add the unique
	 * key, and read by the admin payment-health screen. Duplicates are
	 * reported, never resolved automatically: two rows claiming the same
	 * Stripe charge is either a double-charge the member can see on their
	 * statement or a double-insert this plugin caused, and those need
	 * different remedies. Deleting one to make an index apply destroys the
	 * evidence needed to tell them apart.
	 */
	const TXN_CONFLICTS_OPTION = 'memberistic_payment_txn_conflicts';

	/**
	 * 1.12.0 — payment integrity: billing lifecycle, event ledger, audit trail.
	 *
	 * Staged and additive. No column is renamed and none is dropped: the
	 * `stripe_*` columns keep their meaning and their values, and the new
	 * `provider_*` columns are populated alongside them. A 2.0.1 install that
	 * upgrades and then rolls the plugin back still has everything the old
	 * code reads. The cost is two columns holding the same Stripe id for a
	 * release; the alternative is a destructive rename that cannot be undone
	 * on a live site, which is not a trade worth making to save 191 bytes.
	 *
	 * `billing_status` is the new field, deliberately separate from `status`.
	 * `status` answers "may this member get in today", is set by staff as well
	 * as by billing, and carries values (`comped`, `paused`, `needs_review`)
	 * that no payment provider knows about. `billing_status` answers "what
	 * does the subscription look like at the provider". Collapsing the two —
	 * the obvious refactor — would mean a Stripe event could overwrite a
	 * manager's decision to comp someone, which is exactly the class of bug
	 * this release exists to remove.
	 */
	public static function migrate_1_12_0() {
		global $wpdb;

		// Creates memberistic_payment_events + memberistic_payment_audit, and
		// adds the new membership columns on installs where dbDelta manages
		// the ALTER cleanly.
		Schema::create_tables();

		$memberships = $wpdb->prefix . 'memberistic_memberships';

		// dbDelta is reliable for adding columns but not universally so across
		// every MySQL/MariaDB build this plugin runs on, and a missing column
		// here is a fatal query later. The explicit pass costs one
		// information_schema lookup per column on an upgrade that runs once.
		$columns = array(
			'billing_status'                 => "VARCHAR(32) NULL AFTER stripe_checkout_expires_at",
			'payment_provider'               => 'VARCHAR(32) NULL AFTER billing_status',
			'provider_account_id'            => 'VARCHAR(64) NULL AFTER payment_provider',
			'provider_customer_id'           => 'VARCHAR(191) NULL AFTER provider_account_id',
			'provider_subscription_id'       => 'VARCHAR(191) NULL AFTER provider_customer_id',
			'last_provider_event_id'         => 'VARCHAR(191) NULL AFTER provider_subscription_id',
			'last_provider_event_created_at' => 'DATETIME NULL AFTER last_provider_event_id',
			'last_provider_synced_at'        => 'DATETIME NULL AFTER last_provider_event_created_at',
			'current_period_end'             => 'DATETIME NULL AFTER last_provider_synced_at',
			'grace_period_ends_at'           => 'DATETIME NULL AFTER current_period_end',
		);

		foreach ( $columns as $column => $definition ) {
			self::add_column_if_missing( $memberships, $column, $definition );
		}

		self::add_index_if_missing( $memberships, 'provider_subscription_id', 'provider_subscription_id' );
		self::add_index_if_missing( $memberships, 'billing_status', 'billing_status' );
		self::add_index_if_missing( $memberships, 'grace_period_ends_at', 'grace_period_ends_at' );

		self::backfill_provider_identifiers( $memberships );
		self::backfill_billing_status( $memberships );
		self::converge_payment_transaction_ids();

		return true;
	}

	/**
	 * Copy the Stripe identifiers onto the provider-neutral columns.
	 *
	 * Only fills columns that are still empty, so a re-run cannot overwrite an
	 * identifier the running plugin has since corrected — a migration that
	 * "restores" a stale subscription id would hand the cancellation path the
	 * wrong authoritative subscription, which is the precise failure this
	 * release is closing.
	 */
	private static function backfill_provider_identifiers( $memberships ) {
		global $wpdb;

		$table = esc_sql( $memberships );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix, no user input.
		$wpdb->query(
			"UPDATE `{$table}`
			    SET provider_customer_id = stripe_customer_id
			  WHERE provider_customer_id IS NULL
			    AND stripe_customer_id IS NOT NULL
			    AND stripe_customer_id <> ''"
		);

		$wpdb->query(
			"UPDATE `{$table}`
			    SET provider_subscription_id = stripe_subscription_id
			  WHERE provider_subscription_id IS NULL
			    AND stripe_subscription_id IS NOT NULL
			    AND stripe_subscription_id <> ''"
		);

		$wpdb->query(
			"UPDATE `{$table}`
			    SET payment_provider = 'stripe'
			  WHERE payment_provider IS NULL
			    AND (
			         ( stripe_subscription_id IS NOT NULL AND stripe_subscription_id <> '' )
			      OR ( stripe_customer_id IS NOT NULL AND stripe_customer_id <> '' )
			    )"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Derive an initial billing lifecycle state from the access status.
	 *
	 * Only the statuses that genuinely describe a billing position are mapped.
	 * `comped`, `paused`, `suspended`, `inactive` and `needs_review` are
	 * operational decisions with no provider equivalent, so those rows keep
	 * `billing_status` NULL — read as "no billing lifecycle is being tracked
	 * here". Inventing `active` for a comped member would tell the renewal
	 * machinery there is a subscription to renew.
	 */
	private static function backfill_billing_status( $memberships ) {
		global $wpdb;

		$map = array(
			'active'   => 'active',
			'trial'    => 'trialing',
			'past_due' => 'past_due',
			'cancelled' => 'cancelled',
			'expired'  => 'expired',
			'pending'  => 'pending',
		);

		$table = esc_sql( $memberships );

		foreach ( $map as $status => $billing_status ) {
			$wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
					"UPDATE `{$table}` SET billing_status = %s WHERE billing_status IS NULL AND status = %s",
					$billing_status,
					$status
				)
			);
		}
	}

	/**
	 * Make provider transaction ids uniquely indexable, or report why not.
	 *
	 * `Payments_Repository::create()` wrote an empty string when a payment had
	 * no gateway reference — manual and cash payments, every one of them. A
	 * unique key over (payment_gateway, gateway_transaction_id) would treat
	 * those as collisions, so the empty strings become NULL first: MySQL
	 * permits any number of NULLs in a unique index, which is the behaviour
	 * this column always wanted.
	 *
	 * If real duplicates remain, the index is not created and the conflicts
	 * are recorded for an administrator. A migration that resolves a
	 * double-charge by deleting a row is a migration that hides a refund
	 * someone is owed.
	 */
	private static function converge_payment_transaction_ids() {
		global $wpdb;

		$payments = $wpdb->prefix . 'memberistic_payments';
		$table    = esc_sql( $payments );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
		$wpdb->query( "UPDATE `{$table}` SET gateway_transaction_id = NULL WHERE gateway_transaction_id = ''" );

		$conflicts = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
			"SELECT payment_gateway, gateway_transaction_id, COUNT(*) AS total
			   FROM `{$table}`
			  WHERE gateway_transaction_id IS NOT NULL
			  GROUP BY payment_gateway, gateway_transaction_id
			 HAVING total > 1
			  LIMIT 50",
			ARRAY_A
		);

		if ( empty( $conflicts ) ) {
			delete_option( self::TXN_CONFLICTS_OPTION );
			self::add_unique_index_if_missing(
				$payments,
				'provider_txn',
				array( 'payment_gateway', 'gateway_transaction_id' )
			);
			return;
		}

		$recorded = array();
		foreach ( $conflicts as $conflict ) {
			$recorded[] = array(
				'gateway' => sanitize_key( (string) $conflict['payment_gateway'] ),
				// The transaction id is a provider reference, not a secret,
				// but it is still an identifier: store enough to find the rows
				// and no more.
				'txn'     => substr( sanitize_text_field( (string) $conflict['gateway_transaction_id'] ), 0, 191 ),
				'rows'    => (int) $conflict['total'],
			);
		}

		update_option(
			self::TXN_CONFLICTS_OPTION,
			array(
				'detected_at' => gmdate( 'Y-m-d H:i:s' ),
				'conflicts'   => $recorded,
			),
			false
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
	 * Add a unique index across one or more columns, if it does not exist.
	 *
	 * Separate from add_index_if_missing() rather than a flag on it: a unique
	 * index can fail where a plain one cannot, because the data may already
	 * violate it. Callers are expected to have established that it will not —
	 * see converge_payment_transaction_ids() — and the return value says
	 * whether the index is now present so a caller that guessed wrong finds
	 * out rather than assuming.
	 *
	 * @param string        $table      Fully-qualified table name.
	 * @param string        $index_name Index name.
	 * @param array<string> $columns    Column names, in index order.
	 * @return bool True when the index exists after this call.
	 */
	private static function add_unique_index_if_missing( $table, $index_name, array $columns ) {
		global $wpdb;

		if ( empty( $columns ) ) {
			return false;
		}

		$exists = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(1) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = %s AND index_name = %s',
				$table,
				$index_name
			)
		);

		if ( $exists > 0 ) {
			return true;
		}

		$safe_table   = esc_sql( $table );
		$safe_index   = esc_sql( $index_name );
		$safe_columns = array();
		foreach ( $columns as $column ) {
			$safe_columns[] = '`' . esc_sql( $column ) . '`';
		}
		$column_list = implode( ',', $safe_columns );

		// A concurrent request could have added it between the check and here;
		// suppress so a duplicate-key-name error does not surface as a PHP
		// notice during an upgrade the admin is watching.
		$suppress = $wpdb->suppress_errors( true );
		$wpdb->query( "ALTER TABLE `{$safe_table}` ADD UNIQUE KEY `{$safe_index}` ({$column_list})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->suppress_errors( $suppress );

		$exists = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(1) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = %s AND index_name = %s',
				$table,
				$index_name
			)
		);

		return $exists > 0;
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
