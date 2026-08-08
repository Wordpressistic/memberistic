<?php
/**
 * Webhook signature, replay and payload handling (P0-6).
 *
 * The two webhook routes are the plugin's only unauthenticated entry points:
 * public_permissions_check() returns true, and everything that keeps them shut
 * happens inside the handler. That makes these the highest-value negative
 * tests in the suite — a regression here is not a broken feature, it is an
 * open endpoint.
 *
 * Several tests construct a *valid* signature. That is deliberate: asserting
 * only that garbage is rejected cannot distinguish "the signature check works"
 * from "the endpoint rejects everything", and the second would sail through a
 * suite of negative-only tests while silently breaking every real payment.
 *
 * @package Memberistic
 */

final class WebhookSecurityTest extends Memberistic_Integration_TestCase {

	private const STRIPE_ROUTE = '/memberistic/v1/webhooks/stripe';
	private const WOO_ROUTE    = '/memberistic/v1/webhooks/woocommerce';

	private const STRIPE_SECRET = 'whsec_memberistic_integration_test';
	private const WOO_SECRET    = 'memberistic_woo_integration_test';

	/**
	 * Remove anything a test wrote to settings or $_SERVER.
	 *
	 * $_SERVER matters because the WooCommerce handler reads its signature
	 * from HTTP_X_WC_WEBHOOK_SIGNATURE rather than from the request object,
	 * and a leaked superglobal would silently authenticate a later test.
	 */
	public function tear_down(): void {
		unset( $_SERVER['HTTP_X_WC_WEBHOOK_SIGNATURE'] );
		delete_option( 'memberistic_settings' );

		parent::tear_down();
	}

	private function set_setting( string $key, $value ): void {
		$settings         = get_option( 'memberistic_settings', array() );
		$settings         = is_array( $settings ) ? $settings : array();
		$settings[ $key ] = $value;

		update_option( 'memberistic_settings', $settings );
	}

	private function configure_stripe_webhook(): void {
		$this->set_setting( 'stripe_webhook_secret', self::STRIPE_SECRET );

		$this->assertTrue(
			\WordPressistic\Memberistic\Payments\Stripe_Service::webhook_is_configured(),
			'Test setup failed to configure the Stripe webhook secret.'
		);
	}

	/**
	 * Build the signature header Stripe would send for this payload.
	 */
	private function stripe_signature( string $payload, ?int $timestamp = null ): string {
		$timestamp = $timestamp ?? time();
		$signature = hash_hmac( 'sha256', $timestamp . '.' . $payload, self::STRIPE_SECRET );

		return "t={$timestamp},v1={$signature}";
	}

	private function post_stripe( string $payload, ?string $signature ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', self::STRIPE_ROUTE );
		$request->set_header( 'Content-Type', 'application/json' );

		if ( null !== $signature ) {
			$request->set_header( 'stripe-signature', $signature );
		}

		$request->set_body( $payload );

		return rest_do_request( $request );
	}

	private function post_woo( string $body, ?string $signature ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', self::WOO_ROUTE );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( $body );

		if ( null === $signature ) {
			unset( $_SERVER['HTTP_X_WC_WEBHOOK_SIGNATURE'] );
		} else {
			$_SERVER['HTTP_X_WC_WEBHOOK_SIGNATURE'] = $signature;
		}

		return rest_do_request( $request );
	}

	public function test_webhook_routes_are_registered(): void {
		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( self::STRIPE_ROUTE, $routes );
		$this->assertArrayHasKey( self::WOO_ROUTE, $routes );
	}

	/**
	 * An unconfigured Stripe webhook refuses traffic rather than accepting it.
	 */
	public function test_stripe_refuses_when_not_configured(): void {
		$this->assertFalse(
			\WordPressistic\Memberistic\Payments\Stripe_Service::webhook_is_configured(),
			'A fresh install must not ship a Stripe webhook secret.'
		);

		$status = $this->post_stripe( wp_json_encode( array( 'type' => 'invoice.paid' ) ), null )->get_status();

		$this->assertSame(
			503,
			$status,
			"Unconfigured Stripe webhook returned {$status}; expected 503."
		);
	}

	public function test_stripe_rejects_missing_signature(): void {
		$this->configure_stripe_webhook();

		$status = $this->post_stripe( wp_json_encode( array( 'type' => 'invoice.paid' ) ), null )->get_status();

		$this->assertSame( 400, $status, "Stripe webhook accepted a request with no signature (status {$status})." );
	}

	public function test_stripe_rejects_invalid_signature(): void {
		$this->configure_stripe_webhook();

		$payload = wp_json_encode( array( 'type' => 'invoice.paid' ) );
		$status  = $this->post_stripe( $payload, 't=' . time() . ',v1=deadbeef' )->get_status();

		$this->assertSame( 400, $status, "Stripe webhook accepted an invalid signature (status {$status})." );
	}

	/**
	 * A signature computed with the wrong secret must not verify.
	 */
	public function test_stripe_rejects_signature_from_a_different_secret(): void {
		$this->configure_stripe_webhook();

		$payload   = wp_json_encode( array( 'type' => 'invoice.paid' ) );
		$timestamp = time();
		$forged    = hash_hmac( 'sha256', $timestamp . '.' . $payload, 'not_the_real_secret' );

		$status = $this->post_stripe( $payload, "t={$timestamp},v1={$forged}" )->get_status();

		$this->assertSame( 400, $status, "Stripe webhook accepted a signature made with the wrong secret (status {$status})." );
	}

	/**
	 * Replay window: a correctly signed payload older than 300s is refused.
	 */
	public function test_stripe_rejects_a_stale_but_correctly_signed_payload(): void {
		$this->configure_stripe_webhook();

		$payload = wp_json_encode( array( 'type' => 'invoice.paid' ) );
		$stale   = time() - 400;

		$status = $this->post_stripe( $payload, $this->stripe_signature( $payload, $stale ) )->get_status();

		$this->assertSame(
			400,
			$status,
			"Stripe webhook accepted a payload signed 400 seconds ago (status {$status}); the replay window is 300."
		);
	}

	/**
	 * The same payload inside the window is accepted — proving the rejection
	 * above is the timestamp check and not a blanket refusal.
	 */
	public function test_stripe_accepts_a_fresh_correctly_signed_payload(): void {
		$this->configure_stripe_webhook();

		$payload = wp_json_encode( array( 'id' => 'evt_memberistic_test', 'type' => 'ping' ) );

		$status = $this->post_stripe( $payload, $this->stripe_signature( $payload ) )->get_status();

		$this->assertLessThan(
			400,
			$status,
			"A correctly signed, in-window Stripe payload was rejected with {$status}. If this fails alongside the stale-payload test passing, the endpoint is refusing everything rather than verifying signatures."
		);
	}

	/**
	 * Signature verification happens before the payload is parsed, so
	 * malformed JSON has to carry a valid signature to reach the parser.
	 */
	public function test_stripe_rejects_malformed_json_behind_a_valid_signature(): void {
		$this->configure_stripe_webhook();

		$payload = 'this is not json';

		$status = $this->post_stripe( $payload, $this->stripe_signature( $payload ) )->get_status();

		$this->assertSame( 400, $status, "Stripe webhook accepted malformed JSON (status {$status})." );
	}

	/**
	 * Duplicate event ids are recognised, so a Stripe retry cannot be
	 * processed twice into a duplicate payment row and receipt email.
	 */
	public function test_stripe_event_dedup_store_recognises_a_replayed_id(): void {
		$service  = \WordPressistic\Memberistic\Payments\Stripe_Service::class;
		$event_id = 'evt_memberistic_dedup_' . wp_generate_password( 8, false );

		$this->assertFalse( $service::is_event_processed( $event_id ), 'A never-seen event id must not be reported as processed.' );

		$service::mark_event_processed( $event_id );

		$this->assertTrue( $service::is_event_processed( $event_id ), 'A processed event id must be remembered so retries are idempotent.' );
	}

	/**
	 * WooCommerce integration is off by default, and the route says so
	 * rather than doing any work.
	 */
	public function test_woocommerce_webhook_refuses_while_the_bridge_is_disabled(): void {
		$this->assertFalse(
			\WordPressistic\Memberistic\Integrations\WooCommerce_Bridge::is_enabled(),
			'The WooCommerce bridge must default to disabled.'
		);

		$status = $this->post_woo( '{}', base64_encode( hash_hmac( 'sha256', '{}', 'anything', true ) ) )->get_status();

		$this->assertSame( 503, $status, "Disabled WooCommerce webhook returned {$status}; expected 503." );
	}

	/**
	 * The WooCommerce signature path cannot be exercised in this matrix, and
	 * this test states that in the open rather than hiding it in a skip.
	 *
	 * WooCommerce_Bridge::is_enabled() requires class_exists( 'WooCommerce' ),
	 * so with WooCommerce absent the handler always returns 503 before it ever
	 * reaches the HMAC comparison. Three "rejects a bad HMAC" tests written
	 * against this environment would be permanently skipped, which reads on a
	 * release checklist like coverage that does not exist.
	 *
	 * Covering it for real needs WooCommerce installed in the integration
	 * matrix. Until then the assertion below is the honest one: whatever the
	 * configuration, an unauthenticated POST never succeeds.
	 */
	public function test_woocommerce_webhook_never_succeeds_without_a_valid_signature(): void {
		$this->assertFalse(
			class_exists( 'WooCommerce' ),
			'WooCommerce is now present in the test environment — replace this test with real HMAC coverage (see P0-6 in the backlog).'
		);

		$cases = array(
			'no secret, forged signature'   => array( '', base64_encode( hash_hmac( 'sha256', '{}', 'anything', true ) ) ),
			'secret set, forged signature'  => array( self::WOO_SECRET, base64_encode( hash_hmac( 'sha256', '{}', 'wrong_secret', true ) ) ),
			'secret set, no signature'      => array( self::WOO_SECRET, null ),
		);

		foreach ( $cases as $label => $case ) {
			list( $secret, $signature ) = $case;

			$this->set_setting( 'woocommerce_webhook_secret', $secret );

			$status = $this->post_woo( '{}', $signature )->get_status();

			$this->assertGreaterThanOrEqual(
				400,
				$status,
				"WooCommerce webhook returned {$status} for case '{$label}'; it must never succeed."
			);
		}
	}

	/**
	 * No webhook path may reach the network during a test run.
	 */
	public function test_webhook_rejection_makes_no_outbound_request(): void {
		$this->block_and_record_http();
		$this->configure_stripe_webhook();

		$this->post_stripe( wp_json_encode( array( 'type' => 'invoice.paid' ) ), 't=1,v1=bad' );

		$this->assertNoOutboundHttp( 'A rejected webhook must not call out to a third party.' );
	}
}
