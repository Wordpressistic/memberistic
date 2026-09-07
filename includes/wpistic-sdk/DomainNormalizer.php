<?php
/**
 * Domain normalization for license activations, matching the platform's
 * server-side implementation byte-for-byte (apps/api/src/utils/domain.ts,
 * `normalizeDomain` / `detectEnvironment`):
 *
 *   Lowercase -> strip protocol -> strip credentials -> strip
 *   path/query/fragment -> strip port -> strip trailing dots -> strip a
 *   single leading "www." (but never down to nothing).
 *
 * The platform re-normalizes and re-detects independently and hard-rejects
 * on mismatch (see LicenseManager::validate_body()), so this class exists
 * to keep what the plugin sends in lockstep with what the platform expects
 * back — not to be the source of truth itself.
 *
 * @package WPistic\Sdk
 */

namespace WPistic\Sdk;

/**
 * Normalizes domains and detects environments for license activations.
 */
class DomainNormalizer {

	/**
	 * Regex patterns identifying obviously local hosts.
	 *
	 * @var string[]
	 */
	private const LOCAL_PATTERNS = array(
		'/^localhost(:\d+)?$/',
		'/\.local$/',
		'/\.test$/',
		'/\.localhost$/',
		'/^127\.\d+\.\d+\.\d+(:\d+)?$/',
		'/^10\.\d+\.\d+\.\d+(:\d+)?$/',
		'/^192\.168\.\d+\.\d+(:\d+)?$/',
	);

	/**
	 * Regex patterns identifying obviously staging hosts.
	 *
	 * @var string[]
	 */
	private const STAGING_PATTERNS = array(
		'/^staging\./',
		'/\.staging\./',
		'/^dev\./',
		'/\.dev\./',
		'/^stage\./',
		'/\.wpengine\.com$/',
		'/\.kinsta\.cloud$/',
		'/\.pantheonsite\.io$/',
	);

	/**
	 * Normalize a raw domain, URL, or host:port string.
	 *
	 * @param string $input The raw domain, URL, or host:port string.
	 * @return string
	 */
	public static function normalize( string $input ): string {
		$domain = self::to_lower( trim( $input ) );

		// Protocol, e.g. "https://", "ftp://".
		$domain = (string) preg_replace( '/^[a-z][a-z0-9+.-]*:\/\//', '', $domain );

		// Credentials, e.g. "user:pass@".
		$domain = (string) preg_replace( '/^[^@\/]+@/', '', $domain );

		// Path, query string, fragment — keep everything before the first
		// of "/", "?", "#" (order-independent; see domain.test.ts parity).
		$parts  = preg_split( '/[\/?#]/', $domain, 2 );
		$domain = is_array( $parts ) ? $parts[0] : $domain;

		// Port.
		$domain = (string) preg_replace( '/:\d+$/', '', $domain );

		// Trailing dots.
		$domain = (string) preg_replace( '/\.+$/', '', $domain );

		// A single leading "www." — never strip down to nothing/"www.".
		if ( 0 === strpos( $domain, 'www.' ) && strlen( $domain ) > 8 ) {
			$domain = substr( $domain, 4 );
		}

		return $domain;
	}

	/**
	 * Best-effort environment detection; mirrors detectEnvironment() in
	 * apps/api/src/utils/domain.ts. The plugin-reported value wins unless
	 * the normalized domain is an obviously local host, or (when reported
	 * is production) an obviously staging host.
	 *
	 * @param string $normalized_domain Already-normalized domain.
	 * @param string $reported          One of local|development|staging|production.
	 */
	public static function detect_environment( string $normalized_domain, string $reported = 'production' ): string {
		if ( self::matches_any( $normalized_domain, self::LOCAL_PATTERNS ) ) {
			return 'local';
		}
		if ( 'production' !== $reported ) {
			return $reported;
		}
		if ( self::matches_any( $normalized_domain, self::STAGING_PATTERNS ) ) {
			return 'staging';
		}
		return 'production';
	}

	/**
	 * Whether a domain matches any of the given regex patterns.
	 *
	 * @param string   $domain   The normalized domain to test.
	 * @param string[] $patterns Regex patterns to test the domain against.
	 * @return bool
	 */
	private static function matches_any( string $domain, array $patterns ): bool {
		foreach ( $patterns as $pattern ) {
			if ( 1 === preg_match( $pattern, $domain ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Lowercase that never throws or returns a falsy non-string on
	 * malformed/non-ASCII input — falls back to byte-wise strtolower()
	 * (ASCII-safe, leaves multi-byte sequences untouched) rather than
	 * losing data.
	 *
	 * @param string $value The value to lowercase.
	 * @return string
	 */
	private static function to_lower( string $value ): string {
		if ( function_exists( 'mb_strtolower' ) ) {
			$result = @mb_strtolower( $value, 'UTF-8' );
			if ( is_string( $result ) && ( '' !== $result || '' === $value ) ) {
				return $result;
			}
		}
		return strtolower( $value );
	}
}
