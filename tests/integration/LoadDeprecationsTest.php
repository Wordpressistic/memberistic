<?php
/**
 * Plugin load and activation must not trigger deprecated or incorrect usage.
 *
 * This is the mechanism that finds version incompatibilities without anyone
 * maintaining a list of what each WordPress release deprecated — a list that
 * would be stale the day it was written and impossible to keep correct across
 * three release lines. WordPress reports its own deprecations; the bootstrap
 * captures them during plugin load and activation, and this asserts the set is
 * empty on every version in the matrix.
 *
 * WP_UnitTestCase already fails a test that triggers an unexpected deprecation.
 * This covers the window before the first test, which is where a deprecated
 * hook signature or an early _doing_it_wrong() would otherwise go unseen.
 *
 * @package Memberistic
 */

/**
 * @group integration
 * @group compatibility
 */
class LoadDeprecationsTest extends Memberistic_Integration_TestCase {

	public function test_plugin_load_and_activation_are_deprecation_free(): void {
		$notices = $GLOBALS['memberistic_load_notices'] ?? array();

		$this->assertSame(
			array(),
			$notices,
			sprintf(
				"WordPress %s on PHP %s reported %d deprecated or incorrect usage notice(s) while loading or activating Memberistic:\n  - %s",
				get_bloginfo( 'version' ),
				PHP_VERSION,
				count( $notices ),
				implode( "\n  - ", $notices )
			)
		);
	}

	/**
	 * The job must have tested the WordPress it claims to have tested.
	 *
	 * install-wp-tests.sh exports WP_RESOLVED_VERSION after resolving what it
	 * actually installed. If the running WordPress does not match, the matrix
	 * is reporting green for a version it never exercised — which is precisely
	 * the failure mode `Tested up to` exists to avoid.
	 *
	 * Asserting rather than echoing: the suite runs with
	 * beStrictAboutOutputDuringTests, and a test that prints is marked risky.
	 */
	public function test_running_wordpress_matches_the_requested_version(): void {
		$requested = getenv( 'WP_RESOLVED_VERSION' );

		if ( false === $requested || '' === $requested || 'trunk' === $requested ) {
			$this->markTestSkipped( 'WP_RESOLVED_VERSION not set (local run) or trunk (canary).' );
		}

		$running = get_bloginfo( 'version' );

		$this->assertTrue(
			$running === $requested || str_starts_with( $running, $requested . '.' ),
			"This job installed WordPress {$requested} but is running {$running}."
		);
	}
}
