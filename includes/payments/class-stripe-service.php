<?php
/**
 * Stripe Checkout service.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic\Payments;

use WordPressistic\Memberistic\Database\Activity_Repository;
use WordPressistic\Memberistic\Database\Memberships_Repository;
use WordPressistic\Memberistic\Database\Payments_Repository;
use WordPressistic\Memberistic\Database\People_Repository;
use WordPressistic\Memberistic\Database\Plans_Repository;
use WordPressistic\Memberistic\Emails\Email_Service;
use function WordPressistic\Memberistic\memberistic_admin_url;
use function WordPressistic\Memberistic\memberistic_current_user_can;
use function WordPressistic\Memberistic\memberistic_get_page_url;
use function WordPressistic\Memberistic\memberistic_get_setting;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Stripe_Service {
	const API_BASE    = 'https://api.stripe.com/v1';
	const API_VERSION = '2024-04-10';

	/**
	 * True while an inbound Stripe webhook event is being processed.
	 *
	 * Inbound events (e.g. customer.subscription.deleted) sync Stripe's state
	 * into the local DB via change_status(), which fires
	 * memberistic_membership_status_changed — the same hook the outbound
	 * cancel listener uses. This flag stops the sync from calling Stripe back
	 * to cancel a subscription Stripe just told us it cancelled.
	 *
	 * @var bool
	 */
	private static $processing_inbound_event = false;

	/**
	 * Cron hook for retrying failed remote cancellations.
	 */
	const CANCEL_RETRY_HOOK = 'memberistic_stripe_cancel_retry';

	/**
	 * Option holding memberships whose Stripe cancel failed and is being
	 * retried — keeps the failure visible in wp-admin until resolved.
	 */
	const CANCEL_FAILURES_OPTION = 'memberistic_stripe_cancel_failures';

	/**
	 * Retry delays (seconds) per attempt. After the last one is exhausted the
	 * failure stays on the admin notice until staff resolve it in Stripe.
	 *
	 * @var int[]
	 */
	const CANCEL_RETRY_DELAYS = array( 300, 1800, 7200, 21600, 86400, 172800 );

	/**
	 * Per-user meta holding the fingerprint of the dismissed webhook-health
	 * warning set.
	 */
	const WEBHOOK_NOTICE_DISMISSED_META = 'memberistic_webhook_notice_dismissed';

	/**
	 * Membership ids whose remote cancel was already attempted by
	 * cancel_remote_first() during this request, so the status-change hook
	 * listener doesn't call Stripe a second time for the same cancel.
	 *
	 * @var array<int,bool>
	 */
	private static $cancel_preflight_done = array();

	/**
	 * Per-request id used only in safe checkout diagnostics.
	 *
	 * @var string
	 */
	private static $checkout_request_id = '';

	public static function is_enabled() {
		return 'yes' === memberistic_get_setting( 'stripe_enabled', 'no' ) && '' !== self::get_secret_key();
	}

	public static function get_secret_key() {
		$mode = memberistic_get_setting( 'stripe_mode', 'test' );
		$key  = 'live' === $mode ? memberistic_get_setting( 'stripe_live_secret_key', '' ) : memberistic_get_setting( 'stripe_test_secret_key', '' );
		return trim( (string) $key );
	}

	public static function maybe_handle_public_checkout_request() {
		if ( empty( $_GET['memberistic_checkout_handler'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}

		self::send_checkout_no_cache_headers();

		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			self::checkout_error(
				'memberistic_checkout_method_not_allowed',
				__( 'Checkout requests must be submitted from the membership checkout form.', 'memberistic' ),
				405,
				__( 'Invalid checkout request', 'memberistic' )
			);
		}

		if ( empty( $_POST['memberistic_action'] ) || 'start_checkout' !== sanitize_key( wp_unslash( $_POST['memberistic_action'] ) ) ) {
			self::checkout_error(
				'memberistic_checkout_missing_action',
				__( 'Checkout request could not be started. No payment was taken.', 'memberistic' ),
				400,
				__( 'Invalid checkout request', 'memberistic' )
			);
		}

		self::handle_checkout_request();
	}

	public static function checkout_action_url() {
		return add_query_arg( 'memberistic_checkout_handler', '1', home_url( '/' ) );
	}

	/**
	 * Nonced URL that opens the logged-in member's Stripe billing portal.
	 *
	 * Used by the account dashboard "Update Payment Method" / "Update Payment"
	 * buttons. Hitting it routes through maybe_handle_billing_portal_request(),
	 * which exchanges the member's stored stripe_customer_id for a one-time
	 * Stripe-hosted portal session (update card, view invoices, cancel) and
	 * redirects there. Members with no Stripe customer (legacy / cash / POS)
	 * are sent to the plans page to start a recurring subscription instead.
	 */
	public static function billing_portal_action_url() {
		return wp_nonce_url(
			add_query_arg( 'memberistic_billing_portal', '1', home_url( '/' ) ),
			'memberistic_billing_portal',
			'memberistic_bp_nonce'
		);
	}

	/**
	 * Front-end handler: open the Stripe customer billing portal for the
	 * current member. Registered on `init` alongside the checkout handler.
	 */
	public static function maybe_handle_billing_portal_request() {
		if ( empty( $_GET['memberistic_billing_portal'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}

		$account_url = memberistic_get_page_url( 'account_page_id', 'account', home_url( '/account/' ) );
		$plans_url   = memberistic_get_page_url( 'plans_page_id', 'memberships', '' );
		if ( ! $plans_url ) {
			$plans_url = memberistic_get_page_url( 'plans_page_id', 'memberistic-memberships', home_url( '/memberships/' ) );
		}

		if ( ! is_user_logged_in() ) {
			auth_redirect();
			exit;
		}

		if ( empty( $_GET['memberistic_bp_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['memberistic_bp_nonce'] ) ), 'memberistic_billing_portal' ) ) {
			wp_safe_redirect( add_query_arg( 'memberistic_billing', 'invalid', $account_url ) );
			exit;
		}

		$membership  = Memberships_Repository::get_by_user_id( get_current_user_id() );
		$customer_id = $membership && ! empty( $membership['stripe_customer_id'] ) ? (string) $membership['stripe_customer_id'] : '';

		// Legacy / non-Stripe members have no Stripe customer to manage. Send
		// them to the plans page so they can start a real recurring
		// subscription — this is the self-serve fix for imported members who
		// were never set up to auto-charge.
		if ( ! self::is_enabled() || '' === $customer_id ) {
			wp_safe_redirect( $plans_url ?: $account_url );
			exit;
		}

		$session = self::create_billing_portal_session( $customer_id, $account_url );

		if ( is_wp_error( $session ) || empty( $session['url'] ) ) {
			// Portal not configured in the Stripe dashboard yet, or a transient
			// Stripe error. Fail safe back to the account page with a notice
			// rather than a white wp_die() screen.
			wp_safe_redirect( add_query_arg( 'memberistic_billing', 'unavailable', $account_url ) );
			exit;
		}

		$url  = esc_url_raw( $session['url'] );
		$host = wp_parse_url( $url, PHP_URL_HOST );

		if ( 'billing.stripe.com' !== $host ) {
			wp_safe_redirect( $account_url );
			exit;
		}

		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
	}

	/**
	 * Create a Stripe customer billing-portal session.
	 *
	 * @param string $customer_id Stripe customer id (cus_...).
	 * @param string $return_url  Where Stripe returns the member after they finish.
	 * @return array<string,mixed>|\WP_Error
	 */
	/**
	 * Retrieve a Stripe subscription object by id (read-only).
	 *
	 * @param string $subscription_id Stripe subscription id (sub_…).
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function get_subscription( $subscription_id ) {
		$subscription_id = trim( (string) $subscription_id );
		if ( '' === $subscription_id ) {
			return new \WP_Error( 'memberistic_missing_subscription', __( 'No subscription id provided.', 'memberistic' ) );
		}
		return self::request( 'GET', '/subscriptions/' . rawurlencode( $subscription_id ) );
	}

	public static function get_checkout_session( $session_id ) {
		$session_id = trim( (string) $session_id );
		if ( '' === $session_id ) {
			return new \WP_Error( 'memberistic_missing_checkout_session', __( 'No Checkout Session id provided.', 'memberistic' ) );
		}
		return self::request( 'GET', '/checkout/sessions/' . rawurlencode( $session_id ) . '?expand[]=subscription' );
	}

	public static function create_billing_portal_session( $customer_id, $return_url ) {
		return self::request(
			'POST',
			'/billing_portal/sessions',
			array(
				'customer'   => $customer_id,
				'return_url' => $return_url,
			)
		);
	}

	/**
	 * Cancel a Stripe subscription.
	 *
	 * @param string $subscription_id Stripe subscription id (sub_…).
	 * @param bool   $at_period_end   True to stop billing at the end of the
	 *                                current period instead of immediately.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function cancel_subscription( $subscription_id, $at_period_end = false ) {
		$subscription_id = trim( (string) $subscription_id );
		if ( '' === $subscription_id ) {
			return new \WP_Error( 'memberistic_missing_subscription', __( 'No subscription id provided.', 'memberistic' ) );
		}
		if ( $at_period_end ) {
			return self::request( 'POST', '/subscriptions/' . rawurlencode( $subscription_id ), array( 'cancel_at_period_end' => 'true' ) );
		}
		return self::request( 'DELETE', '/subscriptions/' . rawurlencode( $subscription_id ) );
	}

	/**
	 * Cancel the Stripe subscription behind a membership when its status is
	 * changed to "cancelled" on the WordPress side.
	 *
	 * Hooked on memberistic_membership_status_changed, so every cancel that
	 * goes through Memberships_Repository::change_status() — the admin REST
	 * cancel action, the admin edit-screen status change, and the legacy
	 * wp-admin members page — propagates to Stripe. Before this listener the
	 * status flip was DB-only and Stripe kept billing the member.
	 *
	 * @param int    $membership_id Membership id.
	 * @param string $status        New status.
	 */
	public static function maybe_cancel_remote_subscription( $membership_id, $status ) {
		$membership_id = (int) $membership_id;

		if ( 'cancelled' !== $status || self::$processing_inbound_event || ! self::is_enabled() ) {
			return;
		}

		// cancel_remote_first() already attempted (and logged) this cancel
		// during the current request — success or failure, don't do it twice.
		if ( isset( self::$cancel_preflight_done[ $membership_id ] ) ) {
			return;
		}

		$membership = Memberships_Repository::get( $membership_id );
		if ( ! is_array( $membership ) || empty( $membership['stripe_subscription_id'] ) ) {
			return;
		}

		$result = self::attempt_remote_cancel( $membership_id, $membership );

		if ( is_wp_error( $result ) ) {
			self::record_cancel_failure( $membership_id, (string) $membership['stripe_subscription_id'], $result, 0 );
		}
	}

	/**
	 * Stop remote billing for a membership BEFORE the local record is
	 * cancelled ("Stripe first, local status second").
	 *
	 * Returns true when it is safe to flip the local status: the Stripe
	 * subscription was cancelled now, was already gone, the membership has
	 * no subscription, or Stripe is disabled. Returns WP_Error when Stripe
	 * failed — a retry is already queued, and the caller should NOT mark
	 * the membership cancelled unless the operator explicitly forces a
	 * local-only cancel.
	 *
	 * @param int $membership_id Membership id.
	 * @return true|\WP_Error
	 */
	public static function cancel_remote_first( $membership_id ) {
		$membership_id = (int) $membership_id;

		self::$cancel_preflight_done[ $membership_id ] = true;

		if ( ! self::is_enabled() ) {
			return true;
		}

		$membership = Memberships_Repository::get( $membership_id );
		if ( ! is_array( $membership ) || empty( $membership['stripe_subscription_id'] ) ) {
			return true;
		}

		$result = self::attempt_remote_cancel( $membership_id, $membership );

		if ( is_wp_error( $result ) ) {
			self::record_cancel_failure( $membership_id, (string) $membership['stripe_subscription_id'], $result, 0 );
			return $result;
		}

		return true;
	}

	/**
	 * Perform one idempotent remote cancel attempt and log the outcome.
	 *
	 * "Already cancelled / no such subscription" answers from Stripe count
	 * as success — remote billing is confirmed stopped either way.
	 *
	 * @param int                 $membership_id Membership id.
	 * @param array<string,mixed> $membership    Membership row.
	 * @return true|\WP_Error
	 */
	private static function attempt_remote_cancel( $membership_id, array $membership ) {
		$subscription_id = (string) $membership['stripe_subscription_id'];

		/**
		 * Filter whether the Stripe subscription should stop billing at the
		 * end of the paid period (true) or immediately (false, default —
		 * matches the local status flipping to cancelled right away).
		 *
		 * @param bool                $at_period_end Default false.
		 * @param array<string,mixed> $membership    Membership row.
		 */
		$at_period_end = (bool) apply_filters( 'memberistic_stripe_cancel_at_period_end', false, $membership );

		$result = self::cancel_subscription( $subscription_id, $at_period_end );

		if ( is_wp_error( $result ) ) {
			$data        = $result->get_error_data();
			$http_status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
			$stripe_code = is_array( $data ) && isset( $data['response']['error']['code'] ) ? (string) $data['response']['error']['code'] : '';

			// Idempotent terminus: the subscription is already gone or
			// already cancelled at Stripe — nothing left to stop. Stripe
			// answers "No such subscription" (resource_missing, 404) or
			// "…has been canceled…" for those cases.
			if ( 404 !== $http_status && 'resource_missing' !== $stripe_code && false === stripos( $result->get_error_message(), 'has been canceled' ) ) {
				return $result;
			}
		}

		self::clear_cancel_failure( $membership_id );

		Activity_Repository::log(
			array(
				'membership_id' => (int) $membership_id,
				'activity_type' => 'membership_cancelled',
				'title'         => $at_period_end
					? __( 'Stripe subscription set to cancel at period end', 'memberistic' )
					: __( 'Stripe subscription cancelled — billing stopped', 'memberistic' ),
			)
		);

		return true;
	}

	/**
	 * Record a failed remote cancel: loud activity entry, persistent admin
	 * notice, and a scheduled retry with backoff.
	 *
	 * @param int       $membership_id   Membership id.
	 * @param string    $subscription_id Stripe subscription id.
	 * @param \WP_Error $error           Failure.
	 * @param int       $attempt         Attempt that just failed (0 = first try).
	 */
	private static function record_cancel_failure( $membership_id, $subscription_id, $error, $attempt ) {
		$membership_id = (int) $membership_id;
		$next_attempt  = (int) $attempt + 1;
		$exhausted     = $next_attempt > count( self::CANCEL_RETRY_DELAYS );

		error_log( sprintf( 'Memberistic: failed to cancel Stripe subscription %s for membership #%d (attempt %d): %s', $subscription_id, $membership_id, $attempt + 1, $error->get_error_message() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

		Activity_Repository::log(
			array(
				'membership_id' => $membership_id,
				'activity_type' => 'membership_cancelled',
				'title'         => $exhausted
					? sprintf(
						/* translators: %s = Stripe error message */
						__( 'Stripe cancellation FAILED and retries are exhausted — cancel the subscription manually in the Stripe Dashboard: %s', 'memberistic' ),
						$error->get_error_message()
					)
					: sprintf(
						/* translators: %s = Stripe error message */
						__( 'Stripe cancellation FAILED — subscription may still be billing. A retry is queued: %s', 'memberistic' ),
						$error->get_error_message()
					),
			)
		);

		$failures = get_option( self::CANCEL_FAILURES_OPTION, array() );
		$failures = is_array( $failures ) ? $failures : array();

		$failures[ $membership_id ] = array(
			'subscription_id' => $subscription_id,
			'message'         => $error->get_error_message(),
			'attempts'        => $attempt + 1,
			'exhausted'       => $exhausted,
			'last_failed_at'  => time(),
		);
		update_option( self::CANCEL_FAILURES_OPTION, $failures, false );

		if ( ! $exhausted ) {
			$delay = self::CANCEL_RETRY_DELAYS[ $next_attempt - 1 ];
			if ( ! wp_next_scheduled( self::CANCEL_RETRY_HOOK, array( $membership_id, $next_attempt ) ) ) {
				wp_schedule_single_event( time() + $delay, self::CANCEL_RETRY_HOOK, array( $membership_id, $next_attempt ) );
			}
		}
	}

	/**
	 * Cron: retry a failed remote cancellation.
	 *
	 * On success the local membership is cancelled too if the operator's
	 * original cancel was blocked by the failure (Stripe-first ordering).
	 *
	 * @param int $membership_id Membership id.
	 * @param int $attempt       Attempt number (1-based).
	 */
	public static function run_cancel_retry( $membership_id, $attempt = 1 ) {
		$membership_id = (int) $membership_id;

		$failures = get_option( self::CANCEL_FAILURES_OPTION, array() );
		if ( ! is_array( $failures ) || ! isset( $failures[ $membership_id ] ) ) {
			return; // Resolved elsewhere (webhook, staff, later success).
		}

		if ( ! self::is_enabled() ) {
			return;
		}

		$membership = Memberships_Repository::get( $membership_id );
		if ( ! is_array( $membership ) || empty( $membership['stripe_subscription_id'] ) ) {
			self::clear_cancel_failure( $membership_id );
			return;
		}

		$result = self::attempt_remote_cancel( $membership_id, $membership );

		if ( is_wp_error( $result ) ) {
			self::record_cancel_failure( $membership_id, (string) $membership['stripe_subscription_id'], $result, (int) $attempt );
			return;
		}

		// Remote billing is now confirmed stopped. If the membership is
		// still not cancelled locally (the original cancel was blocked by
		// the Stripe failure), finish the job.
		if ( 'cancelled' !== (string) $membership['status'] ) {
			self::$cancel_preflight_done[ $membership_id ] = true;
			Memberships_Repository::update( $membership_id, array( 'cancelled_at' => current_time( 'mysql' ) ) );
			Memberships_Repository::change_status( $membership_id, 'cancelled' );
			Activity_Repository::log(
				array(
					'membership_id' => $membership_id,
					'activity_type' => 'membership_cancelled',
					'title'         => __( 'Membership cancelled after Stripe retry succeeded', 'memberistic' ),
				)
			);
		}
	}

	/**
	 * Remove a membership from the failed-cancel notice.
	 *
	 * @param int $membership_id Membership id.
	 */
	private static function clear_cancel_failure( $membership_id ) {
		$failures = get_option( self::CANCEL_FAILURES_OPTION, array() );
		if ( is_array( $failures ) && isset( $failures[ (int) $membership_id ] ) ) {
			unset( $failures[ (int) $membership_id ] );
			if ( empty( $failures ) ) {
				delete_option( self::CANCEL_FAILURES_OPTION );
			} else {
				update_option( self::CANCEL_FAILURES_OPTION, $failures, false );
			}
		}
	}

	/**
	 * Persistent wp-admin notice while any Stripe cancellation is failed or
	 * retrying, so a still-billing subscription can never go unnoticed.
	 */
	public static function render_cancel_failure_notice() {
		if ( ! memberistic_current_user_can( 'edit_memberistic_members' ) ) {
			return;
		}

		$failures = get_option( self::CANCEL_FAILURES_OPTION, array() );
		if ( ! is_array( $failures ) || empty( $failures ) ) {
			return;
		}

		$lines = array();
		foreach ( $failures as $membership_id => $failure ) {
			$lines[] = sprintf(
				/* translators: 1: membership id, 2: attempts, 3: error message */
				esc_html__( 'Membership #%1$d (attempt %2$d): %3$s', 'memberistic' ),
				(int) $membership_id,
				isset( $failure['attempts'] ) ? (int) $failure['attempts'] : 1,
				esc_html( isset( $failure['message'] ) ? (string) $failure['message'] : '' )
			);
		}

		printf(
			'<div class="notice notice-error"><p><strong>%1$s</strong></p><p>%2$s</p><p>%3$s</p></div>',
			esc_html__( 'Memberistic: Stripe subscription cancellation failed — these members may still be billed.', 'memberistic' ),
			implode( '<br>', $lines ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- lines escaped above.
			esc_html__( 'Automatic retries are running. If this persists, cancel the subscription manually in the Stripe Dashboard; the notice clears when Stripe confirms.', 'memberistic' )
		);
	}

	/**
	 * Screens this notice is allowed to appear on.
	 *
	 * It used to render on every wp-admin page, so a Memberistic Stripe warning
	 * greeted staff at the top of unrelated plugins' dashboards (the Booking
	 * Engine's especially) with no way to dismiss it and no link to act on it.
	 * It now shows only where it can actually be acted upon.
	 */
	private static function webhook_notice_is_relevant_screen() {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$screen = get_current_screen();
		if ( ! $screen ) {
			return false;
		}

		// The main WP dashboard, plus any Memberistic admin page.
		if ( 'dashboard' === $screen->id ) {
			return true;
		}

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen check.

		return '' !== $page && 0 === strpos( $page, 'memberistic' );
	}

	/**
	 * Fingerprint of the current warning set, so dismissing today's notice
	 * doesn't also hide tomorrow's different (possibly worse) problem.
	 */
	private static function webhook_notice_fingerprint( array $warnings ) {
		return substr( md5( implode( '|', $warnings ) ), 0, 12 );
	}

	public static function render_webhook_health_notice() {
		if ( ! current_user_can( 'manage_options' ) || ! self::is_enabled() ) {
			return;
		}

		if ( ! self::webhook_notice_is_relevant_screen() ) {
			return;
		}

		$last_verified  = get_option( 'memberistic_stripe_webhook_last_verified_at', '' );
		$last_failed    = get_option( 'memberistic_stripe_webhook_last_failed_at', '' );
		$last_processed = get_option( 'memberistic_stripe_webhook_last_processed_at', '' );
		$manual_review  = get_option( 'memberistic_stripe_manual_review', array() );
		$warnings       = array();

		if ( '' === (string) $last_verified || strtotime( (string) $last_verified ) < ( time() - DAY_IN_SECONDS ) ) {
			$warnings[] = __( 'Memberistic Stripe webhook has not verified a signed event in the last 24 hours.', 'memberistic' );
		}
		if ( $last_failed && ( ! $last_processed || strtotime( (string) $last_failed ) > strtotime( (string) $last_processed ) ) ) {
			$warnings[] = __( 'The most recent Memberistic Stripe webhook attempt failed.', 'memberistic' );
		}
		if ( is_array( $manual_review ) && ! empty( $manual_review ) ) {
			$warnings[] = sprintf(
				/* translators: %d: manual review count */
				_n( '%d Stripe membership item needs manual review.', '%d Stripe membership items need manual review.', count( $manual_review ), 'memberistic' ),
				count( $manual_review )
			);
		}
		if ( ! $warnings ) {
			return;
		}

		// Respect a dismissal, but only of this exact warning set.
		$fingerprint = self::webhook_notice_fingerprint( $warnings );
		if ( get_user_meta( get_current_user_id(), self::WEBHOOK_NOTICE_DISMISSED_META, true ) === $fingerprint ) {
			return;
		}

		$dismiss_url = wp_nonce_url(
			add_query_arg(
				array(
					'memberistic_dismiss_webhook_notice' => $fingerprint,
				),
				admin_url( 'admin.php?page=memberistic-dashboard' )
			),
			'memberistic_dismiss_webhook_notice'
		);

		printf(
			'<div class="notice notice-warning is-dismissible"><p><strong>%1$s</strong> %2$s</p><p><a href="%3$s" class="button button-secondary">%4$s</a> <a href="%5$s">%6$s</a></p></div>',
			esc_html__( 'Memberistic webhook health:', 'memberistic' ),
			esc_html( implode( ' ', $warnings ) ),
			esc_url( admin_url( 'admin.php?page=memberistic-payments' ) ),
			esc_html__( 'Review Stripe items', 'memberistic' ),
			esc_url( $dismiss_url ),
			esc_html__( 'Dismiss until this changes', 'memberistic' )
		);
	}

	/**
	 * Persist a dismissal of the webhook-health notice for the current user.
	 *
	 * Stores the warning fingerprint rather than a boolean, so the notice comes
	 * back the moment the underlying problem changes.
	 */
	public static function handle_webhook_notice_dismissal() {
		if ( empty( $_GET['memberistic_dismiss_webhook_notice'] ) || ! is_user_logged_in() ) {
			return;
		}

		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'memberistic_dismiss_webhook_notice' ) ) {
			return;
		}

		$fingerprint = sanitize_text_field( wp_unslash( $_GET['memberistic_dismiss_webhook_notice'] ) );

		update_user_meta( get_current_user_id(), self::WEBHOOK_NOTICE_DISMISSED_META, $fingerprint );
	}

	public static function handle_checkout_request() {
		self::send_checkout_no_cache_headers();

		if ( empty( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'memberistic_checkout' ) ) {
			self::checkout_error( 'memberistic_checkout_nonce_failed', __( 'Checkout request could not be verified. No payment was taken.', 'memberistic' ), 403, __( 'Checkout verification failed', 'memberistic' ) );
		}

		if ( ! self::is_enabled() ) {
			self::checkout_error( 'memberistic_checkout_disabled', __( 'We couldn\'t connect to secure payment checkout. No payment was taken. Please try again.', 'memberistic' ), 503, __( 'Checkout temporarily unavailable', 'memberistic' ) );
		}

		// Throttle the public checkout endpoint per IP. The signup nonce is
		// rendered on a public page (identical for all logged-out visitors),
		// so without this a script could seed pending memberships for
		// arbitrary addresses. 8 attempts / 10 min.
		// Checked and charged as one atomic step (MySQL advisory lock) so a
		// scripted concurrent burst can't have two requests both read the
		// same stale count and both slip through — the lost-update race
		// that plain get_transient()-then-set_transient() is exposed to.
		$plan_id       = isset( $_POST['plan_id'] ) ? absint( $_POST['plan_id'] ) : 0;
		$billing_cycle = isset( $_POST['billing_cycle'] ) ? sanitize_key( wp_unslash( $_POST['billing_cycle'] ) ) : 'monthly';
		$full_name     = isset( $_POST['full_name'] ) ? sanitize_text_field( wp_unslash( $_POST['full_name'] ) ) : '';
		$email         = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$phone         = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
		$terms         = isset( $_POST['terms_acceptance'] ) ? sanitize_key( wp_unslash( $_POST['terms_acceptance'] ) ) : '';
		$plan          = $plan_id ? Plans_Repository::get( $plan_id ) : null;

		if ( ! self::checkout_referer_is_same_origin() ) {
			self::checkout_error( 'memberistic_checkout_cross_origin', __( 'Checkout request could not be verified. No payment was taken.', 'memberistic' ), 403, __( 'Checkout verification failed', 'memberistic' ) );
		}

		if ( ! $plan ) {
			self::checkout_error( 'memberistic_checkout_invalid_plan', __( 'The selected membership plan was not found or is no longer available. No payment was taken.', 'memberistic' ), 400, __( 'Invalid checkout request', 'memberistic' ) );
		}

		if ( '' === $full_name ) {
			self::checkout_error( 'memberistic_checkout_missing_name', __( 'Please enter your full name. No payment was taken.', 'memberistic' ), 400, __( 'Invalid checkout request', 'memberistic' ) );
		}

		if ( ! is_email( $email ) ) {
			self::checkout_error( 'memberistic_checkout_invalid_email', __( 'Please enter a valid email address. No payment was taken.', 'memberistic' ), 400, __( 'Invalid checkout request', 'memberistic' ) );
		}

		if ( 'yes' !== $terms ) {
			self::checkout_error( 'memberistic_checkout_terms_required', __( 'You must accept the terms to continue. No payment was taken.', 'memberistic' ), 400, __( 'Invalid checkout request', 'memberistic' ) );
		}

		// Age-verification gate (Verifyistic integration). When "require
		// verification before signup" is on and the visitor hasn't verified,
		// stop here before any membership / Stripe session is created.
		if ( class_exists( '\\WordPressistic\\Memberistic\\Integrations\\Verifyistic_Bridge' )
			&& \WordPressistic\Memberistic\Integrations\Verifyistic_Bridge::signup_blocked() ) {
			$back = wp_get_referer();
			wp_die(
				esc_html__( 'Please complete the age verification on the website before signing up for a membership.', 'memberistic' ),
				esc_html__( 'Age verification required', 'memberistic' ),
				array(
					'response'  => 403,
					'back_link' => true,
					'link_url'  => esc_url( $back ?: home_url( '/' ) ),
					'link_text' => esc_html__( 'Go back and verify', 'memberistic' ),
				)
			);
		}

		if ( ! in_array( $billing_cycle, array( 'monthly', 'annual' ), true ) ) {
			$billing_cycle = 'monthly';
		}

		$user_id = self::resolve_checkout_user( $email, $full_name );

		// Re-entrance guard: a refresh, back-button, or double-click on the
		// checkout form used to create a SECOND pending membership for the
		// same email. If a pending row already exists for this email, reuse
		// it (and its existing Stripe checkout URL) instead of inserting a
		// duplicate.
		$pending_existing = Memberships_Repository::get_pending_by_person_email( $email );
		if ( $pending_existing && ! empty( $pending_existing['id'] ) ) {
			$existing_session = self::resume_pending_checkout_session( $pending_existing, $plan, $billing_cycle, $email );
			if ( is_wp_error( $existing_session ) ) {
				self::checkout_error( $existing_session->get_error_code(), self::checkout_public_message_for_error( $existing_session ), self::checkout_status_for_error( $existing_session ), __( 'Checkout temporarily unavailable', 'memberistic' ) );
			}
			if ( ! empty( $existing_session['url'] ) ) {
				self::redirect_to_checkout_url( (string) $existing_session['url'], true );
			}
		}

		$mem_rl_key = 'memberistic_checkout_rl_' . md5( self::client_ip() . '|' . hash( 'sha256', strtolower( $email ) ) );
		$limit_check = self::atomic_check_and_increment( $mem_rl_key, (int) apply_filters( 'memberistic_checkout_rate_limit', 8 ), 10 * MINUTE_IN_SECONDS );
		if ( is_wp_error( $limit_check ) ) {
			self::checkout_error( $limit_check->get_error_code(), self::checkout_public_message_for_error( $limit_check ), self::checkout_status_for_error( $limit_check ), __( 'Checkout temporarily unavailable', 'memberistic' ) );
		}
		if ( ! $limit_check ) {
			if ( ! headers_sent() ) {
				status_header( 429 );
				header( 'Retry-After: 600' );
			}
			self::checkout_error( 'memberistic_checkout_rate_limited', __( 'Too many checkout attempts. Please wait a few minutes and try again.', 'memberistic' ), 429, __( 'Slow down', 'memberistic' ) );
		}

		$membership_id = Memberships_Repository::create(
			array(
				'plan_id'        => $plan_id,
				'primary_user_id'=> $user_id,
				'billing_cycle'  => $billing_cycle,
				'status'         => 'pending',
				'payment_source' => 'stripe',
				'created_by'     => get_current_user_id(),
			)
		);

		if ( ! $membership_id ) {
			self::checkout_error( 'memberistic_checkout_membership_create_failed', self::checkout_unavailable_message(), 503, __( 'Checkout temporarily unavailable', 'memberistic' ) );
		}

		$person_id = People_Repository::create(
			array(
				'membership_id' => $membership_id,
				'role'          => 'primary',
				'full_name'     => $full_name,
				'email'         => $email,
				'phone'         => $phone,
				'wp_user_id'    => $user_id,
				'waiver_status' => 'missing',
				'status'        => 'active',
			)
		);

		Activity_Repository::log(
			array(
				'membership_id' => $membership_id,
				'person_id'     => $person_id,
				'activity_type' => 'membership_created',
				'title'         => __( 'Online checkout started', 'memberistic' ),
			)
		);

		$session = self::create_checkout_session( $membership_id, $plan, $billing_cycle, $email );

		if ( is_wp_error( $session ) ) {
			self::record_manual_review( 'checkout_session_create_failed', $membership_id, array( 'message' => $session->get_error_message() ) );
			self::checkout_error( $session->get_error_code(), self::checkout_public_message_for_error( $session ), self::checkout_status_for_error( $session ), __( 'Checkout temporarily unavailable', 'memberistic' ) );
		}

		if ( empty( $session['url'] ) ) {
			self::record_manual_review( 'checkout_session_missing_url', $membership_id );
			self::checkout_error( 'memberistic_stripe_missing_checkout_url', __( 'We couldn\'t connect to secure payment checkout. No payment was taken. Please try again.', 'memberistic' ), 502, __( 'Checkout temporarily unavailable', 'memberistic' ) );
		}

		self::redirect_to_checkout_url( (string) $session['url'] );
	}

	private static function checkout_referer_is_same_origin() {
		$referer = wp_get_referer();
		if ( ! $referer ) {
			return true;
		}

		$referer_host = wp_parse_url( $referer, PHP_URL_HOST );
		if ( ! $referer_host ) {
			return true;
		}

		return strtolower( (string) $referer_host ) === strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
	}

	private static function redirect_to_checkout_url( $url, $allow_site_return = false ) {
		$redirect_url  = esc_url_raw( $url );
		$redirect_host = wp_parse_url( $redirect_url, PHP_URL_HOST );
		$allowed_hosts = array( 'checkout.stripe.com' );

		if ( $allow_site_return ) {
			$site_host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
			if ( $site_host ) {
				$allowed_hosts[] = $site_host;
			}
		}

		if ( ! $redirect_host || ! in_array( strtolower( (string) $redirect_host ), array_map( 'strtolower', $allowed_hosts ), true ) ) {
			self::checkout_error( 'memberistic_invalid_checkout_redirect', self::checkout_unavailable_message(), 502, __( 'Checkout temporarily unavailable', 'memberistic' ) );
		}

		wp_redirect( $redirect_url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
	}

	private static function checkout_public_message_for_error( $error ) {
		$code = is_wp_error( $error ) ? (string) $error->get_error_code() : '';

		if ( 'memberistic_rate_lock_contention' === $code ) {
			return __( 'Checkout is handling another request. Please wait a few seconds and try again.', 'memberistic' );
		}

		if ( false !== strpos( $code, 'rate_limited' ) ) {
			return __( 'Too many checkout attempts. Please wait a few minutes and try again.', 'memberistic' );
		}

		if ( false !== strpos( $code, 'stripe' ) || false !== strpos( $code, 'checkout_session' ) ) {
			return __( 'We couldn\'t connect to secure payment checkout. No payment was taken. Please try again.', 'memberistic' );
		}

		return self::checkout_unavailable_message();
	}

	/**
	 * "Checkout didn't start, nobody was charged, here's who to ask."
	 *
	 * Addressed to the customer, so it names the business rather than the
	 * plugin, falling back to a generic "support" when the site has not set
	 * a brand label yet. Deliberately never leaks the underlying error.
	 *
	 * @since 2.0.0
	 *
	 * @return string Translated, customer-safe message.
	 */
	private static function checkout_unavailable_message() {
		$brand = trim( (string) memberistic_get_brand_label() );

		if ( '' !== $brand ) {
			return sprintf(
				/* translators: %s: the business name shown to customers. */
				__( 'We couldn\'t start checkout. No payment was taken. Please contact %s if the issue continues.', 'memberistic' ),
				$brand
			);
		}

		return __( 'We couldn\'t start checkout. No payment was taken. Please contact support if the issue continues.', 'memberistic' );
	}

	private static function checkout_status_for_error( $error ) {
		$data = is_wp_error( $error ) ? $error->get_error_data() : array();
		if ( is_array( $data ) && ! empty( $data['status'] ) ) {
			return max( 400, min( 599, (int) $data['status'] ) );
		}

		$code = is_wp_error( $error ) ? (string) $error->get_error_code() : '';
		if ( 'memberistic_rate_lock_contention' === $code ) {
			return 409;
		}
		if ( false !== strpos( $code, 'rate_limited' ) ) {
			return 429;
		}
		if ( false !== strpos( $code, 'stripe' ) || false !== strpos( $code, 'checkout_session' ) ) {
			return 502;
		}
		return 503;
	}

	private static function checkout_error( $code, $message, $status = 400, $title = '' ) {
		self::send_checkout_no_cache_headers();
		$status = max( 400, min( 599, (int) $status ) );
		$title  = $title ? $title : __( 'Checkout unavailable', 'memberistic' );

		self::debug_log_checkout(
			sanitize_key( (string) $code ),
			array(
				'result' => 'checkout_error',
				'status' => $status,
			)
		);

		wp_die(
			esc_html( $message ),
			esc_html( $title ),
			array(
				'response'  => (int) $status,
				'back_link' => true,
			)
		);
	}

	private static function send_checkout_no_cache_headers() {
		if ( headers_sent() ) {
			return;
		}
		nocache_headers();
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		header( 'Pragma: no-cache' );
	}

	/**
	 * Pre-payment lookup ONLY. Returns the current user id, or a matching
	 * existing user, or 0. Does NOT create a new WP user — that would
	 * fire wp_new_user_notification() (and consume the email_exists()
	 * uniqueness slot) for any visitor who clicks Checkout, including
	 * spray attackers who supply someone else's email. User creation is
	 * deferred to ensure_user_for_completed_checkout(), invoked from
	 * handle_checkout_completed() after Stripe confirms payment.
	 */
	private static function resolve_checkout_user( $email, $full_name ) {
		$current_user_id = get_current_user_id();

		if ( $current_user_id ) {
			return $current_user_id;
		}

		$existing_user_id = email_exists( $email );

		if ( $existing_user_id ) {
			return (int) $existing_user_id;
		}

		return 0;
	}

	/**
	 * Post-payment user provisioning. Called from handle_checkout_completed
	 * once Stripe confirms a successful checkout. Creates the WP user if
	 * one didn't exist at checkout time and links it back to the
	 * membership + person rows.
	 */
	private static function ensure_user_for_completed_checkout( $membership_id ) {
		$membership = Memberships_Repository::get( $membership_id );
		if ( ! $membership ) {
			return 0;
		}

		if ( ! empty( $membership['primary_user_id'] ) ) {
			return (int) $membership['primary_user_id'];
		}

		$person = People_Repository::get_primary_by_membership( (int) $membership_id );
		if ( empty( $person['email'] ) || ! is_email( $person['email'] ) ) {
			return 0;
		}

		$email     = (string) $person['email'];
		$full_name = isset( $person['full_name'] ) ? (string) $person['full_name'] : '';

		$existing = email_exists( $email );
		if ( $existing ) {
			$user_id = (int) $existing;
		} else {
			$email_parts   = explode( '@', $email );
			$username_base = sanitize_user( $email_parts[0], true );
			$username      = $username_base ?: 'memberistic-member';
			$suffix        = 1;
			while ( username_exists( $username ) ) {
				$username = $username_base . '-' . $suffix;
				$suffix++;
			}
			$user_id = wp_insert_user(
				array(
					'user_login'   => $username,
					'user_email'   => $email,
					'display_name' => $full_name,
					'user_pass'    => wp_generate_password( 24, true, true ),
					'role'         => 'subscriber',
				)
			);
			if ( is_wp_error( $user_id ) ) {
				return 0;
			}
			if ( function_exists( 'wp_new_user_notification' ) ) {
				wp_new_user_notification( (int) $user_id, null, 'user' );
			}
		}

		Memberships_Repository::update( (int) $membership_id, array( 'primary_user_id' => (int) $user_id ) );
		if ( ! empty( $person['id'] ) ) {
			People_Repository::update( (int) $person['id'], array( 'wp_user_id' => (int) $user_id ) );
		}

		return (int) $user_id;
	}

	private static function resume_pending_checkout_session( $pending, $plan, $billing_cycle, $email ) {
		$membership_id = isset( $pending['id'] ) ? (int) $pending['id'] : 0;
		if ( ! $membership_id ) {
			return new \WP_Error( 'memberistic_missing_membership', __( 'Pending membership could not be found.', 'memberistic' ) );
		}

		$customer_id = '';
		if ( ! empty( $pending['stripe_checkout_session_id'] ) ) {
			$session = self::get_checkout_session( (string) $pending['stripe_checkout_session_id'] );
			if ( is_wp_error( $session ) ) {
				return $session;
			}

			$customer_id = isset( $session['customer'] ) ? sanitize_text_field( (string) $session['customer'] ) : '';
			$valid = self::validate_checkout_session_for_membership( $session, $membership_id, $plan, $billing_cycle, $email );
			if ( is_wp_error( $valid ) ) {
				self::record_manual_review( 'checkout_session_mismatch', $membership_id, array(
					'session_id' => self::mask_stripe_id( (string) $pending['stripe_checkout_session_id'] ),
					'message'    => $valid->get_error_message(),
				) );
				return $valid;
			}

			$paid = self::checkout_session_is_paid( $session );
			if ( is_wp_error( $paid ) ) {
				return $paid;
			}
			if ( $paid ) {
				$result = self::handle_checkout_completed( $session );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				return array(
					'url' => add_query_arg(
						array(
							'memberistic_checkout' => 'success',
							'membership_id'        => $membership_id,
							'session_id'           => sanitize_text_field( (string) $session['id'] ),
						),
						memberistic_get_page_url( 'thank_you_page_id', 'memberistic-thank-you', home_url( '/' ) )
					),
				);
			}

			if ( self::checkout_session_is_open( $session ) && ! empty( $session['url'] ) ) {
				return $session;
			}
		}

		$active_subscription = self::find_active_subscription_for_membership( $membership_id, $customer_id );
		if ( is_wp_error( $active_subscription ) ) {
			return $active_subscription;
		}
		if ( is_array( $active_subscription ) ) {
			self::record_manual_review( 'active_subscription_for_pending_membership', $membership_id, array(
				'subscription_id' => self::mask_stripe_id( (string) ( $active_subscription['id'] ?? '' ) ),
				'status'          => sanitize_text_field( (string) ( $active_subscription['status'] ?? '' ) ),
			) );
			return new \WP_Error( 'memberistic_duplicate_subscription_review', __( 'A Stripe subscription already appears to exist for this pending membership. Staff must review before creating another checkout.', 'memberistic' ) );
		}

		return self::create_checkout_session( $membership_id, $plan, $billing_cycle, $email );
	}

	public static function confirm_checkout_return( $membership_id, $session_id ) {
		$membership_id = absint( $membership_id );
		$session_id    = sanitize_text_field( (string) $session_id );
		if ( '' === $session_id ) {
			return array( 'state' => 'processing', 'title' => __( 'Payment Processing', 'memberistic' ), 'message' => __( 'We are waiting for Stripe confirmation. Please refresh this page in a moment or contact staff if it does not update.', 'memberistic' ) );
		}

		$session = self::get_checkout_session( $session_id );
		if ( is_wp_error( $session ) ) {
			return array( 'state' => 'manual_review', 'title' => __( 'Manual Review Required', 'memberistic' ), 'message' => __( 'Stripe confirmation could not be retrieved. Staff can reconcile this payment from Stripe.', 'memberistic' ) );
		}

		$metadata_id = ! empty( $session['metadata']['membership_id'] ) ? absint( $session['metadata']['membership_id'] ) : 0;
		if ( $membership_id && $metadata_id && $membership_id !== $metadata_id ) {
			self::record_manual_review( 'thank_you_membership_mismatch', $membership_id, array(
				'session_id'        => self::mask_stripe_id( $session_id ),
				'metadata_member_id'=> $metadata_id,
			) );
			return array( 'state' => 'manual_review', 'title' => __( 'Manual Review Required', 'memberistic' ), 'message' => __( 'The Stripe session does not match this membership record.', 'memberistic' ) );
		}

		$membership_id = $metadata_id ?: $membership_id;
		$membership    = $membership_id ? Memberships_Repository::get( $membership_id ) : null;
		if ( ! $membership ) {
			return array( 'state' => 'manual_review', 'title' => __( 'Manual Review Required', 'memberistic' ), 'message' => __( 'No matching membership record was found for this Stripe session.', 'memberistic' ) );
		}

		$plan = Plans_Repository::get( (int) $membership['plan_id'] );
		if ( ! $plan ) {
			return array( 'state' => 'manual_review', 'title' => __( 'Manual Review Required', 'memberistic' ), 'message' => __( 'The membership plan could not be verified.', 'memberistic' ) );
		}

		$person = People_Repository::get_primary_by_membership( $membership_id );
		$email  = ! empty( $person['email'] ) ? (string) $person['email'] : '';
		$valid  = self::validate_checkout_session_for_membership( $session, $membership_id, $plan, (string) $membership['billing_cycle'], $email );
		if ( is_wp_error( $valid ) ) {
			self::record_manual_review( 'thank_you_session_validation_failed', $membership_id, array(
				'session_id' => self::mask_stripe_id( $session_id ),
				'message'    => $valid->get_error_message(),
			) );
			return array( 'state' => 'manual_review', 'title' => __( 'Manual Review Required', 'memberistic' ), 'message' => __( 'The Stripe session did not pass membership verification.', 'memberistic' ) );
		}

		$paid = self::checkout_session_is_paid( $session );
		if ( is_wp_error( $paid ) ) {
			return array( 'state' => 'processing', 'title' => __( 'Payment Processing', 'memberistic' ), 'message' => __( 'Stripe has not returned a final subscription state yet. Please refresh this page in a moment.', 'memberistic' ) );
		}
		if ( ! $paid ) {
			$session_status = isset( $session['status'] ) ? (string) $session['status'] : '';
			return array(
				'state'   => 'expired' === $session_status ? 'failed' : 'processing',
				'title'   => 'expired' === $session_status ? __( 'Payment Not Completed', 'memberistic' ) : __( 'Payment Processing', 'memberistic' ),
				'message' => 'expired' === $session_status ? __( 'The Stripe checkout session expired before payment completed.', 'memberistic' ) : __( 'Stripe has not confirmed payment yet.', 'memberistic' ),
			);
		}

		$result = self::handle_checkout_completed( $session );
		if ( is_wp_error( $result ) ) {
			return array( 'state' => 'manual_review', 'title' => __( 'Manual Review Required', 'memberistic' ), 'message' => __( 'Payment was found, but local activation could not complete automatically.', 'memberistic' ) );
		}
		if ( false === $result || ( is_array( $result ) && 'manual_review' === (string) ( $result['status'] ?? '' ) ) ) {
			return array( 'state' => 'manual_review', 'title' => __( 'Manual Review Required', 'memberistic' ), 'message' => __( 'Payment was found, but staff must review this membership before activation can be completed.', 'memberistic' ) );
		}

		return array( 'state' => 'active', 'title' => __( 'Membership Active', 'memberistic' ), 'message' => __( 'Your payment is confirmed and your membership is active.', 'memberistic' ) );
	}

	public static function create_checkout_session( $membership_id, $plan, $billing_cycle, $email ) {
		$amount   = 'annual' === $billing_cycle ? (float) $plan['annual_price'] : (float) $plan['monthly_price'];
		$interval = 'annual' === $billing_cycle ? 'year' : 'month';

		if ( $amount <= 0 ) {
			return new \WP_Error( 'memberistic_invalid_amount', __( 'The selected plan does not have a valid price.', 'memberistic' ) );
		}

		$success_url = memberistic_get_page_url( 'thank_you_page_id', 'memberistic-thank-you', home_url( '/' ) );
		$cancel_url  = memberistic_get_page_url( 'failed_payment_page_id', 'memberistic-payment-failed', home_url( '/' ) );

		// Guard against a corrupted/unsupported admin currency setting that would
		// make Stripe reject the whole checkout session.
		$currency = strtolower( (string) memberistic_get_setting( 'currency', 'USD' ) );
		if ( ! in_array( $currency, array( 'usd', 'eur', 'gbp', 'cad', 'aud' ), true ) ) {
			$currency = 'usd';
		}

		$success_return_url = add_query_arg( array( 'memberistic_checkout' => 'success', 'membership_id' => $membership_id, 'session_id' => '{CHECKOUT_SESSION_ID}' ), $success_url );
		$success_return_url = str_replace( rawurlencode( '{CHECKOUT_SESSION_ID}' ), '{CHECKOUT_SESSION_ID}', $success_return_url );

		$payload = array(
			'mode'                                      => 'subscription',
			'customer_email'                            => $email,
			'client_reference_id'                       => (string) $membership_id,
			'success_url'                               => $success_return_url,
			'cancel_url'                                => add_query_arg( array( 'memberistic_checkout' => 'cancelled', 'membership_id' => $membership_id ), $cancel_url ),
			'line_items[0][quantity]'                   => 1,
			'line_items[0][price_data][currency]'       => $currency,
			'line_items[0][price_data][unit_amount]'    => (int) round( $amount * 100 ),
			'line_items[0][price_data][recurring][interval]' => $interval,
			'line_items[0][price_data][product_data][name]'  => $plan['name'] . ' Membership',
			'metadata[membership_id]'                   => $membership_id,
			'metadata[plan_id]'                         => $plan['id'],
			'metadata[billing_cycle]'                   => $billing_cycle,
			'subscription_data[metadata][membership_id]'=> $membership_id,
			'subscription_data[metadata][plan_id]'      => $plan['id'],
			'subscription_data[metadata][billing_cycle]'=> $billing_cycle,
		);

		$session = self::request(
			'POST',
			'/checkout/sessions',
			$payload,
			array( 'Idempotency-Key' => 'memberistic_checkout_' . absint( $membership_id ) . '_' . sanitize_key( $billing_cycle ) . '_' . md5( strtolower( (string) $email ) ) )
		);

		if ( ! is_wp_error( $session ) && ! empty( $session['id'] ) ) {
			Memberships_Repository::update(
				(int) $membership_id,
				array(
					'stripe_checkout_session_id' => sanitize_text_field( (string) $session['id'] ),
					'stripe_checkout_expires_at' => ! empty( $session['expires_at'] ) ? wp_date( 'Y-m-d H:i:s', (int) $session['expires_at'] ) : null,
				)
			);
		}

		return $session;
	}

	public static function request( $method, $endpoint, $payload = array(), $headers = array() ) {
		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array_merge( array(
				'Authorization'  => 'Bearer ' . self::get_secret_key(),
				'Stripe-Version' => self::API_VERSION,
			), (array) $headers ),
		);

		if ( ! empty( $payload ) ) {
			$args['body'] = $payload;
		}

		$response = wp_remote_request( self::API_BASE . $endpoint, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$message = ! empty( $body['error']['message'] ) ? $body['error']['message'] : __( 'Stripe request failed.', 'memberistic' );
			return new \WP_Error( 'memberistic_stripe_error', $message, array( 'status' => $code, 'response' => $body ) );
		}

		return is_array( $body ) ? $body : array();
	}

	private static function validate_checkout_session_for_membership( $session, $membership_id, $plan, $billing_cycle, $email = '' ) {
		if ( empty( $session['id'] ) ) {
			return new \WP_Error( 'memberistic_session_missing_id', __( 'Stripe Checkout Session is missing an id.', 'memberistic' ) );
		}
		if ( 'subscription' !== (string) ( $session['mode'] ?? '' ) ) {
			return new \WP_Error( 'memberistic_session_wrong_mode', __( 'Stripe Checkout Session mode is not subscription.', 'memberistic' ) );
		}

		$metadata = isset( $session['metadata'] ) && is_array( $session['metadata'] ) ? $session['metadata'] : array();
		if ( absint( $metadata['membership_id'] ?? 0 ) !== absint( $membership_id ) ) {
			return new \WP_Error( 'memberistic_session_membership_mismatch', __( 'Stripe Checkout Session does not belong to this membership.', 'memberistic' ) );
		}
		if ( ! empty( $metadata['plan_id'] ) && absint( $metadata['plan_id'] ) !== absint( $plan['id'] ?? 0 ) ) {
			return new \WP_Error( 'memberistic_session_plan_mismatch', __( 'Stripe Checkout Session plan does not match the membership.', 'memberistic' ) );
		}
		if ( ! empty( $metadata['billing_cycle'] ) && sanitize_key( (string) $metadata['billing_cycle'] ) !== sanitize_key( (string) $billing_cycle ) ) {
			return new \WP_Error( 'memberistic_session_cycle_mismatch', __( 'Stripe Checkout Session billing cycle does not match the membership.', 'memberistic' ) );
		}

		$site_live = 'live' === memberistic_get_setting( 'stripe_mode', 'test' );
		if ( array_key_exists( 'livemode', $session ) && (bool) $session['livemode'] !== $site_live ) {
			return new \WP_Error( 'memberistic_session_mode_mismatch', __( 'Stripe Checkout Session mode does not match this site.', 'memberistic' ) );
		}

		$expected_amount = 'annual' === sanitize_key( (string) $billing_cycle ) ? (float) ( $plan['annual_price'] ?? 0 ) : (float) ( $plan['monthly_price'] ?? 0 );
		$amount_total    = isset( $session['amount_total'] ) ? (int) $session['amount_total'] : 0;
		if ( $amount_total > 0 && (int) round( $expected_amount * 100 ) !== $amount_total ) {
			return new \WP_Error( 'memberistic_session_amount_mismatch', __( 'Stripe Checkout Session amount does not match the selected plan.', 'memberistic' ) );
		}

		$currency = strtolower( (string) memberistic_get_setting( 'currency', 'USD' ) );
		if ( ! in_array( $currency, array( 'usd', 'eur', 'gbp', 'cad', 'aud' ), true ) ) {
			$currency = 'usd';
		}
		if ( ! empty( $session['currency'] ) && strtolower( (string) $session['currency'] ) !== $currency ) {
			return new \WP_Error( 'memberistic_session_currency_mismatch', __( 'Stripe Checkout Session currency does not match this site.', 'memberistic' ) );
		}

		$expected_email = strtolower( trim( (string) $email ) );
		$session_email  = strtolower( trim( (string) ( $session['customer_email'] ?? ( $session['customer_details']['email'] ?? '' ) ) ) );
		if ( '' !== $expected_email && '' !== $session_email && $expected_email !== $session_email ) {
			return new \WP_Error( 'memberistic_session_email_mismatch', __( 'Stripe Checkout Session email does not match the membership.', 'memberistic' ) );
		}

		return true;
	}

	private static function checkout_session_is_open( $session ) {
		if ( 'open' !== (string) ( $session['status'] ?? '' ) ) {
			return false;
		}
		$expires_at = isset( $session['expires_at'] ) ? (int) $session['expires_at'] : 0;
		return ! $expires_at || $expires_at > time();
	}

	private static function checkout_session_is_paid( $session ) {
		$payment_status = (string) ( $session['payment_status'] ?? '' );
		if ( ! in_array( $payment_status, array( 'paid', 'no_payment_required' ), true ) ) {
			return false;
		}

		$subscription = $session['subscription'] ?? '';
		if ( is_array( $subscription ) ) {
			$status = (string) ( $subscription['status'] ?? '' );
		} elseif ( '' !== (string) $subscription ) {
			$subscription = self::get_subscription( (string) $subscription );
			if ( is_wp_error( $subscription ) ) {
				return $subscription;
			}
			$status = (string) ( $subscription['status'] ?? '' );
		} else {
			return new \WP_Error( 'memberistic_session_missing_subscription', __( 'Stripe Checkout Session has no subscription reference.', 'memberistic' ), array( 'status' => 503 ) );
		}

		if ( ! in_array( $status, array( 'active', 'trialing' ), true ) ) {
			return false;
		}

		return true;
	}

	private static function find_active_subscription_for_membership( $membership_id, $customer_id ) {
		$customer_id = trim( (string) $customer_id );
		if ( '' === $customer_id ) {
			return null;
		}

		$response = self::request( 'GET', '/subscriptions?customer=' . rawurlencode( $customer_id ) . '&status=all&limit=20' );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		foreach ( (array) ( $response['data'] ?? array() ) as $subscription ) {
			if ( ! is_array( $subscription ) ) {
				continue;
			}
			$status = (string) ( $subscription['status'] ?? '' );
			if ( ! in_array( $status, array( 'active', 'trialing' ), true ) ) {
				continue;
			}
			$metadata_id = absint( $subscription['metadata']['membership_id'] ?? 0 );
			if ( $metadata_id && $metadata_id === absint( $membership_id ) ) {
				return $subscription;
			}
		}

		return null;
	}

	private static function record_manual_review( $type, $membership_id, $context = array() ) {
		$items = get_option( 'memberistic_stripe_manual_review', array() );
		if ( ! is_array( $items ) ) {
			$items = array();
		}
		$items[] = array(
			'type'          => sanitize_key( (string) $type ),
			'membership_id' => absint( $membership_id ),
			'context'       => is_array( $context ) ? $context : array(),
			'created_at'    => current_time( 'mysql' ),
		);
		if ( count( $items ) > 200 ) {
			$items = array_slice( $items, -200 );
		}
		update_option( 'memberistic_stripe_manual_review', $items, false );
	}

	private static function mask_stripe_id( $id ) {
		$id = sanitize_text_field( (string) $id );
		if ( strlen( $id ) <= 10 ) {
			return $id;
		}
		return substr( $id, 0, 6 ) . '...' . substr( $id, -4 );
	}

	/**
	 * Whether the Stripe webhook secret is configured.
	 *
	 * Used to short-circuit incoming webhook requests before any payload parsing.
	 */
	public static function webhook_is_configured() {
		return '' !== trim( (string) memberistic_get_setting( 'stripe_webhook_secret', '' ) );
	}

	public static function verify_webhook_signature( $payload, $header ) {
		$secret = trim( (string) memberistic_get_setting( 'stripe_webhook_secret', '' ) );

		if ( '' === $secret || '' === $header ) {
			return false;
		}

		$timestamp = '';
		$signature = '';
		$parts     = explode( ',', $header );

		foreach ( $parts as $part ) {
			$pair = explode( '=', $part, 2 );
			if ( 2 !== count( $pair ) ) {
				continue;
			}
			if ( 't' === $pair[0] ) {
				$timestamp = $pair[1];
			}
			if ( 'v1' === $pair[0] ) {
				$signature = $pair[1];
			}
		}

		if ( '' === $timestamp || '' === $signature || abs( time() - (int) $timestamp ) > 300 ) {
			return false;
		}

		$expected = hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );
		return hash_equals( $expected, $signature );
	}

	/**
	 * Stripe webhook dedup: persistent processed-event-id store.
	 *
	 * Backed by an option (FIFO-capped at 500 ids) so a redeploy / object-
	 * cache flush doesn't reset dedup and cause a duplicate Payments row +
	 * duplicate receipt email on Stripe retries.
	 */
	private static function processed_events_option_key() {
		return 'memberistic_stripe_processed_events';
	}

	public static function is_event_processed( $event_id ) {
		$event_id = (string) $event_id;
		if ( '' === $event_id ) {
			return false;
		}
		$list = get_option( self::processed_events_option_key(), array() );
		if ( ! is_array( $list ) ) {
			$list = array();
		}
		return in_array( $event_id, $list, true );
	}

	public static function mark_event_processed( $event_id ) {
		$event_id = (string) $event_id;
		if ( '' === $event_id ) {
			return;
		}
		$list = get_option( self::processed_events_option_key(), array() );
		if ( ! is_array( $list ) ) {
			$list = array();
		}
		if ( in_array( $event_id, $list, true ) ) {
			return;
		}
		$list[] = $event_id;
		// FIFO cap — keep the most recent 500 ids.
		if ( count( $list ) > 500 ) {
			$list = array_slice( $list, -500 );
		}
		update_option( self::processed_events_option_key(), $list, false );
	}

	public static function process_webhook_event( $event ) {
		$type     = isset( $event['type'] ) ? $event['type'] : '';
		$event_id = isset( $event['id'] ) ? sanitize_text_field( (string) $event['id'] ) : '';
		$obj      = isset( $event['data']['object'] ) && is_array( $event['data']['object'] ) ? $event['data']['object'] : array();

		// Idempotency: Stripe retries on 5xx (and occasionally double-sends
		// on success). Without dedup, every retry creates a duplicate
		// Payments row, a duplicate Activity row, and a duplicate
		// membership_activated / payment_received email. We persist the
		// processed event ids in a capped option list so dedup survives an
		// object-cache flush (transients used to fail open after a redeploy).
		//
		// The check-then-mark is wrapped in the same MySQL advisory lock used
		// elsewhere in this class (atomic_check_and_increment) — two near-
		// simultaneous deliveries of the same event id (a documented Stripe
		// behavior) would otherwise both read is_event_processed() as false
		// before either finished mark_event_processed(), letting both through.
		$lock_key = '';
		if ( '' !== $event_id ) {
			$lock_key = 'stripe_evt_' . $event_id;
			$lock = self::acquire_lock( $lock_key );
			if ( is_wp_error( $lock ) ) {
				return $lock;
			}
			if ( ! $lock ) {
				return new \WP_Error( 'memberistic_stripe_webhook_locked', __( 'Could not acquire the idempotency lock; try again.', 'memberistic' ), array( 'status' => 503 ) );
			}
			if ( self::is_event_processed( $event_id ) ) {
				self::release_lock( $lock_key );
				do_action( 'memberistic_stripe_webhook_event_duplicate', $event_id, $type );
				return array( 'status' => 'duplicate', 'event_id' => $event_id );
			}
		}

		do_action( 'memberistic_stripe_webhook_event', $type, $obj, $event );

		// Inbound events reflect state Stripe already holds; flag the window
		// so the outbound-cancel listener on the status-changed hook doesn't
		// call Stripe back about a cancellation Stripe just reported.
		self::$processing_inbound_event = true;

		try {
			switch ( $type ) {
				case 'checkout.session.completed':
					$result = self::handle_checkout_completed( $obj );
					break;
				case 'customer.subscription.deleted':
					$result = self::handle_subscription_deleted( $obj );
					break;
				case 'invoice.payment_failed':
					$result = self::handle_invoice_failed( $obj );
					break;
				case 'invoice.payment_succeeded':
					$result = self::handle_invoice_succeeded( $obj );
					break;
				case 'payment_intent.succeeded':
				case 'payment_intent.payment_failed':
					// Payment-intent events are handled by the invoice events for
					// subscriptions; one-off PaymentIntents are not currently used
					// but the hook above lets integrations extend.
					$result = true;
					break;
				default:
					$result = true;
					break;
			}

			if ( is_wp_error( $result ) ) {
				return $result;
			}
			if ( false === $result ) {
				self::record_manual_review( 'webhook_permanent_mismatch', 0, array(
					'event_id' => self::mask_stripe_id( $event_id ),
					'type'     => sanitize_text_field( (string) $type ),
				) );
			}
			if ( '' !== $event_id ) {
				self::mark_event_processed( $event_id );
			}
			return false === $result ? array( 'status' => 'permanent_mismatch', 'type' => $type ) : $result;
		} finally {
			self::$processing_inbound_event = false;
			if ( '' !== $lock_key ) {
				self::release_lock( $lock_key );
			}
		}
	}

	/**
	 * Recurring renewal succeeded — bump renewal_date forward and log a payment.
	 *
	 * @param array<string, mixed> $invoice Invoice object payload.
	 */
	private static function invoice_subscription_id( $invoice ) {
		if ( ! empty( $invoice['subscription'] ) && is_string( $invoice['subscription'] ) ) {
			return sanitize_text_field( (string) $invoice['subscription'] );
		}
		if ( ! empty( $invoice['parent']['subscription_details']['subscription'] ) ) {
			return sanitize_text_field( (string) $invoice['parent']['subscription_details']['subscription'] );
		}
		if ( ! empty( $invoice['subscription_details']['subscription'] ) ) {
			return sanitize_text_field( (string) $invoice['subscription_details']['subscription'] );
		}
		return '';
	}

	private static function handle_invoice_succeeded( $invoice ) {
		$subscription_id = self::invoice_subscription_id( $invoice );

		if ( '' === $subscription_id && empty( $invoice['metadata']['membership_id'] ) ) {
			return false;
		}

		$membership = '' !== $subscription_id ? Memberships_Repository::get_by_stripe_subscription_id( $subscription_id ) : null;
		if ( ! $membership && ! empty( $invoice['metadata']['membership_id'] ) ) {
			$membership = Memberships_Repository::get( absint( $invoice['metadata']['membership_id'] ) );
		}

		if ( ! $membership ) {
			return false;
		}

		$membership_id = (int) $membership['id'];
		$billing_cycle = $membership['billing_cycle'];
		// Anchor the new renewal off the EXISTING renewal_date if the
		// member is being renewed before their current term ends — so
		// they keep the paid time. Falls back to "now" for first-time
		// invoices or expired memberships. Site-local time throughout.
		$now_local     = current_time( 'mysql' );
		$current_rd    = ! empty( $membership['renewal_date'] ) ? $membership['renewal_date'] : '';
		$anchor        = ( $current_rd && $current_rd > $now_local ) ? $current_rd : $now_local;
		$renewal_date  = \WordPressistic\Memberistic\Integrations\WooCommerce_Bridge::compute_next_renewal( $billing_cycle, $anchor );

		// Skip the very first invoice that fires on checkout completion — that path is
		// already handled by checkout.session.completed and creates the start_date row.
		$is_first_invoice = ! empty( $invoice['billing_reason'] ) && 'subscription_create' === $invoice['billing_reason'];

		Memberships_Repository::update(
			$membership_id,
			array(
				'status'       => 'active',
				'renewal_date' => $renewal_date,
			)
		);

		// The very first invoice fires alongside checkout.session.completed,
		// which already writes the Payments row + sends the receipt. Skip
		// the create() here to avoid a duplicate Payments row + duplicate
		// receipt for the same initial charge. A secondary safety guards
		// against any other path that might double-write the same txn id.
		if ( ! $is_first_invoice ) {
			$txn_id = isset( $invoice['payment_intent'] )
				? sanitize_text_field( (string) $invoice['payment_intent'] )
				: sanitize_text_field( (string) ( $invoice['id'] ?? '' ) );
			$already = '' !== $txn_id ? Payments_Repository::get_by_gateway_transaction_id( $txn_id ) : null;
			if ( ! $already ) {
				Payments_Repository::create(
					array(
						'membership_id'          => $membership_id,
						'amount'                 => ! empty( $invoice['amount_paid'] ) ? ( (float) $invoice['amount_paid'] / 100 ) : 0,
						'currency'               => ! empty( $invoice['currency'] ) ? strtoupper( $invoice['currency'] ) : 'USD',
						'payment_method'         => 'stripe_subscription',
						'payment_gateway'        => 'stripe',
						'gateway_transaction_id' => $txn_id,
						'status'                 => 'completed',
						'paid_at'                => current_time( 'mysql' ),
						'raw_response'           => $invoice,
					)
				);
			}
		}

		// On renewals (every invoice after the first) send two distinct
		// messages: a transactional charge receipt, then a renewal
		// confirmation. The very first invoice fires on subscription create
		// alongside checkout.session.completed, which already sends the
		// initial receipt + activation email — so we skip it here to avoid a
		// duplicate receipt for the same charge.
		if ( ! $is_first_invoice ) {
			$amount_paid = ! empty( $invoice['amount_paid'] ) ? ( (float) $invoice['amount_paid'] / 100 ) : 0;
			$currency    = ! empty( $invoice['currency'] ) ? strtoupper( $invoice['currency'] ) : 'USD';
			$txn         = isset( $invoice['payment_intent'] ) ? (string) $invoice['payment_intent'] : (string) ( $invoice['id'] ?? '' );

			Email_Service::send_membership_email(
				$membership_id,
				'payment_receipt',
				array(
					'amount'         => \WordPressistic\Memberistic\memberistic_format_price( $amount_paid, $currency ),
					// Actual transaction amount — the receipt template renders
					// {paid_amount} so partial/deposit charges never show the
					// full plan value.
					'paid_amount'    => \WordPressistic\Memberistic\memberistic_format_price( $amount_paid, $currency ),
					'transaction_id' => $txn,
					'payment_date'   => date_i18n( get_option( 'date_format' ) ),
					'payment_method' => __( 'Card on file (Stripe)', 'memberistic' ),
				)
			);

			Activity_Repository::log(
				array(
					'membership_id' => $membership_id,
					'activity_type' => 'membership_renewed',
					'title'         => __( 'Membership renewed via Stripe', 'memberistic' ),
				)
			);

			// Renewal confirmation — a second, distinct message from the receipt.
			Email_Service::send_membership_email( $membership_id, 'membership_renewed' );
		}

		do_action( 'memberistic_membership_activated', $membership_id );

		return true;
	}

	private static function handle_checkout_completed( $session ) {
		$metadata      = isset( $session['metadata'] ) && is_array( $session['metadata'] ) ? $session['metadata'] : array();
		$membership_id = ! empty( $metadata['membership_id'] ) ? absint( $metadata['membership_id'] ) : 0;

		if ( ! $membership_id ) {
			return false;
		}

		$lock_key = 'membership_activate_' . $membership_id;
		$lock = self::acquire_lock( $lock_key );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}
		if ( ! $lock ) {
			return new \WP_Error( 'memberistic_membership_locked', __( 'Membership activation is already processing.', 'memberistic' ), array( 'status' => 503 ) );
		}

		try {
		$membership = Memberships_Repository::get( $membership_id );

		if ( ! $membership ) {
			return false;
		}

		$plan = Plans_Repository::get( (int) $membership['plan_id'] );
		if ( ! $plan ) {
			return false;
		}

		$person = People_Repository::get_primary_by_membership( $membership_id );
		$email  = ! empty( $person['email'] ) ? (string) $person['email'] : '';
		$valid  = self::validate_checkout_session_for_membership( $session, $membership_id, $plan, (string) $membership['billing_cycle'], $email );
		if ( is_wp_error( $valid ) ) {
			self::record_manual_review( 'checkout_completed_validation_failed', $membership_id, array(
				'session_id' => self::mask_stripe_id( (string) ( $session['id'] ?? '' ) ),
				'message'    => $valid->get_error_message(),
			) );
			return false;
		}

		$paid = self::checkout_session_is_paid( $session );
		if ( is_wp_error( $paid ) ) {
			return $paid;
		}
		if ( ! $paid ) {
			return false;
		}

		$session_id = isset( $session['id'] ) ? sanitize_text_field( (string) $session['id'] ) : '';
		$subscription_id = '';
		if ( isset( $session['subscription'] ) && is_array( $session['subscription'] ) ) {
			$subscription_id = sanitize_text_field( (string) ( $session['subscription']['id'] ?? '' ) );
		} elseif ( isset( $session['subscription'] ) ) {
			$subscription_id = sanitize_text_field( (string) $session['subscription'] );
		}

		if ( 'active' === (string) ( $membership['status'] ?? '' ) ) {
			$same_session = '' !== $session_id && $session_id === (string) ( $membership['stripe_checkout_session_id'] ?? '' );
			$same_sub     = '' !== $subscription_id && $subscription_id === (string) ( $membership['stripe_subscription_id'] ?? '' );
			if ( $same_session || $same_sub ) {
				return true;
			}
			self::record_manual_review( 'active_membership_subscription_conflict', $membership_id, array(
				'existing_subscription_id' => self::mask_stripe_id( (string) ( $membership['stripe_subscription_id'] ?? '' ) ),
				'incoming_subscription_id' => self::mask_stripe_id( $subscription_id ),
				'incoming_session_id'      => self::mask_stripe_id( $session_id ),
			) );
			return array( 'status' => 'manual_review', 'reason' => 'active_subscription_conflict' );
		}

		// User creation is deferred from checkout-start to here so an
		// unauthenticated visitor can't spray wp_new_user_notification
		// at arbitrary emails by hitting the checkout endpoint.
		self::ensure_user_for_completed_checkout( $membership_id );

		$billing_cycle = $membership['billing_cycle'];
		$start_date    = current_time( 'mysql' );
		// Site-local renewal compute (was UTC-via-gmdate which mis-drifted
		// on non-UTC sites like Mesa AZ).
		$renewal_date  = \WordPressistic\Memberistic\Integrations\WooCommerce_Bridge::compute_next_renewal( $billing_cycle, $start_date );

		Memberships_Repository::update(
			$membership_id,
			array(
				'status'                 => 'active',
				'start_date'             => $start_date,
				'renewal_date'           => $renewal_date,
				'stripe_customer_id'      => isset( $session['customer'] ) ? sanitize_text_field( (string) $session['customer'] ) : '',
				'stripe_subscription_id'  => $subscription_id,
				'stripe_checkout_session_id' => $session_id,
				'stripe_checkout_expires_at' => ! empty( $session['expires_at'] ) ? wp_date( 'Y-m-d H:i:s', (int) $session['expires_at'] ) : null,
			)
		);

		$checkout_txn_id = isset( $session['payment_intent'] )
			? sanitize_text_field( (string) $session['payment_intent'] )
			: sanitize_text_field( (string) ( isset( $session['id'] ) ? $session['id'] : '' ) );
		$existing_payment = '' !== $checkout_txn_id ? Payments_Repository::get_by_gateway_transaction_id( $checkout_txn_id ) : null;
		if ( ! $existing_payment ) {
			Payments_Repository::create(
				array(
					'membership_id'          => $membership_id,
					'amount'                 => ! empty( $session['amount_total'] ) ? ( (float) $session['amount_total'] / 100 ) : 0,
					'currency'               => ! empty( $session['currency'] ) ? strtoupper( $session['currency'] ) : 'USD',
					'payment_method'         => 'stripe_checkout',
					'payment_gateway'        => 'stripe',
					'gateway_transaction_id' => $checkout_txn_id,
					'status'                 => 'completed',
					'paid_at'                => current_time( 'mysql' ),
					'raw_response'           => $session,
				)
			);
		}

		Activity_Repository::log(
			array(
				'membership_id' => $membership_id,
				'activity_type' => 'membership_activated',
				'title'         => __( 'Membership activated by Stripe checkout', 'memberistic' ),
			)
		);

		Email_Service::send_membership_email( $membership_id, 'membership_activated' );

		// Receipt for the initial charge (amount / reference) alongside the
		// welcome/activation email.
		$amount_total = ! empty( $session['amount_total'] ) ? ( (float) $session['amount_total'] / 100 ) : 0;
		if ( $amount_total > 0 ) {
			$currency = ! empty( $session['currency'] ) ? strtoupper( $session['currency'] ) : 'USD';
			$txn      = isset( $session['payment_intent'] ) ? (string) $session['payment_intent'] : (string) ( $session['id'] ?? '' );
			Email_Service::send_membership_email(
				$membership_id,
				'payment_receipt',
				array(
					'amount'         => \WordPressistic\Memberistic\memberistic_format_price( $amount_total, $currency ),
					// Actual transaction amount — the receipt template renders
					// {paid_amount} so partial/deposit charges never show the
					// full plan value.
					'paid_amount'    => \WordPressistic\Memberistic\memberistic_format_price( $amount_total, $currency ),
					'transaction_id' => $txn,
					'payment_date'   => date_i18n( get_option( 'date_format' ) ),
					'payment_method' => __( 'Card on file (Stripe)', 'memberistic' ),
				)
			);
		}

		do_action( 'memberistic_membership_activated', $membership_id );

		return true;
		} finally {
			self::release_lock( $lock_key );
		}
	}

	private static function handle_subscription_deleted( $subscription ) {
		// Trust the subscription_id over metadata: metadata can be stale (the
		// subscription may have been edited in the Stripe dashboard, or the
		// metadata.membership_id may point at a different row after a
		// re-subscribe), but stripe_subscription_id is authoritative on our
		// side. Fall back to metadata only when no row maps to the sub id.
		$membership_id = 0;
		if ( ! empty( $subscription['id'] ) ) {
			$existing = Memberships_Repository::get_by_stripe_subscription_id( sanitize_text_field( (string) $subscription['id'] ) );
			if ( $existing ) {
				$membership_id = (int) $existing['id'];
			}
		}

		if ( ! $membership_id && ! empty( $subscription['metadata']['membership_id'] ) ) {
			$membership_id = absint( $subscription['metadata']['membership_id'] );
		}

		if ( ! $membership_id ) {
			return false;
		}

		Memberships_Repository::change_status( $membership_id, 'cancelled' );
		Activity_Repository::log(
			array(
				'membership_id' => $membership_id,
				'activity_type' => 'membership_cancelled',
				'title'         => __( 'Stripe subscription cancelled', 'memberistic' ),
			)
		);

		Email_Service::send_membership_email( $membership_id, 'membership_cancelled' );

		return true;
	}

	private static function handle_invoice_failed( $invoice ) {
		$subscription_id = self::invoice_subscription_id( $invoice );

		if ( '' === $subscription_id && empty( $invoice['metadata']['membership_id'] ) ) {
			return false;
		}

		$membership = '' !== $subscription_id ? Memberships_Repository::get_by_stripe_subscription_id( $subscription_id ) : null;
		if ( ! $membership && ! empty( $invoice['metadata']['membership_id'] ) ) {
			$membership = Memberships_Repository::get( absint( $invoice['metadata']['membership_id'] ) );
		}

		if ( ! $membership ) {
			return false;
		}

		Memberships_Repository::change_status( (int) $membership['id'], 'past_due' );
		Activity_Repository::log(
			array(
				'membership_id' => (int) $membership['id'],
				'activity_type' => 'payment_failed',
				'title'         => __( 'Stripe invoice payment failed', 'memberistic' ),
			)
		);

		Email_Service::send_membership_email( (int) $membership['id'], 'payment_failed' );

		return true;
	}

	/**
	 * Atomic "still under cap? then charge one" for a transient-backed
	 * counter — the check and the increment happen as a single step (via a
	 * MySQL advisory lock) so a scripted concurrent burst can't have two
	 * requests both read the same stale count and both slip through.
	 *
	 * @return bool True when the counter was under `$max` and got charged.
	 */
	private static function atomic_check_and_increment( $key, $max, $ttl ) {
		$lock = self::acquire_lock( $key );
		if ( is_wp_error( $lock ) ) {
			return self::fallback_atomic_check_and_increment( $key, $max, $ttl, $lock );
		}
		if ( ! $lock ) {
			return new \WP_Error( 'memberistic_rate_lock_contention', __( 'Checkout is handling another request. Please wait a few seconds and try again.', 'memberistic' ), array( 'status' => 409 ) );
		}
		try {
			$count = (int) get_transient( $key );
			if ( $count >= $max ) {
				return false;
			}
			set_transient( $key, $count + 1, $ttl );
			return true;
		} finally {
			self::release_lock( $key );
		}
	}

	private static function client_ip() {
		$remote_addr = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( '' === $remote_addr || ! filter_var( $remote_addr, FILTER_VALIDATE_IP ) ) {
			return '0.0.0.0';
		}

		if ( self::remote_is_trusted_proxy( $remote_addr ) ) {
			$candidates = array();
			if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
				$candidates[] = trim( (string) wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
			}
			if ( ! empty( $_SERVER['HTTP_X_REAL_IP'] ) ) {
				$candidates[] = trim( (string) wp_unslash( $_SERVER['HTTP_X_REAL_IP'] ) );
			}
			if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
				$parts = explode( ',', (string) wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
				if ( ! empty( $parts[0] ) ) {
					$candidates[] = trim( $parts[0] );
				}
			}
			foreach ( $candidates as $candidate ) {
				if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
					return sanitize_text_field( $candidate );
				}
			}
		}

		return sanitize_text_field( $remote_addr );
	}

	private static function remote_is_trusted_proxy( $ip ) {
		foreach ( self::trusted_proxy_ranges() as $range ) {
			$range = trim( (string) $range );
			if ( '' === $range ) {
				continue;
			}
			if ( false === strpos( $range, '/' ) ) {
				if ( $ip === $range ) {
					return true;
				}
				continue;
			}
			if ( self::ip_in_cidr( $ip, $range ) ) {
				return true;
			}
		}
		return false;
	}

	private static function trusted_proxy_ranges() {
		$ranges = array(
			'173.245.48.0/20',
			'103.21.244.0/22',
			'103.22.200.0/22',
			'103.31.4.0/22',
			'141.101.64.0/18',
			'108.162.192.0/18',
			'190.93.240.0/20',
			'188.114.96.0/20',
			'197.234.240.0/22',
			'198.41.128.0/17',
			'162.158.0.0/15',
			'104.16.0.0/13',
			'104.24.0.0/14',
			'172.64.0.0/13',
			'131.0.72.0/22',
			'2400:cb00::/32',
			'2606:4700::/32',
			'2803:f800::/32',
			'2405:b500::/32',
			'2405:8100::/32',
			'2a06:98c0::/29',
			'2c0f:f248::/32',
		);
		if ( defined( 'MEMBERISTIC_TRUSTED_PROXIES' ) && MEMBERISTIC_TRUSTED_PROXIES ) {
			$configured = array_filter( array_map( 'trim', explode( ',', (string) MEMBERISTIC_TRUSTED_PROXIES ) ) );
			if ( $configured ) {
				$ranges = array_merge( $ranges, $configured );
			}
		}
		$option = get_option( 'memberistic_trusted_proxies', array() );
		if ( is_string( $option ) ) {
			$option = array_filter( array_map( 'trim', preg_split( '/[\r\n,]+/', $option ) ) );
		}
		if ( is_array( $option ) ) {
			$ranges = array_merge( $ranges, $option );
		}
		return apply_filters( 'memberistic_trusted_proxies', array_values( array_unique( $ranges ) ) );
	}

	private static function ip_in_cidr( $ip, $cidr ) {
		$parts = explode( '/', (string) $cidr, 2 );
		if ( 2 !== count( $parts ) ) {
			return false;
		}
		list( $subnet, $bits ) = $parts;
		$ip_bin     = @inet_pton( $ip );
		$subnet_bin = @inet_pton( $subnet );
		if ( false === $ip_bin || false === $subnet_bin || strlen( $ip_bin ) !== strlen( $subnet_bin ) ) {
			return false;
		}
		$bits      = max( 0, min( strlen( $ip_bin ) * 8, (int) $bits ) );
		$full      = intdiv( $bits, 8 );
		$remainder = $bits % 8;
		if ( $full && substr( $ip_bin, 0, $full ) !== substr( $subnet_bin, 0, $full ) ) {
			return false;
		}
		if ( 0 === $remainder ) {
			return true;
		}
		$mask = ( 0xff << ( 8 - $remainder ) ) & 0xff;
		return ( ord( $ip_bin[ $full ] ) & $mask ) === ( ord( $subnet_bin[ $full ] ) & $mask );
	}

	/**
	 * MySQL advisory lock. Duck-types `$wpdb` (rather than requiring the
	 * real `wpdb` class) so it silently no-ops if a request context somehow
	 * lacks it; every real WordPress request has `$wpdb`.
	 */
	private static function acquire_lock( $key, $timeout = 3 ) {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return new \WP_Error( 'memberistic_lock_database_unavailable', __( 'Database locking is unavailable.', 'memberistic' ), array( 'status' => 503 ) );
		}

		$lock_name = self::rate_limit_lock_name( $key );
		$result    = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_name, max( 0, $timeout ) ) );
		$category  = self::lock_result_category( $result );

		if ( 'acquired' === $category ) {
			return true;
		}

		self::debug_log_checkout(
			'memberistic_lock_' . $category,
			array(
				'lock_name_length' => strlen( $lock_name ),
				'result'           => $category,
			)
		);

		if ( 'contention' === $category ) {
			return false;
		}

		return new \WP_Error( 'memberistic_lock_database_' . $category, __( 'Database locking is unavailable.', 'memberistic' ), array( 'status' => 503 ) );
	}

	private static function release_lock( $key ) {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return;
		}
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::rate_limit_lock_name( $key ) ) );
	}

	private static function rate_limit_lock_name( $key ) {
		$prefix = 'memberistic_rl_';
		return $prefix . substr( hash( 'sha256', (string) $key ), 0, 64 - strlen( $prefix ) );
	}

	private static function lock_result_category( $result ) {
		if ( null === $result || false === $result ) {
			return 'failure';
		}
		if ( 1 === (int) $result ) {
			return 'acquired';
		}
		if ( 0 === (int) $result ) {
			return 'contention';
		}
		return 'failure';
	}

	private static function fallback_atomic_check_and_increment( $key, $max, $ttl, $lock_error ) {
		global $wpdb;

		self::debug_log_checkout(
			'memberistic_rate_limit_lock_fallback',
			array(
				'result'           => 'fallback',
				'lock_error_code'  => is_wp_error( $lock_error ) ? $lock_error->get_error_code() : '',
				'lock_name_length' => strlen( self::rate_limit_lock_name( $key ) ),
			)
		);

		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'query' ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return new \WP_Error( 'memberistic_rate_limit_unavailable', __( 'Checkout rate limiting is unavailable.', 'memberistic' ), array( 'status' => 503 ) );
		}

		$table       = $wpdb->prefix . 'memberistic_rate_limits';
		$key_hash    = hash( 'sha256', (string) $key );
		$now_ts      = current_time( 'timestamp' );
		$now         = wp_date( 'Y-m-d H:i:s', $now_ts );
		$expires_at  = wp_date( 'Y-m-d H:i:s', $now_ts + max( 1, (int) $ttl ) );
		$table_sql   = esc_sql( $table );

		$query = $wpdb->prepare(
			"INSERT INTO `{$table_sql}` ( rate_key_hash, attempt_count, window_started_at, expires_at, updated_at )
			VALUES ( %s, 1, %s, %s, %s )
			ON DUPLICATE KEY UPDATE
				attempt_count = IF( expires_at <= VALUES( updated_at ), 1, attempt_count + 1 ),
				window_started_at = IF( expires_at <= VALUES( updated_at ), VALUES( window_started_at ), window_started_at ),
				expires_at = IF( expires_at <= VALUES( updated_at ), VALUES( expires_at ), expires_at ),
				updated_at = VALUES( updated_at )",
			$key_hash,
			$now,
			$expires_at,
			$now
		);

		if ( false === $wpdb->query( $query ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			self::debug_log_checkout( 'memberistic_rate_limit_fallback_query_failed', array( 'result' => 'failure' ) );
			return new \WP_Error( 'memberistic_rate_limit_unavailable', __( 'Checkout rate limiting is unavailable.', 'memberistic' ), array( 'status' => 503 ) );
		}

		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT attempt_count FROM `{$table_sql}` WHERE rate_key_hash = %s LIMIT 1", $key_hash ) );
		return $count <= max( 1, (int) $max );
	}

	public static function prune_expired_rate_limits() {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'query' ) ) {
			return;
		}
		$table = esc_sql( $wpdb->prefix . 'memberistic_rate_limits' );
		$wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE expires_at < %s", current_time( 'mysql' ) ) );
	}

	private static function checkout_request_id() {
		if ( '' === self::$checkout_request_id ) {
			self::$checkout_request_id = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'memberistic_', true );
		}
		return self::$checkout_request_id;
	}

	private static function debug_log_checkout( $code, $context = array() ) {
		if ( ! defined( 'WP_DEBUG_LOG' ) || ! WP_DEBUG_LOG ) {
			return;
		}

		global $wpdb;
		$db_version = '';
		if ( is_object( $wpdb ) && method_exists( $wpdb, 'get_var' ) ) {
			$db_version = (string) $wpdb->get_var( 'SELECT VERSION()' );
			$db_version = preg_replace( '/[^0-9A-Za-z.\-_+ ]/', '', $db_version );
		}

		$payload = array_merge(
			array(
				'code'       => sanitize_key( (string) $code ),
				'request_id' => sanitize_text_field( self::checkout_request_id() ),
				'db_version' => $db_version,
			),
			is_array( $context ) ? $context : array()
		);

		error_log( 'Memberistic checkout diagnostic: ' . wp_json_encode( $payload ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}
