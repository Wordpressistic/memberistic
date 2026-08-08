<?php
/**
 * PHPUnit bootstrap for the WordPress integration suite.
 *
 * Unlike tests/bootstrap.php — which stubs WordPress so unit tests stay fast —
 * this loads a real WordPress, a real database and a real plugin activation.
 * It is the only way to answer the questions P0-1 actually asks: does the
 * schema build, do capabilities land, does activation stay silent on the
 * network, and does any of that change between WordPress versions.
 *
 * @package Memberistic
 */

$memberistic_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $memberistic_tests_dir ) {
	$memberistic_tests_dir = '/tmp/wordpress-tests-lib';
}

if ( ! file_exists( $memberistic_tests_dir . '/includes/functions.php' ) ) {
	fwrite(
		STDERR,
		"Could not find the WordPress test library at {$memberistic_tests_dir}.\n" .
		"Run bin/install-wp-tests.sh first, or set WP_TESTS_DIR.\n"
	);
	exit( 1 );
}

require_once $memberistic_tests_dir . '/includes/functions.php';

/**
 * Fail loudly on any deprecation WordPress itself reports.
 *
 * This is the mechanism that actually finds version incompatibilities. Rather
 * than maintaining a hand-written list of what each WordPress release
 * deprecated — a list that is stale the day it is written and impossible to
 * keep correct across three release lines — every _deprecated_function(),
 * _deprecated_argument(), _deprecated_hook() and _doing_it_wrong() call that
 * the plugin triggers becomes a test failure on every version in the matrix.
 *
 * WP_UnitTestCase already does this for notices raised inside a test. These
 * handlers extend it to plugin load, which happens before the first test and
 * is where a deprecated hook signature would otherwise pass unnoticed.
 */
$memberistic_load_notices = array();

foreach ( array( 'deprecated_function_run', 'deprecated_argument_run', 'deprecated_hook_run', 'deprecated_file_included', 'doing_it_wrong_run' ) as $memberistic_hook ) {
	tests_add_filter(
		$memberistic_hook,
		static function ( $thing ) use ( $memberistic_hook, &$memberistic_load_notices ) {
			$memberistic_load_notices[] = $memberistic_hook . ': ' . ( is_string( $thing ) ? $thing : wp_json_encode( $thing ) );
		},
		10,
		1
	);
}

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__, 2 ) . '/memberistic-membership-solutions.php';
	}
);

/**
 * Activate the plugin the way WordPress would.
 *
 * register_activation_hook() does not fire in the test environment, so the
 * activator is called directly. This runs once, before any test, so every
 * test sees a site where Memberistic has genuinely been activated rather than
 * merely included.
 */
tests_add_filter(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( \WordPressistic\Memberistic\Activator::class ) ) {
			require_once MEMBERISTIC_PATH . 'includes/class-activator.php';
		}

		\WordPressistic\Memberistic\Activator::activate();
	},
	100
);

require $memberistic_tests_dir . '/includes/bootstrap.php';

if ( ! empty( $memberistic_load_notices ) ) {
	fwrite(
		STDERR,
		"\nWordPress reported deprecated or incorrect usage while loading the plugin:\n  - " .
		implode( "\n  - ", $memberistic_load_notices ) . "\n\n"
	);
	exit( 1 );
}

require_once __DIR__ . '/class-memberistic-integration-testcase.php';
