<?php
/**
 * Fresh-install behaviour: network silence and no seeded paid inventory.
 *
 * These cover invariants I5 and I2 behaviourally. tests/unit already guards
 * them by scanning the source, which catches a developer writing the wrong
 * thing; this catches WordPress or an integration doing the wrong thing at
 * runtime, which a source scan cannot see.
 *
 * @package Memberistic
 */

/**
 * @group integration
 * @group invariants
 */
class FreshInstallTest extends Memberistic_Integration_TestCase {

	/**
	 * Return the site to a pre-install state.
	 *
	 * WP_UnitTestCase wraps each test in a transaction and rolls back, so the
	 * deletions here do not leak into other tests.
	 */
	private function simulate_first_install(): void {
		delete_option( 'memberistic_version' );
		delete_option( 'memberistic_settings' );
		delete_option( 'memberistic_db_version' );
		delete_option( 'memberistic_pre_2_0_defaults_pinned' );
	}

	/**
	 * Invariant I5 — a fresh activation makes no outbound HTTP request at all.
	 *
	 * This is the promise that lets Memberistic be installed on a site with no
	 * outbound network access, and the promise that means activating it phones
	 * nobody. It has been true by design since 2.0.0 and untested until now;
	 * an invariant nothing tests is a preference.
	 */
	public function test_activation_makes_no_outbound_http_request(): void {
		$this->block_and_record_http();
		$this->simulate_first_install();

		\WordPressistic\Memberistic\Activator::activate();

		$this->assertNoOutboundHttp(
			'Fresh activation attempted outbound HTTP: ' . implode( ', ', $this->captured_http )
		);
	}

	/**
	 * Invariant I2 — no priced plans appear on their own.
	 */
	public function test_fresh_install_seeds_no_plans(): void {
		global $wpdb;

		$this->simulate_first_install();
		\WordPressistic\Memberistic\Activator::activate();

		$table = $wpdb->prefix . 'memberistic_plans';
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB

		$this->assertSame( 0, $count, 'A fresh install created plans.' );
	}

	/**
	 * The seeding hook must exist and must default to empty, so a site owner
	 * can opt in without the plugin ever opting in for them.
	 */
	public function test_default_plans_filter_defaults_to_empty(): void {
		$this->assertSame( array(), apply_filters( 'memberistic_default_plans', array() ) );
	}

	/**
	 * Invariant I5, second half — third-party integrations ship disabled.
	 *
	 * A future integration accidentally shipping enabled must fail here rather
	 * than reach a customer, which is the whole point of the registry owning
	 * the defaults.
	 */
	public function test_third_party_integrations_default_to_disabled(): void {
		// Email Automation and Waiver Manager are built in and send nothing
		// off-site, which is why they are the documented exceptions.
		$allowed_defaults_on = array( 'email_automation', 'waiver_manager' );

		$definitions = \WordPressistic\Memberistic\Integrations\Integrations_Registry::definitions();

		$this->assertNotEmpty( $definitions, 'Integrations registry returned nothing.' );

		foreach ( $definitions as $key => $integration ) {
			if ( in_array( $key, $allowed_defaults_on, true ) ) {
				$this->assertSame( 'yes', $integration['default'] ?? 'no', "Built-in integration '{$key}' changed its default." );
				continue;
			}

			$this->assertSame(
				'no',
				$integration['default'] ?? 'no',
				"Integration '{$key}' ships enabled by default. Third-party integrations must default to 'no'."
			);
		}
	}

	/**
	 * Nothing enabled by default may reach off-site, even if a future change
	 * flips a built-in integration on. Enumerating the registry proves the
	 * defaults; this proves the consequence.
	 */
	public function test_no_integration_is_enabled_that_would_call_out(): void {
		$this->block_and_record_http();

		do_action( 'init' );

		$this->assertNoOutboundHttp( 'An integration issued an outbound request during init.' );
	}

	/**
	 * Uninstall is opt-in. A site owner who removes the plugin without
	 * changing anything must keep their membership data.
	 */
	public function test_data_retention_on_uninstall_is_the_default(): void {
		$this->simulate_first_install();
		\WordPressistic\Memberistic\Activator::activate();

		$this->assertSame(
			'no',
			memberistic_get_setting( 'delete_data_on_uninstall', 'no' ),
			'Uninstall must not delete member data unless the owner opted in.'
		);
	}
}
