<?php
/**
 * Activation, schema, capabilities, roles and scheduled tasks.
 *
 * These are the P0-1 "Installation" acceptance flows. They run on every
 * WordPress version in the compatibility matrix, which is the point: a schema
 * or capability change that only breaks on WordPress 7.0 shows up here rather
 * than on a customer's site.
 *
 * @package Memberistic
 */

/**
 * @group integration
 * @group activation
 */
class ActivationTest extends Memberistic_Integration_TestCase {

	public function test_plugin_bootstrapped(): void {
		$this->assertTrue( defined( 'MEMBERISTIC_VERSION' ), 'Plugin did not bootstrap.' );
		$this->assertTrue( defined( 'MEMBERISTIC_DB_VERSION' ) );
		$this->assertTrue( class_exists( \WordPressistic\Memberistic\Plugin::class ) );
	}

	/**
	 * The requirements gate returns instead of fataling, so a plugin that
	 * bailed looks identical to one that loaded — except that its classes are
	 * missing. Assert the floors match what the site is actually running so a
	 * silent bail can never be mistaken for a pass.
	 */
	public function test_requirements_gate_did_not_bail(): void {
		$this->assertTrue(
			version_compare( PHP_VERSION, MEMBERISTIC_MIN_PHP, '>=' ),
			'Test environment PHP is below the plugin floor.'
		);
		$this->assertTrue(
			version_compare( get_bloginfo( 'version' ), MEMBERISTIC_MIN_WP, '>=' ),
			'Test environment WordPress is below the plugin floor.'
		);
		$this->assertTrue(
			class_exists( \WordPressistic\Memberistic\Database\Schema::class ),
			'Schema class missing — the requirements gate bailed before loading dependencies.'
		);
	}

	public function test_every_table_is_created(): void {
		global $wpdb;

		foreach ( $this->expected_tables() as $table ) {
			$name  = $wpdb->prefix . $table;
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

			$this->assertSame( $name, $found, "Missing table: {$name}" );
		}
	}

	public function test_db_version_option_is_recorded(): void {
		$this->assertSame(
			MEMBERISTIC_DB_VERSION,
			get_option( 'memberistic_db_version' ),
			'memberistic_db_version does not match the constant after activation.'
		);
	}

	/**
	 * A capability missing from administrator silently removes an admin
	 * screen, which is the failure mode Capabilities::assign_capabilities()
	 * exists to prevent.
	 */
	public function test_administrator_has_every_capability(): void {
		$administrator = get_role( 'administrator' );
		$this->assertNotNull( $administrator );

		foreach ( \WordPressistic\Memberistic\Capabilities::get_all() as $capability ) {
			$this->assertTrue(
				$administrator->has_cap( $capability ),
				"administrator is missing capability: {$capability}"
			);
		}
	}

	public function test_plugin_roles_exist(): void {
		$roles = array(
			'memberistic_manager',
			'memberistic_staff',
			'memberistic_cashier',
			'memberistic_instructor',
			'memberistic_kiosk_operator',
			'memberistic_pos_staff',
		);

		foreach ( $roles as $role ) {
			$this->assertNotNull( get_role( $role ), "Role not created: {$role}" );
		}
	}

	/**
	 * The PII capability is deliberately narrower than the admin capability:
	 * cashier, POS and instructor roles have dashboard access without member
	 * contact data. A refactor that flattens the role map would break a
	 * privacy control, not just a permission.
	 */
	public function test_operational_roles_do_not_receive_pii_capability(): void {
		foreach ( array( 'memberistic_cashier', 'memberistic_pos_staff', 'memberistic_instructor', 'memberistic_kiosk_operator' ) as $role_name ) {
			$role = get_role( $role_name );
			$this->assertNotNull( $role );
			$this->assertFalse(
				$role->has_cap( 'view_memberistic_pii' ),
				"{$role_name} must not hold view_memberistic_pii"
			);
		}
	}

	public function test_scheduled_tasks_are_registered(): void {
		$scheduler  = new ReflectionClass( \WordPressistic\Memberistic\Scheduler::class );
		$hook_names = array();

		foreach ( $scheduler->getConstants() as $name => $value ) {
			if ( str_starts_with( $name, 'HOOK_' ) && is_string( $value ) ) {
				$hook_names[] = $value;
			}
		}

		$this->assertNotEmpty( $hook_names, 'Scheduler exposes no HOOK_* constants.' );

		foreach ( $hook_names as $hook ) {
			$this->assertNotFalse(
				wp_next_scheduled( $hook ),
				"Cron hook not scheduled after activation: {$hook}"
			);
		}
	}
}
