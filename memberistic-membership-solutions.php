<?php
/**
 * Plugin Name:       Memberistic Membership Solutions
 * Plugin URI:        https://memberistic.com
 * Description:       A modern, high-performance membership operations engine for service businesses. Manage plans, members, linked family accounts, digital waivers, check-ins, payments, and staff workflows from a single professional admin.
 * Version:           2.0.0
 * Author:            WordPressistic
 * Author URI:        https://www.wordpressistic.com
 * Text Domain:       memberistic
 * Domain Path:       /languages
 * Requires PHP:      8.2
 * Requires at least: 6.8
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package Memberistic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Idempotency guard: if a second copy of this plugin is active from a
// different folder (e.g. a stray "memberistic-membership-solutions-main"
// folder left over from a GitHub zip upload, alongside the real one), its
// own define()/require_once calls would otherwise still run — define()
// on an already-defined constant only WARNS and is silently ignored (it
// does not fatal), but that warning is printed to the page before any
// header()/redirect the request needs to make, breaking check-in,
// checkout, and waiver-signing/printing flows with "headers already
// sent" errors. Bootstrapping only once, from whichever folder loads
// first, makes every later copy fully inert instead of noisy and
// half-broken.
if ( ! defined( 'MEMBERISTIC_BOOTSTRAPPED' ) ) {

	define( 'MEMBERISTIC_BOOTSTRAPPED', __FILE__ );
	define( 'MEMBERISTIC_VERSION', '2.0.0' );
	define( 'MEMBERISTIC_DB_VERSION', '1.11.0' );
	define( 'MEMBERISTIC_FILE', __FILE__ );
	define( 'MEMBERISTIC_PATH', plugin_dir_path( __FILE__ ) );
	define( 'MEMBERISTIC_URL', plugin_dir_url( __FILE__ ) );
	define( 'MEMBERISTIC_BASENAME', plugin_basename( __FILE__ ) );
	define( 'MEMBERISTIC_MIN_PHP', '8.2' );
	define( 'MEMBERISTIC_MIN_WP', '6.8' );

	/**
	 * Requirements gate.
	 *
	 * WordPress only enforces the `Requires PHP` / `Requires at least`
	 * headers at install and update time. A site that was already running
	 * this plugin and then downgrades PHP, or activates a copy dropped in
	 * over FTP, reaches this file regardless — so the floor is enforced
	 * here too.
	 *
	 * This returns instead of fataling, and returns BEFORE requiring any
	 * other file. A fatal here would white-screen wp-admin and lock the
	 * user out of the very screen they need to fix it, and a partial boot
	 * would register hooks whose handlers cannot be parsed. Bailing early
	 * leaves the site working, with an actionable notice.
	 */
	if ( version_compare( PHP_VERSION, MEMBERISTIC_MIN_PHP, '<' )
		|| version_compare( get_bloginfo( 'version' ), MEMBERISTIC_MIN_WP, '<' ) ) {

		add_action(
			'admin_notices',
			static function () {
				if ( ! current_user_can( 'activate_plugins' ) ) {
					return;
				}

				printf(
					'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s</p></div>',
					esc_html__( 'Memberistic Membership Solutions is inactive.', 'memberistic' ),
					esc_html(
						sprintf(
							/* translators: 1: required PHP version, 2: required WordPress version, 3: current PHP version, 4: current WordPress version */
							__( 'It requires PHP %1$s or newer and WordPress %2$s or newer. This site is running PHP %3$s and WordPress %4$s. Ask your host to update, then reload this page.', 'memberistic' ),
							MEMBERISTIC_MIN_PHP,
							MEMBERISTIC_MIN_WP,
							PHP_VERSION,
							get_bloginfo( 'version' )
						)
					)
				);
			}
		);

		return;
	}

	/**
	 * Declare WooCommerce feature compatibility.
	 *
	 * Registered unconditionally and this early because WooCommerce fires
	 * `before_woocommerce_init` during its own bootstrap — later is too
	 * late, and an undeclared plugin makes Woo warn the site owner that
	 * HPOS may be unsafe to enable. The callback no-ops when WooCommerce
	 * is absent, which is the common case: Woo is an optional integration
	 * here, not a dependency.
	 */
	add_action(
		'before_woocommerce_init',
		static function () {
			if ( ! class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
				return;
			}

			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', MEMBERISTIC_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', MEMBERISTIC_FILE, true );
		}
	);

	require_once MEMBERISTIC_PATH . 'includes/class-plugin.php';

	register_activation_hook(
		MEMBERISTIC_FILE,
		static function () {
			require_once MEMBERISTIC_PATH . 'includes/class-activator.php';
			WordPressistic\Memberistic\Activator::activate();
		}
	);

	register_deactivation_hook(
		MEMBERISTIC_FILE,
		static function () {
			require_once MEMBERISTIC_PATH . 'includes/class-deactivator.php';
			WordPressistic\Memberistic\Deactivator::deactivate();
		}
	);

	add_action(
		'plugins_loaded',
		static function () {
			$plugin = new WordPressistic\Memberistic\Plugin();
			$plugin->run();
		}
	);
} elseif ( MEMBERISTIC_BOOTSTRAPPED !== __FILE__ ) {
	// A second, different copy of this plugin is active. Nothing above ran
	// (no redefinition warnings, no duplicate hook registrations), but this
	// needs a human to remove the extra plugin folder — surface it loudly
	// instead of leaving it to silently rot.
	add_action(
		'admin_notices',
		static function () {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			printf(
				'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s</p></div>',
				esc_html__( 'Duplicate Memberistic plugin folder detected:', 'memberistic' ),
				esc_html(
					sprintf(
						/* translators: 1: path of the copy that loaded first and is active, 2: path of the inert duplicate */
						__( 'Two copies of Memberistic Membership Solutions are active — "%1$s" is running; "%2$s" is a leftover duplicate and should be deactivated and deleted from wp-content/plugins/.', 'memberistic' ),
						MEMBERISTIC_BOOTSTRAPPED,
						__FILE__
					)
				)
			);
		}
	);
}
