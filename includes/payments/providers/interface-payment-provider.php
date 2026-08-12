<?php
/**
 * The contract a payment provider adapter satisfies.
 *
 * An adapter knows one provider's vocabulary and nothing about Memberistic's
 * rules. It authenticates a request, turns a payload into the normalised shape
 * described below, and fetches current truth when asked. It never decides
 * whether a membership may change state — that is the integrity gate's job,
 * and keeping the two apart is what allows the gate's rules to be tested
 * without a provider and the adapter's parsing to be tested without a database.
 *
 * The normalised event array every adapter produces:
 *
 *     provider                  string  Adapter key, e.g. 'stripe'.
 *     provider_account_id       string  Account the event belongs to; '' if none.
 *     environment               string  'live' or 'test'.
 *     event_id                  string  Provider's unique event id.
 *     event_type                string  Provider's event type.
 *     created_timestamp         int     Provider's event creation time, Unix.
 *     provider_created_at       string  The same, as a UTC MySQL datetime.
 *     payload_hash              string  SHA-256 of the raw payload.
 *     intent                    string  One of the INTENT_* constants below.
 *     object                    array   The provider object the event carried.
 *     provider_customer_id      string  Customer id, '' when absent.
 *     provider_subscription_id  string  Subscription id, '' when absent.
 *     provider_transaction_id   string  Charge/invoice id for payment events.
 *     amount                    float|null   Amount actually paid, major units.
 *     currency                  string  ISO code, uppercase, '' when absent.
 *     membership_hint           int     Membership id claimed by provider metadata.
 *
 * `membership_hint` is named a hint on purpose. Provider metadata is written by
 * this plugin but lives on the provider's servers, where it can be edited by
 * anyone with dashboard access and survives changes this plugin makes later.
 * It is evidence, never authority: the gate may use it to *find* a candidate
 * membership, and must then confirm the relationship from our own records
 * before acting on it.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic\Payments\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface Payment_Provider {
	/** Nothing in this event should change membership state. */
	const INTENT_IGNORE = 'ignore';

	/** A subscription is being established for the first time. */
	const INTENT_ACTIVATION = 'activation';

	/** A recurring payment succeeded. */
	const INTENT_RENEWAL = 'renewal';

	/** A payment failed. */
	const INTENT_PAYMENT_FAILED = 'payment_failed';

	/** A subscription ended. */
	const INTENT_CANCELLATION = 'cancellation';

	/** A subscription's shape changed — trial ended, cancellation scheduled. */
	const INTENT_SUBSCRIPTION_UPDATED = 'subscription_updated';

	/** A trial is about to end. Informational; no state change. */
	const INTENT_TRIAL_ENDING = 'trial_ending';

	/**
	 * Adapter key. Must match the `payment_provider` column value.
	 *
	 * @return string
	 */
	public static function key();

	/**
	 * Whether this provider is configured well enough to process events.
	 *
	 * @return bool
	 */
	public static function is_configured();

	/**
	 * Which environment this site is configured for.
	 *
	 * @return string 'live' or 'test'.
	 */
	public static function environment();

	/**
	 * The provider account this site expects events from.
	 *
	 * @return string Empty when the provider has no account concept.
	 */
	public static function expected_account_id();

	/**
	 * Authenticate a raw inbound request.
	 *
	 * Implementations must verify against the exact raw body, before any
	 * attempt to parse it. Returning anything other than an error means the
	 * payload provably came from the provider.
	 *
	 * @param string                $payload Raw request body, unmodified.
	 * @param array<string, string> $headers Request headers.
	 * @return true|\WP_Error
	 */
	public static function authenticate( $payload, array $headers );

	/**
	 * Turn an authenticated payload into the normalised event shape.
	 *
	 * @param array<string, mixed> $raw     Decoded payload.
	 * @param string               $payload Raw body, for hashing.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function normalize_event( array $raw, $payload );

	/**
	 * Fetch the provider's current view of a subscription.
	 *
	 * @param string $subscription_id Provider subscription id.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function fetch_subscription( $subscription_id );

	/**
	 * Fetch the provider's current view of an invoice or order.
	 *
	 * @param string $invoice_id Provider invoice id.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function fetch_invoice( $invoice_id );

	/**
	 * Read the billing state out of a provider subscription object.
	 *
	 * @param array<string, mixed> $subscription Provider subscription.
	 * @return string|null Canonical billing state, or null if unrecognised.
	 */
	public static function billing_state_for_subscription( array $subscription );
}
