<?php
/**
 * Transactional email service.
 *
 * Lifecycle responsibility:
 *  - Render templates with the 20 documented merge tags.
 *  - Dispatch through wp_mail() with branded From headers.
 *  - Log every send (success or failure) to the Activity feed and the
 *    dedicated memberistic_email_logs table so the front desk can audit
 *    renewals, payment-failure follow-ups, and waiver reminders.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic\Emails;

use WordPressistic\Memberistic\Database\Activity_Repository;
use WordPressistic\Memberistic\Database\Email_Logs_Repository;
use WordPressistic\Memberistic\Database\Memberships_Repository;
use WordPressistic\Memberistic\Database\People_Repository;
use function WordPressistic\Memberistic\memberistic_get_brand_label;
use function WordPressistic\Memberistic\memberistic_get_page_url;
use function WordPressistic\Memberistic\memberistic_get_setting;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Email_Service {
	/**
	 * Plain-text AltBody for the message currently being dispatched —
	 * attached via phpmailer_init so branded HTML mail goes out
	 * multipart/alternative with the original plain-text template body.
	 *
	 * @var string
	 */
	private static $alt_body = '';

	/** @var bool */
	private static $mail_hooks_registered = false;

	/**
	 * Registered transactional templates exposed to the admin UI.
	 *
	 * @return array<int, array<string, string>>
	 */
	public static function templates() {
		return apply_filters(
			'memberistic_email_templates',
			array(
				array(
					'id'          => 'membership_created',
					'label'       => __( 'Membership Created', 'memberistic' ),
					'description' => __( 'Confirms a new membership was set up. Sent automatically on checkout; safe to resend.', 'memberistic' ),
				),
				array(
					'id'          => 'membership_activated',
					'label'       => __( 'Membership Activated', 'memberistic' ),
					'description' => __( 'Welcomes a member when their membership becomes active.', 'memberistic' ),
				),
				array(
					'id'          => 'membership_renewed',
					'label'       => __( 'Membership Renewed', 'memberistic' ),
					'description' => __( 'Receipt sent after a successful renewal payment or staff renewal action.', 'memberistic' ),
				),
				array(
					'id'          => 'payment_receipt',
					'label'       => __( 'Payment Receipt', 'memberistic' ),
					'description' => __( 'Transactional receipt sent every time a card is charged (amount, date, reference).', 'memberistic' ),
				),
				array(
					'id'          => 'renewal_reminder',
					'label'       => __( 'Renewal Reminder (Generic)', 'memberistic' ),
					'description' => __( 'Generic renewal reminder used when no specific window template is configured.', 'memberistic' ),
				),
				array(
					'id'          => 'expiring_30_days',
					'label'       => __( 'Expiring in 30 Days', 'memberistic' ),
					'description' => __( 'Heads-up notice 30 days before renewal_date.', 'memberistic' ),
				),
				array(
					'id'          => 'expiring_7_days',
					'label'       => __( 'Expiring in 7 Days', 'memberistic' ),
					'description' => __( 'Sent 7 days before renewal_date.', 'memberistic' ),
				),
				array(
					'id'          => 'expiring_tomorrow',
					'label'       => __( 'Expiring Tomorrow', 'memberistic' ),
					'description' => __( 'Last-day-of-membership reminder.', 'memberistic' ),
				),
				array(
					'id'          => 'membership_expired',
					'label'       => __( 'Membership Expired', 'memberistic' ),
					'description' => __( 'Sent after the membership expires.', 'memberistic' ),
				),
				array(
					'id'          => 'payment_failed',
					'label'       => __( 'Payment Failed', 'memberistic' ),
					'description' => __( 'Notifies the member that the most recent charge failed.', 'memberistic' ),
				),
				array(
					'id'          => 'membership_cancelled',
					'label'       => __( 'Membership Cancelled', 'memberistic' ),
					'description' => __( 'Confirms cancellation and final billing window.', 'memberistic' ),
				),
				array(
					'id'          => 'linked_member_added',
					'label'       => __( 'Linked Member Added', 'memberistic' ),
					'description' => __( 'Confirms a new linked / family member was attached to the membership.', 'memberistic' ),
				),
				array(
					'id'          => 'waiver_missing',
					'label'       => __( 'Waiver Missing', 'memberistic' ),
					'description' => __( 'Reminds the member to complete an outstanding waiver before benefits unlock.', 'memberistic' ),
				),
				array(
					'id'          => 'waiver_renewal',
					'label'       => __( 'Waiver Renewal Reminder', 'memberistic' ),
					'description' => __( 'Sent before a signed waiver expires so the member can re-sign in advance.', 'memberistic' ),
				),
				array(
					'id'          => 'staff_manual',
					'label'       => __( 'Staff Manual Message', 'memberistic' ),
					'description' => __( 'Generic staff-initiated message sent from the member profile.', 'memberistic' ),
				),
			)
		);
	}

	/**
	 * Whether a template id is supported.
	 *
	 * @param string $template Template id.
	 */
	public static function template_exists( $template ) {
		foreach ( self::templates() as $row ) {
			if ( $row['id'] === $template ) {
				return true;
			}
		}

		return false;
	}

	public static function send_membership_email( $membership_id, $template, $extra_context = array() ) {
		$membership = Memberships_Repository::get_with_summary( absint( $membership_id ) );

		if ( ! $membership ) {
			return false;
		}

		$person = People_Repository::get_primary_by_membership( (int) $membership['id'] );

		if ( empty( $person['email'] ) || ! is_email( $person['email'] ) ) {
			return false;
		}

		// Global kill-switch. Use the MEMBERISTIC_EMAIL_DISABLED constant in
		// wp-config.php (recommended for staging/dev) or the
		// memberistic_emails_disabled option for an admin-toggleable switch.
		if ( ( defined( 'MEMBERISTIC_EMAIL_DISABLED' ) && MEMBERISTIC_EMAIL_DISABLED )
			|| (bool) get_option( 'memberistic_emails_disabled', false ) ) {
			Email_Logs_Repository::log(
				array(
					'membership_id' => (int) $membership['id'],
					'person_id'     => (int) $person['id'],
					'template'      => $template,
					'recipient'     => $person['email'],
					'subject'       => '',
					'status'        => 'suppressed',
					'error_message' => __( 'Suppressed: email kill-switch is enabled.', 'memberistic' ),
				)
			);
			return false;
		}

		// Allow integrators to short-circuit individual templates per membership.
		$should_send = apply_filters( 'memberistic_should_send_email', true, $template, $membership, $person, $extra_context );
		if ( ! $should_send ) {
			return false;
		}

		$context = self::build_context( $membership, $person, $extra_context );
		$message = self::build_message( $template, $context );

		// Staging override: redirect all outbound to a single staff inbox.
		$recipient = $person['email'];
		$override  = defined( 'MEMBERISTIC_EMAIL_OVERRIDE_RECIPIENT' )
			? MEMBERISTIC_EMAIL_OVERRIDE_RECIPIENT
			: (string) get_option( 'memberistic_email_override_recipient', '' );
		if ( $override && is_email( $override ) ) {
			$recipient          = $override;
			$message['subject'] = '[REROUTED -> ' . $person['email'] . '] ' . $message['subject'];
		}

		// Branded HTML mode (default on): wrap the rendered plain-text body
		// in the shared 600px shell. Plain-text sending stays fully intact
		// when the email_html_enabled setting is switched off, and HTML
		// sends carry the original plain-text body as a multipart
		// alternative (see phpmailer_init hook below).
		$html_mode = self::html_enabled();
		$body      = $html_mode
			? self::wrap_html( $message['body'], $message['subject'], $context, $template )
			: $message['body'];

		if ( $html_mode ) {
			self::register_mail_hooks();
			self::$alt_body = (string) $message['body'];
		}

		$sent = wp_mail( $recipient, $message['subject'], $body, self::headers( $html_mode ) );

		self::$alt_body = '';

		Email_Logs_Repository::log(
			array(
				'membership_id' => (int) $membership['id'],
				'person_id'     => (int) $person['id'],
				'template'      => $template,
				'recipient'     => $recipient,
				'subject'       => $message['subject'],
				'status'        => $sent ? 'sent' : 'failed',
				'error_message' => $sent ? '' : __( 'wp_mail() returned false.', 'memberistic' ),
			)
		);

		if ( $sent ) {
			Activity_Repository::log(
				array(
					'membership_id'       => (int) $membership['id'],
					'person_id'           => (int) $person['id'],
					'activity_type'       => 'email_sent',
					'title'               => sprintf(
						/* translators: %s: email template key */
						__( 'Email sent: %s', 'memberistic' ),
						str_replace( '_', ' ', $template )
					),
					'related_object_type' => 'email',
					'related_object_id'   => $template,
				)
			);
		}

		return $sent;
	}

	/**
	 * Build the merge-tag context array for a membership.
	 *
	 * Exposes the 20 documented merge tags so template overrides via the
	 * `memberistic_email_template_body` / `memberistic_email_template_subject`
	 * filters can stay declarative.
	 */
	private static function build_context( $membership, $person, $extra_context = array() ) {
		$brand        = memberistic_get_brand_label();
		// Account URL: prefer the customer's branded /account/ slug,
		// then the historic /memberistic-account/ slug, then home as a
		// final fallback. This means emails never leak the plugin's
		// internal namespace even on installs that didn't wire the
		// page in Memberistic → Settings.
		$account_url  = memberistic_get_page_url( 'account_page_id', 'account', '' );
		if ( ! $account_url ) {
			$account_url = memberistic_get_page_url( 'account_page_id', 'memberistic-account', home_url( '/account/' ) );
		}
		$renewal_url  = memberistic_get_page_url( 'renewal_page_id', 'account', $account_url );
		// Booking URL: prefer a real Book-a-Lane page, then the
		// legacy memberistic-memberships slug, then home.
		$booking_url  = memberistic_get_page_url( 'booking_page_id', 'book-a-lane', '' );
		if ( ! $booking_url ) {
			$booking_url = memberistic_get_page_url( 'plans_page_id', 'memberistic-memberships', home_url( '/book-a-lane/' ) );
		}
		$current_user = wp_get_current_user();
		$staff_name   = $current_user && $current_user->exists() ? $current_user->display_name : '';

		// {payment_link}: members on a Stripe subscription must land on the
		// Stripe billing-portal handler (update card / retry the failed
		// charge), built exactly the way the account dashboard builds it via
		// Stripe_Service::billing_portal_action_url(). Only members with NO
		// Stripe subscription/customer fall back to the generic renewal page.
		$payment_link = $renewal_url;
		$stripe_sub   = trim( (string) ( $membership['stripe_subscription_id'] ?? '' ) );
		$stripe_cust  = trim( (string) ( $membership['stripe_customer_id'] ?? '' ) );
		if ( ( '' !== $stripe_sub || '' !== $stripe_cust )
			&& class_exists( '\WordPressistic\Memberistic\Payments\Stripe_Service' )
			&& \WordPressistic\Memberistic\Payments\Stripe_Service::is_enabled() ) {
			$payment_link = \WordPressistic\Memberistic\Payments\Stripe_Service::billing_portal_action_url();
		}

		// Tokenised self-serve waiver link for this member (no login needed).
		// Falls back to the account page if the person has no WP user yet.
		$waiver_url = '';
		if ( ! empty( $person['wp_user_id'] ) && class_exists( '\WordPressistic\Memberistic\Waivers\Waiver_Service' ) ) {
			$waiver_url = \WordPressistic\Memberistic\Waivers\Waiver_Service::waiver_url( (int) $person['wp_user_id'] );
		}

		$context = array(
			'{member_name}'        => (string) ( $person['full_name'] ?? '' ),
			'{membership_id}'      => (string) ( $membership['membership_uuid'] ?? '' ),
			'{plan_name}'          => (string) ( $membership['plan_name'] ?? '' ),
			'{status}'             => ucwords( str_replace( '_', ' ', (string) ( $membership['status'] ?? '' ) ) ),
			'{billing_cycle}'      => ucwords( str_replace( '_', ' ', (string) ( $membership['billing_cycle'] ?? '' ) ) ),
			'{renewal_date}'       => ! empty( $membership['renewal_date'] ) ? date_i18n( get_option( 'date_format' ), strtotime( $membership['renewal_date'] ) ) : __( 'Not set', 'memberistic' ),
			'{expiration_date}'    => ! empty( $membership['end_date'] ) ? date_i18n( get_option( 'date_format' ), strtotime( $membership['end_date'] ) ) : ( ! empty( $membership['renewal_date'] ) ? date_i18n( get_option( 'date_format' ), strtotime( $membership['renewal_date'] ) ) : __( 'Not set', 'memberistic' ) ),
			'{amount}'             => isset( $extra_context['amount'] ) ? (string) $extra_context['amount'] : '',
			// Actual transaction amount (partial/deposit charges included) —
			// receipts must show what was charged, never the full plan value.
			'{paid_amount}'        => isset( $extra_context['paid_amount'] )
				? (string) $extra_context['paid_amount']
				: ( isset( $extra_context['amount'] ) ? (string) $extra_context['amount'] : '' ),
			'{transaction_id}'     => isset( $extra_context['transaction_id'] ) ? (string) $extra_context['transaction_id'] : '',
			'{payment_date}'       => isset( $extra_context['payment_date'] ) ? (string) $extra_context['payment_date'] : date_i18n( get_option( 'date_format' ) ),
			'{payment_method}'     => isset( $extra_context['payment_method'] ) ? (string) $extra_context['payment_method'] : __( 'Card on file', 'memberistic' ),
			'{payment_link}'       => $payment_link,
			'{renewal_link}'       => $renewal_url,
			'{account_url}'        => $account_url,
			'{booking_url}'        => $booking_url,
			'{business_name}'      => (string) memberistic_get_setting( 'business_name', $brand ),
			'{business_phone}'     => (string) memberistic_get_setting( 'business_phone', '' ),
			'{business_address}'   => (string) memberistic_get_setting( 'business_address', '' ),
			'{site_url}'           => home_url( '/' ),
			'{linked_member_name}' => isset( $extra_context['linked_member_name'] ) ? (string) $extra_context['linked_member_name'] : '',
			'{waiver_status}'      => ucwords( str_replace( '_', ' ', (string) ( $person['waiver_status'] ?? 'missing' ) ) ),
			'{waiver_url}'         => $waiver_url ?: $account_url,
			'{waiver_expires}'     => isset( $extra_context['waiver_expires'] )
				? (string) $extra_context['waiver_expires']
				: ( ! empty( $person['waiver_expires_at'] ) ? date_i18n( get_option( 'date_format' ), strtotime( (string) $person['waiver_expires_at'] ) ) : '' ),
			'{staff_name}'         => $staff_name,
			'{support_email}'      => (string) memberistic_get_setting( 'email_from_address', get_option( 'admin_email' ) ),
			'{logo_url}'           => (string) memberistic_get_setting( 'logo_url', '' ),
			'{brand_label}'        => $brand,
			// wp_login_url() + wp_lostpassword_url() honor the theme's
			// login_url / lostpassword_url filters, so members get the
			// branded /login/ URL instead of /wp-login.php in every
			// templated email.
			'{login_url}'          => function_exists( 'wp_login_url' ) ? wp_login_url() : home_url( '/login/' ),
			'{lostpassword_url}'   => function_exists( 'wp_lostpassword_url' ) ? wp_lostpassword_url() : home_url( '/login/?action=lostpassword' ),
		);

		return apply_filters( 'memberistic_email_merge_tags', $context, $membership, $person, $extra_context );
	}

	private static function build_message( $template, $context ) {
		$defaults = self::default_template_strings();
		$subject  = $defaults[ $template ]['subject'] ?? sprintf(
			/* translators: %s: the site's Memberistic brand label. */
			__( '%s membership update', 'memberistic' ),
			$context['{brand_label}']
		);
		$body     = $defaults[ $template ]['body'] ?? '';

		if ( '' === $body ) {
			// Generic body falls back to a status summary.
			$body = __(
				"Hi {member_name},\n\nMembership: {membership_id}\nPlan: {plan_name}\nStatus: {status}\nRenewal: {renewal_date}\n\nView your account here:\n{account_url}\n\n{brand_label}",
				'memberistic'
			);
		}

		$subject = apply_filters( 'memberistic_email_template_subject', $subject, $template, $context );
		$body    = apply_filters( 'memberistic_email_template_body', $body, $template, $context );

		return array(
			'subject' => strtr( $subject, $context ),
			'body'    => strtr( $body, $context ),
		);
	}

	private static function default_template_strings() {
		return array(
			'membership_created'   => array(
				'subject' => __( 'Your {brand_label} membership was started', 'memberistic' ),
				'body'    => __( "Hi {member_name},\n\nGood news — your {plan_name} membership ({membership_id}) has been created. Once payment is confirmed, your membership becomes active automatically and we'll send a welcome email with everything you need.\n\nView your account: {account_url}\n\nQuestions? Just reply to this email and our team will help.\n\n{brand_label}\n{business_phone}", 'memberistic' ),
			),
			'membership_activated' => array(
				'subject' => __( 'Welcome to {brand_label} — your membership is active', 'memberistic' ),
				'body'    => __( "Hi {member_name},\n\nWelcome to {brand_label}! Your {plan_name} membership is now active.\n\nMembership ID: {membership_id}\nBilling cycle: {billing_cycle}\nNext renewal: {renewal_date}\n\nSign in to your account:\n{login_url}\n\nForgot your password? Reset it here:\n{lostpassword_url}\n\nManage your membership and download your digital card:\n{account_url}\n\nSign your range waiver (required before your first visit):\n{waiver_url}\n\nBook a lane:\n{booking_url}\n\nSee you at the range.\n\n{brand_label}\n{business_phone}", 'memberistic' ),
			),
			'membership_renewed'   => array(
				'subject' => __( 'Your {brand_label} membership was renewed', 'memberistic' ),
				'body'    => __( "Hi {member_name},\n\nThanks for renewing your {plan_name} membership ({membership_id}).\nNext renewal: {renewal_date}\n\n{brand_label}\n{account_url}", 'memberistic' ),
			),
			'payment_receipt'      => array(
				'subject' => __( 'Your {brand_label} payment receipt', 'memberistic' ),
				'body'    => __( "Hi {member_name},\n\nThis confirms a payment was processed for your {plan_name} membership ({membership_id}).\n\nAmount paid: {paid_amount}\nDate: {payment_date}\nPayment method: {payment_method}\nReference: {transaction_id}\n\nNext renewal: {renewal_date}\n\nKeep this email for your records. View your full billing history any time: {account_url}\n\nThanks for being a member.\n\n{brand_label}\n{business_phone}", 'memberistic' ),
			),
			'renewal_reminder'     => array(
				'subject' => __( 'Your {brand_label} membership renewal is coming up', 'memberistic' ),
				'body'    => __( "Hi {member_name},\n\nYour {plan_name} membership renews on {renewal_date}.\n\nManage or renew your membership: {renewal_link}\n\n{brand_label}", 'memberistic' ),
			),
			'expiring_30_days'     => array(
				'subject' => __( '30 days until your {brand_label} membership renews', 'memberistic' ),
				'body'    => __( "Hi {member_name},\n\nYour {plan_name} membership ({membership_id}) renews on {renewal_date} — 30 days from now.\n\nIf you need to update payment details or change plans, you can do that here:\n{account_url}\n\n{brand_label}", 'memberistic' ),
			),
			'expiring_7_days'      => array(
				'subject' => __( '7 days until your {brand_label} membership renews', 'memberistic' ),
				'body'    => __( "Hi {member_name},\n\nQuick reminder: your {plan_name} membership renews on {renewal_date}.\n\nManage your membership: {account_url}\nRenew now: {renewal_link}\n\n{brand_label}", 'memberistic' ),
			),
			'expiring_tomorrow'    => array(
				'subject' => __( 'Your {brand_label} membership renews tomorrow', 'memberistic' ),
				'body'    => __( "Hi {member_name},\n\nYour {plan_name} membership renews tomorrow, {renewal_date}.\n\nRenew or update payment: {renewal_link}\n\n{brand_label}", 'memberistic' ),
			),
			'membership_expired'   => array(
				'subject' => __( 'Your {brand_label} membership has expired', 'memberistic' ),
				'body'    => __( "Hi {member_name},\n\nYour {plan_name} membership ({membership_id}) has expired. To restore member benefits, renew here:\n{renewal_link}\n\n{brand_label}\n{business_phone}", 'memberistic' ),
			),
			'payment_failed'       => array(
				'subject' => __( 'Action needed for your {brand_label} membership', 'memberistic' ),
				'body'    => __( "Hi {member_name},\n\nWe could not complete your latest payment for the {plan_name} membership ({membership_id}). Please update payment details to keep the membership active.\n\nUpdate payment: {payment_link}\n\n{brand_label}\n{support_email}", 'memberistic' ),
			),
			'membership_cancelled' => array(
				'subject' => __( 'Your {brand_label} membership was cancelled', 'memberistic' ),
				'body'    => __( "Hi {member_name},\n\nYour {plan_name} membership ({membership_id}) has been cancelled. If this was unexpected, please reach out to staff.\n\n{business_phone}\n{brand_label}", 'memberistic' ),
			),
			'linked_member_added'  => array(
				'subject' => __( 'A linked member was added to your {brand_label} membership', 'memberistic' ),
				'body'    => __( "Hi {member_name},\n\n{linked_member_name} was added as a linked member on your {plan_name} membership ({membership_id}).\n\nView linked members: {account_url}\n\n{brand_label}", 'memberistic' ),
			),
			'waiver_missing'       => array(
				'subject' => __( 'Sign your {brand_label} range waiver', 'memberistic' ),
				'body'    => __( "Hi {member_name},\n\nA signed waiver is needed before your range benefits can be fully used. You can sign it now from any device in under a minute — no login required:\n\n{waiver_url}\n\nMembership: {membership_id}\n\nSee you at the range.\n\n{brand_label}\n{business_phone}", 'memberistic' ),
			),
			'waiver_renewal'       => array(
				'subject' => __( 'Your {brand_label} range waiver expires soon', 'memberistic' ),
				'body'    => __( "Hi {member_name},\n\nYour signed range waiver expires on {waiver_expires}. Re-sign it now from any device in under a minute — no login required — and skip the front-desk paperwork on your next visit:\n\n{waiver_url}\n\nMembership: {membership_id}\n\nSee you at the range.\n\n{brand_label}\n{business_phone}", 'memberistic' ),
			),
			'staff_manual'         => array(
				'subject' => __( 'A message from {brand_label}', 'memberistic' ),
				'body'    => __( "Hi {member_name},\n\n{staff_name} from {brand_label} wanted to reach out about your membership ({membership_id}).\n\n{business_phone}\n{brand_label}", 'memberistic' ),
			),
		);
	}

	/**
	 * Whether outbound mail should use the branded HTML layout.
	 *
	 * Controlled by the email_html_enabled setting (defaults to on for new
	 * installs). Any explicit "no"-style value falls back to plain text.
	 */
	private static function html_enabled() {
		$value = memberistic_get_setting( 'email_html_enabled', 'yes' );
		return ! in_array( strtolower( (string) $value ), array( 'no', '0', '', 'off', 'false' ), true );
	}

	/**
	 * Resolve the header logo URL for HTML mail.
	 *
	 * Order: the logo_url setting, then the site's Custom Logo, then the
	 * Site Icon, else empty string (the site name renders as text instead).
	 *
	 * Resolving through core's own branding means a site that has already
	 * set a logo in the Customizer gets branded mail with no extra config,
	 * and a site that has not still gets a clean text header rather than a
	 * broken image.
	 *
	 * @param array $context Rendered merge-tag context.
	 * @return string Absolute logo URL, or '' when none is available.
	 */
	private static function logo_url( $context ) {
		$logo = (string) ( $context['{logo_url}'] ?? '' );

		if ( '' === $logo && function_exists( 'get_theme_mod' ) ) {
			$custom_logo_id = (int) get_theme_mod( 'custom_logo' );
			if ( $custom_logo_id > 0 ) {
				$logo = (string) wp_get_attachment_image_url( $custom_logo_id, 'full' );
			}
		}

		if ( '' === $logo && function_exists( 'get_site_icon_url' ) ) {
			$logo = (string) get_site_icon_url( 192 );
		}

		/**
		 * Filters the logo URL used in Memberistic HTML email headers.
		 *
		 * Return '' to render the site name as text instead of an image.
		 *
		 * @since 2.0.0
		 *
		 * @param string $logo_url Resolved logo URL, or '' when none was found.
		 * @param array  $context  Rendered merge-tag context.
		 */
		$logo = (string) apply_filters( 'memberistic_email_logo_url', $logo, $context );

		return '' === $logo ? '' : esc_url_raw( $logo );
	}

	/**
	 * Bulletproof, image-free CTA button. Table-based (Outlook-safe);
	 * 14px vertical padding + 16px line height keeps the touch target at
	 * 44px minimum. Primary is a solid accent fill with near-black text;
	 * secondary is an outline. Both colours come from the email branding
	 * settings, so they follow the site's palette rather than a fixed one.
	 *
	 * @param string $url   Destination URL.
	 * @param string $label Button label (plain text — escaped here).
	 * @param string $style 'primary' or 'secondary'.
	 */
	public static function button( $url, $label, $style = 'primary' ) {
		$href = esc_url( (string) $url );
		if ( '' === $href ) {
			return '';
		}

		if ( 'secondary' === $style ) {
			$td_style = 'border-radius:6px;border:2px solid #C9A84C;background:transparent;';
			$a_style  = 'display:inline-block;padding:12px 30px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:16px;font-weight:bold;color:#7A621F;text-decoration:none;border-radius:6px;';
			$bgcolor  = '';
		} else {
			$td_style = 'border-radius:6px;background:#E8802F;';
			$a_style  = 'display:inline-block;padding:14px 32px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:16px;font-weight:bold;color:#1A1408;text-decoration:none;border-radius:6px;';
			$bgcolor  = ' bgcolor="#E8802F"';
		}

		return '<table role="presentation" border="0" cellspacing="0" cellpadding="0" style="margin:20px 0 8px;">'
			. '<tr><td align="center"' . $bgcolor . ' style="' . $td_style . '">'
			. '<a href="' . $href . '" style="' . $a_style . '">' . esc_html( $label ) . '</a>'
			. '</td></tr></table>';
	}

	/**
	 * Per-template primary CTA appended below the body copy so members get
	 * a real button instead of a bare link for the message's main action.
	 *
	 * @param string $template Template id.
	 * @param array  $context  Rendered merge-tag context.
	 * @return string Button HTML fragment ('' when no CTA applies).
	 */
	private static function primary_cta( $template, $context ) {
		$map = array(
			'payment_failed'       => array( '{payment_link}', __( 'Update payment method', 'memberistic' ) ),
			'renewal_reminder'     => array( '{renewal_link}', __( 'Renew membership', 'memberistic' ) ),
			'expiring_7_days'      => array( '{renewal_link}', __( 'Renew membership', 'memberistic' ) ),
			'expiring_tomorrow'    => array( '{renewal_link}', __( 'Renew membership', 'memberistic' ) ),
			'membership_expired'   => array( '{renewal_link}', __( 'Renew membership', 'memberistic' ) ),
			'expiring_30_days'     => array( '{account_url}', __( 'Manage membership', 'memberistic' ) ),
			'payment_receipt'      => array( '{account_url}', __( 'View billing history', 'memberistic' ) ),
			'membership_renewed'   => array( '{account_url}', __( 'View your account', 'memberistic' ) ),
			'membership_created'   => array( '{account_url}', __( 'View your account', 'memberistic' ) ),
			'membership_activated' => array( '{account_url}', __( 'View your account', 'memberistic' ) ),
			'linked_member_added'  => array( '{account_url}', __( 'View linked members', 'memberistic' ) ),
			'waiver_missing'       => array( '{waiver_url}', __( 'Sign your waiver', 'memberistic' ) ),
			'waiver_renewal'       => array( '{waiver_url}', __( 'Re-sign your waiver', 'memberistic' ) ),
		);

		$map = apply_filters( 'memberistic_email_primary_cta', $map, $template, $context );

		if ( empty( $map[ $template ] ) || ! is_array( $map[ $template ] ) ) {
			return '';
		}

		list( $tag, $label ) = $map[ $template ];
		$url = isset( $context[ $tag ] ) ? trim( (string) $context[ $tag ] ) : '';
		if ( '' === $url ) {
			return '';
		}

		return self::button( $url, $label, 'primary' );
	}

	/**
	 * Wrap a rendered plain-text email body in the branded HTML shell.
	 *
	 * Bulletproof 600px table layout, fully inlined styles, a dark header
	 * band carrying the site logo (falling back to the site name as text),
	 * accent top border and divider, a light content card, an accent CTA
	 * button, and a footer with the business name / address / phone from
	 * settings. The plain-text body is escaped, URLs linkified, and
	 * newlines converted, so existing template copy (and admin overrides)
	 * keep working untouched.
	 *
	 * @param string $body     Rendered plain-text body (merge tags applied).
	 * @param string $subject  Rendered subject (used for the <title>).
	 * @param array  $context  Rendered merge-tag context.
	 * @param string $template Template id (drives the primary CTA button).
	 */
	private static function wrap_html( $body, $subject, $context, $template = '' ) {
		$dark  = '#0F1115';
		$brass = '#C9A84C';
		$page  = '#EDEAE2';
		$card  = '#F4F1EA';
		$text  = '#26241F';
		$muted = '#6F6A5E';

		$brand = (string) ( $context['{brand_label}'] ?? memberistic_get_brand_label() );
		$logo  = self::logo_url( $context );

		$header = $logo
			? '<img src="' . esc_url( $logo ) . '" alt="' . esc_attr( $brand ) . '" width="200" style="display:block;width:200px;max-width:100%;height:auto;border:0;margin:0 auto;">'
			: '<strong style="color:' . $brass . ';font-family:Arial,Helvetica,sans-serif;font-size:24px;letter-spacing:.06em;text-transform:uppercase;">' . esc_html( $brand ) . '</strong>';

		// Escape → linkify → preserve line breaks. make_clickable() turns the
		// bare URLs used throughout the plain-text templates into real links.
		$content = esc_html( (string) $body );
		if ( function_exists( 'make_clickable' ) ) {
			$content = make_clickable( $content );
		}
		$content = nl2br( $content );

		$content .= self::primary_cta( $template, $context );

		$footer_bits = array();
		if ( ! empty( $context['{business_name}'] ) ) {
			$footer_bits[] = esc_html( (string) $context['{business_name}'] );
		}
		if ( ! empty( $context['{business_address}'] ) ) {
			$footer_bits[] = esc_html( preg_replace( '/\s+/', ' ', (string) $context['{business_address}'] ) );
		}
		if ( ! empty( $context['{business_phone}'] ) ) {
			$footer_bits[] = esc_html( (string) $context['{business_phone}'] );
		}
		if ( empty( $footer_bits ) ) {
			$footer_bits[] = esc_html( $brand );
		}

		$footer = '<p style="margin:0 0 6px;font-size:12px;line-height:1.6;color:' . $muted . ';">' . implode( ' &middot; ', $footer_bits ) . '</p>'
			. '<p style="margin:0;font-size:11px;color:' . $muted . ';">&copy; ' . esc_html( gmdate( 'Y' ) ) . ' ' . esc_html( $brand ) . '. ' . esc_html__( 'All rights reserved.', 'memberistic' ) . '</p>';

		$note = sprintf(
			/* translators: %s: brand label */
			esc_html__( 'You are receiving this message about your %s membership. Questions? Reply to this email or contact us directly.', 'memberistic' ),
			esc_html( $brand )
		);

		$html = '<!doctype html><html lang="en"><head><meta charset="UTF-8">'
			. '<meta name="viewport" content="width=device-width,initial-scale=1">'
			. '<meta http-equiv="X-UA-Compatible" content="IE=edge">'
			. '<meta name="color-scheme" content="light">'
			. '<title>' . esc_html( $subject ) . '</title></head>'
			. '<body style="margin:0;padding:0;background:' . $page . ';font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:' . $text . ';-webkit-text-size-adjust:100%;">'
			. '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:' . $page . ';">'
			. '<tr><td align="center" style="padding:32px 16px;">'
			. '<table role="presentation" width="600" cellspacing="0" cellpadding="0" border="0" style="background:' . $card . ';max-width:600px;width:100%;border-collapse:collapse;border-top:4px solid ' . $brass . ';">'
			. '<tr><td align="center" bgcolor="' . $dark . '" style="background:' . $dark . ';padding:26px 32px;">' . $header . '</td></tr>'
			. '<tr><td style="font-size:0;line-height:0;height:3px;background:' . $brass . ';">&nbsp;</td></tr>'
			. '<tr><td style="padding:32px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:' . $text . ';">' . $content . '</td></tr>'
			. '<tr><td style="background:#E9E5DB;padding:22px 32px;border-top:1px solid #DDD8CC;" align="center">' . $footer . '</td></tr>'
			. '</table>'
			. '<table role="presentation" width="600" cellspacing="0" cellpadding="0" border="0" style="max-width:600px;width:100%;">'
			. '<tr><td align="center" style="padding:16px 12px 0;font-family:Arial,Helvetica,sans-serif;font-size:11px;line-height:1.6;color:' . $muted . ';">' . $note . '</td></tr>'
			. '</table>'
			. '</td></tr></table></body></html>';

		/**
		 * Filter the final branded HTML document before dispatch.
		 *
		 * @param string $html    Full HTML email document.
		 * @param string $subject Rendered subject line.
		 * @param array  $context Rendered merge-tag context.
		 */
		return apply_filters( 'memberistic_email_html_body', $html, $subject, $context );
	}

	/**
	 * Register the phpmailer_init hook once so branded HTML sends carry a
	 * text/plain alternative (the original rendered template body).
	 */
	private static function register_mail_hooks() {
		if ( self::$mail_hooks_registered ) {
			return;
		}
		self::$mail_hooks_registered = true;
		add_action( 'phpmailer_init', array( self::class, 'inject_alt_body' ) );
	}

	/**
	 * phpmailer_init callback — attach the plain-text AltBody for the
	 * message currently being dispatched.
	 *
	 * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer Mailer instance.
	 */
	public static function inject_alt_body( $phpmailer ) {
		if ( '' !== self::$alt_body && empty( $phpmailer->AltBody ) ) {
			$phpmailer->AltBody = self::$alt_body;
		}
	}

	/**
	 * Build outbound mail headers.
	 *
	 * @param bool $html Whether the body is the branded HTML layout —
	 *                   Content-Type switches to text/html ONLY then.
	 */
	private static function headers( $html = false ) {
		$from_name  = memberistic_get_setting( 'email_from_name', memberistic_get_brand_label() );
		$from_email = memberistic_get_setting( 'email_from_address', get_option( 'admin_email' ) );
		$headers    = array( $html ? 'Content-Type: text/html; charset=UTF-8' : 'Content-Type: text/plain; charset=UTF-8' );

		if ( is_email( $from_email ) ) {
			$headers[] = 'From: ' . sanitize_text_field( $from_name ) . ' <' . sanitize_email( $from_email ) . '>';

			// Reply-To: prefer a dedicated reply address (e.g. range@…) so member
			// replies reach staff rather than a no-reply SMTP relay; fall back to
			// the From address. A valid Reply-To also improves deliverability with
			// inbox providers that penalise unrepliable bulk mail.
			$reply_to = (string) memberistic_get_setting( 'email_reply_to_address', $from_email );
			if ( is_email( $reply_to ) ) {
				$headers[] = 'Reply-To: ' . sanitize_email( $reply_to );
			}
		}

		/**
		 * Allow integrations (e.g. an SMTP/deliverability add-on) to append
		 * headers such as List-Unsubscribe without overriding the branded From.
		 */
		return apply_filters( 'memberistic_email_headers', $headers );
	}
}
