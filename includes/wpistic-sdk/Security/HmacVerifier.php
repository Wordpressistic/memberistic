<?php
/**
 * Offline HMAC verification for signed platform responses. Mirrors
 * apps/api/src/utils/crypto.ts exactly:
 *
 *   Derived_key = HMAC-SHA256(LICENSE_SIGNING_SECRET, license_key_hash)  // server-only
 *   signature   = HMAC-SHA256(derived_key, canonical_json(payload_without_signature))
 *
 * Both are hex-encoded — no base64 anywhere in this contract. The plugin
 * receives `derived_key` once (as `verification_key`) at activation/refresh
 * and uses it as the HMAC key for the second step; it never derives it
 * itself and never sees the master secret.
 *
 * Canonical JSON: recursively key-sorted objects, no whitespace, arrays
 * keep their order, `signature` is excluded before hashing.
 *
 * @package WPistic\Sdk
 */

namespace WPistic\Sdk\Security;

/**
 * Verifies HMAC signatures on platform responses without any network call.
 */
class HmacVerifier {

	/**
	 * Verify a platform response's HMAC signature.
	 *
	 * @param array<string,mixed> $response A decoded platform response, including `signature`.
	 *   Activation/refresh responses also carry `activation_token` and
	 *   `verification_key` — delivery metadata bolted on *after* the server
	 *   computes the signature (see apps/api/src/modules/licenses/service.ts
	 *   `activate()`/`refresh()`: `{...signedBase, activation_token,
	 *   verification_key}`). Both must be excluded here exactly like
	 *   `signature` itself, or verifying any activation/refresh response
	 *   would always fail.
	 * @param string              $verification_key_hex The hex-encoded per-license HMAC key.
	 * @return bool
	 */
	public static function verify( array $response, string $verification_key_hex ): bool {
		if ( empty( $response['signature'] ) || '' === $verification_key_hex ) {
			return false;
		}
		$signature = (string) $response['signature'];
		unset( $response['signature'], $response['activation_token'], $response['verification_key'] );

		$key = self::hex_to_bytes( $verification_key_hex );
		if ( null === $key ) {
			return false;
		}

		$canonical = self::canonical_json( $response );
		$expected  = bin2hex( hash_hmac( 'sha256', $canonical, $key, true ) );

		return hash_equals( $expected, strtolower( $signature ) );
	}

	/**
	 * Recursively encode a value into the platform's canonical JSON form.
	 *
	 * @param mixed $value The value to encode.
	 * @return string
	 */
	public static function canonical_json( $value ): string {
		if ( is_array( $value ) ) {
			// PHP has one array type for both JSON arrays and objects; an
			// empty array is treated as `{}` (never `[]`) because every
			// field in this response contract that can be empty (e.g.
			// `entitlements`) is a JSON object, matching the platform's
			// canonicalJson() which only takes the array branch for real
			// JS arrays.
			$is_list = array() !== $value && array_keys( $value ) === range( 0, count( $value ) - 1 );
			if ( $is_list ) {
				$parts = array();
				foreach ( $value as $item ) {
					$parts[] = self::canonical_json( $item );
				}
				return '[' . implode( ',', $parts ) . ']';
			}
			ksort( $value, SORT_STRING );
			$parts = array();
			foreach ( $value as $k => $v ) {
				$parts[] = self::json_string( (string) $k ) . ':' . self::canonical_json( $v );
			}
			return '{' . implode( ',', $parts ) . '}';
		}
		if ( null === $value ) {
			return 'null';
		}
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return json_encode( $value );
		}
		return self::json_string( (string) $value );
	}

	/**
	 * Encode a single string value as JSON, preferring wp_json_encode().
	 *
	 * @param string $value The string to encode.
	 * @return string
	 */
	private static function json_string( string $value ): string {
		$encoded = function_exists( 'wp_json_encode' )
			? wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
			: json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $encoded ) ? $encoded : '""';
	}

	/**
	 * Decode a hex string into raw bytes, or null if it isn't valid hex.
	 *
	 * @param string $hex The hex-encoded string to decode.
	 * @return string|null
	 */
	private static function hex_to_bytes( string $hex ): ?string {
		if ( '' === $hex || 0 !== strlen( $hex ) % 2 || 1 !== preg_match( '/^[0-9a-fA-F]+$/', $hex ) ) {
			return null;
		}
		$bytes = @pack( 'H*', $hex );
		return is_string( $bytes ) ? $bytes : null;
	}
}
