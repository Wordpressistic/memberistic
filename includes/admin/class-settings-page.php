<?php
/**
 * Settings page.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic\Admin;

use function WordPressistic\Memberistic\memberistic_current_user_can;
use function WordPressistic\Memberistic\memberistic_sanitize_hex_color;
use function WordPressistic\Memberistic\memberistic_sanitize_text;
use function WordPressistic\Memberistic\memberistic_sanitize_textarea;
use function WordPressistic\Memberistic\memberistic_sanitize_yes_no;
use function WordPressistic\Memberistic\memberistic_admin_url;
use function WordPressistic\Memberistic\memberistic_verify_admin_nonce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Settings_Page {
	/**
	 * Register settings API config.
	 */
	public static function register_settings() {
		register_setting(
			'memberistic_settings_group',
			'memberistic_settings',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( self::class, 'sanitize_settings' ),
				'default'           => array(),
			)
		);
	}

	/**
	 * Handle settings actions.
	 */
	public static function handle_actions() {
		if ( empty( $_GET['page'] ) || 'memberistic-settings' !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
			return;
		}

		$action = empty( $_GET['memberistic_action'] ) ? '' : sanitize_key( wp_unslash( $_GET['memberistic_action'] ) );

		if ( ! in_array( $action, array( 'create_pages', 'remap_pages', 'repair_logins' ), true ) ) {
			return;
		}

		// The page tools share one nonce; the login-repair tool uses its own.
		$nonce_action = 'repair_logins' === $action ? 'memberistic_repair_logins' : 'memberistic_create_pages';
		if ( ! memberistic_current_user_can( 'manage_memberistic_settings' ) || ! memberistic_verify_admin_nonce( $nonce_action ) ) {
			wp_safe_redirect( memberistic_admin_url( 'memberistic-settings', array( 'memberistic_notice' => 'invalid_request', 'memberistic_notice_type' => 'error' ) ) );
			exit;
		}

		if ( 'repair_logins' === $action ) {
			$tally = \WordPressistic\Memberistic\Account_Provisioner::repair_all( true );
			wp_safe_redirect( memberistic_admin_url( 'memberistic-settings', array(
				'memberistic_notice'  => 'logins_repaired',
				'memberistic_created' => (int) $tally['created'],
				'memberistic_linked'  => (int) $tally['linked'],
				'memberistic_emailed' => (int) $tally['emailed'],
			) ) );
			exit;
		}

		self::create_required_pages( 'remap_pages' === $action );
		wp_safe_redirect( memberistic_admin_url( 'memberistic-settings', array( 'memberistic_notice' => 'remap_pages' === $action ? 'pages_remapped' : 'pages_created' ) ) );
		exit;
	}

	/**
	 * Sanitize settings.
	 *
	 * @param mixed $settings Raw settings.
	 * @return array<string, string>
	 */
	public static function sanitize_settings( $settings ) {
		$settings = is_array( $settings ) ? $settings : array();
		// Read current settings once so secret-field sanitization can
		// preserve existing values when the incoming payload sends a
		// masked placeholder or a blank.
		$existing = get_option( 'memberistic_settings', array() );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}

		// Only surface the admin notice when we're actually in an admin screen
		// (the REST controller also uses this sanitizer and shouldn't pollute
		// the admin notices transient).
		if ( function_exists( 'add_settings_error' ) && is_admin() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			add_settings_error( 'memberistic_settings', 'settings_saved', __( 'Settings saved successfully.', 'memberistic' ), 'success' );
		}

		$output = array(
			// Customer-facing brand label. Defaults to the WP site name so
			// out-of-the-box installs show the site's own brand and never
			// expose the plugin's name "Memberistic" in customer mail.
			'brand_label'              => isset( $settings['brand_label'] ) ? memberistic_sanitize_text( $settings['brand_label'] ) : (string) get_bloginfo( 'name' ),
			'business_name'            => isset( $settings['business_name'] ) ? memberistic_sanitize_text( $settings['business_name'] ) : '',
			'business_phone'           => isset( $settings['business_phone'] ) ? memberistic_sanitize_text( $settings['business_phone'] ) : '',
			'business_address'         => isset( $settings['business_address'] ) ? memberistic_sanitize_textarea( $settings['business_address'] ) : '',
			'primary_brand_color'      => isset( $settings['primary_brand_color'] ) ? memberistic_sanitize_hex_color( $settings['primary_brand_color'] ) : '#0F2044',
			'enable_debug_logging'     => isset( $settings['enable_debug_logging'] ) ? memberistic_sanitize_yes_no( $settings['enable_debug_logging'] ) : 'no',
			'delete_data_on_uninstall' => isset( $settings['delete_data_on_uninstall'] ) ? memberistic_sanitize_yes_no( $settings['delete_data_on_uninstall'] ) : 'no',
			// Data retention windows, in days. 0 means "keep indefinitely",
			// which is the default — see Scheduler::run_retention_purge()
			// for why this plugin never starts deleting history on its own.
			// Capped at ~27 years so a fat-fingered value can't overflow the
			// date maths into a cutoff in the past.
			'checkin_retention_days'   => isset( $settings['checkin_retention_days'] ) ? min( 10000, max( 0, absint( $settings['checkin_retention_days'] ) ) ) : 0,
			'activity_retention_days'  => isset( $settings['activity_retention_days'] ) ? min( 10000, max( 0, absint( $settings['activity_retention_days'] ) ) ) : 0,
			'currency'                 => isset( $settings['currency'] ) ? strtoupper( memberistic_sanitize_text( $settings['currency'] ) ) : 'USD',
			'stripe_enabled'           => isset( $settings['stripe_enabled'] ) ? memberistic_sanitize_yes_no( $settings['stripe_enabled'] ) : 'no',
			'stripe_mode'              => isset( $settings['stripe_mode'] ) && 'live' === $settings['stripe_mode'] ? 'live' : 'test',
			'stripe_test_publishable_key' => isset( $settings['stripe_test_publishable_key'] ) ? memberistic_sanitize_text( $settings['stripe_test_publishable_key'] ) : '',
			'stripe_test_secret_key'      => self::sanitize_secret_field( 'stripe_test_secret_key', $settings, $existing ),
			'stripe_live_publishable_key' => isset( $settings['stripe_live_publishable_key'] ) ? memberistic_sanitize_text( $settings['stripe_live_publishable_key'] ) : '',
			'stripe_live_secret_key'      => self::sanitize_secret_field( 'stripe_live_secret_key', $settings, $existing ),
			'stripe_webhook_secret'       => self::sanitize_secret_field( 'stripe_webhook_secret', $settings, $existing ),
			'woocommerce_enabled'         => isset( $settings['woocommerce_enabled'] ) ? memberistic_sanitize_yes_no( $settings['woocommerce_enabled'] ) : 'no',
			'woocommerce_webhook_secret'  => isset( $settings['woocommerce_webhook_secret'] ) ? memberistic_sanitize_text( $settings['woocommerce_webhook_secret'] ) : '',
			// Email From: name on every Memberistic-sent message. Defaults
			// to the site name so mail arrives from the business, never
			// branded as "Memberistic".
			'email_from_name'             => isset( $settings['email_from_name'] ) ? memberistic_sanitize_text( $settings['email_from_name'] ) : (string) get_bloginfo( 'name' ),
			'email_from_address'          => isset( $settings['email_from_address'] ) ? sanitize_email( $settings['email_from_address'] ) : get_option( 'admin_email' ),
			'logo_url'                    => isset( $settings['logo_url'] ) ? esc_url_raw( (string) $settings['logo_url'] ) : '',
			'accent_brand_color'          => isset( $settings['accent_brand_color'] ) ? memberistic_sanitize_hex_color( $settings['accent_brand_color'], '#1F3A8A' ) : '#1F3A8A',
			'timezone'                    => isset( $settings['timezone'] ) ? memberistic_sanitize_text( $settings['timezone'] ) : (string) get_option( 'timezone_string', 'UTC' ),
			'plans_page_id'            => isset( $settings['plans_page_id'] ) ? absint( $settings['plans_page_id'] ) : 0,
			'checkout_page_id'         => isset( $settings['checkout_page_id'] ) ? absint( $settings['checkout_page_id'] ) : 0,
			'account_page_id'          => isset( $settings['account_page_id'] ) ? absint( $settings['account_page_id'] ) : 0,
			'renewal_page_id'          => isset( $settings['renewal_page_id'] ) ? absint( $settings['renewal_page_id'] ) : 0,
			'login_page_id'            => isset( $settings['login_page_id'] ) ? absint( $settings['login_page_id'] ) : 0,
			'thank_you_page_id'        => isset( $settings['thank_you_page_id'] ) ? absint( $settings['thank_you_page_id'] ) : 0,
			'failed_payment_page_id'   => isset( $settings['failed_payment_page_id'] ) ? absint( $settings['failed_payment_page_id'] ) : 0,
			'staff_dashboard_page_id'  => isset( $settings['staff_dashboard_page_id'] ) ? absint( $settings['staff_dashboard_page_id'] ) : 0,
			// Booking page (Book a Lane) — used by email/URL resolvers so
			// booking-related links point at the configured page instead of
			// a guessed slug.
			'booking_page_id'          => isset( $settings['booking_page_id'] ) ? absint( $settings['booking_page_id'] ) : 0,
		);

		// ── Integration toggles ─────────────────────────────────────────
		// This sanitizer runs on EVERY update_option('memberistic_settings')
		// call (register_setting wires it into the sanitize_option_* filter),
		// including the Integrations page save handler. Before this loop the
		// returned allowlist silently STRIPPED every integration_* key, which
		// is why toggles like Verifyistic flipped back off after saving.
		$registry = '\\WordPressistic\\Memberistic\\Integrations\\Integrations_Registry';
		if ( class_exists( $registry ) ) {
			foreach ( $registry::definitions() as $key => $def ) {
				if ( ! empty( $def['coming_soon'] ) || empty( $def['setting'] ) || isset( $output[ $def['setting'] ] ) ) {
					continue;
				}
				$skey = $def['setting'];
				$raw  = $settings[ $skey ] ?? ( $existing[ $skey ] ?? $def['default'] );
				$output[ $skey ] = memberistic_sanitize_yes_no( $raw );
			}
		}

		// Integration sub-options + known keys that were missing from the
		// allowlist (and therefore wiped on each save).
		$output['integration_verifyistic_autostamp']     = memberistic_sanitize_yes_no( $settings['integration_verifyistic_autostamp'] ?? ( $existing['integration_verifyistic_autostamp'] ?? 'yes' ) );
		// Default ON — signup age verification should fail safe, not silently
		// off just because an admin never visited this checkbox.
		$output['integration_verifyistic_require_signup'] = memberistic_sanitize_yes_no( $settings['integration_verifyistic_require_signup'] ?? ( $existing['integration_verifyistic_require_signup'] ?? 'yes' ) );
		$output['email_reply_to_address']                 = sanitize_email( (string) ( $settings['email_reply_to_address'] ?? ( $existing['email_reply_to_address'] ?? '' ) ) );
		// Branded HTML email layout toggle — defaults to on; "no" falls all
		// transactional mail back to plain text (see Email_Service).
		$output['email_html_enabled']                     = memberistic_sanitize_yes_no( $settings['email_html_enabled'] ?? ( $existing['email_html_enabled'] ?? 'yes' ) );
		$output['verifyistic_max_age_days']               = absint( $settings['verifyistic_max_age_days'] ?? ( $existing['verifyistic_max_age_days'] ?? 0 ) );

		// ── Unknown-key passthrough ─────────────────────────────────────
		// Other modules (waivers, bridges, future integrations) also store
		// keys in memberistic_settings. Returning a fixed allowlist deleted
		// them on every unrelated save. Preserve any scalar key we did not
		// explicitly sanitize above; private/meta keys (leading underscore,
		// e.g. the _locked_secrets echo from the REST GET) are dropped.
		foreach ( array_merge( $existing, $settings ) as $key => $value ) {
			if ( array_key_exists( $key, $output ) || ! is_string( $key ) || '' === $key || '_' === $key[0] || ! is_scalar( $value ) ) {
				continue;
			}
			$output[ $key ] = memberistic_sanitize_text( (string) $value );
		}

		return $output;
	}

	/**
	 * Render the React settings console.
	 */
	public static function render() {
		if ( ! memberistic_current_user_can( 'manage_memberistic_settings' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'memberistic' ) );
		}
		?>
		<div class="wrap memberistic-wrap">
			<div id="memberistic-settings-app" class="memberistic-react-root">
				<div class="memberistic-react-loading">
					<p><?php esc_html_e( 'Loading settings…', 'memberistic' ); ?></p>
					<noscript>
						<p><?php esc_html_e( 'The Memberistic settings console requires JavaScript.', 'memberistic' ); ?></p>
					</noscript>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Create missing frontend pages and save page IDs.
	 *
	 * Called both from the legacy admin GET-action handler and the new
	 * Settings REST controller.
	 */
	public static function create_required_pages( $force_remap = false ) {
		$settings = get_option( 'memberistic_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();

		$pages = self::get_required_pages();

		foreach ( $pages as $key => $page ) {
			$current_id = ! empty( $settings[ $key ] ) ? absint( $settings[ $key ] ) : 0;

			if ( ! $force_remap && $current_id && 'trash' !== get_post_status( $current_id ) ) {
				continue;
			}

			$existing = get_page_by_path( $page['slug'] );

			if ( $existing && 'trash' !== get_post_status( $existing ) ) {
				$settings[ $key ] = (int) $existing->ID;
				continue;
			}

			// Map-only entries (e.g. the booking page, whose content is owned
			// by the booking-engine plugin) are matched to an existing page
			// but never auto-created by Memberistic.
			if ( ! empty( $page['map_only'] ) ) {
				continue;
			}

			$page_id = wp_insert_post(
				array(
					'post_title'   => $page['title'],
					'post_name'    => $page['slug'],
					'post_content' => $page['content'],
					'post_status'  => 'publish',
					'post_type'    => 'page',
				)
			);

			if ( ! is_wp_error( $page_id ) ) {
				$settings[ $key ] = (int) $page_id;
			}
		}

		update_option( 'memberistic_settings', $settings, false );
	}

	/**
	 * Get branded frontend page defaults.
	 *
	 * @return array<string, array<string, string>>
	 */
	private static function get_required_pages() {
		// Branded titles + slugs — no "memberistic-*" namespace leaks
		// out to public URLs. Settings page admin still says
		// "Memberships / Account / etc." which is what the customer
		// expects to see in their site nav.
		$pages = array(
			'plans_page_id'          => array( 'title' => 'Memberships',     'slug' => 'memberships',     'content' => '[memberistic_plans]' ),
			'checkout_page_id'       => array( 'title' => 'Checkout',        'slug' => 'membership-checkout-page', 'content' => '[memberistic_checkout]' ),
			'account_page_id'        => array( 'title' => 'My Account',      'slug' => 'account',         'content' => '[memberistic_account]' ),
			'renewal_page_id'        => array( 'title' => 'Renew Membership','slug' => 'renew-membership','content' => '[memberistic_renewal]' ),
			'login_page_id'          => array( 'title' => 'Sign In',         'slug' => 'login',           'content' => '[memberistic_login]' ),
			'thank_you_page_id'      => array( 'title' => 'Thank You',       'slug' => 'membership-thank-you', 'content' => '[memberistic_thank_you]' ),
			'failed_payment_page_id' => array( 'title' => 'Payment Failed',  'slug' => 'payment-failed',  'content' => '[memberistic_payment_failed]' ),
			'staff_dashboard_page_id'=> array( 'title' => 'Staff Dashboard', 'slug' => 'staff-dashboard', 'content' => '[memberistic_staff_dashboard]' ),
			// Booking page: mapped when a /book-a-lane/ page exists, but never
			// created here — its shortcode belongs to the booking-engine plugin.
			'booking_page_id'        => array( 'title' => 'Book a Lane',     'slug' => 'book-a-lane',     'content' => '', 'map_only' => true ),
		);

		return apply_filters( 'memberistic_required_pages', $pages );
	}

	/**
	 * Sanitize a secret-bearing field with three protections:
	 *  1. If a wp-config.php constant overrides this key, refuse to
	 *     persist anything — keep the existing option (or empty), and
	 *     surface an admin notice that the constant takes precedence.
	 *  2. If the incoming value looks like our own masked placeholder
	 *     (the format produced by memberistic_mask_secret() for the
	 *     REST GET response), preserve the existing stored value
	 *     instead of overwriting it with stars.
	 *  3. Otherwise sanitize normally and store the raw secret.
	 */
	private static function sanitize_secret_field( $key, $incoming, $existing ) {
		$existing_value = isset( $existing[ $key ] ) ? (string) $existing[ $key ] : '';

		if ( memberistic_setting_is_locked_by_constant( $key ) ) {
			if ( function_exists( 'add_settings_error' ) && is_admin() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
				add_settings_error(
					'memberistic_settings',
					'secret_locked_by_constant',
					sprintf(
						/* translators: %s = setting key */
						__( 'The setting "%s" is locked by a wp-config.php constant and was not updated.', 'memberistic' ),
						$key
					),
					'warning'
				);
			}
			return $existing_value;
		}

		$value = isset( $incoming[ $key ] ) ? (string) $incoming[ $key ] : '';
		// Masked placeholder coming back from the React app — preserve
		// the existing secret rather than blanking it.
		if ( '' !== $existing_value && false !== strpos( $value, '****' ) ) {
			return $existing_value;
		}
		return memberistic_sanitize_text( $value );
	}
}
