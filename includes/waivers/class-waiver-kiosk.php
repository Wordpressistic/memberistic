<?php
/**
 * Waiver kiosk — station-token hardening for the on-site signing surface.
 *
 * The guest sign page stays public (throttled) for links sent by email/SMS,
 * but a front-desk kiosk device gets a signed STATION TOKEN — the same
 * HMAC-with-expiry pattern the Booking Engine uses for member self check-in
 * stations. A valid station:
 *
 *  - bypasses the per-IP guest throttle (one desk iPad signs many guests),
 *  - stamps the station name on every signature (audit trail),
 *  - unlocks the full-screen attract UI with auto-reset between guests,
 *  - optionally shows a PIN-gated staff-exit corner button.
 *
 * Tokens are stateless (HMAC over "station|expires" with a site secret) and
 * default to 12-hour validity — staff mint the day's kiosk link from
 * Memberistic → Waivers each morning, mirroring the check-in station flow.
 * An expired token degrades gracefully to the public throttled page.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic\Waivers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Waiver_Kiosk {

	const SECRET_OPTION = 'memberistic_kiosk_secret';
	const PIN_OPTION    = 'memberistic_kiosk_staff_pin';
	const PARAM         = 'station';

	/** Default token lifetime: 12 hours (filterable). */
	public static function ttl() {
		return (int) apply_filters( 'memberistic_kiosk_token_ttl', 12 * HOUR_IN_SECONDS );
	}

	/** Site kiosk secret, created on first use. */
	private static function secret() {
		$s = (string) get_option( self::SECRET_OPTION, '' );
		if ( '' === $s ) {
			$s = wp_generate_password( 64, true, true );
			update_option( self::SECRET_OPTION, $s, false );
		}
		return $s;
	}

	/** Optional staff PIN for the kiosk's exit button ('' = disabled). */
	public static function staff_pin() {
		return (string) get_option( self::PIN_OPTION, '' );
	}

	/**
	 * Mint a station token: base64url("station|expires") . "." . hmac.
	 *
	 * @param string $station Station label, e.g. "front-desk-ipad".
	 * @param int    $ttl     Seconds of validity (0 = default).
	 * @return string
	 */
	public static function mint_token( $station, $ttl = 0 ) {
		$station = sanitize_title( (string) $station );
		if ( '' === $station ) {
			$station = 'kiosk';
		}
		$expires = time() + ( $ttl > 0 ? (int) $ttl : self::ttl() );
		$payload = $station . '|' . $expires;
		$sig     = hash_hmac( 'sha256', $payload, self::secret() );
		return rtrim( strtr( base64_encode( $payload ), '+/', '-_' ), '=' ) . '.' . $sig;
	}

	/**
	 * Verify a station token.
	 *
	 * @param string $token The token from the request.
	 * @return string Station name, or '' when missing/invalid/expired.
	 */
	public static function verify_token( $token ) {
		$token = (string) $token;
		if ( '' === $token || false === strpos( $token, '.' ) ) {
			return '';
		}
		list( $b64, $sig ) = explode( '.', $token, 2 );
		$payload           = base64_decode( strtr( $b64, '-_', '+/' ) );
		if ( ! $payload || false === strpos( $payload, '|' ) ) {
			return '';
		}
		if ( ! hash_equals( hash_hmac( 'sha256', $payload, self::secret() ), (string) $sig ) ) {
			return '';
		}
		list( $station, $expires ) = explode( '|', $payload, 2 );
		if ( (int) $expires < time() ) {
			return '';
		}
		return sanitize_title( $station );
	}

	/** The station token on the current request ('' when absent). */
	public static function request_token() {
		// phpcs:disable WordPress.Security.NonceVerification -- token is its own HMAC credential.
		if ( isset( $_POST[ self::PARAM ] ) ) {
			return sanitize_text_field( wp_unslash( $_POST[ self::PARAM ] ) );
		}
		if ( isset( $_GET[ self::PARAM ] ) ) {
			return sanitize_text_field( wp_unslash( $_GET[ self::PARAM ] ) );
		}
		// phpcs:enable
		return '';
	}

	/** Verified station name for the current request ('' when not a kiosk). */
	public static function request_station() {
		return self::verify_token( self::request_token() );
	}

	/** Kiosk attract-screen URL for a freshly minted token. */
	public static function kiosk_url( $token ) {
		return add_query_arg(
			array(
				'memberistic_kiosk' => '1',
				self::PARAM         => rawurlencode( (string) $token ),
			),
			home_url( '/' )
		);
	}

	/** Guest sign URL carrying the station token. */
	public static function guest_url( $token ) {
		return add_query_arg( self::PARAM, rawurlencode( (string) $token ), Waiver_Public::guest_url() );
	}
}
