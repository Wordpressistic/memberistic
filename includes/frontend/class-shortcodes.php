<?php
/**
 * Frontend shortcodes.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic\Frontend;

use WordPressistic\Memberistic\Database\Memberships_Repository;
use WordPressistic\Memberistic\Database\Payments_Repository;
use WordPressistic\Memberistic\Database\Plans_Repository;
use WordPressistic\Memberistic\Database\People_Repository;
use WordPressistic\Memberistic\Database\Checkins_Repository;
use WordPressistic\Memberistic\Database\Activity_Repository;
use WordPressistic\Memberistic\Integrations\Booking_Engine;
use WordPressistic\Memberistic\Payments\Stripe_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Shortcodes {
	public static function register() {
		add_shortcode( 'memberistic_plans', array( self::class, 'plans' ) );
		add_shortcode( 'memberistic_plan', array( self::class, 'single_plan' ) );
		add_shortcode( 'memberistic_checkout', array( self::class, 'checkout_placeholder' ) );
		add_shortcode( 'memberistic_account', array( self::class, 'account_placeholder' ) );
		add_shortcode( 'memberistic_people', array( self::class, 'people' ) );
		add_shortcode( 'memberistic_payment_history', array( self::class, 'payment_history' ) );
		add_shortcode( 'memberistic_booking_history', array( self::class, 'booking_history' ) );
		add_shortcode( 'memberistic_renewal', array( self::class, 'checkout_placeholder' ) );
		add_shortcode( 'memberistic_login', array( self::class, 'login_placeholder' ) );
		add_shortcode( 'memberistic_thank_you', array( self::class, 'thank_you' ) );
		add_shortcode( 'memberistic_payment_failed', array( self::class, 'payment_failed' ) );
		add_shortcode( 'memberistic_profile_summary', array( self::class, 'account_placeholder' ) );
		add_shortcode( 'memberistic_status', array( self::class, 'membership_status' ) );
		add_shortcode( 'memberistic_expiring_notice', array( self::class, 'expiring_notice' ) );
	}

	public static function plans( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'status'      => 'active',
				'layout'      => 'cards',
				'show_toggle' => 'yes',
			),
			$atts,
			'memberistic_plans'
		);

		$plans = Plans_Repository::get_all( array( 'status' => sanitize_key( $atts['status'] ) ) );

		ob_start();
		$memberistic_tpl = \WordPressistic\Memberistic\memberistic_locate_template( 'plans-grid.php' );
		if ( '' !== $memberistic_tpl ) {
			require $memberistic_tpl;
		}
		return ob_get_clean();
	}

	public static function single_plan( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'id'   => 0,
				'slug' => '',
			),
			$atts,
			'memberistic_plan'
		);

		$plan = ! empty( $atts['id'] ) ? Plans_Repository::get( absint( $atts['id'] ) ) : Plans_Repository::get_by_slug( sanitize_title( $atts['slug'] ) );

		if ( ! $plan ) {
			return '';
		}

		$plans = array( $plan );
		$atts['show_toggle'] = 'yes';

		ob_start();
		$memberistic_tpl = \WordPressistic\Memberistic\memberistic_locate_template( 'plans-grid.php' );
		if ( '' !== $memberistic_tpl ) {
			require $memberistic_tpl;
		}
		return ob_get_clean();
	}

	public static function checkout_placeholder( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'plan'  => '',
				'cycle' => 'monthly',
			),
			$atts,
			'memberistic_checkout'
		);

		$selected_slug = ! empty( $_GET['memberistic_plan'] ) ? sanitize_title( wp_unslash( $_GET['memberistic_plan'] ) ) : sanitize_title( $atts['plan'] );
		$plans         = Plans_Repository::get_all( array( 'status' => 'active' ) );
		$selected_plan = $selected_slug ? Plans_Repository::get_by_slug( $selected_slug ) : null;

		ob_start();
		$memberistic_tpl = \WordPressistic\Memberistic\memberistic_locate_template( 'checkout.php' );
		if ( '' !== $memberistic_tpl ) {
			require $memberistic_tpl;
		}
		return ob_get_clean();
	}

	public static function account_placeholder() {
		if ( ! is_user_logged_in() ) {
			return self::login_placeholder();
		}

		$current = self::get_current_membership();
		$people  = $current ? People_Repository::get_by_membership( (int) $current['id'] ) : array();
		$payments = $current ? Payments_Repository::get_by_membership( (int) $current['id'] ) : array();
		$checkins = $current ? Checkins_Repository::get_by_membership( (int) $current['id'] ) : array();
		$bookings = $current ? Booking_Engine::get_bookings_for_membership( (int) $current['id'] ) : array();
		$activity = $current ? Activity_Repository::get_by_membership( (int) $current['id'] ) : array();

		ob_start();
		$memberistic_tpl = \WordPressistic\Memberistic\memberistic_locate_template( 'account.php' );
		if ( '' !== $memberistic_tpl ) {
			require $memberistic_tpl;
		}
		return ob_get_clean();
	}

	public static function people() {
		return self::account_section( 'people' );
	}

	public static function payment_history() {
		return self::account_section( 'payments' );
	}

	public static function booking_history() {
		return self::account_section( 'bookings' );
	}

	private static function account_section( $section ) {
		if ( ! is_user_logged_in() ) {
			return self::login_placeholder();
		}

		$current = self::get_current_membership();
		$people  = $current ? People_Repository::get_by_membership( (int) $current['id'] ) : array();
		$payments = $current ? Payments_Repository::get_by_membership( (int) $current['id'] ) : array();
		$checkins = $current ? Checkins_Repository::get_by_membership( (int) $current['id'] ) : array();
		$bookings = $current ? Booking_Engine::get_bookings_for_membership( (int) $current['id'] ) : array();
		$activity = $current ? Activity_Repository::get_by_membership( (int) $current['id'] ) : array();

		ob_start();
		$memberistic_tpl = \WordPressistic\Memberistic\memberistic_locate_template( 'account.php' );
		if ( '' !== $memberistic_tpl ) {
			require $memberistic_tpl;
		}
		return ob_get_clean();
	}

	private static function get_current_membership() {
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return null;
		}

		$membership = Memberships_Repository::get_by_user_id( $user_id );

		if ( $membership ) {
			return Memberships_Repository::get_with_summary( (int) $membership['id'] );
		}

		$user = get_user_by( 'id', $user_id );

		if ( $user && ! empty( $user->user_email ) ) {
			$membership = Memberships_Repository::get_by_person_email( $user->user_email );
			return $membership ? Memberships_Repository::get_with_summary( (int) $membership['id'] ) : null;
		}

		return null;
	}

	public static function membership_status() {
		if ( ! is_user_logged_in() ) {
			return '';
		}
		$current = self::get_current_membership();
		if ( ! $current ) {
			return '<span class="memberistic-status-badge memberistic-status-none">' . esc_html__( 'No Membership', 'memberistic' ) . '</span>';
		}
		return wp_kses_post( \WordPressistic\Memberistic\memberistic_status_badge( $current['status'] ) );
	}

	public static function expiring_notice() {
		if ( ! is_user_logged_in() ) {
			return '';
		}
		$current = self::get_current_membership();
		if ( ! $current || 'active' !== $current['status'] || empty( $current['renewal_date'] ) ) {
			return '';
		}
		$renewal_ts = strtotime( $current['renewal_date'] );
		$days_left  = (int) ceil( ( $renewal_ts - time() ) / DAY_IN_SECONDS );
		if ( $days_left > 30 || $days_left < 0 ) {
			return '';
		}
		$account_url = \WordPressistic\Memberistic\memberistic_get_page_url( 'account_page_id', 'account', home_url( '/account/' ) );
		ob_start();
		?>
		<div class="memberistic-frontend memberistic-expiring-notice">
			<p>
				<?php
				printf(
					/* translators: %d: days until renewal */
					esc_html( _n( 'Your membership renews in %d day.', 'Your membership renews in %d days.', $days_left, 'memberistic' ) ),
					(int) $days_left
				);
				?>
				<a href="<?php echo esc_url( $account_url ); ?>"><?php esc_html_e( 'View Account', 'memberistic' ); ?></a>
			</p>
		</div>
		<?php
		return ob_get_clean();
	}

	public static function login_placeholder() {
		// The full auth surface (login + forgot-password + reset-password) lives
		// in the Auth handler so the branded /login/ page can process every auth
		// action the theme funnels to it — not just render the login form.
		return Auth::render();
	}

	public static function thank_you() {
		nocache_headers();
		$membership_id = isset( $_GET['membership_id'] ) ? absint( $_GET['membership_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$session_id    = isset( $_GET['session_id'] ) ? sanitize_text_field( wp_unslash( $_GET['session_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$result        = Stripe_Service::confirm_checkout_return( $membership_id, $session_id );
		$type          = 'active' === (string) ( $result['state'] ?? '' ) ? 'success' : ( 'failed' === (string) ( $result['state'] ?? '' ) ? 'failed' : 'pending' );

		return self::result_page(
			$type,
			isset( $result['title'] ) ? (string) $result['title'] : __( 'Membership Checkout Received', 'memberistic' ),
			isset( $result['message'] ) ? (string) $result['message'] : __( 'We are checking your Stripe payment status.', 'memberistic' )
		);
	}

	public static function payment_failed() {
		return self::result_page(
			'failed',
			__( 'Payment Was Not Completed', 'memberistic' ),
			__( 'The payment was cancelled or could not be completed. You can return to checkout or contact staff for help completing your membership.', 'memberistic' )
		);
	}

	private static function result_page( $type, $title, $message ) {
		$checkout_url = \WordPressistic\Memberistic\memberistic_get_page_url( 'checkout_page_id', 'memberistic-checkout', home_url( '/' ) );
		$account_url  = \WordPressistic\Memberistic\memberistic_get_page_url( 'account_page_id', 'account', home_url( '/account/' ) );

		ob_start();
		?>
		<div class="memberistic-frontend memberistic-result memberistic-result--<?php echo esc_attr( $type ); ?>">
			<div class="memberistic-result-icon"><?php echo 'success' === $type ? esc_html( '✓' ) : esc_html( '!' ); ?></div>
			<h2><?php echo esc_html( $title ); ?></h2>
			<p><?php echo esc_html( $message ); ?></p>
			<div class="memberistic-result-actions">
				<a class="memberistic-plan-button" href="<?php echo esc_url( $checkout_url ); ?>"><?php esc_html_e( 'Return to Checkout', 'memberistic' ); ?></a>
				<a class="memberistic-secondary-button" href="<?php echo esc_url( $account_url ); ?>"><?php esc_html_e( 'Member Account', 'memberistic' ); ?></a>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}
