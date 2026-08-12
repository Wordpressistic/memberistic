<?php
/**
 * A scriptable payment provider for exercising the integrity gate.
 *
 * The gate is provider-neutral by design, so testing it through a purpose-built
 * adapter is not a shortcut around the real thing — it is the same interface
 * Stripe and WooCommerce implement, with the remote calls replaced by canned
 * answers a test can set. Driving the tests through the Stripe adapter instead
 * would mean mocking HTTP to assert things that have nothing to do with HTTP,
 * and would quietly stop testing the gate the moment Stripe changed a field
 * name.
 *
 * Stripe's own half — signatures, payload shapes, account identity — is
 * covered by the unit suite, where it needs no database.
 *
 * @package Memberistic
 */

use WordPressistic\Memberistic\Payments\Payment_Clock;
use WordPressistic\Memberistic\Payments\Providers\Payment_Provider;
use WordPressistic\Memberistic\Payments\Subscription_State_Machine;

/**
 * Provider adapter whose "remote truth" is whatever the test says it is.
 */
final class Memberistic_Test_Payment_Provider implements Payment_Provider {
	/** @var array<string, array<string, mixed>> Subscription id => object. */
	public static array $subscriptions = array();

	/** @var array<string, array<string, mixed>> Invoice id => object. */
	public static array $invoices = array();

	/** @var string Account id this site expects events from. */
	public static string $account = 'acct_test';

	/** @var string Environment this site is configured for. */
	public static string $environment = 'live';

	/** @var bool When true, every remote lookup fails as if the API were down. */
	public static bool $unavailable = false;

	/** @var int Remote lookups performed, so tests can assert truth was fetched. */
	public static int $lookups = 0;

	public static function reset(): void {
		self::$subscriptions = array();
		self::$invoices      = array();
		self::$account       = 'acct_test';
		self::$environment   = 'live';
		self::$unavailable   = false;
		self::$lookups       = 0;
	}

	public static function key() {
		return 'testpay';
	}

	public static function is_configured() {
		return true;
	}

	public static function environment() {
		return self::$environment;
	}

	public static function expected_account_id() {
		return self::$account;
	}

	public static function authenticate( $payload, array $headers ) {
		return true;
	}

	public static function normalize_event( array $raw, $payload ) {
		return $raw;
	}

	public static function fetch_subscription( $subscription_id ) {
		self::$lookups++;

		if ( self::$unavailable ) {
			return new WP_Error( 'testpay_unavailable', 'Provider unavailable.' );
		}

		$id = (string) $subscription_id;

		return self::$subscriptions[ $id ] ?? new WP_Error( 'testpay_missing', 'No such subscription.' );
	}

	public static function fetch_invoice( $invoice_id ) {
		self::$lookups++;

		if ( self::$unavailable ) {
			return new WP_Error( 'testpay_unavailable', 'Provider unavailable.' );
		}

		$id = (string) $invoice_id;

		return self::$invoices[ $id ] ?? new WP_Error( 'testpay_missing', 'No such invoice.' );
	}

	public static function billing_state_for_subscription( array $subscription ) {
		$status = isset( $subscription['status'] ) ? (string) $subscription['status'] : '';

		if ( ! empty( $subscription['cancel_at_period_end'] ) && in_array( $status, array( 'active', 'trialing' ), true ) ) {
			return Subscription_State_Machine::CANCEL_AT_PERIOD_END;
		}

		// Reuses Stripe's vocabulary so the state map under test is a real one
		// rather than a bespoke mapping invented for the tests.
		return Subscription_State_Machine::from_provider_state( 'stripe', $status );
	}

	public static function current_period_end( array $subscription ) {
		return isset( $subscription['current_period_end'] )
			? Payment_Clock::from_timestamp( $subscription['current_period_end'] )
			: null;
	}

	/**
	 * Register a subscription as the provider's current truth.
	 *
	 * @param string               $id       Subscription id.
	 * @param string               $status   Provider status.
	 * @param array<string, mixed> $extra    Additional fields.
	 */
	public static function set_subscription( string $id, string $status, array $extra = array() ): void {
		self::$subscriptions[ $id ] = array_merge(
			array(
				'id'                 => $id,
				'status'             => $status,
				'current_period_end' => Payment_Clock::timestamp() + ( 30 * DAY_IN_SECONDS ),
			),
			$extra
		);
	}

	/**
	 * Register an invoice as the provider's current truth.
	 *
	 * @param string $id       Invoice id.
	 * @param float  $amount   Amount paid, major units.
	 * @param bool   $paid     Whether the invoice settled.
	 * @param string $currency ISO code.
	 */
	public static function set_invoice( string $id, float $amount, bool $paid = true, string $currency = 'USD' ): void {
		self::$invoices[ $id ] = array(
			'id'          => $id,
			'paid'        => $paid,
			'status'      => $paid ? 'paid' : 'open',
			'amount_paid' => $amount,
			'currency'    => $currency,
		);
	}
}
