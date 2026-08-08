<?php
/**
 * Fixture factory for Memberistic's own tables.
 *
 * WordPress's WP_UnitTest_Factory covers users, posts and terms. It knows
 * nothing about {prefix}memberistic_*, and every integration test that needs a
 * membership to point at has otherwise had to hand-roll the insert — which is
 * how three tests ended up with three slightly different ideas of which
 * columns are NOT NULL.
 *
 * Two rules this class exists to enforce:
 *
 * 1. **Every insert is checked.** $wpdb->insert() returns false on a column
 *    mismatch rather than throwing. An unchecked failure leaves insert_id at 0,
 *    the test then addresses /memberships/0/..., gets a 404, and a "cannot read
 *    another member's data" assertion passes for entirely the wrong reason. The
 *    factory fails loudly at the point of insert instead.
 *
 * 2. **Required columns are supplied.** memberships needs membership_uuid and
 *    billing_cycle; checkins needs person_id and checked_in_at; notes needs
 *    note and created_by. Omitting any of them is the silent-failure case above.
 *
 * There is deliberately no teardown. WP_UnitTestCase wraps each test in a
 * transaction and rolls it back in tear_down(), and these rows are written
 * inside that transaction, so they disappear on their own. The plugin's tables
 * survive because they are created before the suite starts, outside any
 * transaction — see tests/integration/bootstrap.php.
 *
 * @package Memberistic
 */

final class Memberistic_Record_Factory {

	/**
	 * Insert a row and return its id, or fail the test with the DB error.
	 *
	 * @param string               $table   Unprefixed table name.
	 * @param array<string, mixed> $data    Column => value.
	 * @return int
	 */
	private static function insert( string $table, array $data ): int {
		global $wpdb;

		$result = $wpdb->insert( $wpdb->prefix . $table, $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

		if ( false === $result ) {
			throw new RuntimeException(
				sprintf(
					'Fixture insert into %s failed: %s. Columns given: %s',
					$table,
					$wpdb->last_error ? $wpdb->last_error : '(no error reported)',
					implode( ', ', array_keys( $data ) )
				)
			);
		}

		return (int) $wpdb->insert_id;
	}

	private static function now(): string {
		return current_time( 'mysql', true );
	}

	private static function unique_suffix(): string {
		return wp_generate_password( 8, false );
	}

	/**
	 * @param array<string, mixed> $overrides
	 */
	public static function plan( array $overrides = array() ): int {
		return self::insert(
			'memberistic_plans',
			array_merge(
				array(
					'name'            => 'Test Plan',
					'slug'            => 'test-plan-' . self::unique_suffix(),
					'monthly_price'   => '10.00',
					'annual_price'    => '100.00',
					'included_people' => 1,
					'status'          => 'active',
					'created_at'      => self::now(),
				),
				$overrides
			)
		);
	}

	/**
	 * A membership owned by $user_id.
	 *
	 * @param array<string, mixed> $overrides
	 */
	public static function membership( int $plan_id, int $user_id, array $overrides = array() ): int {
		return self::insert(
			'memberistic_memberships',
			array_merge(
				array(
					'membership_uuid' => wp_generate_uuid4(),
					'primary_user_id' => $user_id,
					'plan_id'         => $plan_id,
					'billing_cycle'   => 'monthly',
					'status'          => 'active',
					'start_date'      => self::now(),
					'created_at'      => self::now(),
				),
				$overrides
			)
		);
	}

	/**
	 * @param array<string, mixed> $overrides
	 */
	public static function person( int $membership_id, array $overrides = array() ): int {
		return self::insert(
			'memberistic_people',
			array_merge(
				array(
					'membership_id' => $membership_id,
					'role'          => 'linked',
					'full_name'     => 'Test Person',
					'email'         => 'person-' . self::unique_suffix() . '@example.test',
					'waiver_status' => 'missing',
					'status'        => 'active',
					'created_at'    => self::now(),
				),
				$overrides
			)
		);
	}

	/**
	 * @param array<string, mixed> $overrides
	 */
	public static function payment( int $membership_id, array $overrides = array() ): int {
		return self::insert(
			'memberistic_payments',
			array_merge(
				array(
					'membership_id'  => $membership_id,
					'amount'         => '10.00',
					'currency'       => 'USD',
					'payment_method' => 'manual',
					'status'         => 'succeeded',
					'paid_at'        => self::now(),
					'created_at'     => self::now(),
				),
				$overrides
			)
		);
	}

	/**
	 * person_id and checked_in_at are both NOT NULL.
	 *
	 * @param array<string, mixed> $overrides
	 */
	public static function checkin( int $membership_id, int $person_id, array $overrides = array() ): int {
		return self::insert(
			'memberistic_checkins',
			array_merge(
				array(
					'membership_id' => $membership_id,
					'person_id'     => $person_id,
					'checkin_type'  => 'walk_in',
					'status'        => 'checked_in',
					'checked_in_at' => self::now(),
					'created_at'    => self::now(),
				),
				$overrides
			)
		);
	}

	/**
	 * note and created_by are both NOT NULL.
	 *
	 * @param array<string, mixed> $overrides
	 */
	public static function note( int $membership_id, int $author_id, array $overrides = array() ): int {
		return self::insert(
			'memberistic_notes',
			array_merge(
				array(
					'membership_id' => $membership_id,
					'note'          => 'Test note.',
					'visibility'    => 'staff_only',
					'created_by'    => $author_id,
					'created_at'    => self::now(),
				),
				$overrides
			)
		);
	}

	/**
	 * @param array<string, mixed> $overrides
	 */
	public static function waiver_signature( int $membership_id, array $overrides = array() ): int {
		return self::insert(
			'memberistic_waiver_signatures',
			array_merge(
				array(
					'membership_id' => $membership_id,
					'signer_name'   => 'Test Signer',
					'signer_email'  => 'signer-' . self::unique_suffix() . '@example.test',
					'source'        => 'self_serve',
					'signed_at'     => self::now(),
					'created_at'    => self::now(),
				),
				$overrides
			)
		);
	}

	/**
	 * @param array<string, mixed> $overrides
	 */
	public static function document( int $membership_id, array $overrides = array() ): int {
		return self::insert(
			'memberistic_documents',
			array_merge(
				array(
					'membership_id' => $membership_id,
					'file_name'     => 'waiver-' . self::unique_suffix() . '.pdf',
					'mime'          => 'application/pdf',
					'doc_type'      => 'waiver',
					'created_at'    => self::now(),
				),
				$overrides
			)
		);
	}

	/**
	 * A member: a WordPress user with a plan and an active membership.
	 *
	 * Returns the ids together because almost every ownership test needs all
	 * three and deriving one from another is what the tests should be asserting,
	 * not doing.
	 *
	 * @return array{user_id:int, plan_id:int, membership_id:int}
	 */
	public static function member( array $membership_overrides = array() ): array {
		$user_id       = self::wp_user( 'subscriber' );
		$plan_id       = self::plan();
		$membership_id = self::membership( $plan_id, $user_id, $membership_overrides );

		return array(
			'user_id'       => $user_id,
			'plan_id'       => $plan_id,
			'membership_id' => $membership_id,
		);
	}

	/**
	 * A WordPress user in the given role.
	 *
	 * Wraps the core factory so tests have one place to create people,
	 * whichever table they end up in.
	 */
	public static function wp_user( string $role ): int {
		return (int) WP_UnitTestCase::factory()->user->create( array( 'role' => $role ) );
	}
}
