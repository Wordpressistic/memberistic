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
	 * Record what was actually exercised, so a green run says which versions it
	 * was green on rather than leaving that to be inferred from the job name.
	 */
	public function test_environment_is_reported(): void {
		$wp  = get_bloginfo( 'version' );
		$php = PHP_VERSION;

		echo "\nEnvironment: WordPress {$wp} · PHP {$php} · Memberistic " . MEMBERISTIC_VERSION
			. ' · DB schema ' . MEMBERISTIC_DB_VERSION . "\n";

		$this->assertNotEmpty( $wp );
	}
}
