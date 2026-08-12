<?php
/**
 * PHPUnit bootstrap for memberistic-membership-solutions unit tests.
 *
 * No live WordPress: WP functions are stubbed with small, deterministic,
 * resettable implementations, and the Database repository classes consumed
 * by the Entitlement_Service are replaced with configurable static fixtures
 * BEFORE the service file is required.
 */

/* ── Repository stubs (must be defined before the real classes could load) ── */

namespace WordPressistic\Memberistic\Database {

	final class Memberships_Repository {
		/** @var array<int,array> user_id → membership row */
		public static array $by_user_id = array();
		/** @var array<int,array> membership id → membership row */
		public static array $by_id = array();

		public static function get_by_user_id( $user_id ) {
			return self::$by_user_id[ (int) $user_id ] ?? null;
		}

		public static function get( $id ) {
			return self::$by_id[ (int) $id ] ?? null;
		}

		public static function reset(): void {
			self::$by_user_id = array();
			self::$by_id      = array();
		}
	}

	final class People_Repository {
		/** @var array<int,array> wp_user_id → person row */
		public static array $by_wp_user_id = array();

		public static function table() {
			return 'wp_memberistic_people';
		}

		public static function reset(): void {
			self::$by_wp_user_id = array();
		}
	}

	final class Plans_Repository {
		/** @var array<int,array> plan id → plan row */
		public static array $by_id = array();

		public static function get_by_slug( $slug ) {
			foreach ( self::$by_id as $plan ) {
				if ( (string) ( $plan['slug'] ?? '' ) === (string) $slug ) {
					return $plan;
				}
			}
			return null;
		}

		public static function get( $id ) {
			return self::$by_id[ (int) $id ] ?? null;
		}

		public static function reset(): void {
			self::$by_id = array();
		}
	}
}

/* ── Global WordPress stubs ── */

/* ── Plugin-namespace helpers the payment units call directly ── */

namespace WordPressistic\Memberistic {

	/**
	 * Settings stub.
	 *
	 * Backed by a plain array the tests write to, so a test that needs the
	 * live-mode signing secret, or a seven-day grace period, says so in one
	 * line instead of constructing an options table.
	 */
	function memberistic_get_setting( $key, $default = null ) {
		$settings = $GLOBALS['memberistic_test_settings'] ?? array();

		return array_key_exists( $key, $settings ) ? $settings[ $key ] : $default;
	}
}

namespace {

	use WordPressistic\Memberistic\Database\Memberships_Repository;
	use WordPressistic\Memberistic\Database\People_Repository;
	use WordPressistic\Memberistic\Database\Plans_Repository;

	error_reporting( E_ALL );

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', sys_get_temp_dir() . '/wordpress/' );
	}
	if ( ! defined( 'ARRAY_A' ) ) {
		define( 'ARRAY_A', 'ARRAY_A' );
	}
	if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
		define( 'MINUTE_IN_SECONDS', 60 );
	}
	if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
		define( 'HOUR_IN_SECONDS', 3600 );
	}
	if ( ! defined( 'DAY_IN_SECONDS' ) ) {
		define( 'DAY_IN_SECONDS', 86400 );
	}

	$GLOBALS['memberistic_test_filters']  = array();
	$GLOBALS['memberistic_test_actions']  = array();
	$GLOBALS['memberistic_test_options']  = array();
	$GLOBALS['memberistic_test_settings'] = array();

	function memberistic_tests_reset_state(): void {
		$GLOBALS['memberistic_test_filters']  = array();
		$GLOBALS['memberistic_test_actions']  = array();
		$GLOBALS['memberistic_test_options']  = array();
		$GLOBALS['memberistic_test_settings'] = array();
		$GLOBALS['memberistic_test_user_id']  = 0;
		$GLOBALS['memberistic_test_user_can'] = false;
		$GLOBALS['memberistic_test_users']    = array();
		Memberships_Repository::reset();
		People_Repository::reset();
		Plans_Repository::reset();
	}

	/* Hooks */

	function add_filter( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['memberistic_test_filters'][ $tag ][ (int) $priority ][] = array( $callback, (int) $accepted_args );
		return true;
	}

	function add_action( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
		return add_filter( $tag, $callback, $priority, $accepted_args );
	}

	function apply_filters( $tag, $value, ...$args ) {
		if ( empty( $GLOBALS['memberistic_test_filters'][ $tag ] ) ) {
			return $value;
		}
		$priorities = $GLOBALS['memberistic_test_filters'][ $tag ];
		ksort( $priorities );
		foreach ( $priorities as $callbacks ) {
			foreach ( $callbacks as list( $callback, $accepted_args ) ) {
				$all   = array_merge( array( $value ), $args );
				$value = call_user_func_array( $callback, array_slice( $all, 0, max( 1, $accepted_args ) ) );
			}
		}
		return $value;
	}

	function do_action( $tag, ...$args ) {
		$GLOBALS['memberistic_test_actions'][] = array( 'tag' => $tag, 'args' => $args );
	}

	/* Options */

	function get_option( $name, $default = false ) {
		return array_key_exists( $name, $GLOBALS['memberistic_test_options'] )
			? $GLOBALS['memberistic_test_options'][ $name ]
			: $default;
	}

	function update_option( $name, $value, $autoload = null ) {
		$GLOBALS['memberistic_test_options'][ $name ] = $value;
		return true;
	}

	function delete_option( $name ) {
		unset( $GLOBALS['memberistic_test_options'][ $name ] );
		return true;
	}

	/* Sanitizers / misc */

	function sanitize_key( $key ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}

	function sanitize_text_field( $str ) {
		$str = (string) $str;
		$str = preg_replace( '/<[^>]*>/', '', $str );
		$str = preg_replace( '/[\r\n\t ]+/', ' ', $str );
		return trim( $str );
	}

	function absint( $maybeint ) {
		return abs( (int) $maybeint );
	}

	function __( $text, $domain = 'default' ) { // phpcs:ignore
		return $text;
	}

	function get_current_user_id() {
		return (int) ( $GLOBALS['memberistic_test_user_id'] ?? 0 );
	}

	function user_can( $user, $capability ) {
		return (bool) ( $GLOBALS['memberistic_test_user_can'] ?? false );
	}

	function get_userdata( $user_id ) {
		if ( empty( $GLOBALS['memberistic_test_users'][ (int) $user_id ] ) ) {
			return false;
		}
		return (object) $GLOBALS['memberistic_test_users'][ (int) $user_id ];
	}

	function wp_timezone() {
		return new DateTimeZone( 'UTC' );
	}

	function current_time( $type, $gmt = 0 ) {
		if ( 'timestamp' === $type ) {
			return time();
		}
		if ( 'mysql' === $type ) {
			return gmdate( 'Y-m-d H:i:s' );
		}
		return gmdate( (string) $type );
	}

	class WP_Error {
		public $errors     = array();
		public $error_data = array();

		public function __construct( $code = '', $message = '', $data = '' ) {
			if ( '' !== $code ) {
				$this->add( $code, $message, $data );
			}
		}

		public function add( $code, $message, $data = '' ) {
			$this->errors[ $code ][] = $message;
			if ( ! empty( $data ) ) {
				$this->error_data[ $code ] = $data;
			}
		}

		public function get_error_code() {
			$codes = array_keys( $this->errors );
			return $codes ? $codes[0] : '';
		}

		public function get_error_message( $code = '' ) {
			$code = $code ? $code : $this->get_error_code();
			return isset( $this->errors[ $code ][0] ) ? $this->errors[ $code ][0] : '';
		}

		public function get_error_data( $code = '' ) {
			$code = $code ? $code : $this->get_error_code();
			return isset( $this->error_data[ $code ] ) ? $this->error_data[ $code ] : null;
		}
	}

	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}

	/**
	 * Minimal $wpdb double.
	 *
	 * The Entitlement_Service's only direct query is the linked-person lookup:
	 *   SELECT * FROM {people} WHERE wp_user_id = %d ORDER BY id ASC LIMIT 1
	 * get_row() answers it from People_Repository::$by_wp_user_id.
	 */
	class Memberistic_Tests_WPDB {
		public $prefix = 'wp_';

		public function prepare( $query, ...$args ) {
			if ( 1 === count( $args ) && is_array( $args[0] ) ) {
				$args = $args[0];
			}
			return json_encode( array( 'query' => (string) $query, 'args' => array_values( $args ) ) );
		}

		public function get_row( $prepared, $output = OBJECT ) {
			$decoded = json_decode( (string) $prepared, true );
			$query   = is_array( $decoded ) ? (string) ( $decoded['query'] ?? '' ) : (string) $prepared;
			$args    = is_array( $decoded ) ? (array) ( $decoded['args'] ?? array() ) : array();

			if ( str_contains( $query, People_Repository::table() ) && str_contains( $query, 'wp_user_id' ) ) {
				$row = People_Repository::$by_wp_user_id[ (int) ( $args[0] ?? 0 ) ] ?? null;
				if ( null === $row ) {
					return null;
				}
				return ARRAY_A === $output ? (array) $row : (object) $row;
			}
			return null;
		}
	}

	if ( ! defined( 'OBJECT' ) ) {
		define( 'OBJECT', 'OBJECT' );
	}

	$GLOBALS['wpdb'] = new Memberistic_Tests_WPDB();

	/* ── Load the service under test (repositories above shadow the real ones) ── */

	define( 'MEMBERISTIC_TESTS_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
	require_once MEMBERISTIC_TESTS_PLUGIN_DIR . 'includes/integrations/class-entitlement-service.php';
	require_once MEMBERISTIC_TESTS_PLUGIN_DIR . 'includes/class-membership-service.php';

	/*
	 * Payment-integrity units that hold still without WordPress.
	 *
	 * The state machine and the clock are pure, and Stripe signature
	 * verification touches nothing but the settings stub — which is the point
	 * of having it in an adapter. Everything that needs a real database (the
	 * atomic event claim, the compare-and-swap, the unique keys) is tested in
	 * the integration suite instead, because faking those is testing the fake.
	 *
	 * Stripe_Provider names Stripe_Service in a `use` statement. That is an
	 * alias resolved when a method is called, not when the file loads, and
	 * nothing exercised here calls one — so the whole checkout service, and
	 * the tree of repositories behind it, stays out of the unit suite.
	 */
	require_once MEMBERISTIC_TESTS_PLUGIN_DIR . 'includes/payments/class-payment-clock.php';
	require_once MEMBERISTIC_TESTS_PLUGIN_DIR . 'includes/payments/class-subscription-state-machine.php';
	require_once MEMBERISTIC_TESTS_PLUGIN_DIR . 'includes/payments/providers/interface-payment-provider.php';
	require_once MEMBERISTIC_TESTS_PLUGIN_DIR . 'includes/payments/providers/class-stripe-provider.php';
}
