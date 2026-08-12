<?php
/**
 * Stripe webhook signature verification.
 *
 * The webhook endpoint is the one unauthenticated route in the plugin, and
 * this function is the whole of its authentication. Every test here is a
 * property somebody could otherwise regress without any other test noticing:
 * the suite that existed before this release would have stayed green through
 * a change that accepted forged signatures.
 *
 * @package Memberistic
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WordPressistic\Memberistic\Payments\Payment_Clock;
use WordPressistic\Memberistic\Payments\Providers\Stripe_Provider;

final class StripeSignatureTest extends TestCase {
	private const SECRET  = 'whsec_test_secret_value';
	private const PAYLOAD = '{"id":"evt_1","type":"invoice.payment_succeeded"}';

	protected function setUp(): void {
		memberistic_tests_reset_state();

		$GLOBALS['memberistic_test_settings'] = array(
			'stripe_mode'                => 'test',
			'stripe_webhook_secret_test' => self::SECRET,
		);
	}

	/**
	 * Build a Stripe-Signature header the way Stripe does.
	 *
	 * @param int         $timestamp  Signature timestamp.
	 * @param string|null $secret     Secret to sign with; null for the real one.
	 * @param string      $payload    Body to sign.
	 * @return string
	 */
	private function header( int $timestamp, ?string $secret = null, string $payload = self::PAYLOAD ): string {
		$signature = hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret ?? self::SECRET );

		return sprintf( 't=%d,v1=%s', $timestamp, $signature );
	}

	private function authenticate( string $header, string $payload = self::PAYLOAD ) {
		return Stripe_Provider::authenticate( $payload, array( 'stripe-signature' => $header ) );
	}

	private function assertRejected( $result, string $expected_reason ): void {
		self::assertInstanceOf( WP_Error::class, $result );

		$data = $result->get_error_data();
		self::assertSame( $expected_reason, $data['reason'] ?? null );

		// One public message for every failure. A forger who can tell a stale
		// timestamp from a bad signature can tune their attempts.
		self::assertSame( 'Invalid Stripe webhook signature.', $result->get_error_message() );
	}

	public function test_a_correctly_signed_payload_is_accepted(): void {
		self::assertTrue( $this->authenticate( $this->header( time() ) ) );
	}

	public function test_a_forged_signature_is_rejected(): void {
		$this->assertRejected(
			$this->authenticate( $this->header( time(), 'whsec_the_wrong_secret' ) ),
			'signature_mismatch'
		);
	}

	public function test_a_valid_signature_over_a_different_body_is_rejected(): void {
		// The captured-and-replayed-with-edits case: the signature is genuine,
		// but not for this payload.
		$header = $this->header( time(), null, '{"id":"evt_1","type":"invoice.payment_succeeded","tampered":true}' );

		$this->assertRejected( $this->authenticate( $header ), 'signature_mismatch' );
	}

	public function test_a_missing_signature_header_is_rejected(): void {
		$this->assertRejected( $this->authenticate( '' ), 'missing_signature_header' );
	}

	public function test_a_missing_secret_is_a_configuration_error_not_a_forgery(): void {
		$GLOBALS['memberistic_test_settings'] = array( 'stripe_mode' => 'test' );

		$result = $this->authenticate( $this->header( time() ) );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'memberistic_stripe_webhook_secret_missing', $result->get_error_code() );

		// 503, not 400: the request may be perfectly valid and this site is
		// simply not ready. Stripe retries a 503 and drops a 400, and an event
		// lost because a secret had not been pasted in yet is a renewal that
		// never happens.
		$data = $result->get_error_data();
		self::assertSame( 503, $data['status'] );
	}

	public function test_an_expired_timestamp_is_rejected(): void {
		$this->assertRejected(
			$this->authenticate( $this->header( time() - 301 ) ),
			'timestamp_outside_tolerance'
		);
	}

	public function test_a_timestamp_inside_tolerance_is_accepted(): void {
		self::assertTrue( $this->authenticate( $this->header( time() - 299 ) ) );
	}

	public function test_a_far_future_timestamp_is_rejected(): void {
		// A one-sided check would accept this forever, handing the holder of a
		// captured request an unlimited replay window.
		$this->assertRejected(
			$this->authenticate( $this->header( time() + 3600 ) ),
			'timestamp_outside_tolerance'
		);
	}

	public function test_a_non_numeric_timestamp_is_rejected(): void {
		$header = 't=not-a-number,v1=' . str_repeat( 'a', 64 );

		$this->assertRejected( $this->authenticate( $header ), 'malformed_timestamp' );
	}

	public function test_a_numeric_prefixed_timestamp_is_rejected(): void {
		// `(int) "1600000000junk"` is a valid-looking timestamp. The old
		// implementation used exactly that cast.
		$timestamp = time();
		$header    = sprintf( 't=%djunk,v1=%s', $timestamp, hash_hmac( 'sha256', $timestamp . '.' . self::PAYLOAD, self::SECRET ) );

		$this->assertRejected( $this->authenticate( $header ), 'malformed_timestamp' );
	}

	public function test_a_header_with_no_v1_signature_is_rejected(): void {
		$this->assertRejected(
			$this->authenticate( sprintf( 't=%d,v0=%s', time(), str_repeat( 'a', 64 ) ) ),
			'no_v1_signature'
		);
	}

	public function test_any_one_valid_signature_among_several_is_accepted(): void {
		// Stripe sends two during a secret rotation. Either may be the live one.
		$timestamp = time();
		$valid     = hash_hmac( 'sha256', $timestamp . '.' . self::PAYLOAD, self::SECRET );
		$other     = hash_hmac( 'sha256', $timestamp . '.' . self::PAYLOAD, 'whsec_the_previous_secret' );

		self::assertTrue(
			$this->authenticate( sprintf( 't=%d,v1=%s,v1=%s', $timestamp, $other, $valid ) ),
			'a valid signature listed second must be accepted'
		);

		self::assertTrue(
			$this->authenticate( sprintf( 't=%d,v1=%s,v1=%s', $timestamp, $valid, $other ) ),
			'a valid signature listed first must not be overwritten by a later one'
		);
	}

	public function test_several_invalid_signatures_are_still_rejected(): void {
		$timestamp = time();
		$header    = sprintf(
			't=%d,v1=%s,v1=%s',
			$timestamp,
			hash_hmac( 'sha256', $timestamp . '.' . self::PAYLOAD, 'wrong-one' ),
			hash_hmac( 'sha256', $timestamp . '.' . self::PAYLOAD, 'wrong-two' )
		);

		$this->assertRejected( $this->authenticate( $header ), 'signature_mismatch' );
	}

	public function test_an_oversized_header_is_refused_before_any_hmac_work(): void {
		$timestamp  = time();
		$signatures = array();
		for ( $i = 0; $i < 500; $i++ ) {
			$signatures[] = 'v1=' . str_repeat( 'a', 64 );
		}
		$header = 't=' . $timestamp . ',' . implode( ',', $signatures );

		self::assertGreaterThan( 4096, strlen( $header ) );
		$this->assertRejected( $this->authenticate( $header ), 'signature_header_too_large' );
	}

	public function test_an_empty_payload_is_rejected(): void {
		$this->assertRejected( $this->authenticate( $this->header( time() ), '' ), 'empty_payload' );
	}

	public function test_the_live_secret_is_used_in_live_mode(): void {
		$GLOBALS['memberistic_test_settings'] = array(
			'stripe_mode'                => 'live',
			'stripe_webhook_secret_test' => 'whsec_test_only',
			'stripe_webhook_secret_live' => 'whsec_live_only',
		);

		$timestamp = time();
		$live      = sprintf( 't=%d,v1=%s', $timestamp, hash_hmac( 'sha256', $timestamp . '.' . self::PAYLOAD, 'whsec_live_only' ) );
		$test      = sprintf( 't=%d,v1=%s', $timestamp, hash_hmac( 'sha256', $timestamp . '.' . self::PAYLOAD, 'whsec_test_only' ) );

		self::assertTrue( $this->authenticate( $live ) );

		// The regression this replaced: one shared secret meant a site
		// switched to live verified live events against the test secret and
		// rejected every one of them.
		$this->assertRejected( $this->authenticate( $test ), 'signature_mismatch' );
	}

	public function test_the_legacy_shared_secret_still_verifies_after_upgrade(): void {
		// An install upgrading from 2.0.x has only the old shared setting.
		// It must keep working until the admin adds the per-mode one.
		$GLOBALS['memberistic_test_settings'] = array(
			'stripe_mode'           => 'live',
			'stripe_webhook_secret' => self::SECRET,
		);

		self::assertTrue( $this->authenticate( $this->header( time() ) ) );
		self::assertFalse( Stripe_Provider::has_mode_specific_secret( 'live' ) );
	}

	public function test_the_mode_specific_secret_wins_over_the_legacy_one(): void {
		$GLOBALS['memberistic_test_settings'] = array(
			'stripe_mode'                => 'live',
			'stripe_webhook_secret'      => 'whsec_stale_shared',
			'stripe_webhook_secret_live' => 'whsec_current_live',
		);

		$timestamp = time();
		$header    = sprintf( 't=%d,v1=%s', $timestamp, hash_hmac( 'sha256', $timestamp . '.' . self::PAYLOAD, 'whsec_current_live' ) );

		self::assertTrue( $this->authenticate( $header ) );
		self::assertTrue( Stripe_Provider::has_mode_specific_secret( 'live' ) );
	}

	public function test_the_signature_covers_the_exact_bytes_of_the_body(): void {
		// Sanitising, trimming or re-encoding the body before verification
		// would turn every legitimate event into a rejection. A payload with
		// leading whitespace and unicode proves the raw bytes are used.
		$payload   = "  {\"id\":\"evt_1\",\"note\":\"café — ünicode\"}\n";
		$timestamp = time();
		$header    = sprintf( 't=%d,v1=%s', $timestamp, hash_hmac( 'sha256', $timestamp . '.' . $payload, self::SECRET ) );

		self::assertTrue( $this->authenticate( $header, $payload ) );
		$this->assertRejected( $this->authenticate( $header, trim( $payload ) ), 'signature_mismatch' );
	}

	public function test_the_clock_can_be_frozen_for_reproducible_failures(): void {
		$fixed = 1750000000;
		add_filter( 'memberistic_payment_clock_timestamp', static fn(): int => $fixed );

		self::assertSame( $fixed, Payment_Clock::timestamp() );
		self::assertTrue( $this->authenticate( $this->header( $fixed - 10 ) ) );
		$this->assertRejected( $this->authenticate( $this->header( $fixed - 400 ) ), 'timestamp_outside_tolerance' );
	}
}
