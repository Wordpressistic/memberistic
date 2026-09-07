<?php
/**
 * Activate / validate / deactivate / refresh HTTP orchestration against
 * api.wpistic.com, plus the encrypted-at-rest state that backs it.
 *
 * Storage (wp_options, autoload off, value is one AES-256-GCM-encrypted
 * blob via Security\TokenStorage):
 *   wpistic_{slug}_license = [
 *     'activation_token'  => opaque RS256 JWT, sent back verbatim on
 *                            validate/refresh/deactivate — the SDK never
 *                            parses or verifies it,
 *     'verification_key'  => per-license HMAC key (hex) for offline
 *                            Security\HmacVerifier checks,
 *     'key_mask'          => masked key for display,
 *     'response'          => last signature-verified validation response,
 *     'validated_at'      => unix time of last successful remote validation,
 *   ]
 *
 * The raw license key is sent to the platform once at activation and
 * never stored. Premium features degrade gracefully: valid cached
 * response → active; API unreachable → active through the grace period
 * (GracePeriodManager); after that, features turn off — nothing
 * destructive. A response with `grace_period_ends_at` set, or a `status`
 * the platform reports, is authoritative and is never second-guessed by
 * the offline grace math — that math only fills the gap when the API
 * cannot be reached at all.
 *
 * @package WPistic\Sdk
 */

namespace WPistic\Sdk;

use WP_Error;
use WPistic\Sdk\Api\ApiClientInterface;
use WPistic\Sdk\Security\HmacVerifier;
use WPistic\Sdk\Security\TokenStorage;

/**
 * Orchestrates license activation, validation, and state persistence.
 */
class LicenseManager {

	/**
	 * The API client used for licensing HTTP requests.
	 *
	 * @var ApiClientInterface
	 */
	private $api;

	/**
	 * The activation/installation context (domain, UUID, product info).
	 *
	 * @var Activation
	 */
	private $activation;

	/**
	 * Encrypts and decrypts license state at rest.
	 *
	 * @var TokenStorage
	 */
	private $storage;

	/**
	 * Computes offline grace-period status when the API is unreachable.
	 *
	 * @var GracePeriodManager
	 */
	private $grace;

	/**
	 * The wp_options key under which the encrypted license state is stored.
	 *
	 * @var string
	 */
	private $option_key;

	/**
	 * Constructor.
	 *
	 * @param ApiClientInterface $api        The API client used for licensing HTTP requests.
	 * @param Activation         $activation The activation/installation context.
	 * @param TokenStorage       $storage    Encrypts and decrypts license state at rest.
	 * @param GracePeriodManager $grace      Computes offline grace-period status.
	 * @param string             $option_key The wp_options key for the encrypted state.
	 */
	public function __construct(
		ApiClientInterface $api,
		Activation $activation,
		TokenStorage $storage,
		GracePeriodManager $grace,
		string $option_key
	) {
		$this->api        = $api;
		$this->activation = $activation;
		$this->storage    = $storage;
		$this->grace      = $grace;
		$this->option_key = $option_key;
	}

	// -------------------------------------------------------------------------
	// Lifecycle
	// -------------------------------------------------------------------------

	/**
	 * Activate with a raw key (from the settings form). The key is not stored.
	 *
	 * @param string $raw_key The raw license key entered by the user.
	 * @return true|WP_Error
	 */
	public function activate( string $raw_key ) {
		$raw_key = trim( $raw_key );
		if ( '' === $raw_key ) {
			return new WP_Error( 'wpistic_empty_key', __( 'Enter your license key.', 'wpistic-sdk' ) );
		}

		$response = $this->api->post(
			'/api/v1/licenses/activate',
			array_merge( array( 'key' => $raw_key ), $this->activation->payload() ),
			array( 'X-Idempotency-Key' => $this->activation->installation_uuid() . '-' . md5( $raw_key ) )
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( empty( $response['activation_token'] ) || empty( $response['verification_key'] ) ) {
			return new WP_Error( 'wpistic_invalid_activation', __( 'Activation response was incomplete.', 'wpistic-sdk' ) );
		}

		$this->store(
			array(
				'activation_token' => (string) $response['activation_token'],
				'verification_key' => (string) $response['verification_key'],
				'key_mask'         => $this->mask_key( $raw_key ),
				'response'         => $response,
				'validated_at'     => time(),
			)
		);
		return true;
	}

	/**
	 * Deactivate the license on this site.
	 *
	 * @return true|WP_Error
	 */
	public function deactivate() {
		$state = $this->state();
		if ( empty( $state['activation_token'] ) ) {
			return true;
		}
		$result = $this->api->post(
			'/api/v1/licenses/deactivate',
			array(
				'activation_token'  => $state['activation_token'],
				'installation_uuid' => $this->activation->installation_uuid(),
			)
		);
		// Local state clears even if the remote call failed — the platform
		// side can be cleaned up from the dashboard.
		delete_option( $this->option_key );
		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * On-demand revalidation (e.g. "check now" from the settings screen).
	 *
	 * @return true|WP_Error
	 */
	public function validate_now() {
		$state = $this->state();
		if ( empty( $state['activation_token'] ) ) {
			return new WP_Error( 'wpistic_not_activated', __( 'No license is activated on this site.', 'wpistic-sdk' ) );
		}

		$response = $this->api->post( '/api/v1/licenses/validate', $this->validate_body( (string) $state['activation_token'] ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( ! HmacVerifier::verify( $response, (string) $state['verification_key'] ) ) {
			return new WP_Error( 'wpistic_signature_invalid', __( 'The validation response failed signature verification and was discarded.', 'wpistic-sdk' ) );
		}

		$state['response']     = $response;
		$state['validated_at'] = time();
		$this->store( $state );
		return true;
	}

	/** Cron: revalidate and rotate the activation token. Unreachable API is not an error here — grace period covers it. */
	public function scheduled_validation(): void {
		$state = $this->state();
		if ( empty( $state['activation_token'] ) ) {
			return;
		}

		$response = $this->api->post( '/api/v1/licenses/validate', $this->validate_body( (string) $state['activation_token'] ) );
		if ( is_wp_error( $response ) ) {
			return;
		}
		if ( ! HmacVerifier::verify( $response, (string) $state['verification_key'] ) ) {
			// A response that fails signature verification is never trusted.
			return;
		}

		$state['response']     = $response;
		$state['validated_at'] = time();
		$this->store( $state );

		// Rotate the activation token every cycle — the old one is revoked
		// server-side the instant this succeeds, so only store on success.
		$refreshed = $this->api->post( '/api/v1/licenses/refresh', array( 'activation_token' => $state['activation_token'] ) );
		if ( is_wp_error( $refreshed ) || empty( $refreshed['activation_token'] ) || empty( $refreshed['verification_key'] ) ) {
			return;
		}
		// The refresh response carries the same signature contract; verify
		// it with the (unchanged, unless the key itself rotated) key before
		// trusting the new token.
		if ( ! HmacVerifier::verify( $refreshed, (string) $state['verification_key'] ) ) {
			return;
		}

		$state['activation_token'] = (string) $refreshed['activation_token'];
		$state['verification_key'] = (string) $refreshed['verification_key'];
		$state['response']         = $refreshed;
		$this->store( $state );
	}

	/**
	 * Body sent on every validate call — domain/environment/installation_uuid
	 * are re-sent every time and hard-checked server-side (403 on mismatch).
	 *
	 * @param string $activation_token The stored activation token to validate.
	 * @return array<string,string>
	 */
	private function validate_body( string $activation_token ): array {
		return array(
			'activation_token'  => $activation_token,
			'domain'            => $this->activation->domain(),
			'environment'       => $this->activation->environment(),
			'installation_uuid' => $this->activation->installation_uuid(),
			'plugin_version'    => $this->activation->product_version(),
		);
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
		$state = $this->state();
		return ! empty( $state['activation_token'] );
	}

	/**
	 * Whether premium features should be considered active right now.
	 *
	 * @param int $now Current unix timestamp.
	 * @return bool
	 */
	public function is_premium_active( int $now ): bool {
		$state = $this->state();
		if ( empty( $state['response'] ) || empty( $state['validated_at'] ) ) {
			return false;
		}
		$response = $state['response'];
		if ( empty( $response['valid'] ) ) {
			return false;
		}
		if ( ! HmacVerifier::verify( $response, (string) ( $state['verification_key'] ?? '' ) ) ) {
			return false;
		}
		return $this->grace->is_active( $response, (int) $state['validated_at'], $now );
	}

	/**
	 * Seconds remaining in the offline grace period, if applicable.
	 *
	 * @param int $now Current unix timestamp.
	 * @return int|null Seconds remaining, or null when not in a grace period.
	 */
	public function grace_seconds_remaining( int $now ): ?int {
		$state = $this->state();
		if ( empty( $state['response'] ) || empty( $state['validated_at'] ) || empty( $state['response']['valid'] ) ) {
			return null;
		}
		if ( ! HmacVerifier::verify( $state['response'], (string) ( $state['verification_key'] ?? '' ) ) ) {
			return null;
		}
		return $this->grace->seconds_remaining( $state['response'], (int) $state['validated_at'], $now );
	}

	/**
	 * The raw entitlements map from the last validated response.
	 *
	 * @param int $now Current unix timestamp.
	 * @return array<string,mixed>
	 */
	public function entitlements_map( int $now ): array {
		$state = $this->state();
		if ( $this->is_premium_active( $now ) && isset( $state['response']['entitlements'] ) && is_array( $state['response']['entitlements'] ) ) {
			return $state['response']['entitlements'];
		}
		return array();
	}

	/**
	 * The currently stored activation token, if any.
	 *
	 * @return string
	 */
	public function activation_token(): string {
		$state = $this->state();
		return isset( $state['activation_token'] ) ? (string) $state['activation_token'] : '';
	}

	/**
	 * Sanitized status for the settings screen.
	 *
	 * @param int $now Current unix timestamp.
	 * @return array<string,mixed>
	 */
	public function status( int $now ): array {
		$state      = $this->state();
		$response   = isset( $state['response'] ) && is_array( $state['response'] ) ? $state['response'] : array();
		$activation = isset( $response['activation'] ) && is_array( $response['activation'] ) ? $response['activation'] : array();
		$updates    = isset( $response['updates'] ) && is_array( $response['updates'] ) ? $response['updates'] : array();
		return array(
			'connected'            => $this->is_connected(),
			'active'               => $this->is_premium_active( $now ),
			'key_mask'             => isset( $state['key_mask'] ) ? (string) $state['key_mask'] : '',
			'plan'                 => isset( $response['plan'] ) ? (string) $response['plan'] : '',
			'status'               => isset( $response['status'] ) ? (string) $response['status'] : 'not_connected',
			'expires_at'           => isset( $response['expires_at'] ) ? (string) $response['expires_at'] : '',
			'grace_period_ends_at' => isset( $response['grace_period_ends_at'] ) ? (string) $response['grace_period_ends_at'] : '',
			'domain'               => isset( $activation['domain'] ) ? (string) $activation['domain'] : '',
			'environment'          => isset( $activation['environment'] ) ? (string) $activation['environment'] : '',
			'update_channel'       => isset( $updates['channel'] ) ? (string) $updates['channel'] : 'stable',
			'validated_at'         => isset( $state['validated_at'] ) ? (int) $state['validated_at'] : 0,
			'grace_left'           => $this->grace_seconds_remaining( $now ),
			// The current API response contract has no organization name or
			// activation-usage (max/current count) fields — see README /
			// task follow-up notes. Rendered as empty rather than guessed.
			'organization'         => '',
			'max_activations'      => null,
			'activation_count'     => null,
		);
	}

	// -------------------------------------------------------------------------
	// Internals
	// -------------------------------------------------------------------------

	/**
	 * Load and decrypt the stored license state.
	 *
	 * @return array<string,mixed>
	 */
	private function state(): array {
		$stored = get_option( $this->option_key );
		if ( ! is_string( $stored ) || '' === $stored ) {
			return array();
		}
		$json = $this->storage->decrypt( $stored );
		if ( null === $json ) {
			return array();
		}
		$data = json_decode( $json, true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Encrypt and persist the license state.
	 *
	 * @param array<string,mixed> $state The license state to store.
	 * @return void
	 */
	private function store( array $state ): void {
		$encoded   = wp_json_encode( $state );
		$encrypted = $this->storage->encrypt( is_string( $encoded ) ? $encoded : '' );
		update_option( $this->option_key, $encrypted, false );
	}

	/**
	 * Build a partially-masked license key for display purposes.
	 *
	 * @param string $raw_key The raw license key.
	 * @return string
	 */
	private function mask_key( string $raw_key ): string {
		$prefix = $this->activation->product_slug() . '_';
		$tail   = substr( $raw_key, -4 );
		return $prefix . '****' . $tail;
	}
}
