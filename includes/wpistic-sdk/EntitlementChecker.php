<?php
/**
 * Typed access to the entitlement map from the last valid license response.
 * Product code never checks plan names:
 *
 *     If ( $client->entitlements()->allows( 'seoistic.pro.enabled' ) ) { ... }
 *     $max_sites = $client->entitlements()->getMax( 'seoistic.sites.max' );
 *     if ( $client->entitlements()->isPro( 'seoistic' ) ) { ... }
 *
 * @package WPistic\Sdk
 */

namespace WPistic\Sdk;

/**
 * Typed, plan-name-agnostic access to a license's entitlement map.
 */
class EntitlementChecker {

	/**
	 * The raw entitlement map from the last valid license response.
	 *
	 * @var array<string,mixed>
	 */
	private $map;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $map The raw entitlement map.
	 */
	public function __construct( array $map = array() ) {
		$this->map = $map;
	}

	/**
	 * Whether a boolean-flag entitlement is enabled.
	 *
	 * @param string $key The entitlement key.
	 * @return bool
	 */
	public function allows( string $key ): bool {
		return isset( $this->map[ $key ] ) && true === $this->map[ $key ];
	}

	/**
	 * The numeric limit for an entitlement key, or 0 if absent/non-numeric.
	 *
	 * @param string $key The entitlement key.
	 * @return int
	 */
	public function getMax( string $key ): int { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName -- deliberate camelCase public API, spec-mandated.
		if ( isset( $this->map[ $key ] ) && is_numeric( $this->map[ $key ] ) ) {
			return (int) $this->map[ $key ];
		}
		return 0;
	}

	/**
	 * Convenience over allows( "{slug}.pro.enabled" ).
	 *
	 * @param string $product_slug The product's slug.
	 * @return bool
	 */
	public function isPro( string $product_slug ): bool { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName -- deliberate camelCase public API, spec-mandated.
		return $this->allows( $product_slug . '.pro.enabled' );
	}

	/**
	 * The raw value for an entitlement key, or a default when absent.
	 *
	 * @param string $key           The entitlement key.
	 * @param mixed  $default_value The value to return when the key is absent.
	 * @return mixed
	 */
	public function value( string $key, $default_value = null ) {
		return isset( $this->map[ $key ] ) ? $this->map[ $key ] : $default_value;
	}

	/**
	 * The full raw entitlement map.
	 *
	 * @return array<string,mixed>
	 */
	public function all(): array {
		return $this->map;
	}
}
