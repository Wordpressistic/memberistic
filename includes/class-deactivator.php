<?php
/**
 * Deactivation handler.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Deactivator {
	/**
	 * Run deactivation tasks.
	 *
	 * Reversible — does NOT drop tables, options, or member data;
	 * uninstall.php handles that when the plugin is deleted. This
	 * method only:
	 *   - clears cron schedules
	 *   - removes the operational + dynamic plan roles so the WP
	 *     user list isn't littered with "Memberistic …" entries
	 *     forever after deactivation
	 *   - flushes rewrite rules
	 *
	 * Users who held those roles keep their wp_users + wp_usermeta
	 * rows; they're reassigned to 'subscriber' by remove_role().
	 * If the plugin is reactivated later, Roles::add_roles() runs
	 * again on activation and the operational roles come back. The
	 * dynamic memberistic_plan_* roles are re-added on the next
	 * membership-activation event via Content_Restrictions::
	 * sync_roles_for_membership().
	 */
	public static function deactivate() {
		require_once MEMBERISTIC_PATH . 'includes/class-scheduler.php';
		Scheduler::clear_scheduled();

		self::remove_roles();

		flush_rewrite_rules();
	}

	private static function remove_roles() {
		$static_roles = array(
			// Operational (registered in class-roles.php)
			'memberistic_manager',
			'memberistic_staff',
			'memberistic_cashier',
			'memberistic_instructor',
			'memberistic_kiosk_operator',
			'memberistic_pos_staff',
			// Top-level customer role
			'memberistic_member',
		);
		foreach ( $static_roles as $role ) {
			if ( get_role( $role ) ) {
				remove_role( $role );
			}
		}

		// Dynamic per-plan roles: memberistic_plan_<slug>. We don't
		// know the slugs without hitting the plans table (which may
		// also be gone), so iterate WP_Roles and remove anything that
		// matches the prefix. Safe because no other plugin uses that
		// namespace.
		if ( function_exists( 'wp_roles' ) ) {
			$all = wp_roles()->roles;
			foreach ( array_keys( $all ) as $role_slug ) {
				if ( 0 === strpos( $role_slug, 'memberistic_plan_' ) ) {
					remove_role( $role_slug );
				}
			}
		}
	}
}
