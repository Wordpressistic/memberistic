<?php
/**
 * "Connect to WPistic" onboarding: OAuth 2.1 + PKCE against account.wpistic.com
 * (GET/POST /authorize, POST /token — confirmed against
 * apps/account/src/auth/authorize.ts, apps/account/src/auth/token.ts, and
 * apps/account/src/index.ts route wiring in this repo) so an admin can sign
 * in with their WPistic account instead of copy-pasting a license key.
 *
 * IMPORTANT — deliberately incomplete, and documented as such rather than
 * faked: account.wpistic.com's OAuth endpoints authenticate a *user* and
 * hand back a generic access/id token. There is currently no endpoint,
 * anywhere in this task's contract or in apps/account, that turns a signed-in
 * account session into a product-specific WPistic license `activation_token`
 * (the thing this SDK actually needs — see LicenseManager). That last step —
 * "pick an org, pick a license, activate it for this site" — has to be
 * confirmed and built against account.wpistic.com (and probably api.wpistic.com)
 * before this flow can complete end-to-end. Until then, `exchange_code_for_activation()`
 * performs the real, confirmed code→token exchange and returns that token
 * bundle; it does NOT return `{activation_token, verification_key}`, and the
 * manual license-key field in SettingsPage stays the reliable path to
 * activation regardless of this flow's state.
 *
 * @package WPistic\Sdk
 */

namespace WPistic\Sdk\Admin;

use WP_Error;
use WPistic\Sdk\WpisticClient;

/**
 * Handles the "Connect to WPistic" OAuth 2.1 + PKCE onboarding flow.
 */
class OnboardingWizard {

	private const PKCE_TRANSIENT_TTL = 5 * MINUTE_IN_SECONDS;

	/**
	 * The SDK client this onboarding flow connects.
	 *
	 * @var WpisticClient
	 */
	private $client;

	/**
	 * Constructor.
	 *
	 * @param WpisticClient $client The SDK client this onboarding flow connects.
	 */
	public function __construct( WpisticClient $client ) {
		$this->client = $client;
	}

	/**
	 * Wire up WordPress hooks for the connect and OAuth callback actions.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_' . $this->connect_action(), array( $this, 'handle_connect' ) );
		add_action( 'admin_post_' . $this->callback_action(), array( $this, 'handle_callback' ) );
	}

	/**
	 * The consuming product's slug.
	 *
	 * @return string
	 */
	private function slug(): string {
		return $this->client->product_slug();
	}

	/**
	 * The admin-post action name that starts the connect flow.
	 *
	 * @return string
	 */
	public function connect_action(): string {
		return 'wpistic_' . $this->slug() . '_connect';
	}

	/**
	 * The admin-post action name that receives the OAuth callback.
	 *
	 * @return string
	 */
	public function callback_action(): string {
		return 'wpistic_' . $this->slug() . '_oauth_callback';
	}

	/**
	 * The settings page URL to return to after the flow completes.
	 *
	 * @return string
	 */
	private function page_url(): string {
		return admin_url( 'options-general.php?page=wpistic-' . $this->slug() . '-license' );
	}

	/**
	 * The redirect_uri sent to account.wpistic.com's authorize endpoint.
	 *
	 * @return string
	 */
	private function redirect_uri(): string {
		return admin_url( 'admin-post.php?action=' . $this->callback_action() );
	}

	/**
	 * The transient key under which the PKCE verifier for a state is stored.
	 *
	 * @param string $state The OAuth `state` value minted for this attempt.
	 * @return string
	 */
	private function pkce_transient_key( string $state ): string {
		return 'wpistic_oauth_' . $this->slug() . '_' . $state;
	}

	/**
	 * Step 1: redirect the admin to account.wpistic.com to sign in and pick
	 * an organization/license, carrying a WordPress-nonce-verified request
	 * plus a fresh, unguessable `state` (also our CSRF token — account.wpistic.com
	 * always returns whatever `state` it was given) and a PKCE S256 challenge
	 * (OAuth 2.1 requires PKCE for every client — see authorize.ts).
	 */
	public function handle_connect(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage licenses.', 'wpistic-sdk' ) );
		}
		check_admin_referer( $this->connect_action() );

		$verifier  = self::base64url_encode( random_bytes( 32 ) );
		$challenge = self::base64url_encode( hash( 'sha256', $verifier, true ) );
		$state     = bin2hex( random_bytes( 16 ) );

		set_transient(
			$this->pkce_transient_key( $state ),
			array(
				'verifier' => $verifier,
				'user_id'  => get_current_user_id(),
			),
			self::PKCE_TRANSIENT_TTL
		);

		$authorize_url = add_query_arg(
			array(
				'response_type'         => 'code',
				'client_id'             => $this->slug(),
				'redirect_uri'          => rawurlencode( $this->redirect_uri() ),
				'scope'                 => rawurlencode( 'openid profile email org' ),
				'state'                 => $state,
				'code_challenge'        => $challenge,
				'code_challenge_method' => 'S256',
			),
			rtrim( $this->client->account_url(), '/' ) . '/authorize'
		);

		// Intentional off-site redirect to the account.wpistic.com sign-in
		// flow; the URL is entirely self-constructed above, not
		// user-supplied, so wp_safe_redirect()'s host allowlist is not the
		// right tool here.
		wp_redirect( $authorize_url ); // phpcs:ignore WordPress.Security.SafeRedirect
		exit;
	}

	/**
	 * Step 2: account.wpistic.com redirects back here with `code` + `state`.
	 */
	public function handle_callback(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage licenses.', 'wpistic-sdk' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- `state` below *is* the CSRF token, verified against the transient it was minted into.
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$oauth_error = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';

		if ( '' !== $oauth_error ) {
			$this->redirect_with_error( $oauth_error );
		}

		$pkce = '' !== $state ? get_transient( $this->pkce_transient_key( $state ) ) : false;
		if ( '' === $state || '' === $code || ! is_array( $pkce ) || empty( $pkce['verifier'] ) ) {
			$this->redirect_with_error( __( 'The connection request expired or was tampered with. Please try again.', 'wpistic-sdk' ) );
		}
		delete_transient( $this->pkce_transient_key( $state ) ); // Single use.

		$result = $this->exchange_code_for_activation( $code, (string) $pkce['verifier'] );
		if ( is_wp_error( $result ) ) {
			$this->redirect_with_error( $result->get_error_message() );
		}

		// See class docblock: this is a generic account session, not yet a
		// product activation. Nothing sensitive from it is persisted by the
		// SDK; the manual key field remains the way to actually activate.
		wp_safe_redirect( add_query_arg( 'wpistic_account_connected', '1', $this->page_url() ) );
		exit;
	}

	/**
	 * Confirmed OAuth 2.1 + PKCE code→token exchange against
	 * account.wpistic.com's `/token` endpoint. Returns the raw token
	 * response (access_token/id_token/refresh_token/expires_in) — NOT a
	 * WPistic `{activation_token, verification_key}` pair; see class
	 * docblock for why that mapping does not exist yet.
	 *
	 * @param string $code          The authorization code from the callback.
	 * @param string $code_verifier The PKCE verifier minted in handle_connect().
	 * @return array<string,mixed>|WP_Error
	 */
	public function exchange_code_for_activation( string $code, string $code_verifier ) {
		$response = wp_remote_post(
			rtrim( $this->client->account_url(), '/' ) . '/token',
			array(
				'timeout'   => 15,
				'sslverify' => true,
				'headers'   => array( 'Accept' => 'application/json' ),
				'body'      => array(
					'grant_type'    => 'authorization_code',
					'client_id'     => $this->slug(),
					'code'          => $code,
					'redirect_uri'  => $this->redirect_uri(),
					'code_verifier' => $code_verifier,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'wpistic_oauth_network_error', __( 'Could not reach the WPistic account service.', 'wpistic-sdk' ), $response );
		}

		$status = wp_remote_retrieve_response_code( $response );
		$json   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status >= 400 ) {
			$message = ( is_array( $json ) && isset( $json['error_description'] ) )
				? sanitize_text_field( (string) $json['error_description'] )
				: __( 'The WPistic account service rejected the connection request.', 'wpistic-sdk' );
			return new WP_Error( 'wpistic_oauth_error', $message, array( 'status' => $status ) );
		}

		if ( ! is_array( $json ) || empty( $json['access_token'] ) ) {
			return new WP_Error( 'wpistic_oauth_invalid_response', __( 'Unexpected response from the WPistic account service.', 'wpistic-sdk' ) );
		}

		return $json;
	}

	/**
	 * Redirects and terminates the request — never returns.
	 *
	 * @param string $message The error message to show on the settings page.
	 * @return void
	 */
	private function redirect_with_error( string $message ): void {
		wp_safe_redirect( add_query_arg( 'wpistic_connect_error', rawurlencode( $message ), $this->page_url() ) );
		exit;
	}

	/**
	 * Base64url-encode raw bytes (RFC 4648 §5) for use in a PKCE challenge.
	 *
	 * @param string $raw The raw bytes to encode.
	 * @return string
	 */
	private static function base64url_encode( string $raw ): string {
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
	}
}
