<?php
/**
 * The Payment Integrity Gate.
 *
 * One rule, from which everything here follows:
 *
 *     Payment-provider events are evidence to verify, never commands to trust.
 *
 * A webhook is an unauthenticated HTTP request until proven otherwise, and even
 * once its signature checks out it is only a claim about something that
 * happened somewhere else. Before it may change a membership it has to survive,
 * in this order:
 *
 *     authenticate the request
 *     confirm the provider account and environment
 *     normalise the payload
 *     claim the event atomically, or recognise it as a duplicate
 *     resolve the membership from our own records
 *     confirm the customer relationship
 *     confirm the subscription relationship
 *     confirm the plan relationship
 *     confirm the amount and currency
 *     fetch the provider's current truth where the decision depends on it
 *     reject anything older than what we have already applied
 *     ask the state machine whether the transition is permitted
 *     commit the local changes
 *     record the decision, whichever way it went
 *     only then, fire side effects
 *
 * No webhook handler activates, renews, downgrades, cancels or expires a
 * membership by itself any more. They describe what an event appears to mean;
 * this decides whether that may happen.
 *
 * The ordering of the last three steps is not stylistic. Emails and hooks fire
 * after the state is committed, because a receipt for a payment that failed to
 * record is worse than a late receipt, and because a duplicate delivery that
 * loses the atomic claim must send nothing at all.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic\Payments;

use WordPressistic\Memberistic\Database\Activity_Repository;
use WordPressistic\Memberistic\Database\Memberships_Repository;
use WordPressistic\Memberistic\Database\Payments_Repository;
use WordPressistic\Memberistic\Database\Plans_Repository;
use WordPressistic\Memberistic\Emails\Email_Service;
use WordPressistic\Memberistic\Payments\Providers\Payment_Provider;
use WordPressistic\Memberistic\Payments\Providers\Stripe_Provider;
use WordPressistic\Memberistic\Payments\Providers\WooCommerce_Provider;

use function WordPressistic\Memberistic\memberistic_format_price;
use function WordPressistic\Memberistic\memberistic_get_setting;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Payment_Integrity_Gate {
	/** Membership statuses a payment event must never overwrite. */
	const STAFF_OWNED_STATUSES = array( 'comped', 'paused', 'suspended', 'needs_review', 'inactive' );

	/**
	 * Whether an inbound provider event is being applied right now.
	 *
	 * Read by the outbound-cancellation listener. When a provider tells us a
	 * subscription was cancelled and we record that locally, the resulting
	 * status change must not send a cancellation request straight back to the
	 * provider for a subscription it has just finished cancelling.
	 *
	 * @var bool
	 */
	private static $processing_inbound_event = false;

	/**
	 * Whether the gate is currently applying an inbound provider event.
	 *
	 * @return bool
	 */
	public static function is_processing_inbound_event() {
		return self::$processing_inbound_event;
	}

	/**
	 * The registered provider adapters.
	 *
	 * @return array<string, string> Provider key => class name.
	 */
	public static function providers() {
		$providers = array(
			Stripe_Provider::key()      => Stripe_Provider::class,
			WooCommerce_Provider::key() => WooCommerce_Provider::class,
		);

		/**
		 * Filters the payment provider adapters the gate will accept events from.
		 *
		 * Every value must implement Payments\Providers\Payment_Provider.
		 *
		 * @param array<string, string> $providers Provider key => class name.
		 */
		return (array) apply_filters( 'memberistic_payment_providers', $providers );
	}

	/**
	 * Resolve a provider adapter class.
	 *
	 * @param string $key Provider key.
	 * @return string|null Class name.
	 */
	public static function provider( $key ) {
		$providers = self::providers();
		$key       = sanitize_key( (string) $key );

		if ( ! isset( $providers[ $key ] ) ) {
			return null;
		}

		$class = (string) $providers[ $key ];

		// A filter that registers a class which is not an adapter would
		// otherwise fail deep inside the flow, with a fatal, on a webhook.
		if ( ! class_exists( $class ) || ! in_array( Payment_Provider::class, class_implements( $class ) ?: array(), true ) ) {
			return null;
		}

		return $class;
	}

	/**
	 * Entry point for an inbound provider webhook.
	 *
	 * @param string                $provider_key Provider key.
	 * @param string                $payload      Raw request body, unmodified.
	 * @param array<string, string> $headers      Request headers, lowercase keys.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function handle_webhook( $provider_key, $payload, array $headers ) {
		$provider = self::provider( $provider_key );

		if ( null === $provider ) {
			return new \WP_Error(
				'memberistic_unknown_payment_provider',
				__( 'Unknown payment provider.', 'memberistic' ),
				array( 'status' => 404 )
			);
		}

		if ( ! $provider::is_configured() ) {
			// 503, not 400: the request may be perfectly valid and this site is
			// simply not ready for it. Stripe retries a 503 and does not retry
			// a 400, and an event lost because the secret had not been pasted
			// in yet is a renewal that never happens.
			return new \WP_Error(
				'memberistic_payment_provider_not_configured',
				__( 'This payment provider is not configured on this site.', 'memberistic' ),
				array( 'status' => 503 )
			);
		}

		// Authentication happens against the exact bytes received, before any
		// attempt to interpret them. Parsing first would mean running a JSON
		// decoder — and, historically in other plugins, an unserialiser — on
		// input from anyone who can reach the URL.
		$authenticated = $provider::authenticate( (string) $payload, $headers );

		if ( is_wp_error( $authenticated ) ) {
			$data = $authenticated->get_error_data();

			Payment_Audit_Repository::record(
				array(
					'provider'         => $provider::key(),
					'integrity_result' => Payment_Audit_Repository::RESULT_REJECTED,
					'reason_code'      => Payment_Audit_Repository::REASON_INVALID_SIGNATURE,
					'context'          => array(
						'detail' => isset( $data['reason'] ) ? $data['reason'] : 'unspecified',
					),
				)
			);

			return $authenticated;
		}

		$decoded = json_decode( (string) $payload, true );

		if ( ! is_array( $decoded ) ) {
			return self::record_and_error(
				$provider,
				array(),
				Payment_Audit_Repository::REASON_MALFORMED_EVENT,
				__( 'Payment webhook payload could not be read.', 'memberistic' ),
				400
			);
		}

		$event = $provider::normalize_event( $decoded, (string) $payload );

		if ( is_wp_error( $event ) ) {
			return self::record_and_error(
				$provider,
				array(),
				Payment_Audit_Repository::REASON_MALFORMED_EVENT,
				$event->get_error_message(),
				400
			);
		}

		return self::process_event( $provider_key, $event );
	}

	/**
	 * Run a normalised event through the gate.
	 *
	 * Separated from handle_webhook() so locally-originated events — a
	 * WooCommerce order transition, a reconciliation repair — go through
	 * exactly the same checks as an internet-facing webhook, minus the
	 * signature step they have no signature for.
	 *
	 * @param string               $provider_key Provider key.
	 * @param array<string, mixed> $event        Normalised event.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function process_event( $provider_key, array $event ) {
		$provider = self::provider( $provider_key );

		if ( null === $provider ) {
			return new \WP_Error(
				'memberistic_unknown_payment_provider',
				__( 'Unknown payment provider.', 'memberistic' ),
				array( 'status' => 404 )
			);
		}

		// The environment check is not a formality. A test-mode endpoint
		// pointed at a production site — a routine mistake when copying a
		// configuration — would otherwise let anyone with a Stripe test
		// account activate memberships for free, because test-mode signing
		// secrets are trivially obtainable and test-mode money is not money.
		if ( isset( $event['environment'] ) && $event['environment'] !== $provider::environment() ) {
			return self::record_and_error(
				$provider,
				$event,
				Payment_Audit_Repository::REASON_WRONG_ENVIRONMENT,
				__( 'Payment event environment does not match this site.', 'memberistic' ),
				400
			);
		}

		$expected_account = $provider::expected_account_id();
		$event_account    = isset( $event['provider_account_id'] ) ? (string) $event['provider_account_id'] : '';

		if ( '' !== $expected_account && '' !== $event_account && ! hash_equals( $expected_account, $event_account ) ) {
			return self::record_and_error(
				$provider,
				$event,
				Payment_Audit_Repository::REASON_WRONG_ACCOUNT,
				__( 'Payment event belongs to a different provider account.', 'memberistic' ),
				400
			);
		}

		$claim = Payment_Event_Repository::claim( $event );

		if ( 'rejected' === $claim['claim'] ) {
			return self::record_and_error(
				$provider,
				$event,
				Payment_Audit_Repository::REASON_MALFORMED_EVENT,
				__( 'Payment event has no id and cannot be processed safely.', 'memberistic' ),
				400
			);
		}

		if ( 'duplicate' === $claim['claim'] ) {
			// Acknowledged, deliberately without repeating anything: no
			// payment row, no state change, no email, no hook. The provider
			// gets a success so it stops retrying.
			Payment_Audit_Repository::record(
				self::audit_base( $provider, $event ) + array(
					'integrity_result' => Payment_Audit_Repository::RESULT_ACCEPTED,
					'transition_result' => 'unchanged',
					'reason_code'      => Payment_Audit_Repository::REASON_DUPLICATE,
					'membership_id'    => isset( $claim['row']['membership_id'] ) ? (int) $claim['row']['membership_id'] : 0,
				)
			);

			do_action( 'memberistic_payment_event_duplicate', $event['event_id'] ?? '', $event['event_type'] ?? '', $provider::key() );

			return array(
				'status'   => 'duplicate',
				'event_id' => isset( $event['event_id'] ) ? $event['event_id'] : '',
			);
		}

		if ( 'claimed' !== $claim['claim'] ) {
			// Another worker holds this event, or the ledger write failed.
			// 503 so the provider retries rather than assuming success.
			return new \WP_Error(
				'memberistic_payment_event_held',
				__( 'This payment event is already being processed.', 'memberistic' ),
				array( 'status' => 503 )
			);
		}

		$ledger_id = (int) $claim['id'];

		try {
			$decision = self::decide( $provider, $event );
		} catch ( \Throwable $error ) {
			// A crash mid-decision leaves the claim held; release it as
			// retryable so the provider's next delivery can pick it up rather
			// than waiting out the takeover window.
			Payment_Event_Repository::finish(
				$ledger_id,
				Payment_Event_Repository::STATUS_FAILED_RETRYABLE,
				array(
					'failure_code'    => 'decision_exception',
					'failure_message' => $error->getMessage(),
				)
			);

			throw $error;
		}

		return self::commit( $provider, $event, $decision, $ledger_id );
	}

	/**
	 * Decide what, if anything, an event may do.
	 *
	 * Everything below this point is pure decision-making: it reads the
	 * database and the provider, and writes nothing.
	 *
	 * @param string               $provider Provider class.
	 * @param array<string, mixed> $event    Normalised event.
	 * @return array<string, mixed> Decision.
	 */
	private static function decide( $provider, array $event ) {
		$intent = isset( $event['intent'] ) ? (string) $event['intent'] : Payment_Provider::INTENT_IGNORE;

		if ( Payment_Provider::INTENT_IGNORE === $intent ) {
			return self::no_change( Payment_Audit_Repository::REASON_NO_CHANGE );
		}

		$resolved = self::resolve_membership( $provider, $event );

		if ( isset( $resolved['error'] ) ) {
			return $resolved['error'];
		}

		$membership = $resolved['membership'];

		$relationship = self::verify_relationships( $provider, $event, $membership, $intent );
		if ( null !== $relationship ) {
			return $relationship;
		}

		$freshness = self::verify_freshness( $event, $membership );
		if ( null !== $freshness ) {
			return $freshness;
		}

		switch ( $intent ) {
			case Payment_Provider::INTENT_ACTIVATION:
				return self::decide_activation( $provider, $event, $membership );

			case Payment_Provider::INTENT_RENEWAL:
				return self::decide_renewal( $provider, $event, $membership );

			case Payment_Provider::INTENT_PAYMENT_FAILED:
				return self::decide_payment_failed( $provider, $event, $membership );

			case Payment_Provider::INTENT_CANCELLATION:
				return self::decide_cancellation( $provider, $event, $membership );

			case Payment_Provider::INTENT_SUBSCRIPTION_UPDATED:
				return self::decide_subscription_updated( $provider, $event, $membership );

			case Payment_Provider::INTENT_TRIAL_ENDING:
				return self::decide_trial_ending( $event, $membership );
		}

		return self::no_change( Payment_Audit_Repository::REASON_NO_CHANGE );
	}

	/**
	 * Find the membership an event is about, from our records rather than the
	 * event's own claim about itself.
	 *
	 * The subscription id recorded against the membership is authoritative.
	 * Provider metadata is consulted only when the subscription id resolves
	 * nothing at all — a first activation, where our row does not yet know its
	 * subscription — and even then the candidate is accepted only if it is not
	 * already committed to a different subscription.
	 *
	 * That last condition is the stale-cancellation fix. The old code fell back
	 * to `metadata.membership_id` whenever the subscription lookup missed, so a
	 * `customer.subscription.deleted` for a subscription the member had already
	 * replaced would find the membership by metadata and cancel it — taking
	 * access away from someone who had just re-subscribed and paid.
	 *
	 * @param string               $provider   Provider class.
	 * @param array<string, mixed> $event      Normalised event.
	 * @return array{membership?:array<string,mixed>, error?:array<string,mixed>}
	 */
	private static function resolve_membership( $provider, array $event ) {
		$subscription_id = isset( $event['provider_subscription_id'] ) ? (string) $event['provider_subscription_id'] : '';
		$hint            = isset( $event['membership_hint'] ) ? absint( $event['membership_hint'] ) : 0;

		if ( '' !== $subscription_id ) {
			$membership = Memberships_Repository::get_by_provider_subscription( $provider::key(), $subscription_id );

			if ( ! $membership ) {
				// Installs upgraded from 2.0.x have the id in the legacy
				// column and not yet in the provider column.
				$membership = Memberships_Repository::get_by_stripe_subscription_id( $subscription_id );
			}

			if ( $membership ) {
				return array( 'membership' => $membership );
			}
		}

		if ( ! $hint ) {
			return array(
				'error' => self::reject(
					Payment_Audit_Repository::REASON_MEMBERSHIP_NOT_FOUND,
					array( 'subscription' => self::mask( $subscription_id ) )
				),
			);
		}

		$candidate = Memberships_Repository::get( $hint );

		if ( ! $candidate ) {
			return array(
				'error' => self::reject(
					Payment_Audit_Repository::REASON_MEMBERSHIP_NOT_FOUND,
					array( 'hint' => $hint )
				),
			);
		}

		$current = self::current_subscription_id( $candidate );

		if ( '' !== $current && '' !== $subscription_id && ! hash_equals( $current, $subscription_id ) ) {
			// The membership has moved on. Whatever this event wants, it is
			// talking about a subscription that is no longer the one paying for
			// this membership, and metadata agreeing with it changes nothing.
			return array(
				'error' => self::reject(
					Payment_Audit_Repository::REASON_STALE_SUBSCRIPTION_EVENT,
					array(
						'event_subscription'   => self::mask( $subscription_id ),
						'current_subscription' => self::mask( $current ),
						'membership_id'        => (int) $candidate['id'],
					),
					(int) $candidate['id'],
					Payment_Event_Repository::STATUS_MANUAL_REVIEW
				),
			);
		}

		return array( 'membership' => $candidate );
	}

	/**
	 * Confirm the customer, subscription and plan relationships.
	 *
	 * @param string               $provider   Provider class.
	 * @param array<string, mixed> $event      Normalised event.
	 * @param array<string, mixed> $membership Membership row.
	 * @param string               $intent     Event intent.
	 * @return array<string, mixed>|null Rejection decision, or null to proceed.
	 */
	private static function verify_relationships( $provider, array $event, array $membership, $intent ) {
		$membership_id = (int) $membership['id'];

		$event_customer   = isset( $event['provider_customer_id'] ) ? (string) $event['provider_customer_id'] : '';
		$current_customer = self::current_customer_id( $membership );

		if ( '' !== $event_customer && '' !== $current_customer && ! hash_equals( $current_customer, $event_customer ) ) {
			return self::reject(
				Payment_Audit_Repository::REASON_CUSTOMER_MISMATCH,
				array(
					'event_customer'   => self::mask( $event_customer ),
					'current_customer' => self::mask( $current_customer ),
				),
				$membership_id,
				Payment_Event_Repository::STATUS_MANUAL_REVIEW
			);
		}

		$event_subscription   = isset( $event['provider_subscription_id'] ) ? (string) $event['provider_subscription_id'] : '';
		$current_subscription = self::current_subscription_id( $membership );

		// An activation is the one intent allowed to introduce a subscription
		// id the membership does not have yet; that is what it is for.
		$introducing = Payment_Provider::INTENT_ACTIVATION === $intent && '' === $current_subscription;

		if ( ! $introducing && '' !== $event_subscription && '' !== $current_subscription && ! hash_equals( $current_subscription, $event_subscription ) ) {
			return self::reject(
				Payment_Audit_Repository::REASON_SUBSCRIPTION_MISMATCH,
				array(
					'event_subscription'   => self::mask( $event_subscription ),
					'current_subscription' => self::mask( $current_subscription ),
				),
				$membership_id,
				Payment_Event_Repository::STATUS_MANUAL_REVIEW
			);
		}

		// When the event says what was actually bought, it has to be this
		// membership's plan. An order carrying a membership id in its meta but
		// no membership product in its basket is not a membership purchase —
		// the meta records what the order was created for, not what was paid
		// for, and the two come apart when a customer edits their cart.
		$plan_ids = isset( $event['object']['plan_ids'] ) && is_array( $event['object']['plan_ids'] )
			? array_map( 'absint', $event['object']['plan_ids'] )
			: array();

		if ( ! empty( $plan_ids ) && ! in_array( (int) $membership['plan_id'], $plan_ids, true ) ) {
			return self::reject(
				Payment_Audit_Repository::REASON_PLAN_MISMATCH,
				array(
					'membership_plan' => (int) $membership['plan_id'],
					'purchased_plans' => implode( ',', $plan_ids ),
				),
				$membership_id,
				Payment_Event_Repository::STATUS_MANUAL_REVIEW
			);
		}

		return null;
	}

	/**
	 * Refuse an event older than one already applied to this membership.
	 *
	 * Providers do not guarantee delivery order, and the failure this prevents
	 * is concrete: a `payment_failed` delayed in a retry queue arriving after
	 * the member's card has already succeeded would move a paid-up membership
	 * back to past_due and email them about a failure they have already fixed.
	 *
	 * Equal timestamps are allowed through rather than refused. Providers batch
	 * events within a second, so equality is genuinely ambiguous, and the
	 * decision handlers for the destructive intents re-fetch the provider's
	 * current state before acting — which resolves the ambiguity with evidence
	 * instead of a coin toss.
	 *
	 * @param array<string, mixed> $event      Normalised event.
	 * @param array<string, mixed> $membership Membership row.
	 * @return array<string, mixed>|null Rejection decision, or null to proceed.
	 */
	private static function verify_freshness( array $event, array $membership ) {
		$event_time = isset( $event['created_timestamp'] ) ? (int) $event['created_timestamp'] : 0;
		$last_time  = Payment_Clock::to_timestamp( $membership['last_provider_event_created_at'] ?? '' );

		if ( ! $event_time || null === $last_time ) {
			return null;
		}

		if ( $event_time >= $last_time ) {
			return null;
		}

		return self::reject(
			Payment_Audit_Repository::REASON_STALE_EVENT,
			array(
				'event_created' => Payment_Clock::from_timestamp( $event_time ),
				'last_applied'  => (string) $membership['last_provider_event_created_at'],
			),
			(int) $membership['id'],
			Payment_Event_Repository::STATUS_REJECTED
		);
	}

	/**
	 * A first subscription payment, from a completed checkout.
	 *
	 * @param string               $provider   Provider class.
	 * @param array<string, mixed> $event      Normalised event.
	 * @param array<string, mixed> $membership Membership row.
	 * @return array<string, mixed>
	 */
	private static function decide_activation( $provider, array $event, array $membership ) {
		$membership_id   = (int) $membership['id'];
		$object          = isset( $event['object'] ) ? (array) $event['object'] : array();
		$subscription_id = isset( $event['provider_subscription_id'] ) ? (string) $event['provider_subscription_id'] : '';

		$plan = Plans_Repository::get( (int) $membership['plan_id'] );

		if ( ! $plan ) {
			return self::reject( Payment_Audit_Repository::REASON_PLAN_MISMATCH, array( 'plan_id' => (int) $membership['plan_id'] ), $membership_id );
		}

		// An already-paying membership being handed a *different* subscription
		// is not an activation, it is two subscriptions billing one member.
		// Whichever way that is resolved, someone is owed a refund or has lost
		// access, so it goes to a human rather than to whichever event arrived
		// last. Re-delivery of the subscription already on file is harmless
		// and falls through to the ordinary path.
		$existing_subscription = self::current_subscription_id( $membership );
		$already_paid_up       = in_array(
			Subscription_State_Machine::normalize_current( $membership['billing_status'] ?? null ),
			Subscription_State_Machine::paid_states(),
			true
		);

		// Already activated, on this very subscription. The checkout webhook
		// and the browser returning from checkout both report the same
		// activation, and a member who refreshes the return page reports it
		// again; none of those may re-send a welcome email or re-write a
		// start date. Verified, and nothing to do.
		if ( $already_paid_up && '' !== $existing_subscription && '' !== $subscription_id && hash_equals( $existing_subscription, $subscription_id ) ) {
			return self::no_change(
				Payment_Audit_Repository::REASON_NO_CHANGE,
				$membership_id,
				array( 'detail' => 'already_activated' )
			);
		}

		if ( $already_paid_up && '' !== $existing_subscription && '' !== $subscription_id && ! hash_equals( $existing_subscription, $subscription_id ) ) {
			return self::reject(
				Payment_Audit_Repository::REASON_MANUAL_REVIEW,
				array(
					'detail'               => 'active_subscription_conflict',
					'current_subscription' => self::mask( $existing_subscription ),
					'event_subscription'   => self::mask( $subscription_id ),
				),
				$membership_id,
				Payment_Event_Repository::STATUS_MANUAL_REVIEW
			);
		}

		$amount        = isset( $event['amount'] ) ? $event['amount'] : null;
		$currency      = isset( $event['currency'] ) ? (string) $event['currency'] : '';
		$subscription  = null;
		$amount_policy = 'exact';

		if ( '' !== $subscription_id ) {
			// The checkout says a subscription was created. Whether that
			// subscription is in a state worth granting access for is a
			// question only the subscription can answer, so it is asked
			// directly rather than inferred from the checkout completing.
			$subscription = $provider::fetch_subscription( $subscription_id );

			if ( is_wp_error( $subscription ) ) {
				return self::defer( Payment_Audit_Repository::REASON_PROVIDER_UNAVAILABLE, $membership_id, $subscription->get_error_message() );
			}

			$target = $provider::billing_state_for_subscription( $subscription );

			if ( null === $target ) {
				return self::reject(
					Payment_Audit_Repository::REASON_MALFORMED_EVENT,
					array( 'detail' => 'unmapped_subscription_status' ),
					$membership_id,
					Payment_Event_Repository::STATUS_MANUAL_REVIEW
				);
			}

			// A subscription that has not started paying and is not trialing
			// is not an activation, whatever the checkout said.
			if ( ! in_array( $target, array( Subscription_State_Machine::ACTIVE, Subscription_State_Machine::TRIALING, Subscription_State_Machine::CANCEL_AT_PERIOD_END ), true ) ) {
				return self::reject(
					Payment_Audit_Repository::REASON_MANUAL_REVIEW,
					array( 'subscription_state' => $target ),
					$membership_id,
					Payment_Event_Repository::STATUS_MANUAL_REVIEW
				);
			}
		} else {
			// No subscription: a one-off purchase, such as a WooCommerce order
			// paying for a term. The transaction itself is re-read and must
			// report as paid — the event announcing a completed order is not
			// the same as the order being paid, and the two can diverge when
			// an order is edited or a payment reversed between the two.
			//
			// This branch is chosen by what the event carries, not by which
			// provider sent it. A provider that grows subscriptions later
			// takes the branch above without a line changing here.
			$transaction = isset( $event['provider_transaction_id'] ) ? (string) $event['provider_transaction_id'] : '';

			if ( '' === $transaction ) {
				return self::reject( Payment_Audit_Repository::REASON_MALFORMED_EVENT, array( 'detail' => 'no_subscription_or_transaction' ), $membership_id );
			}

			$invoice = $provider::fetch_invoice( $transaction );

			if ( is_wp_error( $invoice ) ) {
				return self::defer( Payment_Audit_Repository::REASON_PROVIDER_UNAVAILABLE, $membership_id, $invoice->get_error_message() );
			}

			if ( empty( $invoice['paid'] ) && 'paid' !== ( $invoice['status'] ?? '' ) ) {
				return self::reject(
					Payment_Audit_Repository::REASON_MANUAL_REVIEW,
					array(
						'detail'         => 'transaction_not_paid',
						'invoice_status' => sanitize_text_field( (string) ( $invoice['status'] ?? '' ) ),
					),
					$membership_id,
					Payment_Event_Repository::STATUS_MANUAL_REVIEW
				);
			}

			$target = Subscription_State_Machine::ACTIVE;

			if ( isset( $invoice['amount_paid'] ) && is_numeric( $invoice['amount_paid'] ) ) {
				$amount = (float) $invoice['amount_paid'];
			}
			if ( ! empty( $invoice['currency'] ) ) {
				$currency = strtoupper( (string) $invoice['currency'] );
			}

			// A cart total legitimately exceeds the plan price: tax, shipping
			// and order-level fees all land in it, and a store charging tax
			// would otherwise send every purchase to manual review. Under-
			// payment is the attack; over-payment is a tax rate.
			$amount_policy = 'at_least';
		}

		// A trial charges nothing up front, so a zero amount is expected and
		// correct here rather than a mismatch. Any non-zero amount must match.
		$is_trial = Subscription_State_Machine::TRIALING === $target;

		if ( ! $is_trial && null !== $amount && $amount > 0 ) {
			$financial = self::verify_financials( $membership, $plan, $amount, $currency, $amount_policy );
			if ( null !== $financial ) {
				return $financial;
			}
		}

		$fields = array(
			'payment_provider'         => $provider::key(),
			'provider_subscription_id' => $subscription_id,
			'provider_customer_id'     => isset( $event['provider_customer_id'] ) ? (string) $event['provider_customer_id'] : '',
			'provider_account_id'      => (string) $provider::expected_account_id(),
			// Written to the legacy columns too, so a rollback to 2.0.x leaves
			// the membership fully functional.
			'stripe_subscription_id'   => Stripe_Provider::key() === $provider::key() ? $subscription_id : ( $membership['stripe_subscription_id'] ?? '' ),
			'start_date'               => ! empty( $membership['start_date'] ) ? $membership['start_date'] : current_time( 'mysql' ),
		);

		if ( Stripe_Provider::key() === $provider::key() && ! empty( $event['provider_customer_id'] ) ) {
			$fields['stripe_customer_id'] = (string) $event['provider_customer_id'];
		}

		$period_end = is_array( $subscription ) ? self::period_end( $provider, $subscription ) : null;
		if ( $period_end ) {
			$fields['current_period_end'] = $period_end;
			$fields['renewal_date']       = self::local_renewal_date( $membership, $period_end );
		} else {
			$fields['renewal_date'] = self::fallback_renewal_date( $membership );
		}

		// Provider-specific bookkeeping columns. Merged rather than trusted:
		// the repository's field allowlist drops anything it does not
		// recognise, so an adapter cannot reach a column it has no business
		// writing.
		if ( ! empty( $event['membership_fields'] ) && is_array( $event['membership_fields'] ) ) {
			$fields = array_merge( $fields, $event['membership_fields'] );
		}

		$payment = null;
		if ( null !== $amount && $amount > 0 ) {
			$payment = self::payment_row( $membership_id, $provider, $event, $amount, $currency );
		}

		$emails = array();
		if ( $payment ) {
			$emails[] = self::receipt_email( $membership_id, $amount, $currency, (string) $event['provider_transaction_id'] );
		}
		$emails[] = array(
			'template' => $is_trial ? 'membership_trial_started' : 'membership_activated',
			'args'     => array(),
		);

		return self::accept(
			$membership_id,
			$target,
			$fields,
			array(
				'payment'        => $payment,
				'emails'         => $emails,
				// User creation is deferred from checkout-start to here, so an
				// unauthenticated visitor cannot spray new-user notifications
				// at arbitrary addresses by hitting the checkout endpoint.
				// It has to run before the activation email, which is why it is
				// a flag on the decision rather than a listener on the
				// activation hook that fires after.
				'provision_user' => true,
				'activity'       => array(
					'activity_type' => $is_trial ? 'membership_trial_started' : 'membership_activated',
					'title'         => $is_trial
						? __( 'Membership trial started', 'memberistic' )
						: __( 'Membership activated after payment', 'memberistic' ),
				),
				'hooks'          => array( array( 'memberistic_membership_activated', array( $membership_id ) ) ),
			)
		);
	}

	/**
	 * A recurring payment succeeded.
	 *
	 * This is the P0 fix. The previous implementation renewed a membership on
	 * the strength of an invoice event alone: it looked up the membership from
	 * the subscription id (or, failing that, from metadata), set the status to
	 * active, advanced the renewal date, wrote a payment row and sent a
	 * receipt — without ever checking who paid, how much, in what currency, on
	 * which account, or whether the invoice had actually been paid at all.
	 *
	 * Now the invoice is re-fetched from the provider and every one of those
	 * questions is answered before a single field moves.
	 *
	 * @param string               $provider   Provider class.
	 * @param array<string, mixed> $event      Normalised event.
	 * @param array<string, mixed> $membership Membership row.
	 * @return array<string, mixed>
	 */
	private static function decide_renewal( $provider, array $event, array $membership ) {
		$membership_id = (int) $membership['id'];
		$plan          = Plans_Repository::get( (int) $membership['plan_id'] );

		if ( ! $plan ) {
			return self::reject( Payment_Audit_Repository::REASON_PLAN_MISMATCH, array( 'plan_id' => (int) $membership['plan_id'] ), $membership_id );
		}

		$object     = isset( $event['object'] ) ? (array) $event['object'] : array();
		$invoice_id = isset( $object['id'] ) ? (string) $object['id'] : '';

		if ( '' === $invoice_id ) {
			return self::reject( Payment_Audit_Repository::REASON_MALFORMED_EVENT, array( 'detail' => 'no_invoice_id' ), $membership_id );
		}

		// Provider truth, not the event's copy of it. The event body is a
		// snapshot from when the event was queued and may be minutes old; a
		// refund or dispute in between changes the answer.
		$invoice = $provider::fetch_invoice( $invoice_id );

		if ( is_wp_error( $invoice ) ) {
			return self::defer( Payment_Audit_Repository::REASON_PROVIDER_UNAVAILABLE, $membership_id, $invoice->get_error_message() );
		}

		$paid   = ! empty( $invoice['paid'] ) || 'paid' === ( $invoice['status'] ?? '' );
		$amount = isset( $invoice['amount_paid'] ) && is_numeric( $invoice['amount_paid'] )
			? Stripe_Provider::to_major_units( (int) $invoice['amount_paid'], (string) ( $invoice['currency'] ?? '' ) )
			: null;

		if ( ! $paid ) {
			return self::reject(
				Payment_Audit_Repository::REASON_MANUAL_REVIEW,
				array(
					'detail'         => 'invoice_not_paid',
					'invoice_status' => sanitize_text_field( (string) ( $invoice['status'] ?? '' ) ),
				),
				$membership_id,
				Payment_Event_Repository::STATUS_MANUAL_REVIEW
			);
		}

		$currency = isset( $invoice['currency'] ) ? strtoupper( (string) $invoice['currency'] ) : '';

		$financial = self::verify_financials( $membership, $plan, $amount, $currency );
		if ( null !== $financial ) {
			return $financial;
		}

		$subscription_id = isset( $event['provider_subscription_id'] ) ? (string) $event['provider_subscription_id'] : '';
		$subscription    = '' !== $subscription_id ? $provider::fetch_subscription( $subscription_id ) : null;

		if ( is_wp_error( $subscription ) ) {
			return self::defer( Payment_Audit_Repository::REASON_PROVIDER_UNAVAILABLE, $membership_id, $subscription->get_error_message() );
		}

		$target = is_array( $subscription )
			? $provider::billing_state_for_subscription( $subscription )
			: Subscription_State_Machine::ACTIVE;

		if ( null === $target ) {
			return self::reject(
				Payment_Audit_Repository::REASON_MANUAL_REVIEW,
				array( 'detail' => 'unmapped_subscription_status' ),
				$membership_id,
				Payment_Event_Repository::STATUS_MANUAL_REVIEW
			);
		}

		// The first invoice of a subscription arrives alongside the checkout
		// completion, which has already recorded the payment and sent the
		// receipt. Recognising it stops the member getting two receipts for one
		// charge — and the payments table's unique key stops it regardless, so
		// this is the polite half of a belt-and-braces pair.
		$is_first = 'subscription_create' === ( $event['billing_reason'] ?? '' );

		$fields = array(
			'payment_provider'     => $provider::key(),
			'provider_account_id'  => (string) $provider::expected_account_id(),
		);

		$period_end = is_array( $subscription ) ? self::period_end( $provider, $subscription ) : null;
		if ( $period_end ) {
			$fields['current_period_end'] = $period_end;
			$fields['renewal_date']       = self::local_renewal_date( $membership, $period_end );
		} else {
			$fields['renewal_date'] = self::fallback_renewal_date( $membership );
		}

		// A recovered payment ends any dunning that was running.
		$fields['grace_period_ends_at'] = null;

		$emails   = array();
		$activity = null;
		$payment  = null;

		if ( ! $is_first ) {
			$payment  = self::payment_row( $membership_id, $provider, $event, $amount, $currency );
			$emails[] = self::receipt_email( $membership_id, $amount, $currency, (string) $event['provider_transaction_id'] );
			$emails[] = array(
				'template' => 'membership_renewed',
				'args'     => array(),
			);
			$activity = array(
				'activity_type' => 'membership_renewed',
				'title'         => __( 'Membership renewed', 'memberistic' ),
			);
		}

		return self::accept(
			$membership_id,
			$target,
			$fields,
			array(
				'payment'  => $payment,
				'emails'   => $emails,
				'activity' => $activity,
				'hooks'    => array( array( 'memberistic_membership_activated', array( $membership_id ) ) ),
			)
		);
	}

	/**
	 * A payment failed.
	 *
	 * @param string               $provider   Provider class.
	 * @param array<string, mixed> $event      Normalised event.
	 * @param array<string, mixed> $membership Membership row.
	 * @return array<string, mixed>
	 */
	private static function decide_payment_failed( $provider, array $event, array $membership ) {
		$membership_id   = (int) $membership['id'];
		$subscription_id = isset( $event['provider_subscription_id'] ) ? (string) $event['provider_subscription_id'] : '';

		// Confirm the failure still stands. A failed-payment event delayed
		// behind a successful retry would otherwise take access away from a
		// member whose card has already gone through — the provider's current
		// view of the subscription is the only thing that can tell us which
		// happened last.
		if ( '' !== $subscription_id ) {
			$subscription = $provider::fetch_subscription( $subscription_id );

			if ( is_wp_error( $subscription ) ) {
				return self::defer( Payment_Audit_Repository::REASON_PROVIDER_UNAVAILABLE, $membership_id, $subscription->get_error_message() );
			}

			$live_state = $provider::billing_state_for_subscription( $subscription );

			if ( in_array( $live_state, Subscription_State_Machine::paid_states(), true ) ) {
				return self::no_change(
					Payment_Audit_Repository::REASON_STALE_EVENT,
					$membership_id,
					array( 'detail' => 'recovered_before_event_processed' )
				);
			}
		}

		$current = Subscription_State_Machine::normalize_current( $membership['billing_status'] ?? null );

		// First failure opens the dunning window; subsequent failures inside it
		// do not restart it. Restarting on every retry means a subscription
		// whose card fails weekly never expires, which is how a membership ends
		// up permanently free.
		if ( Subscription_State_Machine::PAST_DUE === $current || Subscription_State_Machine::GRACE_PERIOD === $current ) {
			$existing_deadline = (string) ( $membership['grace_period_ends_at'] ?? '' );

			if ( '' !== $existing_deadline ) {
				return self::no_change(
					Payment_Audit_Repository::REASON_NO_CHANGE,
					$membership_id,
					array(
						'detail'   => 'already_in_dunning',
						'deadline' => $existing_deadline,
					)
				);
			}
		}

		$fields = array(
			'grace_period_ends_at' => Payment_Clock::in( Subscription_State_Machine::grace_period_seconds() ),
		);

		return self::accept(
			$membership_id,
			Subscription_State_Machine::PAST_DUE,
			$fields,
			array(
				'emails'   => array(
					array(
						'template' => 'payment_failed',
						'args'     => array(),
					),
				),
				'activity' => array(
					'activity_type' => 'payment_failed',
					'title'         => __( 'Subscription payment failed', 'memberistic' ),
				),
			)
		);
	}

	/**
	 * A subscription ended.
	 *
	 * @param string               $provider   Provider class.
	 * @param array<string, mixed> $event      Normalised event.
	 * @param array<string, mixed> $membership Membership row.
	 * @return array<string, mixed>
	 */
	private static function decide_cancellation( $provider, array $event, array $membership ) {
		$membership_id   = (int) $membership['id'];
		$subscription_id = isset( $event['provider_subscription_id'] ) ? (string) $event['provider_subscription_id'] : '';
		$current         = self::current_subscription_id( $membership );

		// The check that makes a stale cancellation harmless. verify_relationships()
		// has already compared these, but cancellation is the one intent where
		// getting it wrong removes a paying member's access, so it is asserted
		// again here rather than assumed from a caller two frames up.
		if ( '' !== $current && '' !== $subscription_id && ! hash_equals( $current, $subscription_id ) ) {
			return self::reject(
				Payment_Audit_Repository::REASON_STALE_SUBSCRIPTION_EVENT,
				array(
					'event_subscription'   => self::mask( $subscription_id ),
					'current_subscription' => self::mask( $current ),
				),
				$membership_id,
				Payment_Event_Repository::STATUS_MANUAL_REVIEW
			);
		}

		return self::accept(
			$membership_id,
			Subscription_State_Machine::CANCELLED,
			array(
				'cancelled_at'         => current_time( 'mysql' ),
				'grace_period_ends_at' => null,
			),
			array(
				'emails'   => array(
					array(
						'template' => 'membership_cancelled',
						'args'     => array(),
					),
				),
				'activity' => array(
					'activity_type' => 'membership_cancelled',
					'title'         => __( 'Subscription cancelled at the payment provider', 'memberistic' ),
				),
				'hooks'    => array( array( 'memberistic_membership_cancelled', array( $membership_id ) ) ),
			)
		);
	}

	/**
	 * A subscription's shape changed: a trial converted, a cancellation was
	 * scheduled or withdrawn, a plan was switched.
	 *
	 * @param string               $provider   Provider class.
	 * @param array<string, mixed> $event      Normalised event.
	 * @param array<string, mixed> $membership Membership row.
	 * @return array<string, mixed>
	 */
	private static function decide_subscription_updated( $provider, array $event, array $membership ) {
		$membership_id = (int) $membership['id'];
		$subscription  = isset( $event['object'] ) ? (array) $event['object'] : array();

		$target = $provider::billing_state_for_subscription( $subscription );

		if ( null === $target ) {
			return self::no_change(
				Payment_Audit_Repository::REASON_NO_CHANGE,
				$membership_id,
				array( 'detail' => 'unmapped_subscription_status' )
			);
		}

		$current = Subscription_State_Machine::normalize_current( $membership['billing_status'] ?? null );

		if ( $current === $target ) {
			return self::no_change( Payment_Audit_Repository::REASON_NO_CHANGE, $membership_id );
		}

		$fields = array(
			'payment_provider'    => $provider::key(),
			'provider_account_id' => (string) $provider::expected_account_id(),
		);

		$period_end = self::period_end( $provider, $subscription );
		if ( $period_end ) {
			$fields['current_period_end'] = $period_end;
		}

		$emails   = array();
		$activity = null;

		if ( Subscription_State_Machine::CANCEL_AT_PERIOD_END === $target ) {
			$activity = array(
				'activity_type' => 'membership_cancellation_scheduled',
				'title'         => __( 'Cancellation scheduled for the end of the billing period', 'memberistic' ),
			);
			$emails[] = array(
				'template' => 'membership_cancellation_scheduled',
				'args'     => array(),
			);
		}

		if ( Subscription_State_Machine::ACTIVE === $target && Subscription_State_Machine::TRIALING === $current ) {
			$activity = array(
				'activity_type' => 'membership_trial_converted',
				'title'         => __( 'Trial converted to a paid membership', 'memberistic' ),
			);
		}

		// A subscription that has recovered clears any dunning deadline.
		if ( in_array( $target, Subscription_State_Machine::paid_states(), true ) ) {
			$fields['grace_period_ends_at'] = null;
		}

		return self::accept(
			$membership_id,
			$target,
			$fields,
			array(
				'emails'   => $emails,
				'activity' => $activity,
			)
		);
	}

	/**
	 * A trial is about to end. Informational only.
	 *
	 * @param array<string, mixed> $event      Normalised event.
	 * @param array<string, mixed> $membership Membership row.
	 * @return array<string, mixed>
	 */
	private static function decide_trial_ending( array $event, array $membership ) {
		$membership_id = (int) $membership['id'];

		return self::no_change(
			Payment_Audit_Repository::REASON_VERIFIED,
			$membership_id,
			array( 'detail' => 'trial_ending_notice' ),
			array(
				'emails' => array(
					array(
						'template' => 'membership_trial_ending',
						'args'     => array(),
					),
				),
			)
		);
	}

	/**
	 * Confirm the money matches what this membership should be paying.
	 *
	 * `$policy` is `exact` for subscription billing, where the provider charges
	 * a price this plugin set and any difference is a discrepancy worth
	 * stopping for, and `at_least` for basket purchases, where tax, shipping
	 * and fees legitimately push the total above the plan price. Underpayment
	 * is refused under both: that is the direction an attacker travels.
	 *
	 * @param array<string, mixed> $membership Membership row.
	 * @param array<string, mixed> $plan       Plan row.
	 * @param float|null           $amount     Amount paid, major units.
	 * @param string               $currency   ISO code.
	 * @param string               $policy     `exact` or `at_least`.
	 * @return array<string, mixed>|null Rejection decision, or null when sound.
	 */
	private static function verify_financials( array $membership, array $plan, $amount, $currency, $policy = 'exact' ) {
		$membership_id = (int) $membership['id'];

		$site_currency = strtoupper( (string) memberistic_get_setting( 'currency', 'USD' ) );

		if ( '' !== $currency && $currency !== $site_currency ) {
			return self::reject(
				Payment_Audit_Repository::REASON_CURRENCY_MISMATCH,
				array(
					'paid_currency' => $currency,
					'site_currency' => $site_currency,
				),
				$membership_id,
				Payment_Event_Repository::STATUS_MANUAL_REVIEW
			);
		}

		if ( null === $amount ) {
			return null;
		}

		$expected = self::expected_amount( $membership, $plan );

		/**
		 * Filters the tolerance allowed between the expected and paid amount.
		 *
		 * Exists because proration, coupons, tax and partial credit balances
		 * legitimately change what a renewal costs, and a site using any of
		 * them would otherwise send every renewal to manual review. Widening
		 * it widens what an attacker may underpay by.
		 *
		 * @param float                $tolerance  Absolute tolerance, major units.
		 * @param array<string, mixed> $membership Membership row.
		 */
		$tolerance = (float) apply_filters( 'memberistic_payment_amount_tolerance', 0.01, $membership );

		if ( $expected <= 0 ) {
			return null;
		}

		$paid = (float) $amount;

		$mismatched = 'at_least' === $policy
			? ( $paid < ( $expected - $tolerance ) )
			: ( abs( $expected - $paid ) > $tolerance );

		if ( $mismatched ) {
			return self::reject(
				Payment_Audit_Repository::REASON_AMOUNT_MISMATCH,
				array(
					'expected' => $expected,
					'paid'     => $paid,
					'currency' => $currency,
					'policy'   => $policy,
				),
				$membership_id,
				Payment_Event_Repository::STATUS_MANUAL_REVIEW
			);
		}

		return null;
	}

	/**
	 * What this membership is supposed to be charged.
	 *
	 * The membership's own recorded billing amount wins where it exists: a
	 * member on a legacy price must not be flagged as underpaying because the
	 * plan's price has since gone up.
	 *
	 * @param array<string, mixed> $membership Membership row.
	 * @param array<string, mixed> $plan       Plan row.
	 * @return float
	 */
	private static function expected_amount( array $membership, array $plan ) {
		if ( isset( $membership['billing_amount'] ) && null !== $membership['billing_amount'] && '' !== $membership['billing_amount'] ) {
			$recorded = (float) $membership['billing_amount'];
			if ( $recorded > 0 ) {
				return round( $recorded, 2 );
			}
		}

		$cycle = sanitize_key( (string) ( $membership['billing_cycle'] ?? 'monthly' ) );

		return round(
			'annual' === $cycle ? (float) ( $plan['annual_price'] ?? 0 ) : (float) ( $plan['monthly_price'] ?? 0 ),
			2
		);
	}

	/**
	 * Apply a decision: state, payment, audit, ledger, then side effects.
	 *
	 * @param string               $provider  Provider class.
	 * @param array<string, mixed> $event     Normalised event.
	 * @param array<string, mixed> $decision  Decision from decide().
	 * @param int                  $ledger_id Ledger row id.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function commit( $provider, array $event, array $decision, $ledger_id ) {
		$membership_id = (int) $decision['membership_id'];
		$audit         = self::audit_base( $provider, $event ) + array(
			'membership_id' => $membership_id,
			'reason_code'   => $decision['reason'],
			'context'       => $decision['context'],
		);

		if ( 'deferred' === $decision['result'] ) {
			Payment_Event_Repository::finish(
				$ledger_id,
				Payment_Event_Repository::STATUS_FAILED_RETRYABLE,
				array(
					'failure_code'    => $decision['reason'],
					'failure_message' => isset( $decision['context']['detail'] ) ? (string) $decision['context']['detail'] : '',
					'membership_id'   => $membership_id,
				)
			);

			Payment_Audit_Repository::record( $audit + array( 'integrity_result' => Payment_Audit_Repository::RESULT_DEFERRED ) );

			// 503 keeps the event in the provider's retry queue. This is the
			// path a temporary provider outage takes, and it must not look
			// like success or the event is lost for good.
			return new \WP_Error(
				'memberistic_payment_event_deferred',
				__( 'Could not verify this payment event yet; it will be retried.', 'memberistic' ),
				array( 'status' => 503 )
			);
		}

		if ( 'rejected' === $decision['result'] ) {
			Payment_Event_Repository::finish(
				$ledger_id,
				$decision['ledger_status'],
				array(
					'failure_code'  => $decision['reason'],
					'membership_id' => $membership_id,
				)
			);

			Payment_Audit_Repository::record(
				$audit + array(
					'integrity_result'  => Payment_Audit_Repository::RESULT_REJECTED,
					'transition_result' => 'rejected',
				)
			);

			do_action( 'memberistic_payment_event_rejected', $decision['reason'], $membership_id, $event );

			// Acknowledged, not retried: the event is permanently unacceptable,
			// and asking the provider to send it again for three days changes
			// nothing except the size of the log.
			return array(
				'status' => 'rejected',
				'reason' => $decision['reason'],
			);
		}

		$membership = $membership_id ? Memberships_Repository::get( $membership_id ) : null;

		if ( ! $membership ) {
			Payment_Event_Repository::finish( $ledger_id, Payment_Event_Repository::STATUS_REJECTED, array( 'failure_code' => Payment_Audit_Repository::REASON_MEMBERSHIP_NOT_FOUND ) );
			Payment_Audit_Repository::record( $audit + array( 'integrity_result' => Payment_Audit_Repository::RESULT_REJECTED ) );

			return array(
				'status' => 'rejected',
				'reason' => Payment_Audit_Repository::REASON_MEMBERSHIP_NOT_FOUND,
			);
		}

		$previous = Subscription_State_Machine::normalize_current( $membership['billing_status'] ?? null );
		$target   = '' === (string) $decision['target'] ? $previous : (string) $decision['target'];

		$audit['previous_billing_status'] = $previous;
		$audit['new_billing_status']      = $target;

		if ( ! Subscription_State_Machine::can_transition( $previous, $target ) ) {
			Payment_Event_Repository::finish(
				$ledger_id,
				Payment_Event_Repository::STATUS_REJECTED,
				array(
					'failure_code'  => Payment_Audit_Repository::REASON_INVALID_TRANSITION,
					'membership_id' => $membership_id,
				)
			);

			Payment_Audit_Repository::record(
				$audit + array(
					'integrity_result'  => Payment_Audit_Repository::RESULT_REJECTED,
					'transition_result' => 'rejected',
					'reason_code'       => Payment_Audit_Repository::REASON_INVALID_TRANSITION,
				)
			);

			do_action( 'memberistic_payment_transition_rejected', $previous, $target, $membership_id, $event );

			return array(
				'status' => 'rejected',
				'reason' => Payment_Audit_Repository::REASON_INVALID_TRANSITION,
			);
		}

		$fields                   = (array) $decision['fields'];
		$fields['billing_status'] = $target;
		$fields['last_provider_event_id']         = isset( $event['event_id'] ) ? (string) $event['event_id'] : '';
		$fields['last_provider_event_created_at'] = isset( $event['provider_created_at'] ) ? $event['provider_created_at'] : null;
		$fields['last_provider_synced_at']        = Payment_Clock::now();

		$access = self::access_status_update( $membership, $target );
		if ( null !== $access ) {
			$fields['status'] = $access;
		}

		// Compare-and-swap. If another delivery moved the billing state
		// between the decision and here, this returns false and nothing —
		// including every side effect below — happens.
		$status_changed = isset( $fields['status'] );

		$applied = Memberships_Repository::update_billing_state( $membership_id, $fields, $membership['billing_status'] ?? null );

		if ( ! $applied ) {
			Payment_Event_Repository::finish(
				$ledger_id,
				Payment_Event_Repository::STATUS_DUPLICATE,
				array(
					'failure_code'  => 'lost_race',
					'membership_id' => $membership_id,
				)
			);

			Payment_Audit_Repository::record(
				$audit + array(
					'integrity_result'  => Payment_Audit_Repository::RESULT_ACCEPTED,
					'transition_result' => 'unchanged',
					'reason_code'       => Payment_Audit_Repository::REASON_DUPLICATE,
				)
			);

			return array(
				'status' => 'unchanged',
				'reason' => Payment_Audit_Repository::REASON_DUPLICATE,
			);
		}

		$payment_created = false;
		if ( ! empty( $decision['payment'] ) ) {
			$result = Payments_Repository::create_idempotent( $decision['payment'] );

			if ( false === $result ) {
				// The state moved but the payment row did not. Flagged rather
				// than swallowed: an active membership with no record of the
				// charge that paid for it is exactly the sort of inconsistency
				// that is invisible until someone asks for a refund.
				Payment_Audit_Repository::record(
					$audit + array(
						'integrity_result'  => Payment_Audit_Repository::RESULT_ACCEPTED,
						'transition_result' => 'applied',
						'reason_code'       => Payment_Audit_Repository::REASON_MANUAL_REVIEW,
						'context'           => array( 'detail' => 'payment_row_insert_failed' ),
					)
				);
			} else {
				$payment_created = (bool) $result['created'];
			}
		}

		Payment_Event_Repository::finish(
			$ledger_id,
			Payment_Event_Repository::STATUS_PROCESSED,
			array(
				'membership_id'            => $membership_id,
				'provider_subscription_id' => isset( $event['provider_subscription_id'] ) ? (string) $event['provider_subscription_id'] : '',
			)
		);

		Payment_Audit_Repository::record(
			$audit + array(
				'integrity_result'  => Payment_Audit_Repository::RESULT_ACCEPTED,
				'transition_result' => Subscription_State_Machine::is_change( $previous, $target ) ? 'applied' : 'unchanged',
			)
		);

		self::$processing_inbound_event = true;

		try {
			if ( $status_changed ) {
				/**
				 * Fires after a membership's status changes.
				 *
				 * The gate writes `status` through a guarded UPDATE rather
				 * than Memberships_Repository::change_status(), because the
				 * compare-and-swap is what makes concurrent deliveries safe.
				 * The hook still has to fire: role sync, the coreSTORE bridge
				 * and marketing integrations all key off it rather than
				 * polling, and a membership that activates without it is
				 * active in the database and invisible to everything else.
				 *
				 * @param int    $membership_id Membership id.
				 * @param string $status        New access status.
				 */
				do_action( 'memberistic_membership_status_changed', $membership_id, (string) $fields['status'] );
			}

			self::fire_side_effects( $membership_id, $decision, $payment_created );
		} finally {
			self::$processing_inbound_event = false;
		}

		return array(
			'status'         => 'processed',
			'membership_id'  => $membership_id,
			'billing_status' => $target,
		);
	}

	/**
	 * Emails, activity and hooks — after the state is safely stored.
	 *
	 * @param int                  $membership_id   Membership id.
	 * @param array<string, mixed> $decision        Decision.
	 * @param bool                 $payment_created Whether a payment row was written.
	 */
	private static function fire_side_effects( $membership_id, array $decision, $payment_created ) {
		if ( ! empty( $decision['provision_user'] ) ) {
			/**
			 * Fires when a verified payment should provision a WordPress user.
			 *
			 * Runs before any member-facing email, so the account exists by the
			 * time the welcome message points at it.
			 *
			 * @param int $membership_id Membership id.
			 */
			do_action( 'memberistic_payment_provision_member_user', $membership_id );
		}

		if ( ! empty( $decision['activity'] ) ) {
			Activity_Repository::log(
				array(
					'membership_id' => $membership_id,
					'activity_type' => $decision['activity']['activity_type'],
					'title'         => $decision['activity']['title'],
				)
			);
		}

		foreach ( (array) $decision['emails'] as $email ) {
			// A receipt is suppressed when the payment row already existed:
			// the charge had been recorded by another path, so the member has
			// already been told about it.
			if ( 'payment_receipt' === $email['template'] && ! $payment_created ) {
				continue;
			}

			Email_Service::send_membership_email( $membership_id, $email['template'], $email['args'] );
		}

		foreach ( (array) $decision['hooks'] as $hook ) {
			do_action_ref_array( $hook[0], $hook[1] );
		}
	}

	/**
	 * The access status to write alongside a billing state, if any.
	 *
	 * Returns null — meaning "leave `status` alone" — when staff own the
	 * current value. A comped member whose card fails is still comped; that is
	 * the whole reason the two fields are separate.
	 *
	 * @param array<string, mixed> $membership Membership row.
	 * @param string               $target     Target billing state.
	 * @return string|null
	 */
	private static function access_status_update( array $membership, $target ) {
		$current = sanitize_key( (string) ( $membership['status'] ?? '' ) );

		if ( in_array( $current, self::STAFF_OWNED_STATUSES, true ) ) {
			return null;
		}

		$next = Subscription_State_Machine::access_status_for( $target );

		return $next === $current ? null : $next;
	}

	/**
	 * Build the payment row for a verified charge.
	 *
	 * @param int                  $membership_id Membership id.
	 * @param string               $provider      Provider class.
	 * @param array<string, mixed> $event         Normalised event.
	 * @param float|null           $amount        Amount paid.
	 * @param string               $currency      ISO code.
	 * @return array<string, mixed>
	 */
	private static function payment_row( $membership_id, $provider, array $event, $amount, $currency ) {
		$row = array(
			'membership_id'          => $membership_id,
			'amount'                 => null === $amount ? 0 : (float) $amount,
			'currency'               => '' === $currency ? strtoupper( (string) memberistic_get_setting( 'currency', 'USD' ) ) : $currency,
			'payment_method'         => $provider::key() . '_subscription',
			'payment_gateway'        => $provider::key(),
			'gateway_transaction_id' => isset( $event['provider_transaction_id'] ) ? (string) $event['provider_transaction_id'] : '',
			'status'                 => 'completed',
			'paid_at'                => current_time( 'mysql' ),
			// Deliberately not the provider payload. The raw object holds
			// customer details and card metadata that this table has no reason
			// to retain; the event id is enough to find it at the provider.
			'raw_response'           => array(
				'event_id'   => isset( $event['event_id'] ) ? (string) $event['event_id'] : '',
				'event_type' => isset( $event['event_type'] ) ? (string) $event['event_type'] : '',
				'provider'   => $provider::key(),
			),
		);

		if ( ! empty( $event['woo_order_id'] ) ) {
			$row['woo_order_id'] = absint( $event['woo_order_id'] );
		}

		return $row;
	}

	/**
	 * The receipt email descriptor for a charge.
	 *
	 * @param int        $membership_id Membership id.
	 * @param float|null $amount        Amount paid.
	 * @param string     $currency      ISO code.
	 * @param string     $transaction   Transaction id.
	 * @return array<string, mixed>
	 */
	private static function receipt_email( $membership_id, $amount, $currency, $transaction ) {
		$formatted = memberistic_format_price( null === $amount ? 0 : (float) $amount, $currency );

		return array(
			'template' => 'payment_receipt',
			'args'     => array(
				'amount'         => $formatted,
				'paid_amount'    => $formatted,
				'transaction_id' => $transaction,
				'payment_date'   => date_i18n( get_option( 'date_format' ) ),
				'payment_method' => __( 'Card on file', 'memberistic' ),
			),
		);
	}

	/**
	 * The provider's paid-through date, as UTC.
	 *
	 * @param string               $provider     Provider class.
	 * @param array<string, mixed> $subscription Provider subscription.
	 * @return string|null
	 */
	private static function period_end( $provider, array $subscription ) {
		if ( method_exists( $provider, 'current_period_end' ) ) {
			return $provider::current_period_end( $subscription );
		}

		return null;
	}

	/**
	 * Convert a UTC period end into the site-local renewal date the rest of
	 * the plugin reads.
	 *
	 * `renewal_date` predates this release, is site-local, and is rendered on
	 * the member dashboard. It stays site-local; only the conversion lives here.
	 *
	 * @param array<string, mixed> $membership Membership row.
	 * @param string               $period_end UTC datetime.
	 * @return string
	 */
	private static function local_renewal_date( array $membership, $period_end ) {
		$timestamp = Payment_Clock::to_timestamp( $period_end );

		if ( null === $timestamp ) {
			return self::fallback_renewal_date( $membership );
		}

		return get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $timestamp ) );
	}

	/**
	 * The renewal date to use when the provider did not supply a period end.
	 *
	 * Anchored on the existing renewal date when that is still in the future,
	 * so renewing early keeps the time already paid for — the behaviour
	 * Memberistic has always had.
	 *
	 * @param array<string, mixed> $membership Membership row.
	 * @return string
	 */
	private static function fallback_renewal_date( array $membership ) {
		$now     = current_time( 'mysql' );
		$current = ! empty( $membership['renewal_date'] ) ? (string) $membership['renewal_date'] : '';
		$anchor  = ( $current && $current > $now ) ? $current : $now;

		return \WordPressistic\Memberistic\Integrations\WooCommerce_Bridge::compute_next_renewal(
			(string) ( $membership['billing_cycle'] ?? 'monthly' ),
			$anchor
		);
	}

	/**
	 * The subscription id currently authoritative for a membership.
	 *
	 * @param array<string, mixed> $membership Membership row.
	 * @return string
	 */
	private static function current_subscription_id( array $membership ) {
		$id = trim( (string) ( $membership['provider_subscription_id'] ?? '' ) );

		if ( '' !== $id ) {
			return $id;
		}

		return trim( (string) ( $membership['stripe_subscription_id'] ?? '' ) );
	}

	/**
	 * The customer id currently authoritative for a membership.
	 *
	 * @param array<string, mixed> $membership Membership row.
	 * @return string
	 */
	private static function current_customer_id( array $membership ) {
		$id = trim( (string) ( $membership['provider_customer_id'] ?? '' ) );

		if ( '' !== $id ) {
			return $id;
		}

		return trim( (string) ( $membership['stripe_customer_id'] ?? '' ) );
	}

	/**
	 * Shared audit fields for an event.
	 *
	 * @param string               $provider Provider class.
	 * @param array<string, mixed> $event    Normalised event.
	 * @return array<string, mixed>
	 */
	private static function audit_base( $provider, array $event ) {
		return array(
			'event_id'                 => isset( $event['event_id'] ) ? (string) $event['event_id'] : '',
			'event_type'               => isset( $event['event_type'] ) ? (string) $event['event_type'] : '',
			'provider'                 => $provider::key(),
			'provider_account_id'      => isset( $event['provider_account_id'] ) ? (string) $event['provider_account_id'] : '',
			'provider_subscription_id' => isset( $event['provider_subscription_id'] ) ? (string) $event['provider_subscription_id'] : '',
		);
	}

	/**
	 * Record a rejection that happened before the event was claimed, and
	 * return the error the request should answer with.
	 *
	 * @param string               $provider Provider class.
	 * @param array<string, mixed> $event    Normalised event, possibly empty.
	 * @param string               $reason   Reason code.
	 * @param string               $message  Public message.
	 * @param int                  $status   HTTP status.
	 * @return \WP_Error
	 */
	private static function record_and_error( $provider, array $event, $reason, $message, $status ) {
		Payment_Audit_Repository::record(
			self::audit_base( $provider, $event ) + array(
				'integrity_result' => Payment_Audit_Repository::RESULT_REJECTED,
				'reason_code'      => $reason,
			)
		);

		return new \WP_Error( 'memberistic_payment_' . $reason, $message, array( 'status' => $status ) );
	}

	/**
	 * Mask a provider identifier for storage in the audit trail.
	 *
	 * @param string $id Provider id.
	 * @return string
	 */
	private static function mask( $id ) {
		$id = (string) $id;

		if ( strlen( $id ) <= 8 ) {
			return $id;
		}

		return substr( $id, 0, 8 ) . '…' . substr( $id, -4 );
	}

	/**
	 * Build an accepted decision.
	 *
	 * @param int                  $membership_id Membership id.
	 * @param string               $target        Target billing state.
	 * @param array<string, mixed> $fields        Membership fields to write.
	 * @param array<string, mixed> $extra         Payment, emails, activity, hooks.
	 * @return array<string, mixed>
	 */
	private static function accept( $membership_id, $target, array $fields = array(), array $extra = array() ) {
		return array_merge(
			array(
				'result'        => 'accepted',
				'reason'        => Payment_Audit_Repository::REASON_VERIFIED,
				'membership_id' => (int) $membership_id,
				'target'        => (string) $target,
				'fields'        => $fields,
				'payment'       => null,
				'emails'        => array(),
				'activity'      => null,
				'hooks'         => array(),
				'context'       => array(),
				'ledger_status' => Payment_Event_Repository::STATUS_PROCESSED,
			),
			$extra
		);
	}

	/**
	 * Build a decision that verifies but changes no state.
	 *
	 * @param string               $reason        Reason code.
	 * @param int                  $membership_id Membership id.
	 * @param array<string, mixed> $context       Audit context.
	 * @param array<string, mixed> $extra         Side effects, if any.
	 * @return array<string, mixed>
	 */
	private static function no_change( $reason, $membership_id = 0, array $context = array(), array $extra = array() ) {
		return array_merge(
			array(
				'result'        => 'accepted',
				'reason'        => $reason,
				'membership_id' => (int) $membership_id,
				'target'        => '',
				'fields'        => array(),
				'payment'       => null,
				'emails'        => array(),
				'activity'      => null,
				'hooks'         => array(),
				'context'       => $context,
				'ledger_status' => Payment_Event_Repository::STATUS_PROCESSED,
			),
			$extra
		);
	}

	/**
	 * Build a permanent rejection.
	 *
	 * @param string               $reason        Reason code.
	 * @param array<string, mixed> $context       Audit context.
	 * @param int                  $membership_id Membership id.
	 * @param string               $ledger_status Ledger status to record.
	 * @return array<string, mixed>
	 */
	private static function reject( $reason, array $context = array(), $membership_id = 0, $ledger_status = Payment_Event_Repository::STATUS_REJECTED ) {
		return array(
			'result'        => 'rejected',
			'reason'        => $reason,
			'membership_id' => (int) $membership_id,
			'target'        => '',
			'fields'        => array(),
			'payment'       => null,
			'emails'        => array(),
			'activity'      => null,
			'hooks'         => array(),
			'context'       => $context,
			'ledger_status' => $ledger_status,
		);
	}

	/**
	 * Build a deferral: undecidable now, worth retrying.
	 *
	 * @param string $reason        Reason code.
	 * @param int    $membership_id Membership id.
	 * @param string $detail        Non-sensitive detail.
	 * @return array<string, mixed>
	 */
	private static function defer( $reason, $membership_id = 0, $detail = '' ) {
		return array(
			'result'        => 'deferred',
			'reason'        => $reason,
			'membership_id' => (int) $membership_id,
			'target'        => '',
			'fields'        => array(),
			'payment'       => null,
			'emails'        => array(),
			'activity'      => null,
			'hooks'         => array(),
			'context'       => array( 'detail' => $detail ),
			'ledger_status' => Payment_Event_Repository::STATUS_FAILED_RETRYABLE,
		);
	}
}
