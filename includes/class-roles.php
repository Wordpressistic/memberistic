<?php
/**
 * Plugin roles.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Roles {
	/**
	 * Add operational roles.
	 */
	public static function add_roles() {
		$roles = apply_filters(
			'memberistic_roles',
			array(
				'memberistic_manager'        => __( 'Memberistic Manager', 'memberistic' ),
				'memberistic_staff'          => __( 'Memberistic Staff', 'memberistic' ),
				'memberistic_cashier'        => __( 'Memberistic Cashier', 'memberistic' ),
				'memberistic_instructor'     => __( 'Memberistic Instructor', 'memberistic' ),
				'memberistic_kiosk_operator' => __( 'Memberistic KIOSK Operator', 'memberistic' ),
				'memberistic_pos_staff'      => __( 'Memberistic POS Staff', 'memberistic' ),
			)
		);

		foreach ( $roles as $role => $label ) {
			if ( ! get_role( $role ) ) {
				add_role( $role, $label, array() );
			}
		}
	}
}
