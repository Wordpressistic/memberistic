<?php
/**
 * Site identity for activations: stable installation UUID, normalized
 * domain, environment detection, and the activation payload.
 *
 * @package WPistic\Sdk
 */

namespace WPistic\Sdk;

/**
 * Represents this site's identity for license activation purposes.
 */
class Activation {

	/**
	 * The product's slug, used as an identifier and for the license key mask.
	 *
	 * @var string
	 */
	private $product_slug;

	/**
	 * The product's version, sent on every activation/validation call.
	 *
	 * @var string
	 */
	private $product_version;

	/**
	 * Constructor.
	 *
	 * @param string $product_slug    The product's slug.
	 * @param string $product_version The product's version.
	 */
	public function __construct( string $product_slug, string $product_version ) {
		$this->product_slug    = $product_slug;
		$this->product_version = $product_version;
	}

	/**
	 * Stable per-install UUID, shared by every WPistic plugin on the site so
	 * the platform's website registry sees one installation identity.
	 */
	public function installation_uuid(): string {
		$uuid = get_option( 'wpistic_installation_uuid' );
		if ( ! is_string( $uuid ) || '' === $uuid ) {
			$uuid = wp_generate_uuid4();
			add_option( 'wpistic_installation_uuid', $uuid, '', 'no' );
		}
		return $uuid;
	}

	/**
	 * Normalized the same way the platform normalizes it server-side
	 * (DomainNormalizer mirrors apps/api/src/utils/domain.ts) — the plugin
	 * sends this exact value on every validate call, and a mismatch against
	 * the platform's own recomputation is a hard 403, not a soft failure.
	 */
	public function domain(): string {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		return DomainNormalizer::normalize( is_string( $host ) ? $host : '' );
	}

	/**
	 * Environment: WordPress' own declaration first, host heuristics second.
	 * The platform re-detects server-side from the normalized domain and
	 * this reported value — this is advisory, matching detectEnvironment().
	 */
	public function environment(): string {
		$wp_env   = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';
		$reported = in_array( $wp_env, array( 'local', 'development', 'staging', 'production' ), true ) ? $wp_env : 'production';
		return DomainNormalizer::detect_environment( $this->domain(), $reported );
	}

	/**
	 * The site-identity payload sent on every activation/validation call.
	 *
	 * @return array<string,string>
	 */
	public function payload(): array {
		return array(
			'domain'            => $this->domain(),
			'installation_uuid' => $this->installation_uuid(),
			'site_url'          => site_url(),
			'home_url'          => home_url(),
			'environment'       => $this->environment(),
			'product_version'   => $this->product_version,
			'wp_version'        => get_bloginfo( 'version' ),
			'php_version'       => PHP_VERSION,
		);
	}

	/**
	 * The product's slug.
	 *
	 * @return string
	 */
	public function product_slug(): string {
		return $this->product_slug;
	}

	/**
	 * The product's version.
	 *
	 * @return string
	 */
	public function product_version(): string {
		return $this->product_version;
	}
}
