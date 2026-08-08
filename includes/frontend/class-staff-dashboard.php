<?php
/**
 * Frontend staff dashboard.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic\Frontend;

use WordPressistic\Memberistic\Database\Activity_Repository;
use WordPressistic\Memberistic\Database\Checkins_Repository;
use WordPressistic\Memberistic\Database\Memberships_Repository;
use WordPressistic\Memberistic\Database\People_Repository;
use WordPressistic\Memberistic\Database\Plans_Repository;
use WordPressistic\Memberistic\Integrations\Booking_Engine;
use function WordPressistic\Memberistic\memberistic_current_user_can;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Staff_Dashboard {
	public static function register() {
		add_shortcode( 'memberistic_staff_dashboard', array( self::class, 'render' ) );
		add_shortcode( 'memberistic_frontdesk', array( self::class, 'render' ) );
	}

	public static function handle_actions() {
		if ( empty( $_POST['memberistic_staff_action'] ) ) {
			return;
		}

		$action = sanitize_key( wp_unslash( $_POST['memberistic_staff_action'] ) );

		if ( ! self::can_access() || empty( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'memberistic_staff_dashboard' ) ) {
			self::redirect_with_notice( 'invalid_request', 'error' );
		}

		if ( 'create_membership' === $action ) {
			self::create_walkin_membership();
		}

		if ( 'record_checkin' === $action ) {
			self::record_walkin_checkin();
		}

		self::redirect_with_notice( 'invalid_request', 'error' );
	}

	public static function render() {
		if ( ! is_user_logged_in() ) {
			return Shortcodes::login_placeholder();
		}

		if ( ! self::can_access() ) {
			return '<div class="memberistic-frontend memberistic-placeholder"><h2>' . esc_html__( 'Staff Dashboard', 'memberistic' ) . '</h2><p>' . esc_html__( 'Your account does not have access to the front desk dashboard.', 'memberistic' ) . '</p></div>';
		}

		$search          = isset( $_GET['memberistic_staff_search'] ) ? sanitize_text_field( wp_unslash( $_GET['memberistic_staff_search'] ) ) : '';
		$status          = isset( $_GET['memberistic_staff_status'] ) ? sanitize_key( wp_unslash( $_GET['memberistic_staff_status'] ) ) : '';
		$members         = Memberships_Repository::get_all( array( 'search' => $search, 'status' => $status ) );
		$plans           = Plans_Repository::get_all( array( 'status' => 'active' ) );
		$recent_checkins = Checkins_Repository::get_all();
		$recent_bookings = self::get_recent_bookings();
		$notice          = isset( $_GET['memberistic_staff_notice'] ) ? sanitize_key( wp_unslash( $_GET['memberistic_staff_notice'] ) ) : '';
		$notice_type     = isset( $_GET['memberistic_staff_notice_type'] ) ? sanitize_key( wp_unslash( $_GET['memberistic_staff_notice_type'] ) ) : 'success';
		$stats           = array(
			'active'         => Memberships_Repository::count_by_status( 'active' ),
			'pending'        => Memberships_Repository::count_by_status( 'pending' ),
			'checkins_today' => Checkins_Repository::count_today(),
			'waivers'        => Memberships_Repository::count_waiver_missing(),
		);

		ob_start();
		$memberistic_tpl = \WordPressistic\Memberistic\memberistic_locate_template( 'staff-dashboard.php' );
		if ( '' !== $memberistic_tpl ) {
			require $memberistic_tpl;
		}
		return ob_get_clean();
	}

	private static function can_access() {
		$capabilities = apply_filters(
			'memberistic_staff_dashboard_capabilities',
			array(
				'memberistic_checkin_members',
				'manage_memberistic_members',
				'view_memberistic_dashboard',
				'manage_memberistic',
				'manage_options',
			)
		);

		foreach ( (array) $capabilities as $capability ) {
			if ( current_user_can( $capability ) ) {
				return true;
			}
		}

		return false;
	}

	private static function create_walkin_membership() {
		if ( ! self::can_access() ) {
			self::redirect_with_notice( 'not_allowed', 'error' );
		}

		$plan_id = isset( $_POST['plan_id'] ) ? absint( $_POST['plan_id'] ) : 0;
		$plan    = $plan_id ? Plans_Repository::get( $plan_id ) : null;
		$name    = isset( $_POST['full_name'] ) ? sanitize_text_field( wp_unslash( $_POST['full_name'] ) ) : '';
		$email   = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';

		if ( ! $plan || '' === $name ) {
			self::redirect_with_notice( 'missing_required', 'error' );
		}

		$start_date   = current_time( 'mysql' );
		$cycle        = isset( $_POST['billing_cycle'] ) ? sanitize_key( wp_unslash( $_POST['billing_cycle'] ) ) : '';
		$renewal_date = 'annual' === $cycle
			? gmdate( 'Y-m-d H:i:s', strtotime( '+1 year', time() ) )
			: gmdate( 'Y-m-d H:i:s', strtotime( '+1 month', time() ) );

		$membership_id = Memberships_Repository::create(
			array(
				'plan_id'        => $plan_id,
				'billing_cycle'  => isset( $_POST['billing_cycle'] ) ? sanitize_key( wp_unslash( $_POST['billing_cycle'] ) ) : 'monthly',
				'status'         => isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'active',
				'start_date'     => $start_date,
				'renewal_date'   => $renewal_date,
				'payment_source' => 'frontdesk',
				'notes'          => isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '',
				'created_by'     => get_current_user_id(),
			)
		);

		if ( ! $membership_id ) {
			self::redirect_with_notice( 'save_failed', 'error' );
		}

		$person_id = People_Repository::create(
			array(
				'membership_id' => $membership_id,
				'role'          => 'primary',
				'full_name'     => $name,
				'email'         => $email,
				'phone'         => isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '',
				'waiver_status' => isset( $_POST['waiver_status'] ) ? sanitize_key( wp_unslash( $_POST['waiver_status'] ) ) : 'missing',
				'status'        => 'active',
			)
		);

		Activity_Repository::log(
			array(
				'membership_id' => $membership_id,
				'person_id'     => $person_id,
				'activity_type' => 'membership_created',
				'title'         => __( 'Walk-in membership created at front desk', 'memberistic' ),
				'created_by'    => get_current_user_id(),
			)
		);

		self::redirect_with_notice( 'membership_created', 'success' );
	}

	private static function record_walkin_checkin() {
		if ( ! self::can_access() ) {
			self::redirect_with_notice( 'not_allowed', 'error' );
		}

		$membership_id = isset( $_POST['membership_id'] ) ? absint( $_POST['membership_id'] ) : 0;
		$person_id     = isset( $_POST['person_id'] ) ? absint( $_POST['person_id'] ) : 0;

		if ( ! $membership_id || ! $person_id ) {
			self::redirect_with_notice( 'missing_required', 'error' );
		}

		$checkin_id = Checkins_Repository::create(
			array(
				'membership_id' => $membership_id,
				'person_id'     => $person_id,
				'checkin_type'  => isset( $_POST['checkin_type'] ) ? sanitize_key( wp_unslash( $_POST['checkin_type'] ) ) : 'walk_in',
				'status'        => 'checked_in',
				'notes'         => isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '',
			)
		);

		if ( $checkin_id ) {
			Activity_Repository::log(
				array(
					'membership_id'       => $membership_id,
					'person_id'           => $person_id,
					'activity_type'       => 'checkin_created',
					'title'               => __( 'Walk-in customer checked in', 'memberistic' ),
					'related_object_type' => 'checkin',
					'related_object_id'   => $checkin_id,
					'created_by'          => get_current_user_id(),
				)
			);
		}

		self::redirect_with_notice( $checkin_id ? 'checkin_recorded' : 'save_failed', $checkin_id ? 'success' : 'error' );
	}

	private static function get_recent_bookings() {
		global $wpdb;

		$table = \WordPressistic\Memberistic\Integrations\Booking_Adapter::table( 'bookings' );

		if ( '' === $table ) {
			return array(); // No booking engine mapped.
		}

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return array();
		}

		$resources = \WordPressistic\Memberistic\Integrations\Booking_Adapter::table( 'resources' );
		$types     = \WordPressistic\Memberistic\Integrations\Booking_Adapter::table( 'booking_types' );

		return $wpdb->get_results(
			"SELECT b.*, r.name AS resource_name, bt.name AS booking_type_name FROM {$table} b LEFT JOIN {$resources} r ON r.id = b.resource_id LEFT JOIN {$types} bt ON bt.id = b.booking_type_id ORDER BY b.start_at DESC LIMIT 20",
			ARRAY_A
		) ?: array();
	}

	private static function redirect_with_notice( $notice, $type = 'success' ) {
		$url = \WordPressistic\Memberistic\memberistic_get_page_url( 'staff_dashboard_page_id', 'memberistic-staff-dashboard', '' );
		if ( ! $url ) {
			$url = wp_get_referer() ?: home_url( '/' );
		}
		$url = remove_query_arg( array( 'memberistic_staff_notice', 'memberistic_staff_notice_type' ), $url );
		wp_safe_redirect( add_query_arg( array( 'memberistic_staff_notice' => $notice, 'memberistic_staff_notice_type' => $type ), $url ) );
		exit;
	}

	public static function notice_text( $notice ) {
		$map = array(
			'membership_created' => __( 'Walk-in membership created.', 'memberistic' ),
			'checkin_recorded'   => __( 'Check-in recorded.', 'memberistic' ),
			'invalid_request'    => __( 'The request could not be verified.', 'memberistic' ),
			'missing_required'   => __( 'Required information is missing.', 'memberistic' ),
			'not_allowed'        => __( 'Your account cannot perform that action.', 'memberistic' ),
			'save_failed'        => __( 'The record could not be saved.', 'memberistic' ),
		);

		return $map[ $notice ] ?? '';
	}
}
