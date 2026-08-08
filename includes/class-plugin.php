<?php
/**
 * Main plugin coordinator.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {
	/**
	 * Boot the plugin.
	 */
	public function run() {
		$this->load_dependencies();
		$this->register_hooks();

		do_action( 'memberistic_loaded' );
	}

	/**
	 * Load required classes and utilities.
	 */
	private function load_dependencies() {
		$files = array(
			'includes/utilities/security.php',
			'includes/utilities/helpers.php',
			'includes/utilities/global-functions.php',
			'includes/utilities/formatting.php',
			'includes/utilities/class-qr.php',
			'includes/utilities/class-verification.php',
			'includes/class-capabilities.php',
			'includes/class-roles.php',
			'includes/class-membership-service.php',
			'includes/class-content-restrictions.php',
			'includes/class-privacy.php',
			'includes/class-licensing.php',
			'includes/class-account-provisioner.php',
			'includes/class-scheduler.php',
			'includes/class-router.php',
			'includes/class-installer.php',
			'includes/class-activator.php',
			'includes/class-deactivator.php',
			'includes/database/class-schema.php',
			'includes/database/class-migrations.php',
			'includes/database/class-plans-repository.php',
			'includes/database/class-memberships-repository.php',
			'includes/database/class-people-repository.php',
			'includes/database/class-payments-repository.php',
			'includes/database/class-activity-repository.php',
			'includes/database/class-checkins-repository.php',
			'includes/database/class-notes-repository.php',
			'includes/database/class-email-logs-repository.php',
			'includes/emails/class-email-service.php',
			'includes/integrations/class-integrations-registry.php',
			'includes/integrations/class-entitlement-service.php',
			'includes/integrations/class-booking-engine.php',
			'includes/integrations/class-woocommerce-bridge.php',
			'includes/integrations/class-woocommerce-discounts.php',
			'includes/integrations/class-verifyistic-bridge.php',
			'includes/integrations/class-pos-bridge.php',
			'includes/integrations/class-corestore-client.php',
			'includes/integrations/class-corestore-bridge.php',
			'includes/integrations/class-ffl-checkout-bridge.php',
			'includes/payments/class-stripe-service.php',
			'includes/cli/class-stripe-recovery-command.php',
			'includes/cli/class-guest-pass-audit-command.php',
			'includes/admin/class-admin-menu.php',
			'includes/admin/class-dashboard-page.php',
			'includes/admin/class-members-page.php',
			'includes/admin/class-payments-page.php',
			'includes/admin/class-checkins-page.php',
			'includes/admin/class-activity-page.php',
			'includes/admin/class-plans-page.php',
			'includes/admin/class-settings-page.php',
			'includes/admin/class-import-page.php',
			'includes/frontend/class-auth.php',
			'includes/frontend/class-shortcodes.php',
			'includes/frontend/class-staff-dashboard.php',
			'includes/rest/class-rest-controller.php',
			'includes/rest/class-plans-controller.php',
			'includes/rest/class-memberships-controller.php',
			'includes/rest/class-dashboard-controller.php',
			'includes/rest/class-records-controller.php',
			'includes/rest/class-saved-views-controller.php',
			'includes/rest/class-settings-controller.php',
			'includes/corporate/class-corporate-module.php',
			'includes/waivers/class-documents.php',
			'includes/waivers/class-waiver-pdf.php',
			'includes/waivers/class-waiver-versions.php',
			'includes/waivers/class-waiver-kiosk.php',
			'includes/waivers/class-waivers.php',
			'includes/waivers/class-waivers-archive.php',
			'includes/waivers/class-waiver-import.php',
			'includes/waivers/class-waiver-archive-admin.php',
			'includes/waivers/class-waiver-booking-bridge.php',
		);

		foreach ( $files as $file ) {
			require_once MEMBERISTIC_PATH . $file;
		}
	}

	/**
	 * Register WordPress hooks.
	 */
	private function register_hooks() {
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'init', array( Payments\Stripe_Service::class, 'maybe_handle_public_checkout_request' ) );
		CLI\Stripe_Recovery_Command::register();
		CLI\Guest_Pass_Audit_Command::register();
		add_action( 'init', array( Payments\Stripe_Service::class, 'maybe_handle_billing_portal_request' ) );
		// Propagate WordPress-side cancellations to Stripe so the
		// subscription actually stops billing — previously a cancel on the
		// site only flipped the local DB status and Stripe kept charging.
		add_action( 'memberistic_membership_status_changed', array( Payments\Stripe_Service::class, 'maybe_cancel_remote_subscription' ), 10, 2 );
		// Failed Stripe cancels retry with backoff and stay visible in
		// wp-admin until Stripe confirms billing has stopped.
		add_action( Payments\Stripe_Service::CANCEL_RETRY_HOOK, array( Payments\Stripe_Service::class, 'run_cancel_retry' ), 10, 2 );
		add_action( 'admin_notices', array( Payments\Stripe_Service::class, 'render_cancel_failure_notice' ) );
		add_action( 'admin_notices', array( Payments\Stripe_Service::class, 'render_webhook_health_notice' ) );
		add_action( 'admin_init', array( Payments\Stripe_Service::class, 'handle_webhook_notice_dismissal' ) );
		add_action( 'admin_menu', array( Admin\Admin_Menu::class, 'register' ) );
		add_action( 'admin_init', array( Installer::class, 'maybe_upgrade' ) );
		add_action( 'admin_init', array( Admin\Settings_Page::class, 'register_settings' ) );
		add_action( 'admin_init', array( Admin\Settings_Page::class, 'handle_actions' ) );
		add_action( 'admin_init', array( Admin\Plans_Page::class, 'handle_actions' ) );
		add_action( 'admin_init', array( Admin\Members_Page::class, 'handle_actions' ) );
		add_action( 'admin_init', array( Waivers\Waiver_Admin_Page::class, 'handle_actions' ) );
		add_action( 'init', array( Waivers\Waiver_Admin_Page::class, 'maybe_handle_export' ) );
		Waivers\Waiver_Public::register();
		Waivers\Documents::register();
		Waivers\Waiver_Archive_Admin::register();
		Waivers\Waiver_Import::register_cli();
		Waivers\Waiver_Import::register();
		// The booking-engine mirror is part of the Waiver Manager module
		// (Integrations toggle, default ON). The waiver console, signing
		// surfaces, and archive are core and always available.
		add_action( 'init', function () {
			if ( Integrations\Integrations_Registry::is_enabled( 'waiver_manager' ) ) {
				Waivers\Waiver_Booking_Bridge::register();
			}
		}, 4 );
		add_action( 'admin_notices', __NAMESPACE__ . '\\memberistic_admin_notices' );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
		add_action( 'init', array( Frontend\Shortcodes::class, 'register' ) );
		Frontend\Auth::register();
		Account_Provisioner::register();
		add_action( 'init', array( Frontend\Staff_Dashboard::class, 'register' ) );
		add_action( 'init', array( Frontend\Staff_Dashboard::class, 'handle_actions' ) );
		add_action( 'init', array( Content_Restrictions::class, 'register' ) );
		// Booking integration is gated by its Integrations toggle (default on
		// whenever the Booking Engine plugin is present). The entitlement
		// service always registers with it: it is the single authority the
		// booking engine consults for "does this membership include lane time".
		add_action( 'init', function () {
			if ( Integrations\Integrations_Registry::is_enabled( 'booking' ) ) {
				Integrations\Booking_Engine::register();
				Integrations\Entitlement_Service::register();
			}
		} );
		// WooCommerce bridge is gated by its Integrations toggle. Was registered
		// unconditionally — meaning a site that turned WooCommerce off in the
		// Integrations panel would still fire WC order hooks (no-op when WC
		// isn't installed, but still loaded and burning hooks unnecessarily).
		add_action( 'init', function () {
			if ( Integrations\Integrations_Registry::is_enabled( 'woocommerce' ) ) {
				Integrations\WooCommerce_Bridge::register();
			}
		} );
		add_action( 'init', array( Integrations\Verifyistic_Bridge::class, 'register' ) );
		// POS Bridge: feeds live Memberistic status into a connected POS's
		// counter screens (gated by its Integrations toggle + POS presence).
		add_action( 'init', array( Integrations\POS_Bridge::class, 'register' ) );
		// coreSTORE (Coreware) bridge: pushes membership state to the client's
		// hosted coreSTORE POS. Independent from POS_Bridge above; gates
		// itself on its own Integrations toggle.
		add_action( 'init', array( Integrations\CoreStore_Bridge::class, 'register' ) );
		// Advanced FFL Checkout bridge: read-only transfer history on the
		// member account dashboard + staff verification card. Gates itself
		// on its own Integrations toggle + plugin presence.
		add_action( 'init', array( Integrations\FFL_Checkout_Bridge::class, 'register' ) );
		// Per-plan WooCommerce member discounts (gates itself on the Woo
		// toggle + the woo_member_discounts_enabled sub-option).
		add_action( 'init', array( Integrations\WooCommerce_Discounts::class, 'register' ) );
		add_action( 'init', array( Scheduler::class, 'register' ) );
		// GDPR export/erase callbacks + suggested privacy policy text.
		Privacy::register();
		// Save handler for the Integrations page toggles.
		add_action( 'admin_post_memberistic_save_integrations', array( Admin\Admin_Menu::class, 'save_integrations' ) );
		add_action( 'admin_post_memberistic_corestore_test', array( Admin\Admin_Menu::class, 'corestore_test' ) );
		add_action( 'admin_post_memberistic_corestore_sync', array( Admin\Admin_Menu::class, 'corestore_sync' ) );
		add_action( 'template_redirect', array( $this, 'send_sensitive_page_cache_headers' ), 0 );
		// Register Verification before `init` fires so its priority-5 init
		// hook actually lands in the queue before WP processes priority 5.
		Utilities\Verification::register();

		// Corporate / Group Membership module (Phase 1).
		Corporate\Corporate_Module::register();
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	/**
	 * Load translations.
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'memberistic', false, dirname( MEMBERISTIC_BASENAME ) . '/languages' );
	}

	/**
	 * Register REST routes.
	 */
	public function register_rest_routes() {
		( new REST\Plans_Controller() )->register_routes();
		( new REST\Memberships_Controller() )->register_routes();
		( new REST\Dashboard_Controller() )->register_routes();
		( new REST\Records_Controller() )->register_routes();
		( new REST\Saved_Views_Controller() )->register_routes();
		( new REST\Settings_Controller() )->register_routes();
	}

	public function send_sensitive_page_cache_headers() {
		if ( is_admin() || wp_doing_ajax() || ! is_singular() ) {
			return;
		}
		$post = get_post();
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		$shortcodes = array(
			'memberistic_plans',
			'memberistic_checkout',
			'memberistic_account',
			'memberistic_renewal',
			'memberistic_thank_you',
			'memberistic_payment_failed',
			'memberistic_people',
			'memberistic_payment_history',
			'memberistic_booking_history',
			'memberistic_staff_dashboard',
		);
		foreach ( $shortcodes as $shortcode ) {
			if ( has_shortcode( (string) $post->post_content, $shortcode ) ) {
				nocache_headers();
				return;
			}
		}
		$page_keys = array(
			'plans_page_id',
			'checkout_page_id',
			'account_page_id',
			'renewal_page_id',
			'thank_you_page_id',
			'failed_payment_page_id',
			'staff_dashboard_page_id',
		);
		foreach ( $page_keys as $key ) {
			if ( absint( memberistic_get_setting( $key, 0 ) ) === (int) $post->ID ) {
				nocache_headers();
				return;
			}
		}
	}

	/**
	 * Enqueue admin assets only on plugin screens.
	 *
	 * @param string $hook Current admin hook.
	 */
	public function enqueue_admin_assets( $hook ) {
		if ( false === strpos( $hook, 'memberistic' ) ) {
			return;
		}

		wp_enqueue_style( 'memberistic-admin', MEMBERISTIC_URL . 'assets/admin.css', array(), MEMBERISTIC_VERSION );
		wp_enqueue_script( 'memberistic-admin', MEMBERISTIC_URL . 'assets/admin.js', array( 'jquery' ), MEMBERISTIC_VERSION, true );
		wp_localize_script(
			'memberistic-admin',
			'memberisticAdmin',
			array(
				'confirmDelete' => __( 'Are you sure you want to delete this item?', 'memberistic' ),
			)
		);

		$this->maybe_enqueue_react_app( $hook );
	}

	/**
	 * Conditionally enqueue the React apps that drive the modern admin views.
	 *
	 * @param string $hook Current admin hook.
	 */
	private function maybe_enqueue_react_app( $hook ) {
		$page    = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action  = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$wants_dashboard = ( 'memberistic-dashboard' === $page );
		$wants_members   = ( 'memberistic-members' === $page );
		$wants_plans     = ( 'memberistic-plans' === $page );
		$wants_payments  = ( 'memberistic-payments' === $page );
		$wants_checkins  = ( 'memberistic-checkins' === $page );
		$wants_activity  = ( 'memberistic-activity' === $page );
		$wants_settings  = ( 'memberistic-settings' === $page );
		$wants_emails    = ( 'memberistic-emails' === $page );

		if ( ! $wants_dashboard && ! $wants_members && ! $wants_plans && ! $wants_payments && ! $wants_checkins && ! $wants_activity && ! $wants_settings && ! $wants_emails ) {
			return;
		}

		wp_enqueue_style(
			'memberistic-app',
			MEMBERISTIC_URL . 'assets/admin-app.css',
			array( 'memberistic-admin' ),
			MEMBERISTIC_VERSION
		);

		$deps = array( 'wp-element', 'wp-api-fetch', 'wp-i18n' );

		$list_apps = $wants_members || $wants_payments || $wants_checkins || $wants_activity;

		if ( $list_apps ) {
			wp_register_script(
				'memberistic-saved-views',
				MEMBERISTIC_URL . 'assets/admin-saved-views.js',
				$deps,
				MEMBERISTIC_VERSION,
				true
			);
			wp_set_script_translations( 'memberistic-saved-views', 'memberistic' );
		}

		$list_deps = $list_apps ? array_merge( $deps, array( 'memberistic-saved-views' ) ) : $deps;

		if ( $wants_dashboard ) {
			wp_enqueue_script(
				'memberistic-dashboard-app',
				MEMBERISTIC_URL . 'assets/admin-dashboard.js',
				$deps,
				MEMBERISTIC_VERSION,
				true
			);
			wp_set_script_translations( 'memberistic-dashboard-app', 'memberistic' );
		}

		if ( $wants_members ) {
			wp_enqueue_script(
				'memberistic-members-app',
				MEMBERISTIC_URL . 'assets/admin-members.js',
				$list_deps,
				MEMBERISTIC_VERSION,
				true
			);
			wp_set_script_translations( 'memberistic-members-app', 'memberistic' );

			wp_add_inline_script(
				'memberistic-members-app',
				'window.memberisticMembersSettings = ' . wp_json_encode(
					array(
						'addNewUrl'         => admin_url( 'admin.php?page=memberistic-members&action=add' ),
						'corporateNewUrl'   => admin_url( 'admin.php?page=memberistic-corporate-groups&view=new' ),
						'canCreate'         => current_user_can( 'create_memberistic_members' ) || current_user_can( 'manage_options' ),
						'initialAction'     => $action,
						'initialSelectedId' => isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					)
				) . ';',
				'before'
			);
		}

		if ( $wants_plans ) {
			wp_enqueue_script(
				'memberistic-plans-app',
				MEMBERISTIC_URL . 'assets/admin-plans.js',
				$deps,
				MEMBERISTIC_VERSION,
				true
			);
			wp_set_script_translations( 'memberistic-plans-app', 'memberistic' );

			wp_add_inline_script(
				'memberistic-plans-app',
				'window.memberisticPlansSettings = ' . wp_json_encode(
					array(
						'initialAction' => $action,
						'initialPlanId' => isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					)
				) . ';',
				'before'
			);
		}

		if ( $wants_payments ) {
			wp_enqueue_script(
				'memberistic-payments-app',
				MEMBERISTIC_URL . 'assets/admin-payments.js',
				$list_deps,
				MEMBERISTIC_VERSION,
				true
			);
			wp_set_script_translations( 'memberistic-payments-app', 'memberistic' );
		}

		if ( $wants_checkins ) {
			wp_enqueue_script(
				'memberistic-checkins-app',
				MEMBERISTIC_URL . 'assets/admin-checkins.js',
				$list_deps,
				MEMBERISTIC_VERSION,
				true
			);
			wp_set_script_translations( 'memberistic-checkins-app', 'memberistic' );
		}

		if ( $wants_activity ) {
			wp_enqueue_script(
				'memberistic-activity-app',
				MEMBERISTIC_URL . 'assets/admin-activity.js',
				$list_deps,
				MEMBERISTIC_VERSION,
				true
			);
			wp_set_script_translations( 'memberistic-activity-app', 'memberistic' );
		}

		if ( $wants_settings ) {
			wp_enqueue_script(
				'memberistic-settings-app',
				MEMBERISTIC_URL . 'assets/admin-settings.js',
				$deps,
				MEMBERISTIC_VERSION,
				true
			);
			wp_set_script_translations( 'memberistic-settings-app', 'memberistic' );
		}

		if ( $wants_emails ) {
			wp_enqueue_script(
				'memberistic-emails-app',
				MEMBERISTIC_URL . 'assets/admin-emails.js',
				$deps,
				MEMBERISTIC_VERSION,
				true
			);
			wp_set_script_translations( 'memberistic-emails-app', 'memberistic' );
		}
	}

	/**
	 * Enqueue frontend assets on pages that use Memberistic shortcodes or the content restriction overlay.
	 */
	public function enqueue_frontend_assets() {
		global $post;

		$needs_assets = false;

		if ( is_singular() && $post instanceof \WP_Post ) {
			$memberistic_shortcodes = array(
				'memberistic_plans',
				'memberistic_plan',
				'memberistic_checkout',
				'memberistic_account',
				'memberistic_people',
				'memberistic_payment_history',
				'memberistic_booking_history',
				'memberistic_renewal',
				'memberistic_login',
				'memberistic_thank_you',
				'memberistic_payment_failed',
				'memberistic_profile_summary',
				'memberistic_status',
				'memberistic_expiring_notice',
				'memberistic_staff_dashboard',
			);

			foreach ( $memberistic_shortcodes as $shortcode ) {
				if ( has_shortcode( $post->post_content, $shortcode ) ) {
					$needs_assets = true;
					break;
				}
			}

			// Also load if the page is a mapped Memberistic page (content restriction overlay needs the CSS).
			if ( ! $needs_assets ) {
				$page_keys = array(
					'plans_page_id', 'checkout_page_id', 'account_page_id',
					'renewal_page_id', 'login_page_id', 'thank_you_page_id',
					'failed_payment_page_id', 'staff_dashboard_page_id',
				);
				foreach ( $page_keys as $key ) {
					if ( absint( memberistic_get_setting( $key, 0 ) ) === $post->ID ) {
						$needs_assets = true;
						break;
					}
				}
			}
		}

		// PERF: only load on pages that actually need the restriction overlay.
		// Previously this branch fired on every non-singular page (home,
		// archives, search) — shipping ~3 globals + jQuery deps to every
		// visitor regardless of whether any membership-gated content was
		// present. The new rule: load if (a) a known shortcode is on the
		// queried object, (b) the current page is configured as the member
		// dashboard / verify / waiver pages, or (c) the global flag
		// memberistic_force_frontend_assets is on (escape hatch).
		if ( ! $needs_assets ) {
			$post = is_singular() ? get_post() : null;
			$shortcodes = array( 'memberistic_dashboard', 'memberistic_verify', 'memberistic_signup', 'memberistic_login_gate' );
			foreach ( $shortcodes as $sc ) {
				if ( $post && has_shortcode( (string) $post->post_content, $sc ) ) {
					$needs_assets = true;
					break;
				}
			}
		}
		if ( ! $needs_assets && 1 === (int) get_option( 'memberistic_force_frontend_assets', 0 ) ) {
			$needs_assets = true;
		}

		if ( ! $needs_assets ) {
			return;
		}

		// Token bridge must load first so its --memberistic-* custom
		// properties are already resolved when frontend.css's var() calls run.
		wp_enqueue_style( 'memberistic-token-bridge', MEMBERISTIC_URL . 'assets/token-bridge.css', array(), MEMBERISTIC_VERSION );
		wp_enqueue_style( 'memberistic-frontend', MEMBERISTIC_URL . 'assets/frontend.css', array( 'memberistic-token-bridge' ), MEMBERISTIC_VERSION );
		wp_enqueue_script( 'memberistic-frontend', MEMBERISTIC_URL . 'assets/frontend.js', array(), MEMBERISTIC_VERSION, true );
		// SECURITY: use a plugin-namespaced global instead of clobbering the
		// global `wpApiSettings` (used by core's wp-api script and by other
		// plugins). Front-end JS reads window.memberisticApi.
		wp_localize_script(
			'memberistic-frontend',
			'memberisticApi',
			array(
				'root'  => esc_url_raw( rest_url() ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
			)
		);
	}
}
