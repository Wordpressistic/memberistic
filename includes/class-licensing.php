<?php
/**
 * WPistic licensing integration for Memberistic.
 *
 * The shared SDK exchanges a raw key for encrypted activation state, verifies
 * signed license responses locally, refreshes on WP-Cron, and provides the
 * secure update channel. Core membership operations remain available when a
 * license expires; only explicitly premium features are gated.
 *
 * @package Memberistic
 * @since   2.1.1
 */

namespace WordPressistic\Memberistic;

use WPistic\Sdk\WpisticClient;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Licensing {

	const STATUS_UNLICENSED = 'unlicensed';
	const STATUS_VALID      = 'valid';
	const STATUS_EXPIRED    = 'expired';
	const STATUS_INVALID    = 'invalid';

	/** @var WpisticClient|null */
	private static $client = null;

	/**
	 * Load one shared SDK copy and register validation, updates, and admin UI.
	 *
	 * Multiple WPistic plugins may be active together. The class guard prevents
	 * a second vendored copy from redeclaring the shared SDK namespace.
	 *
	 * @return void
	 */
	public static function register() {
		if ( ! class_exists( WpisticClient::class, false ) ) {
			$base  = MEMBERISTIC_PATH . 'includes/wpistic-sdk/';
			$files = array(
				'Activation.php',
				'DomainNormalizer.php',
				'EntitlementChecker.php',
				'GracePeriodManager.php',
				'Api/ApiClientInterface.php',
				'Api/RetryHandler.php',
				'Api/WpisticApi.php',
				'Security/TokenStorage.php',
				'Security/HmacVerifier.php',
				'LicenseManager.php',
				'UpdateClient.php',
				'WpisticClient.php',
				'Admin/SettingsPage.php',
				'Admin/OnboardingWizard.php',
			);

			foreach ( $files as $file ) {
				require_once $base . $file;
			}
		}

		self::client()->boot();
	}

	/**
	 * The Memberistic SDK client.
	 *
	 * @return WpisticClient
	 */
	public static function client() {
		if ( null === self::$client ) {
			self::$client = new WpisticClient(
				array(
					'product_slug'    => 'memberistic',
					'product_version' => MEMBERISTIC_VERSION,
					'plugin_file'     => MEMBERISTIC_FILE,
				)
			);
		}

		return self::$client;
	}

	/**
	 * Current license status, read only from the SDK's cached local state.
	 *
	 * @return string One of the STATUS_* constants.
	 */
	public static function status() {
		$status = self::client()->status();

		if ( empty( $status['connected'] ) ) {
			return self::STATUS_UNLICENSED;
		}
		if ( ! empty( $status['active'] ) ) {
			return self::STATUS_VALID;
		}
		if ( 'expired' === ( $status['status'] ?? '' ) ) {
			return self::STATUS_EXPIRED;
		}

		return self::STATUS_INVALID;
	}

	/**
	 * Is a premium, license-gated feature available?
	 *
	 * Base functionality does not call this premium-only gate. Missing or
	 * inactive licenses must not grant premium access.
	 *
	 * @param string $feature Feature or entitlement slug.
	 * @return bool
	 */
	public static function can_use( $feature ) {
		$feature = sanitize_key( (string) $feature );
		$status  = self::status();

		if ( self::STATUS_VALID !== $status || '' === $feature ) {
			$allowed = false;
		} else {
			$key          = 0 === strpos( $feature, 'memberistic.' ) ? $feature : 'memberistic.' . $feature;
			$entitlements = self::client()->entitlements();
			$all          = $entitlements->all();
			$allowed      = array_key_exists( $key, $all )
				? $entitlements->allows( $key )
				: $entitlements->allows( 'memberistic.pro.enabled' );
		}

		/**
		 * Filters whether a license-gated feature may run.
		 *
		 * @param bool   $allowed Whether the feature is available.
		 * @param string $feature Feature slug.
		 * @param string $status  Current license status.
		 */
		return (bool) apply_filters( 'memberistic_licence_can_use', $allowed, $feature, $status );
	}

	/** Whether this site has exchanged a license key for activation state. */
	public static function is_connected() {
		return self::client()->is_connected();
	}

	/**
	 * Metadata used by the secure update client.
	 *
	 * @return array{slug:string,basename:string,version:string,php:string,wp:string}
	 */
	public static function build_info() {
		return array(
			'slug'     => 'memberistic-membership-solutions',
			'basename' => defined( 'MEMBERISTIC_BASENAME' ) ? MEMBERISTIC_BASENAME : '',
			'version'  => defined( 'MEMBERISTIC_VERSION' ) ? MEMBERISTIC_VERSION : '',
			'php'      => defined( 'MEMBERISTIC_MIN_PHP' ) ? MEMBERISTIC_MIN_PHP : '',
			'wp'       => defined( 'MEMBERISTIC_MIN_WP' ) ? MEMBERISTIC_MIN_WP : '',
		);
	}
}
