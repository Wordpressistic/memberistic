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

$memberistic_root = dirname( __DIR__, 2 );

/**
 * Point the WordPress bootstrap at the PHPUnit Polyfills.
 *
 * The WP test suite hard-requires Yoast's polyfills and refuses to boot
 * without them. It looks for this constant, so define it before loading
 * anything from the test library. Requiring the Composer autoloader as well
 * covers the case where the constant path is present but the classes have not
 * been registered.
 */
if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $memberistic_root . '/vendor/yoast/phpunit-polyfills' );
}

if ( file_exists( $memberistic_root . '/vendor/autoload.php' ) ) {
	require_once $memberistic_root . '/vendor/autoload.php';
}

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
$GLOBALS['memberistic_load_notices'] = array();

foreach ( array( 'deprecated_function_run', 'deprecated_argument_run', 'deprecated_hook_run', 'deprecated_file_included', 'doing_it_wrong_run' ) as $memberistic_hook ) {
	tests_add_filter(
		$memberistic_hook,
		static function ( $thing ) use ( $memberistic_hook ) {
			$GLOBALS['memberistic_load_notices'][] = $memberistic_hook . ': ' . ( is_string( $thing ) ? $thing : wp_json_encode( $thing ) );
		},
		10,
		1
	);
}

tests_add_filter(
	'muplugins_loaded',
	static function () {
		// __DIR__ resolves lexically, so this is correct inside the closure —
		// a captured variable would need an explicit `use`.
		require dirname( __DIR__, 2 ) . '/memberistic-membership-solutions.php';
	}
);

require $memberistic_tests_dir . '/includes/bootstrap.php';

/**
 * Activate the plugin the way WordPress would.
 *
 * register_activation_hook() does not fire in the test environment, so the
 * activator is called directly.
 *
 * It runs *after* the WordPress bootstrap rather than on `plugins_loaded`,
 * because Activator::activate() ends in flush_rewrite_rules() and WordPress
 * does not instantiate $wp_rewrite until after that hook has fired — hooking
 * it earlier fatals before a single test runs. This is not a plugin bug: a
 * real activation happens on an admin request, long after $wp_rewrite exists.
 *
 * Running here also puts the tables outside the per-test transaction, so they
 * survive the rollback WP_UnitTestCase performs between tests.
 */
if ( ! class_exists( \WordPressistic\Memberistic\Activator::class ) ) {
	require_once MEMBERISTIC_PATH . 'includes/class-activator.php';
}

\WordPressistic\Memberistic\Activator::activate();

/**
 * Report load-time notices, but do not exit here.
 *
 * The first version of this file called exit(1) on any captured notice. That
 * was wrong in two ways. It cannot distinguish a deprecation the plugin caused
 * from one WordPress core raised during its own bootstrap, so a core-internal
 * notice would block the entire suite on a version that is otherwise fine. And
 * a bootstrap that exits produces no PHPUnit output at all — the run just dies
 * in about a second with nothing to read, which is precisely the failure mode
 * that cost several CI rounds to diagnose.
 *
 * The notices are printed to stdout so they reach the build log, and
 * LoadDeprecationsTest asserts they are empty. A named failing test with a
 * message beats a silent exit.
 */
if ( ! empty( $GLOBALS['memberistic_load_notices'] ) ) {
	echo "\nWordPress reported deprecated or incorrect usage while loading the plugin:\n  - "
		. implode( "\n  - ", $GLOBALS['memberistic_load_notices'] ) . "\n\n";
}

require_once __DIR__ . '/class-memberistic-integration-testcase.php';
require_once __DIR__ . '/class-memberistic-record-factory.php';
require_once __DIR__ . '/class-memberistic-test-payment-provider.php';
