<?php
/**
 * Secure update client: injects platform releases into the WordPress
 * update system, authorizes a one-time download URL just before WordPress
 * fetches the package, and verifies the SHA-256 checksum before install.
 *
 * Contract (GET /api/v1/products/:slug/updates then POST /api/v1/downloads/authorize):
 *   - The updates-check response's `download_url` is always null; only
 *     `authorize_url` (a fixed path, currently '/api/v1/downloads/authorize')
 *     is usable — hitting it mints a single-use URL good for 15 minutes.
 *   - Because that URL expires quickly and is single-use, it is never
 *     cached: it is requested inside `upgrader_pre_download`, at the
 *     moment WordPress is actually about to fetch the package, not when
 *     the update is merely detected.
 *
 * Authorization: the RS256 activation token is the sole credential for
 * `/api/v1/downloads/authorize` (`{activation_token, product_slug,
 * requested_version}`). The SDK never stores the raw license key after
 * activation, and the API deliberately does not ask for it here — the
 * activation token already proves an active activation for this exact
 * license and installation, which is what makes unattended background
 * updates possible.
 *
 * @package WPistic\Sdk
 */

namespace WPistic\Sdk;

use WP_Error;

/**
 * Wires WPistic's secure update pipeline into WordPress's update system.
 */
class UpdateClient {

	/**
	 * The SDK client this update pipeline serves.
	 *
	 * @var WpisticClient
	 */
	private $client;

	/**
	 * In-request cache of the last fetched update metadata.
	 *
	 * @var array<string,mixed>|null
	 */
	private $update_cache = null;

	/**
	 * Constructor.
	 *
	 * @param WpisticClient $client The SDK client this update pipeline serves.
	 */
	public function __construct( WpisticClient $client ) {
		$this->client = $client;
	}

	/**
	 * Wire up the WordPress update-system hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
		add_filter( 'upgrader_pre_download', array( $this, 'verified_download' ), 10, 3 );
	}

	/**
	 * The plugin's basename, as WordPress identifies it in update data.
	 *
	 * @return string
	 */
	private function plugin_basename(): string {
		return plugin_basename( $this->client->plugin_file() );
	}

	/** The channel the site prefers to receive updates on ('stable' by default, user-selectable in SettingsPage). */
	public function channel(): string {
		$stored = get_option( $this->channel_option_key() );
		return in_array( $stored, array( 'stable', 'beta', 'early_access' ), true ) ? $stored : 'stable';
	}

	/**
	 * Persist the site's preferred update channel.
	 *
	 * @param string $channel One of 'stable', 'beta', 'early_access'.
	 * @return void
	 */
	public function set_channel( string $channel ): void {
		if ( ! in_array( $channel, array( 'stable', 'beta', 'early_access' ), true ) ) {
			$channel = 'stable';
		}
		update_option( $this->channel_option_key(), $channel, false );
	}

	/**
	 * The wp_options key under which the preferred update channel is stored.
	 *
	 * @return string
	 */
	public function channel_option_key(): string {
		return 'wpistic_' . $this->client->product_slug() . '_update_channel';
	}

	/**
	 * Fetch (or return the cached) update metadata from the platform.
	 *
	 * @return array<string,mixed>|null Update metadata from the platform.
	 */
	private function fetch_update_metadata(): ?array {
		if ( null !== $this->update_cache ) {
			return $this->update_cache;
		}
		$token = $this->client->activation_token();
		if ( '' === $token ) {
			return null;
		}

		$cache_key = 'wpistic_update_' . $this->client->product_slug();
		$cached    = get_site_transient( $cache_key );
		if ( is_array( $cached ) ) {
			$this->update_cache = $cached;
			return $cached;
		}

		$response = $this->client->api()->get(
			'/api/v1/products/' . rawurlencode( $this->client->product_slug() ) . '/updates',
			array(
				'activation_token'  => $token,
				'installation_uuid' => $this->client->activation_helper()->installation_uuid(),
				'version'           => $this->client->product_version(),
				'channel'           => $this->channel(),
				'php_version'       => PHP_VERSION,
				'wp_version'        => get_bloginfo( 'version' ),
			)
		);
		if ( is_wp_error( $response ) || ! is_array( $response ) ) {
			return null;
		}

		set_site_transient( $cache_key, $response, 6 * HOUR_IN_SECONDS );
		$this->update_cache = $response;
		return $response;
	}

	/**
	 * Stable, never-fetched sentinel the SDK recognizes in upgrader_pre_download — the real one-time URL is minted just-in-time.
	 *
	 * @param string $version The available version this sentinel represents.
	 * @return string
	 */
	private function package_sentinel( string $version ): string {
		return 'wpistic-update://' . rawurlencode( $this->client->product_slug() ) . '/' . rawurlencode( $version );
	}

	/**
	 * Injects available-update metadata into the plugin update transient.
	 *
	 * @param object|mixed $transient The update_plugins site transient.
	 * @return object|mixed
	 */
	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		$meta = $this->fetch_update_metadata();
		if ( ! $meta || empty( $meta['available'] ) || empty( $meta['version'] ) ) {
			return $transient;
		}

		$requires = isset( $meta['requires'] ) && is_array( $meta['requires'] ) ? $meta['requires'] : array();

		$item = (object) array(
			'slug'         => $this->client->product_slug(),
			'plugin'       => $this->plugin_basename(),
			'new_version'  => (string) $meta['version'],
			'package'      => $this->package_sentinel( (string) $meta['version'] ),
			'url'          => '',
			'requires_php' => isset( $requires['php'] ) ? (string) $requires['php'] : '',
			'requires'     => isset( $requires['wp'] ) ? (string) $requires['wp'] : '',
			'tested'       => '',
		);

		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}
		$transient->response[ $this->plugin_basename() ] = $item;
		return $transient;
	}

	/**
	 * "View details" modal content.
	 *
	 * @param false|object|array $result The current filter value.
	 * @param string             $action The plugins_api action being handled.
	 * @param object             $args   Arguments passed to plugins_api(), including ->slug.
	 * @return false|object|array
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || $args->slug !== $this->client->product_slug() ) {
			return $result;
		}
		$meta = $this->fetch_update_metadata();
		if ( ! $meta || empty( $meta['available'] ) ) {
			return $result;
		}
		$requires = isset( $meta['requires'] ) && is_array( $meta['requires'] ) ? $meta['requires'] : array();
		$notes    = isset( $meta['release_notes'] ) ? (string) $meta['release_notes'] : '';

		return (object) array(
			'name'          => ucfirst( $this->client->product_slug() ),
			'slug'          => $this->client->product_slug(),
			'version'       => isset( $meta['version'] ) ? (string) $meta['version'] : $this->client->product_version(),
			'requires'      => isset( $requires['wp'] ) ? (string) $requires['wp'] : '',
			'requires_php'  => isset( $requires['php'] ) ? (string) $requires['php'] : '',
			'tested'        => '',
			'download_link' => '',
			'sections'      => array(
				'changelog' => '' !== $notes ? wpautop( esc_html( $notes ) ) : '',
			),
		);
	}

	/**
	 * Mint a one-time download URL right now, download it ourselves, verify
	 * the checksum, and hand WordPress a local file path — so no package is
	 * ever installed without a passing checksum.
	 *
	 * @param bool|WP_Error $reply    The current filter value.
	 * @param string        $package The package URL (or our sentinel) WordPress is about to fetch.
	 * @param object        $upgrader The upgrader instance invoking this filter; unused, but required by the upgrader_pre_download hook signature.
	 * @return bool|string|WP_Error
	 */
	public function verified_download( $reply, $package, $upgrader ) {
		$meta = $this->update_cache;
		if ( ! is_string( $package ) || ! $meta || empty( $meta['version'] ) ) {
			return $reply;
		}
		if ( $package !== $this->package_sentinel( (string) $meta['version'] ) ) {
			return $reply;
		}

		$token = $this->client->activation_token();
		if ( '' === $token ) {
			return new WP_Error(
				'wpistic_download_requires_activation',
				__( 'This site has no active license activation. Activate your license on the settings page, then try updating again.', 'wpistic-sdk' )
			);
		}

		$authorized = $this->client->api()->post(
			'/api/v1/downloads/authorize',
			array(
				'activation_token'  => $token,
				'product_slug'      => $this->client->product_slug(),
				'requested_version' => (string) $meta['version'],
			)
		);
		if ( is_wp_error( $authorized ) ) {
			return $authorized;
		}
		if ( empty( $authorized['download_url'] ) ) {
			return new WP_Error( 'wpistic_authorize_failed', __( 'The WPistic licensing service did not return a download URL.', 'wpistic-sdk' ) );
		}

		// Single-use, short-lived — fetched immediately, never cached.
		$file = download_url( esc_url_raw( (string) $authorized['download_url'] ), 300 );
		if ( is_wp_error( $file ) ) {
			return $file;
		}

		$expected = isset( $meta['checksum'] ) ? strtolower( (string) preg_replace( '/^sha256[:-]/', '', (string) $meta['checksum'] ) ) : '';
		if ( '' !== $expected ) {
			$actual = hash_file( 'sha256', $file );
			if ( ! is_string( $actual ) || ! hash_equals( $expected, $actual ) ) {
				wp_delete_file( $file );
				return new WP_Error(
					'wpistic_checksum_mismatch',
					__( 'Update package failed checksum verification and was discarded.', 'wpistic-sdk' )
				);
			}
		}

		return $file;
	}
}
