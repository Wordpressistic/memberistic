<?php
/**
 * Plugin capabilities.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Capabilities {
	/**
	 * Get all capabilities.
	 *
	 * @return array<int, string>
	 */
	public static function get_all() {
		$capabilities = array(
			'manage_memberistic',
			'view_memberistic_dashboard',
			'manage_memberistic_plans',
			'view_memberistic_members',
			'manage_memberistic_members',
			'create_memberistic_members',
			'edit_memberistic_members',
			'delete_memberistic_members',
			'manage_memberistic_payments',
			'view_memberistic_reports',
			'manage_memberistic_settings',
			'memberistic_checkin_members',
			'memberistic_add_notes',
			'view_memberistic_pii',
		);

		return apply_filters( 'memberistic_capabilities', $capabilities );
	}

	/**
	 * Assign capabilities to roles.
	 */
	public static function assign_capabilities() {
		$administrator = get_role( 'administrator' );

		if ( $administrator ) {
			foreach ( self::get_all() as $capability ) {
				$administrator->add_cap( $capability );
			}
		}

		$role_map = array(
			'memberistic_manager'    => array(
				'manage_memberistic',
				'view_memberistic_dashboard',
				'manage_memberistic_plans',
				'view_memberistic_members',
				'manage_memberistic_members',
				'create_memberistic_members',
				'edit_memberistic_members',
				'delete_memberistic_members',
				'manage_memberistic_payments',
				'view_memberistic_reports',
				'manage_memberistic_settings',
				'memberistic_checkin_members',
				'memberistic_add_notes',
				'view_memberistic_pii',
			),
			'memberistic_staff'      => array(
				'view_memberistic_dashboard',
				'view_memberistic_members',
				'create_memberistic_members',
				'edit_memberistic_members',
				'memberistic_checkin_members',
				'memberistic_add_notes',
				'view_memberistic_pii',
			),
			'memberistic_cashier'    => array(
				'view_memberistic_dashboard',
				'view_memberistic_members',
				'create_memberistic_members',
				'manage_memberistic_payments',
			),
			'memberistic_instructor' => array(
				'view_memberistic_dashboard',
				'view_memberistic_members',
			),
			'memberistic_kiosk_operator' => array(
				'memberistic_checkin_members',
				'view_memberistic_members',
			),
			'memberistic_pos_staff' => array(
				'view_memberistic_dashboard',
				'view_memberistic_members',
				'create_memberistic_members',
				'manage_memberistic_payments',
			),
		);

		foreach ( $role_map as $role_name => $capabilities ) {
			$role = get_role( $role_name );

			if ( ! $role ) {
				continue;
			}

			foreach ( $capabilities as $capability ) {
				$role->add_cap( $capability );
			}
		}
	}
}
