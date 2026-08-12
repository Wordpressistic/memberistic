<?php
/**
 * Stripe adapter: authentication, normalisation, and remote truth.
 *
 * Everything Stripe-shaped lives here. The integrity gate above it works in
 * the normalised vocabulary described on Payment_Provider and never sees a
 * Stripe field name, so adding a second provider does not mean threading
 * `if ( 'stripe' === $provider )` through the decision logic.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic\Payments\Providers;

use WordPressistic\Memberistic\Payments\Payment_Clock;
use WordPressistic\Memberistic\Payments\Stripe_Service;
use WordPressistic\Memberistic\Payments\Subscription_State_Machine;

use function WordPressistic\Memberistic\memberistic_get_setting;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Stripe_Provider implements Payment_Provider {
	/**
	 * How far out of step with Stripe's clock a signature may be, in seconds.
	 *
	 * Stripe's own libraries default to 300 and their documentation recommends
	 * it; the number is a replay window, and every second of it is a second in
	 * which a captured request can be resent.
	 */
	const SIGNATURE_TOLERANCE = 300;

	/**
	 * Longest Stripe-Signature header this will look at, in bytes.
	 *
	 * A real header is around 200 bytes. This bound exists because parsing is
	 * the first thing that happens to an unauthenticated request: without it,
	 * an attacker can post a megabyte of `v1=...` pairs and have the server
	 * compute a HMAC for each one, for free, as many times a second as they
	 * like. Rejecting early makes that attack cost nothing to defend.
	 */
	const MAX_SIGNATURE_HEADER = 4096;

	/**
	 * Most `v1` signatures considered from one header.
	 *
	 * Stripe sends more than one only while an endpoint's secret is being
	 * rotated, and then it sends two.
	 */
	const MAX_SIGNATURES = 10;

	/** Option holding the Stripe account id verified for each mode. */
	const ACCOUNT_OPTION = 'memberistic_stripe_account_identity';

	/**
	 * {@inheritDoc}
	 */
	public static function key() {
		return 'stripe';
	}

	/**
	 * {@inheritDoc}
	 */
	public static function is_configured() {
		return Stripe_Service::is_enabled()
			&& '' !== trim( (string) Stripe_Service::get_secret_key() )
			&& '' !== self::webhook_secret();
	}

	/**
	 * {@inheritDoc}
	 */
	public static function environment() {
		return 'live' === memberistic_get_setting( 'stripe_mode', 'test' ) ? 'live' : 'test';
	}

	/**
	 * The signing secret for the mode this site is running in.
	 *
	 * Stripe issues one signing secret per endpoint, and a test endpoint and a
	 * live endpoint are two endpoints with two secrets. Memberistic used to
	 * store a single `stripe_webhook_secret`, which meant that switching a site
	 * from test to live left the test secret in place and every live event
	 * failed its signature check — arriving as a 400, looking exactly like an
	 * attack, and silently not renewing anybody.
	 *
	 * The mode-specific setting wins. The shared legacy setting is the
	 * fallback, so an install that upgrades keeps verifying events on the mode
	 * it was already configured for, and the admin health screen asks for the
	 * missing one rather than waiting for a member to complain.
	 *
	 * @param string $mode Optional mode override; defaults to the site's.
	 * @return string
	 */
	public static function webhook_secret( $mode = '' ) {
		$mode = ( 'live' === $mode || 'test' === $mode ) ? $mode : self::environment();

		$specific = trim( (string) memberistic_get_setting( 'stripe_webhook_secret_' . $mode, '' ) );
		if ( '' !== $specific ) {
			return $specific;
		}

		return trim( (string) memberistic_get_setting( 'stripe_webhook_secret', '' ) );
	}

	/**
	 * Whether the mode in use has its own signing secret, rather than the
	 * shared legacy one.
	 *
	 * @param string $mode Mode to check.
	 * @return bool
	 */
	public static function has_mode_specific_secret( $mode = '' ) {
		$mode = ( 'live' === $mode || 'test' === $mode ) ? $mode : self::environment();

		return '' !== trim( (string) memberistic_get_setting( 'stripe_webhook_secret_' . $mode, '' ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public static function expected_account_id() {
		$identity = get_option( self::ACCOUNT_OPTION, array() );
		$mode     = self::environment();

		if ( ! is_array( $identity ) || empty( $identity[ $mode ]['account_id'] ) ) {
			return '';
		}

		return (string) $identity[ $mode ]['account_id'];
	}

	/**
	 * Ask Stripe which account the configured credentials belong to, and
	 * remember the answer.
	 *
	 * Called when Stripe settings are saved and by the reconciliation command,
	 * never on the webhook path: an API round trip per inbound event would add
	 * a network dependency to the one code path that most needs to stay fast
	 * and available, and the answer only changes when the API key changes.
	 *
	 * @return string|\WP_Error The account id.
	 */
	public static function refresh_account_identity() {
		$account = Stripe_Service::request( 'GET', '/account' );

		if ( is_wp_error( $account ) ) {
			return $account;
		}

		$account_id = isset( $account['id'] ) ? sanitize_text_field( (string) $account['id'] ) : '';

		if ( '' === $account_id ) {
			return new \WP_Error(
				'memberistic_stripe_account_unknown',
				__( 'Stripe did not return an account id for these credentials.', 'memberistic' )
			);
		}

		$identity = get_option( self::ACCOUNT_OPTION, array() );
		$identity = is_array( $identity ) ? $identity : array();

		$identity[ self::environment() ] = array(
			'account_id'  => $account_id,
			'verified_at' => Payment_Clock::now(),
		);

		update_option( self::ACCOUNT_OPTION, $identity, false );

		return $account_id;
	}

	/**
	 * When the account identity for the current mode was last confirmed.
	 *
	 * @return string UTC datetime, or '' if never.
	 */
	public static function account_verified_at() {
		$identity = get_option( self::ACCOUNT_OPTION, array() );
		$mode     = self::environment();

		if ( ! is_array( $identity ) || empty( $identity[ $mode ]['verified_at'] ) ) {
			return '';
		}

		return (string) $identity[ $mode ]['verified_at'];
	}

	/**
	 * {@inheritDoc}
	 *
	 * The raw body is verified byte for byte. It is not trimmed, unslashed,
	 * re-encoded or passed through a sanitiser first: the signature covers the
	 * exact bytes Stripe sent, so altering a single one — which
	 * `sanitize_text_field()` on a JSON body certainly would — turns a valid
	 * signature into an invalid one and every legitimate event into a 400.
	 * Sanitising happens after authentication, on the parsed values.
	 */
	public static function authenticate( $payload, array $headers ) {
		$secret = self::webhook_secret();

		if ( '' === $secret ) {
			return new \WP_Error(
				'memberistic_stripe_webhook_secret_missing',
				sprintf(
					/* translators: %s: Stripe mode, "live" or "test". */
					__( 'No Stripe webhook signing secret is configured for %s mode.', 'memberistic' ),
					self::environment()
				),
				array( 'status' => 503 )
			);
		}

		$header = '';
		foreach ( array( 'stripe-signature', 'stripe_signature' ) as $name ) {
			if ( isset( $headers[ $name ] ) && '' !== trim( (string) $headers[ $name ] ) ) {
				$header = (string) $headers[ $name ];
				break;
			}
		}

		if ( '' === $header ) {
			return self::signature_error( 'missing_signature_header' );
		}

		if ( strlen( $header ) > self::MAX_SIGNATURE_HEADER ) {
			return self::signature_error( 'signature_header_too_large' );
		}

		if ( '' === $payload ) {
			return self::signature_error( 'empty_payload' );
		}

		$parsed = self::parse_signature_header( $header );

		if ( null === $parsed['timestamp'] ) {
			return self::signature_error( 'malformed_timestamp' );
		}

		// Both directions. Too old is a replay; too far in the future is a
		// forged timestamp trying to buy an indefinite replay window, and a
		// one-sided check would accept it forever.
		$drift = abs( Payment_Clock::timestamp() - $parsed['timestamp'] );
		if ( $drift > self::SIGNATURE_TOLERANCE ) {
			return self::signature_error( 'timestamp_outside_tolerance' );
		}

		if ( empty( $parsed['signatures'] ) ) {
			return self::signature_error( 'no_v1_signature' );
		}

		$expected = hash_hmac( 'sha256', $parsed['timestamp'] . '.' . $payload, $secret );
		$matched  = false;

		// Every candidate is compared, and the loop is not broken early on a
		// match. Stripe sends two signatures during a secret rotation and
		// either may be the valid one; comparing all of them in constant time
		// also keeps the work done independent of which one matched.
		foreach ( $parsed['signatures'] as $candidate ) {
			if ( hash_equals( $expected, $candidate ) ) {
				$matched = true;
			}
		}

		if ( ! $matched ) {
			return self::signature_error( 'signature_mismatch' );
		}

		return true;
	}

	/**
	 * Split a Stripe-Signature header into its timestamp and `v1` values.
	 *
	 * @param string $header Raw header value.
	 * @return array{timestamp:int|null, signatures:array<string>}
	 */
	private static function parse_signature_header( $header ) {
		$timestamp  = null;
		$signatures = array();

		foreach ( explode( ',', $header ) as $part ) {
			$pair = explode( '=', trim( $part ), 2 );

			if ( 2 !== count( $pair ) ) {
				continue;
			}

			$name  = trim( $pair[0] );
			$value = trim( $pair[1] );

			if ( 't' === $name && null === $timestamp ) {
				// Digits only. `(int) "16abc"` is 16, and PHP would happily
				// accept "1600000000junk" as a timestamp; a header that is not
				// what Stripe sends should be refused, not interpreted.
				if ( '' !== $value && ctype_digit( $value ) ) {
					$timestamp = (int) $value;
				}
				continue;
			}

			if ( 'v1' === $name && count( $signatures ) < self::MAX_SIGNATURES ) {
				// Hex only, and the right length for SHA-256. hash_equals()
				// compares safely regardless, but filtering here keeps the
				// comparison loop over values that could conceivably match.
				if ( 64 === strlen( $value ) && ctype_xdigit( $value ) ) {
					$signatures[] = strtolower( $value );
				}
			}
		}

		return array(
			'timestamp'  => $timestamp,
			'signatures' => $signatures,
		);
	}

	/**
	 * A uniform signature failure.
	 *
	 * The caller gets one message for every reason. Telling an unauthenticated
	 * requester whether their timestamp was stale, their signature malformed or
	 * simply wrong hands them a tuning signal. The specific code travels in the
	 * error data, is recorded in the ledger, and is visible to administrators.
	 *
	 * The signing secret is never included, logged, or interpolated into any of
	 * these paths.
	 *
	 * @param string $code Internal reason code.
	 * @return \WP_Error
	 */
	private static function signature_error( $code ) {
		return new \WP_Error(
			'memberistic_stripe_bad_signature',
			__( 'Invalid Stripe webhook signature.', 'memberistic' ),
			array(
				'status' => 400,
				'reason' => sanitize_key( $code ),
			)
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public static function normalize_event( array $raw, $payload ) {
		$event_id = isset( $raw['id'] ) ? sanitize_text_field( (string) $raw['id'] ) : '';
		$type     = isset( $raw['type'] ) ? sanitize_text_field( (string) $raw['type'] ) : '';

		if ( '' === $event_id || '' === $type ) {
			return new \WP_Error(
				'memberistic_stripe_malformed_event',
				__( 'Stripe event is missing an id or a type.', 'memberistic' ),
				array( 'status' => 400 )
			);
		}

		$object = isset( $raw['data']['object'] ) && is_array( $raw['data']['object'] ) ? $raw['data']['object'] : array();

		// `livemode` is part of the signed payload, so it is as trustworthy as
		// the signature — which is the point. It tells us whether a test-mode
		// endpoint has been pointed at a live site, or the reverse.
		$environment = ! empty( $raw['livemode'] ) ? 'live' : 'test';

		$created = isset( $raw['created'] ) && is_numeric( $raw['created'] ) ? (int) $raw['created'] : 0;

		$normalized = array(
			'provider'                 => self::key(),
			'provider_account_id'      => isset( $raw['account'] ) ? sanitize_text_field( (string) $raw['account'] ) : self::expected_account_id(),
			'environment'              => $environment,
			'event_id'                 => $event_id,
			'event_type'               => $type,
			'created_timestamp'        => $created,
			'provider_created_at'      => Payment_Clock::from_timestamp( $created ),
			'payload_hash'             => hash( 'sha256', (string) $payload ),
			'intent'                   => self::intent_for( $type ),
			'object'                   => $object,
			'provider_customer_id'     => self::extract_customer_id( $object ),
			'provider_subscription_id' => self::extract_subscription_id( $type, $object ),
			'provider_transaction_id'  => self::extract_transaction_id( $type, $object ),
			'amount'                   => self::extract_amount( $type, $object ),
			'currency'                 => isset( $object['currency'] ) ? strtoupper( sanitize_text_field( (string) $object['currency'] ) ) : '',
			'membership_hint'          => self::extract_membership_hint( $object ),
			'billing_reason'           => isset( $object['billing_reason'] ) ? sanitize_text_field( (string) $object['billing_reason'] ) : '',
		);

		return $normalized;
	}

	/**
	 * What a Stripe event type means in provider-neutral terms.
	 *
	 * Anything not listed is INTENT_IGNORE. That default is deliberate: Stripe
	 * adds event types regularly, and an unknown event must do nothing rather
	 * than fall through to a handler that happens to be nearby.
	 *
	 * @param string $type Stripe event type.
	 * @return string
	 */
	private static function intent_for( $type ) {
		$map = array(
			'checkout.session.completed'           => self::INTENT_ACTIVATION,
			'invoice.payment_succeeded'            => self::INTENT_RENEWAL,
			'invoice.paid'                         => self::INTENT_RENEWAL,
			'invoice.payment_failed'               => self::INTENT_PAYMENT_FAILED,
			'customer.subscription.created'        => self::INTENT_SUBSCRIPTION_UPDATED,
			'customer.subscription.updated'        => self::INTENT_SUBSCRIPTION_UPDATED,
			'customer.subscription.deleted'        => self::INTENT_CANCELLATION,
			'customer.subscription.trial_will_end' => self::INTENT_TRIAL_ENDING,
		);

		return isset( $map[ $type ] ) ? $map[ $type ] : self::INTENT_IGNORE;
	}

	/**
	 * Pull the customer id out of any Stripe object shape.
	 *
	 * @param array<string, mixed> $object Stripe object.
	 * @return string
	 */
	private static function extract_customer_id( array $object ) {
		if ( isset( $object['customer'] ) && is_string( $object['customer'] ) ) {
			return sanitize_text_field( $object['customer'] );
		}

		// Expanded objects carry the customer as a nested object rather than
		// an id string.
		if ( isset( $object['customer']['id'] ) && is_string( $object['customer']['id'] ) ) {
			return sanitize_text_field( $object['customer']['id'] );
		}

		return '';
	}

	/**
	 * Pull the subscription id out of an event object.
	 *
	 * Invoices have moved this field twice across Stripe API versions, and an
	 * installation can be pinned to any of them, so all three shapes are read.
	 *
	 * @param string               $type   Stripe event type.
	 * @param array<string, mixed> $object Stripe object.
	 * @return string
	 */
	private static function extract_subscription_id( $type, array $object ) {
		// The subscription object itself.
		if ( 0 === strpos( $type, 'customer.subscription.' ) ) {
			return isset( $object['id'] ) ? sanitize_text_field( (string) $object['id'] ) : '';
		}

		if ( ! empty( $object['subscription'] ) && is_string( $object['subscription'] ) ) {
			return sanitize_text_field( $object['subscription'] );
		}

		if ( ! empty( $object['parent']['subscription_details']['subscription'] ) && is_string( $object['parent']['subscription_details']['subscription'] ) ) {
			return sanitize_text_field( $object['parent']['subscription_details']['subscription'] );
		}

		if ( ! empty( $object['subscription_details']['subscription'] ) && is_string( $object['subscription_details']['subscription'] ) ) {
			return sanitize_text_field( $object['subscription_details']['subscription'] );
		}

		return '';
	}

	/**
	 * The id that identifies this money movement uniquely.
	 *
	 * @param string               $type   Stripe event type.
	 * @param array<string, mixed> $object Stripe object.
	 * @return string
	 */
	private static function extract_transaction_id( $type, array $object ) {
		if ( 0 === strpos( $type, 'invoice.' ) ) {
			// The payment intent when there is one, the invoice id otherwise.
			// An invoice settled from credit balance has no payment intent and
			// still needs a stable identity for the payments table.
			if ( ! empty( $object['payment_intent'] ) && is_string( $object['payment_intent'] ) ) {
				return sanitize_text_field( $object['payment_intent'] );
			}

			return isset( $object['id'] ) ? sanitize_text_field( (string) $object['id'] ) : '';
		}

		if ( 'checkout.session.completed' === $type ) {
			if ( ! empty( $object['payment_intent'] ) && is_string( $object['payment_intent'] ) ) {
				return sanitize_text_field( $object['payment_intent'] );
			}

			return isset( $object['id'] ) ? sanitize_text_field( (string) $object['id'] ) : '';
		}

		return '';
	}

	/**
	 * The amount actually paid, in major units.
	 *
	 * Stripe reports minor units — cents, pence, yen. `amount_paid` is used in
	 * preference to `amount_due` throughout: the question the gate asks is what
	 * the member was actually charged, and an invoice can be due for one amount
	 * and paid for another when a coupon, credit balance or partial payment is
	 * involved.
	 *
	 * Zero-decimal currencies (JPY and friends) are not divided by 100, because
	 * for those Stripe's minor unit *is* the major unit and dividing would
	 * report a ¥5,000 charge as ¥50.
	 *
	 * @param string               $type   Stripe event type.
	 * @param array<string, mixed> $object Stripe object.
	 * @return float|null
	 */
	private static function extract_amount( $type, array $object ) {
		$minor = null;

		if ( array_key_exists( 'amount_paid', $object ) && is_numeric( $object['amount_paid'] ) ) {
			$minor = (int) $object['amount_paid'];
		} elseif ( array_key_exists( 'amount_total', $object ) && is_numeric( $object['amount_total'] ) ) {
			$minor = (int) $object['amount_total'];
		} elseif ( array_key_exists( 'amount', $object ) && is_numeric( $object['amount'] ) ) {
			$minor = (int) $object['amount'];
		}

		if ( null === $minor ) {
			return null;
		}

		$currency = isset( $object['currency'] ) ? strtolower( (string) $object['currency'] ) : '';

		return self::to_major_units( $minor, $currency );
	}

	/**
	 * Convert a Stripe minor-unit amount to major units.
	 *
	 * @param int    $minor    Amount in the currency's smallest unit.
	 * @param string $currency Lowercase ISO code.
	 * @return float
	 */
	public static function to_major_units( $minor, $currency ) {
		return in_array( strtolower( (string) $currency ), self::zero_decimal_currencies(), true )
			? (float) $minor
			: round( (float) $minor / 100, 2 );
	}

	/**
	 * Convert a major-unit amount to Stripe minor units.
	 *
	 * @param float  $major    Amount in major units.
	 * @param string $currency Lowercase ISO code.
	 * @return int
	 */
	public static function to_minor_units( $major, $currency ) {
		return in_array( strtolower( (string) $currency ), self::zero_decimal_currencies(), true )
			? (int) round( (float) $major )
			: (int) round( (float) $major * 100 );
	}

	/**
	 * Currencies Stripe treats as having no minor unit.
	 *
	 * @return array<string>
	 */
	public static function zero_decimal_currencies() {
		return array(
			'bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga',
			'pyg', 'rwf', 'ugx', 'vnd', 'vuv', 'xaf', 'xof', 'xpf',
		);
	}

	/**
	 * The membership id claimed by provider metadata.
	 *
	 * A hint, and named one everywhere it travels. Metadata is stored on
	 * Stripe's servers, is editable by anyone with dashboard access, and
	 * persists unchanged when this plugin later re-points a membership at a
	 * different subscription. It may be used to *find* a candidate; the
	 * relationship must then be confirmed against our own records.
	 *
	 * @param array<string, mixed> $object Stripe object.
	 * @return int
	 */
	private static function extract_membership_hint( array $object ) {
		if ( ! empty( $object['metadata']['membership_id'] ) ) {
			return absint( $object['metadata']['membership_id'] );
		}

		if ( ! empty( $object['subscription_details']['metadata']['membership_id'] ) ) {
			return absint( $object['subscription_details']['metadata']['membership_id'] );
		}

		if ( ! empty( $object['parent']['subscription_details']['metadata']['membership_id'] ) ) {
			return absint( $object['parent']['subscription_details']['metadata']['membership_id'] );
		}

		return 0;
	}

	/**
	 * {@inheritDoc}
	 */
	public static function fetch_subscription( $subscription_id ) {
		return Stripe_Service::get_subscription( $subscription_id );
	}

	/**
	 * {@inheritDoc}
	 */
	public static function fetch_invoice( $invoice_id ) {
		$invoice_id = trim( (string) $invoice_id );

		if ( '' === $invoice_id ) {
			return new \WP_Error( 'memberistic_missing_invoice', __( 'No invoice id provided.', 'memberistic' ) );
		}

		return Stripe_Service::request( 'GET', '/invoices/' . rawurlencode( $invoice_id ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * `cancel_at_period_end` is a flag on an otherwise active subscription
	 * rather than a status of its own, so it is resolved before the status map
	 * is consulted — otherwise a member who has cancelled but paid through the
	 * month is indistinguishable from one who has not cancelled at all, and the
	 * scheduled cancellation is forgotten.
	 */
	public static function billing_state_for_subscription( array $subscription ) {
		$status = isset( $subscription['status'] ) ? (string) $subscription['status'] : '';

		if ( ! empty( $subscription['cancel_at_period_end'] ) && in_array( $status, array( 'active', 'trialing' ), true ) ) {
			return Subscription_State_Machine::CANCEL_AT_PERIOD_END;
		}

		return Subscription_State_Machine::from_provider_state( self::key(), $status );
	}

	/**
	 * The end of the period a subscription has been paid through.
	 *
	 * @param array<string, mixed> $subscription Stripe subscription.
	 * @return string|null UTC datetime.
	 */
	public static function current_period_end( array $subscription ) {
		if ( isset( $subscription['current_period_end'] ) ) {
			return Payment_Clock::from_timestamp( $subscription['current_period_end'] );
		}

		// Newer API versions moved the period onto the subscription item.
		if ( isset( $subscription['items']['data'][0]['current_period_end'] ) ) {
			return Payment_Clock::from_timestamp( $subscription['items']['data'][0]['current_period_end'] );
		}

		return null;
	}

	/**
	 * The Stripe price ids a subscription is billing.
	 *
	 * @param array<string, mixed> $subscription Stripe subscription.
	 * @return array<string>
	 */
	public static function subscription_price_ids( array $subscription ) {
		$ids = array();

		if ( empty( $subscription['items']['data'] ) || ! is_array( $subscription['items']['data'] ) ) {
			return $ids;
		}

		foreach ( $subscription['items']['data'] as $item ) {
			if ( ! empty( $item['price']['id'] ) && is_string( $item['price']['id'] ) ) {
				$ids[] = sanitize_text_field( $item['price']['id'] );
				continue;
			}

			if ( ! empty( $item['plan']['id'] ) && is_string( $item['plan']['id'] ) ) {
				$ids[] = sanitize_text_field( $item['plan']['id'] );
			}
		}

		return $ids;
	}
}
