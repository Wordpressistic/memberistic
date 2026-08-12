<?php
/**
 * Activation handler.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Activator {
	/**
	 * Run plugin activation tasks.
	 */
	public static function activate() {
		self::load_activation_dependencies();

		Installer::install();
		Roles::add_roles();
		Capabilities::assign_capabilities();

		require_once MEMBERISTIC_PATH . 'includes/class-scheduler.php';
		Scheduler::ensure_scheduled();

		flush_rewrite_rules();
	}

	/**
	 * Load files needed when activation fires before normal boot.
	 */
	private static function load_activation_dependencies() {
		$files = array(
			'includes/utilities/security.php',
			'includes/utilities/helpers.php',
			'includes/utilities/global-functions.php',
			'includes/utilities/formatting.php',
			'includes/class-capabilities.php',
			'includes/class-roles.php',
			'includes/class-installer.php',
			'includes/database/class-schema.php',
			'includes/database/class-migrations.php',
			'includes/database/class-plans-repository.php',
		);

		foreach ( $files as $file ) {
			require_once MEMBERISTIC_PATH . $file;
		}
	}
}
