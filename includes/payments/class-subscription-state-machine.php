<?php
/**
 * Subscription billing lifecycle: states, legal transitions, and the single
 * mapping between a provider's vocabulary, ours, and member access.
 *
 * This class answers exactly one question:
 *
 *     current state + verified event  ->  is the requested next state allowed?
 *
 * It parses no HTTP, calls no payment provider, and reads no request. Given
 * the same inputs it returns the same answer, every time, which is what makes
 * the transition rules testable without a network or a database.
 *
 * Why `billing_status` exists alongside the membership `status` it resembles:
 *
 * `status` is an access decision. Staff set it by hand, it carries values no
 * payment provider has ever heard of (`comped`, `paused`, `needs_review`), and
 * the door, the booking bridge and the member dashboard all read it. If a
 * Stripe event wrote directly to it, a failed card would silently overwrite a
 * manager's decision to comp a member — and the manager would have no way to
 * make it stick.
 *
 * `billing_status` is a description of the subscription at the provider.
 * Payment events own it completely. Access is then *derived* from it, through
 * access_status_for(), and only for memberships the billing system actually
 * governs. The separation is the point: one field records what was paid, the
 * other records what the business decided, and neither silently overwrites the
 * other.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic\Payments;

use function WordPressistic\Memberistic\memberistic_get_setting;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Subscription_State_Machine {
	const PENDING             = 'pending';
	const TRIALING            = 'trialing';
	const ACTIVE              = 'active';
	const PAST_DUE            = 'past_due';
	const GRACE_PERIOD        = 'grace_period';
	const CANCEL_AT_PERIOD_END = 'cancel_at_period_end';
	const CANCELLED           = 'cancelled';
	const EXPIRED             = 'expired';

	/**
	 * Every canonical billing state.
	 *
	 * @return array<string>
	 */
	public static function states() {
		return array(
			self::PENDING,
			self::TRIALING,
			self::ACTIVE,
			self::PAST_DUE,
			self::GRACE_PERIOD,
			self::CANCEL_AT_PERIOD_END,
			self::CANCELLED,
			self::EXPIRED,
		);
	}

	/**
	 * Validate a billing state.
	 *
	 * @param mixed       $state   Candidate state.
	 * @param string|null $default Returned when the candidate is not canonical.
	 * @return string|null
	 */
	public static function validate_state( $state, $default = null ) {
		$state = sanitize_key( (string) $state );

		return in_array( $state, self::states(), true ) ? $state : $default;
	}

	/**
	 * The transition matrix. Anything absent here is refused.
	 *
	 * Fail-closed is the whole design. A transition that is merely plausible —
	 * the kind a `switch` statement grows when a new provider event appears —
	 * is exactly how a stale `customer.subscription.deleted` ends up cancelling
	 * a membership that has since re-subscribed. If a state pair is not written
	 * down here, on purpose, with a reason, the answer is no.
	 *
	 * Notes on the pairs that are not obvious:
	 *
	 * - `pending -> cancelled|expired`: a checkout that was started and never
	 *   completed. Without these the abandoned-checkout sweep cannot close the
	 *   row it created.
	 * - `trialing -> past_due`: the first charge at the end of a trial can
	 *   fail. Stripe reports that as `past_due` on a subscription that never
	 *   reached `active`.
	 * - `past_due -> expired`: dunning runs out. Stripe's terminal state for
	 *   that is `unpaid`, which is not a synonym for `cancelled` — the
	 *   subscription still exists and can be recovered by paying the invoice.
	 * - `cancel_at_period_end -> active`: the member changed their mind before
	 *   the period ended, which Stripe reports as a `customer.subscription
	 *   .updated` clearing the flag. Without this the reversal is unrepresentable
	 *   and the membership expires anyway.
	 * - `cancelled|expired -> pending|active`: re-subscribing on the same
	 *   membership row. The new subscription is verified on its own merits
	 *   before this is reached; what this permits is the row being reused
	 *   rather than orphaned.
	 *
	 * @return array<string, array<string>>
	 */
	public static function transitions() {
		$transitions = array(
			self::PENDING => array(
				self::TRIALING,
				self::ACTIVE,
				self::CANCELLED,
				self::EXPIRED,
			),
			self::TRIALING => array(
				self::ACTIVE,
				self::PAST_DUE,
				self::CANCEL_AT_PERIOD_END,
				self::CANCELLED,
				self::EXPIRED,
			),
			self::ACTIVE => array(
				self::PAST_DUE,
				self::CANCEL_AT_PERIOD_END,
				self::CANCELLED,
				self::EXPIRED,
			),
			self::PAST_DUE => array(
				self::ACTIVE,
				self::GRACE_PERIOD,
				self::CANCEL_AT_PERIOD_END,
				self::CANCELLED,
				self::EXPIRED,
			),
			self::GRACE_PERIOD => array(
				self::ACTIVE,
				self::CANCELLED,
				self::EXPIRED,
			),
			self::CANCEL_AT_PERIOD_END => array(
				self::ACTIVE,
				self::CANCELLED,
				self::EXPIRED,
			),
			self::CANCELLED => array(
				self::PENDING,
				self::ACTIVE,
			),
			self::EXPIRED => array(
				self::PENDING,
				self::ACTIVE,
			),
		);

		/**
		 * Filters the subscription transition matrix.
		 *
		 * Provided for products whose lifecycle genuinely differs. Adding a
		 * transition here removes a guard: the pair becomes legal for every
		 * event that can request it, including a replayed one. Removing a
		 * transition is the safe direction.
		 *
		 * @param array<string, array<string>> $transitions Allowed transitions.
		 */
		$transitions = (array) apply_filters( 'memberistic_billing_transitions', $transitions );

		return $transitions;
	}

	/**
	 * Whether a state change is permitted.
	 *
	 * A transition to the state already held is allowed and is not a change:
	 * a renewal leaves a membership `active` while moving its renewal date, and
	 * refusing that would refuse every successful renewal. Callers that need to
	 * know whether anything moved should ask is_change().
	 *
	 * @param string|null $from Current state; null for a membership with no
	 *                          billing lifecycle yet, treated as `pending`.
	 * @param string      $to   Requested state.
	 * @return bool
	 */
	public static function can_transition( $from, $to ) {
		$from = self::normalize_current( $from );
		$to   = self::validate_state( $to );

		if ( null === $to ) {
			return false;
		}

		if ( $from === $to ) {
			return true;
		}

		$transitions = self::transitions();
		$allowed     = isset( $transitions[ $from ] ) ? (array) $transitions[ $from ] : array();

		return in_array( $to, $allowed, true );
	}

	/**
	 * Whether a transition actually moves the membership.
	 *
	 * @param string|null $from Current state.
	 * @param string      $to   Requested state.
	 * @return bool
	 */
	public static function is_change( $from, $to ) {
		return self::normalize_current( $from ) !== self::validate_state( $to );
	}

	/**
	 * Treat an absent billing state as `pending`.
	 *
	 * A membership created before 2.1.0, or by staff without a subscription,
	 * has NULL here. `pending` is the honest reading: nothing has been billed
	 * yet. It is deliberately not `active` — inferring an active subscription
	 * from an active access status would let a renewal event "restore" a
	 * membership that never had a subscription at all.
	 *
	 * @param string|null $state Stored billing state.
	 * @return string
	 */
	public static function normalize_current( $state ) {
		return self::validate_state( $state, self::PENDING );
	}

	/**
	 * The membership access status a billing state implies.
	 *
	 * This is the one place the mapping exists. Nothing else in the plugin may
	 * decide what a billing state means for access — duplicate it into a
	 * webhook handler and the two copies diverge on the first edge case, which
	 * is how a cancelled member keeps getting in.
	 *
	 * Two of these are configurable because they are genuinely product
	 * decisions rather than facts about billing, and both default to the
	 * behaviour Memberistic 2.0.1 already had, so upgrading changes nobody's
	 * access on its own:
	 *
	 * - A trialing member. Whether a trial grants access before any money has
	 *   moved is a business call. Default: no, matching 2.0.1, where `trial`
	 *   was not an eligible status.
	 * - A member inside the dunning grace window. Retaining access while the
	 *   card is retried is kinder and is what most membership products do, but
	 *   it is still giving away service on an unpaid invoice. Default: no,
	 *   matching 2.0.1, where `past_due` ended access immediately.
	 *
	 * `cancel_at_period_end` is not configurable and maps to `active`: the
	 * member has cancelled but has paid through the end of the period, and
	 * taking access away before the date they paid for would be theft of a
	 * service already bought.
	 *
	 * @param string|null $billing_status Billing state.
	 * @return string Membership access status.
	 */
	public static function access_status_for( $billing_status ) {
		$billing_status = self::normalize_current( $billing_status );

		$map = array(
			self::PENDING              => 'pending',
			self::TRIALING             => self::trial_grants_access() ? 'active' : 'trial',
			self::ACTIVE               => 'active',
			self::PAST_DUE             => self::grace_grants_access() ? 'active' : 'past_due',
			self::GRACE_PERIOD         => self::grace_grants_access() ? 'active' : 'past_due',
			self::CANCEL_AT_PERIOD_END => 'active',
			self::CANCELLED            => 'cancelled',
			self::EXPIRED              => 'expired',
		);

		$status = isset( $map[ $billing_status ] ) ? $map[ $billing_status ] : 'pending';

		/**
		 * Filters the access status derived from a billing state.
		 *
		 * @param string $status         Access status.
		 * @param string $billing_status Billing state it was derived from.
		 */
		return (string) apply_filters( 'memberistic_access_status_for_billing_status', $status, $billing_status );
	}

	/**
	 * Whether a trialing membership may use the service.
	 *
	 * @return bool
	 */
	public static function trial_grants_access() {
		$setting = memberistic_get_setting( 'trial_grants_access', 'no' );

		/**
		 * Filters whether a trialing membership has access.
		 *
		 * @param bool $grants Whether trial access is granted.
		 */
		return (bool) apply_filters( 'memberistic_trial_grants_access', 'yes' === $setting );
	}

	/**
	 * Whether a membership inside the dunning grace window keeps access.
	 *
	 * @return bool
	 */
	public static function grace_grants_access() {
		$setting = memberistic_get_setting( 'grace_period_grants_access', 'no' );

		/**
		 * Filters whether the dunning grace window retains access.
		 *
		 * @param bool $grants Whether grace-period access is granted.
		 */
		return (bool) apply_filters( 'memberistic_grace_period_grants_access', 'yes' === $setting );
	}

	/**
	 * How long a membership stays in dunning before expiring, in seconds.
	 *
	 * @return int
	 */
	public static function grace_period_seconds() {
		$days = (int) memberistic_get_setting( 'grace_period_days', 7 );

		/**
		 * Filters the dunning grace period, in days.
		 *
		 * @param int $days Grace period length.
		 */
		$days = (int) apply_filters( 'memberistic_grace_period_days', $days );

		// A zero-day grace period is a legitimate choice (expire on first
		// failure); a negative one is a configuration error that would set a
		// deadline in the past and expire the membership immediately.
		$days = max( 0, min( 365, $days ) );

		return $days * DAY_IN_SECONDS;
	}

	/**
	 * Provider vocabulary to billing state.
	 *
	 * Kept here rather than in each adapter so there is one table to read when
	 * asking "what does the plugin think `unpaid` means", and one place to
	 * change when a provider adds a status.
	 *
	 * Stripe subscription statuses, and why each lands where it does:
	 *
	 * - `incomplete`: the first payment has not succeeded. Nothing has been
	 *   paid, so this is `pending`, not `past_due` — the distinction matters
	 *   because `past_due` implies a subscription that was once good.
	 * - `incomplete_expired`: the first payment never succeeded and Stripe has
	 *   given up. The subscription is dead and was never active: `expired`.
	 * - `unpaid`: dunning is exhausted but the subscription still exists and
	 *   can be recovered by paying. `expired`, not `cancelled`, because
	 *   `cancelled` is terminal and this is not.
	 * - `paused`: a Stripe pause collection. Mapped to `past_due` so the member
	 *   is not treated as paid-up, and deliberately not to a new state — a
	 *   pause is an operational decision and belongs in `status`, where staff
	 *   already have `paused`.
	 *
	 * `cancel_at_period_end` is not a Stripe status but a boolean on an
	 * otherwise `active` subscription; the adapter resolves it and asks for
	 * this state by name.
	 *
	 * @param string $provider Provider key.
	 * @return array<string, string>
	 */
	public static function provider_state_map( $provider ) {
		$maps = array(
			'stripe' => array(
				'incomplete'         => self::PENDING,
				'incomplete_expired' => self::EXPIRED,
				'trialing'           => self::TRIALING,
				'active'             => self::ACTIVE,
				'past_due'           => self::PAST_DUE,
				'unpaid'             => self::EXPIRED,
				'canceled'           => self::CANCELLED,
				'cancelled'          => self::CANCELLED,
				'paused'             => self::PAST_DUE,
			),
			// WooCommerce Subscriptions statuses, without the `wc-` prefix.
			'woocommerce' => array(
				'pending'        => self::PENDING,
				'active'         => self::ACTIVE,
				'on-hold'        => self::PAST_DUE,
				'pending-cancel' => self::CANCEL_AT_PERIOD_END,
				'cancelled'      => self::CANCELLED,
				'switched'       => self::CANCELLED,
				'expired'        => self::EXPIRED,
			),
		);

		$provider = sanitize_key( (string) $provider );

		/**
		 * Filters the provider-status to billing-state map.
		 *
		 * @param array<string, string> $map      Map for this provider.
		 * @param string                $provider Provider key.
		 */
		return (array) apply_filters(
			'memberistic_provider_state_map',
			isset( $maps[ $provider ] ) ? $maps[ $provider ] : array(),
			$provider
		);
	}

	/**
	 * Translate a provider status into a billing state.
	 *
	 * Returns null for anything unrecognised, which callers must treat as a
	 * reason to stop rather than a reason to guess. A provider adding a status
	 * this plugin has never seen is precisely when guessing is most expensive.
	 *
	 * @param string $provider Provider key.
	 * @param mixed  $status   Provider-native status.
	 * @return string|null
	 */
	public static function from_provider_state( $provider, $status ) {
		$status = strtolower( trim( (string) $status ) );

		if ( '' === $status ) {
			return null;
		}

		$map = self::provider_state_map( $provider );

		return isset( $map[ $status ] ) ? $map[ $status ] : null;
	}

	/**
	 * States in which a membership is considered to be paying its way.
	 *
	 * @return array<string>
	 */
	public static function paid_states() {
		return array( self::ACTIVE, self::CANCEL_AT_PERIOD_END );
	}

	/**
	 * States from which no further billing activity is expected.
	 *
	 * @return array<string>
	 */
	public static function terminal_states() {
		return array( self::CANCELLED, self::EXPIRED );
	}
}
