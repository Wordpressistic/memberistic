<?php
/**
 * Upgrading a 2.0.1 database to 2.1.0.
 *
 * The interesting cases are all about not losing anything. An upgrade runs
 * unattended on sites with live members and real payment history; a migration
 * that drops a column, renames one out from under running code, or deletes a
 * row to make an index apply is not recoverable by the person it happens to.
 *
 * @package Memberistic
 */

use WordPressistic\Memberistic\Database\Memberships_Repository;
use WordPressistic\Memberistic\Database\Migrations;
use WordPressistic\Memberistic\Database\Payments_Repository;
use WordPressistic\Memberistic\Database\Schema;
use WordPressistic\Memberistic\Payments\Subscription_State_Machine as SM;

final class PaymentMigrationTest extends Memberistic_Integration_TestCase {

	private function column_exists( string $table, string $column ): bool {
		global $wpdb;

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(1) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = %s AND column_name = %s',
				$wpdb->prefix . $table,
				$column
			)
		);
	}

	private function index_exists( string $table, string $index ): bool {
		global $wpdb;

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(1) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = %s AND index_name = %s',
				$wpdb->prefix . $table,
				$index
			)
		);
	}

	public function test_the_new_tables_exist_after_activation(): void {
		global $wpdb;

		foreach ( array( 'memberistic_payment_events', 'memberistic_payment_audit' ) as $table ) {
			$name = $wpdb->prefix . $table;

			self::assertSame(
				$name,
				$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) ),
				"{$table} was not created"
			);
		}
	}

	public function test_the_event_ledger_refuses_two_rows_for_one_event(): void {
		// The unique key is the whole of the idempotency guarantee. If it is
		// missing, every duplicate-delivery test still passes — until two
		// deliveries arrive at once in production.
		self::assertTrue( $this->index_exists( 'memberistic_payment_events', 'provider_event' ) );
	}

	public function test_every_new_membership_column_exists(): void {
		$columns = array(
			'billing_status',
			'payment_provider',
			'provider_account_id',
			'provider_customer_id',
			'provider_subscription_id',
			'last_provider_event_id',
			'last_provider_event_created_at',
			'last_provider_synced_at',
			'current_period_end',
			'grace_period_ends_at',
		);

		foreach ( $columns as $column ) {
			self::assertTrue( $this->column_exists( 'memberistic_memberships', $column ), "missing column {$column}" );
		}
	}

	public function test_the_legacy_stripe_columns_are_kept(): void {
		// Nothing is renamed. A site that upgrades and then rolls the plugin
		// back must still have a working membership, and a destructive rename
		// cannot be undone on a live site to save 191 bytes.
		foreach ( array( 'stripe_customer_id', 'stripe_subscription_id', 'stripe_checkout_session_id' ) as $column ) {
			self::assertTrue( $this->column_exists( 'memberistic_memberships', $column ), "legacy column {$column} was dropped" );
		}
	}

	public function test_existing_stripe_identifiers_are_copied_onto_the_provider_columns(): void {
		$plan       = Memberistic_Record_Factory::plan();
		$membership = Memberistic_Record_Factory::membership(
			$plan,
			self::factory()->user->create(),
			array(
				'status'                 => 'active',
				'billing_status'         => null,
				'payment_provider'       => null,
				'provider_customer_id'   => null,
				'provider_subscription_id' => null,
				'stripe_customer_id'     => 'cus_legacy',
				'stripe_subscription_id' => 'sub_legacy',
			)
		);

		Migrations::migrate_1_12_0();

		$row = Memberships_Repository::get( $membership );

		self::assertSame( 'cus_legacy', $row['provider_customer_id'] );
		self::assertSame( 'sub_legacy', $row['provider_subscription_id'] );
		self::assertSame( 'stripe', $row['payment_provider'] );
		self::assertSame( 'cus_legacy', $row['stripe_customer_id'], 'the legacy value must survive' );
	}

	public function test_the_backfill_never_overwrites_a_corrected_identifier(): void {
		// Re-running the migration must not "restore" a subscription id the
		// running plugin has since replaced — that would hand the cancellation
		// path the wrong authoritative subscription, which is the precise
		// failure this release closes.
		$plan       = Memberistic_Record_Factory::plan();
		$membership = Memberistic_Record_Factory::membership(
			$plan,
			self::factory()->user->create(),
			array(
				'stripe_subscription_id'   => 'sub_old',
				'provider_subscription_id' => 'sub_new',
			)
		);

		Migrations::migrate_1_12_0();

		self::assertSame( 'sub_new', Memberships_Repository::get( $membership )['provider_subscription_id'] );
	}

	public function test_billing_status_is_derived_from_the_access_status(): void {
		$plan = Memberistic_Record_Factory::plan();
		$user = self::factory()->user->create();

		$cases = array(
			'active'    => SM::ACTIVE,
			'past_due'  => SM::PAST_DUE,
			'cancelled' => SM::CANCELLED,
			'expired'   => SM::EXPIRED,
			'pending'   => SM::PENDING,
			'trial'     => SM::TRIALING,
		);

		$ids = array();
		foreach ( $cases as $status => $expected ) {
			$ids[ $status ] = Memberistic_Record_Factory::membership(
				$plan,
				$user,
				array(
					'status'         => $status,
					'billing_status' => null,
				)
			);
		}

		Migrations::migrate_1_12_0();

		foreach ( $cases as $status => $expected ) {
			self::assertSame( $expected, Memberships_Repository::get( $ids[ $status ] )['billing_status'], "status {$status}" );
		}
	}

	public function test_a_comped_membership_gets_no_billing_lifecycle(): void {
		// `comped`, `paused` and `suspended` are operational decisions with no
		// provider equivalent. Inventing `active` for them would tell the
		// renewal machinery there is a subscription to renew.
		$plan = Memberistic_Record_Factory::plan();
		$user = self::factory()->user->create();

		$ids = array();
		foreach ( array( 'comped', 'paused', 'suspended' ) as $status ) {
			$ids[ $status ] = Memberistic_Record_Factory::membership( $plan, $user, array( 'status' => $status, 'billing_status' => null ) );
		}

		Migrations::migrate_1_12_0();

		foreach ( $ids as $status => $id ) {
			self::assertNull( Memberships_Repository::get( $id )['billing_status'], "status {$status} should have no billing state" );
		}
	}

	public function test_running_the_migration_twice_changes_nothing(): void {
		$plan       = Memberistic_Record_Factory::plan();
		$membership = Memberistic_Record_Factory::membership(
			$plan,
			self::factory()->user->create(),
			array(
				'status'                 => 'active',
				'billing_status'         => null,
				'stripe_subscription_id' => 'sub_idem',
			)
		);

		self::assertTrue( Migrations::migrate_1_12_0() );
		$first = Memberships_Repository::get( $membership );

		self::assertTrue( Migrations::migrate_1_12_0() );
		$second = Memberships_Repository::get( $membership );

		self::assertSame( $first, $second );
	}

	/**
	 * Put the payments table back into its pre-2.1.0 shape.
	 *
	 * Activation has already run the migration, so the unique key exists before
	 * any test starts. A test that wants to prove what the migration does to
	 * 2.0.1-shaped data has to remove it first — otherwise the fixture rows are
	 * rejected by the very index under test, and the test proves nothing except
	 * that the index works.
	 *
	 * ALTER TABLE implicitly commits in MySQL, so the per-test transaction is
	 * gone from here on. Every caller cleans up its own rows and restores the
	 * index by hand.
	 */
	private function drop_txn_index(): void {
		global $wpdb;

		$suppress = $wpdb->suppress_errors( true );
		$wpdb->query( 'ALTER TABLE ' . Payments_Repository::table() . ' DROP INDEX provider_txn' );
		$wpdb->suppress_errors( $suppress );
	}

	/**
	 * Delete rows this test wrote, and put the schema back.
	 */
	private function restore_payments_table( int $membership_id ): void {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . Payments_Repository::table() . ' WHERE membership_id = %d',
				$membership_id
			)
		);

		delete_option( Migrations::TXN_CONFLICTS_OPTION );

		Migrations::migrate_1_12_0();
	}

	public function test_manual_payments_with_no_transaction_id_become_null_not_empty(): void {
		global $wpdb;

		$plan       = Memberistic_Record_Factory::plan();
		$membership = Memberistic_Record_Factory::membership( $plan, self::factory()->user->create() );

		$this->drop_txn_index();

		try {
			// Two cash payments, exactly as 2.0.1 wrote them: empty string, not
			// NULL. A unique key treats those as a collision, so the second
			// manual payment ever taken would fail to insert — which is why the
			// migration converts them before it adds the key.
			foreach ( array( 10.00, 20.00 ) as $amount ) {
				$wpdb->insert(
					Payments_Repository::table(),
					array(
						'membership_id'          => $membership,
						'amount'                 => $amount,
						'currency'               => 'USD',
						'payment_gateway'        => 'manual',
						'gateway_transaction_id' => '',
						'status'                 => 'completed',
						'created_at'             => current_time( 'mysql' ),
					)
				);
			}

			Migrations::migrate_1_12_0();

			$empties = (int) $wpdb->get_var(
				'SELECT COUNT(1) FROM ' . Payments_Repository::table() . " WHERE gateway_transaction_id = ''"
			);

			self::assertSame( 0, $empties );
			self::assertTrue( $this->index_exists( 'memberistic_payments', 'provider_txn' ) );

			// And a third manual payment still inserts.
			self::assertNotFalse(
				Payments_Repository::create(
					array(
						'membership_id'   => $membership,
						'amount'          => 30.00,
						'payment_gateway' => 'manual',
						'status'          => 'completed',
					)
				)
			);
		} finally {
			$this->restore_payments_table( $membership );
		}
	}

	public function test_duplicate_transaction_ids_are_reported_rather_than_deleted(): void {
		global $wpdb;

		$plan       = Memberistic_Record_Factory::plan();
		$membership = Memberistic_Record_Factory::membership( $plan, self::factory()->user->create() );

		// The index comes off *before* the fixture rows go in. Activation has
		// already created it, so inserting the second conflicting row while it
		// is still there simply fails — and the test would then be asserting
		// against one row rather than the two it means to describe.
		$this->drop_txn_index();

		// Two rows claiming the same charge. That is either a double-charge
		// the member can see on their statement or a double-insert we caused,
		// and those need different remedies — deleting one destroys the
		// evidence needed to tell them apart.
		foreach ( array( 1, 2 ) as $ignored ) {
			$wpdb->insert(
				Payments_Repository::table(),
				array(
					'membership_id'          => $membership,
					'amount'                 => 25.00,
					'currency'               => 'USD',
					'payment_gateway'        => 'stripe',
					'gateway_transaction_id' => 'pi_duplicated',
					'status'                 => 'completed',
					'created_at'             => current_time( 'mysql' ),
				)
			);
		}

		try {
			Migrations::migrate_1_12_0();

			$rows = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(1) FROM ' . Payments_Repository::table() . ' WHERE gateway_transaction_id = %s',
					'pi_duplicated'
				)
			);

			self::assertSame( 2, $rows, 'no payment row may be deleted by a migration' );
			self::assertFalse( $this->index_exists( 'memberistic_payments', 'provider_txn' ), 'the index must not be forced onto conflicting data' );

			$reported = get_option( Migrations::TXN_CONFLICTS_OPTION );
			self::assertIsArray( $reported );
			self::assertSame( 'stripe', $reported['conflicts'][0]['gateway'] );
			self::assertSame( 2, $reported['conflicts'][0]['rows'] );
		} finally {
			// Put the schema back the way activation left it, or every later
			// test runs against a table the plugin does not ship.
			$this->restore_payments_table( $membership );
			self::assertTrue( $this->index_exists( 'memberistic_payments', 'provider_txn' ) );
		}
	}

	public function test_an_active_member_keeps_access_across_the_upgrade(): void {
		$plan       = Memberistic_Record_Factory::plan();
		$user       = self::factory()->user->create();
		$membership = Memberistic_Record_Factory::membership(
			$plan,
			$user,
			array(
				'status'         => 'active',
				'billing_status' => null,
				'renewal_date'   => gmdate( 'Y-m-d H:i:s', time() + ( 30 * DAY_IN_SECONDS ) ),
			)
		);

		Migrations::migrate_1_12_0();

		$row = Memberships_Repository::get( $membership );

		self::assertSame( 'active', $row['status'], 'the upgrade must not change anyone\'s access' );
		self::assertSame( SM::ACTIVE, $row['billing_status'] );
	}

	public function test_the_recorded_db_version_matches_the_constant(): void {
		Schema::create_tables();
		Migrations::run();

		self::assertSame( MEMBERISTIC_DB_VERSION, get_option( 'memberistic_db_version' ) );
	}
}
