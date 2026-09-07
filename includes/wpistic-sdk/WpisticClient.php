<?php
/**
 * The WPistic WordPress SDK's single entry point. One instance per
 * product plugin:
 *
 *     $client = new WpisticClient( array(
 *         'product_slug'    => 'seoistic',
 *         'product_version' => SEOISTIC_VERSION,
 *         'plugin_file'     => __FILE__,
 *     ) );
 *     $client->boot();
 *
 * `boot()` wires the twice-daily validation cron, the secure update
 * client, the license settings screen, the "Connect to WPistic" onboarding
 * flow, and grace-period admin notices. Everything else — activation,
 * entitlements, HTTP, encrypted storage — is delegated to focused
 * collaborators (LicenseManager, EntitlementChecker, GracePeriodManager,
 * Api\WpisticApi, Security\*) that this class composes and exposes a thin,
 * stable facade over.
 *
 * @package WPistic\Sdk
 */

namespace WPistic\Sdk;

use WP_Error;
use WPistic\Sdk\Admin\OnboardingWizard;
use WPistic\Sdk\Admin\SettingsPage;
use WPistic\Sdk\Api\WpisticApi;
use WPistic\Sdk\Security\TokenStorage;

/**
 * The WPistic WordPress SDK's single entry point; one instance per product plugin.
 */
class WpisticClient {

	public const DEFAULT_API_URL     = 'https://api.wpistic.com';
	public const DEFAULT_ACCOUNT_URL = 'https://account.wpistic.com';

	/**
	 * The normalized configuration this client was constructed with.
	 *
	 * @var array{product_slug:string,product_version:string,plugin_file:string,api_url:string,account_url:string}
	 */
	private $config;

	/**
	 * The HTTP client used to talk to the platform API.
	 *
	 * @var WpisticApi
	 */
	private $api;

	/**
	 * This site's activation/installation identity.
	 *
	 * @var Activation
	 */
	private $activation;

	/**
	 * Orchestrates license activation, validation, and state persistence.
	 *
	 * @var LicenseManager
	 */
	private $license_manager;

	/**
	 * Wires WPistic's secure update pipeline into WordPress.
	 *
	 * @var UpdateClient
	 */
	private $update_client;

	/**
	 * Lazily-built entitlement checker for the current license state.
	 *
	 * @var EntitlementChecker|null
	 */
	private $entitlements = null;

	/**
	 * Constructor.
	 *
	 * @param array{product_slug:string,product_version:string,plugin_file:string,api_url?:string,account_url?:string} $config The product's SDK configuration.
	 */
	public function __construct( array $config ) {
		$this->config = array(
			'product_slug'    => $config['product_slug'],
			'product_version' => $config['product_version'],
			'plugin_file'     => $config['plugin_file'],
			'api_url'         => isset( $config['api_url'] ) ? $config['api_url'] : self::DEFAULT_API_URL,
			'account_url'     => isset( $config['account_url'] ) ? $config['account_url'] : self::DEFAULT_ACCOUNT_URL,
		);

		$this->api        = new WpisticApi( $this->config['api_url'], $this->config['product_slug'], $this->config['product_version'] );
		$this->activation = new Activation( $this->config['product_slug'], $this->config['product_version'] );

		$this->license_manager = new LicenseManager(
			$this->api,
			$this->activation,
			new TokenStorage(),
			new GracePeriodManager(),
			$this->option_key()
		);

		$this->update_client = new UpdateClient( $this );
	}

	/** Wire cron + updater + admin UI. Call from the plugin's main file. */
	public function boot(): void {
		$hook = $this->cron_hook();
		add_action( $hook, array( $this->license_manager, 'scheduled_validation' ) );
		if ( ! wp_next_scheduled( $hook ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'twicedaily', $hook );
		}
		register_deactivation_hook( $this->config['plugin_file'], array( $this, 'clear_schedule' ) );

		$this->update_client->register();
		( new SettingsPage( $this ) )->register();
		( new OnboardingWizard( $this ) )->register();
	}

	/**
	 * Unschedule the recurring validation cron event.
	 *
	 * @return void
	 */
	public function clear_schedule(): void {
		wp_clear_scheduled_hook( $this->cron_hook() );
	}

	// -------------------------------------------------------------------------
	// Lifecycle
	// -------------------------------------------------------------------------

	/**
	 * Activate with a raw license key.
	 *
	 * @param string $raw_key The raw license key entered by the user.
	 * @return true|WP_Error
	 */
	public function activate( string $raw_key ) {
		$result = $this->license_manager->activate( $raw_key );
		if ( true === $result ) {
			$this->entitlements = null;
		}
		return $result;
	}

	/**
	 * Deactivate the license on this site.
	 *
	 * @return true|WP_Error
	 */
	public function deactivate() {
		$result             = $this->license_manager->deactivate();
		$this->entitlements = null;
		return $result;
	}

	/**
	 * On-demand revalidation (e.g. "check now" from the settings screen).
	 *
	 * @return true|WP_Error
	 */
	public function validate_now() {
		$result = $this->license_manager->validate_now();
		if ( true === $result ) {
			$this->entitlements = null;
		}
		return $result;
	}

	// -------------------------------------------------------------------------
	// State queries
	// -------------------------------------------------------------------------

	/**
	 * Whether a license activation token is currently stored.
	 *
	 * @return bool
	 */
	public function is_connected(): bool {
		return $this->license_manager->is_connected();
	}

	/** Are premium features available right now? See LicenseManager/GracePeriodManager for the exact rule. */
	public function is_premium_active(): bool {
		return $this->license_manager->is_premium_active( time() );
	}

	/** Seconds until grace expires; null when not in grace. */
	public function grace_seconds_remaining(): ?int {
		return $this->license_manager->grace_seconds_remaining( time() );
	}

	/**
	 * The entitlement checker for the current license state.
	 *
	 * @return EntitlementChecker
	 */
	public function entitlements(): EntitlementChecker {
		if ( null === $this->entitlements ) {
			$this->entitlements = new EntitlementChecker( $this->license_manager->entitlements_map( time() ) );
		}
		return $this->entitlements;
	}

	/**
	 * Sanitized status for the settings screen.
	 *
	 * @return array<string,mixed>
	 */
	public function status(): array {
		return $this->license_manager->status( time() );
	}

	// -------------------------------------------------------------------------
	// Collaborators / accessors (shared with UpdateClient/Admin\*)
	// -------------------------------------------------------------------------

	/**
	 * The HTTP client used to talk to the platform API.
	 *
	 * @return WpisticApi
	 */
	public function api(): WpisticApi {
		return $this->api;
	}

	/**
	 * This site's activation/installation identity.
	 *
	 * @return Activation
	 */
	public function activation_helper(): Activation {
		return $this->activation;
	}

	/**
	 * The license manager backing this client.
	 *
	 * @return LicenseManager
	 */
	public function license_manager(): LicenseManager {
		return $this->license_manager;
	}

	/**
	 * The update client backing this client.
	 *
	 * @return UpdateClient
	 */
	public function update_client(): UpdateClient {
		return $this->update_client;
	}

	/**
	 * The consuming product's slug.
	 *
	 * @return string
	 */
	public function product_slug(): string {
		return $this->config['product_slug'];
	}

	/**
	 * The consuming product's version.
	 *
	 * @return string
	 */
	public function product_version(): string {
		return $this->config['product_version'];
	}

	/**
	 * The consuming plugin's main file path.
	 *
	 * @return string
	 */
	public function plugin_file(): string {
		return $this->config['plugin_file'];
	}

	/**
	 * The configured account.wpistic.com base URL.
	 *
	 * @return string
	 */
	public function account_url(): string {
		return $this->config['account_url'];
	}

	/**
	 * The currently stored activation token, if any.
	 *
	 * @return string
	 */
	public function activation_token(): string {
		return $this->license_manager->activation_token();
	}

	/**
	 * The cron hook name used for scheduled revalidation.
	 *
	 * @return string
	 */
	private function cron_hook(): string {
		return 'wpistic_' . $this->config['product_slug'] . '_validate';
	}

	/**
	 * The wp_options key under which the encrypted license state is stored.
	 *
	 * @return string
	 */
	private function option_key(): string {
		return 'wpistic_' . $this->config['product_slug'] . '_license';
	}
}
