<?php
/**
 * The billing lifecycle: legal transitions, and the mapping to access.
 *
 * The transition matrix is the plugin's answer to "may this event change this
 * membership", so the important half of this file is the transitions that must
 * be refused. A matrix that is merely permissive passes every test that only
 * checks the happy path.
 *
 * @package Memberistic
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WordPressistic\Memberistic\Payments\Subscription_State_Machine as SM;

final class SubscriptionStateMachineTest extends TestCase {
	protected function setUp(): void {
		memberistic_tests_reset_state();
	}

	/**
	 * @return array<string, array{0:string, 1:string}>
	 */
	public static function allowed_transitions(): array {
		return array(
			'pending to trialing'                 => array( SM::PENDING, SM::TRIALING ),
			'pending to active'                   => array( SM::PENDING, SM::ACTIVE ),
			'trialing to active'                  => array( SM::TRIALING, SM::ACTIVE ),
			'trialing to past due'                => array( SM::TRIALING, SM::PAST_DUE ),
			'trialing to cancel at period end'    => array( SM::TRIALING, SM::CANCEL_AT_PERIOD_END ),
			'trialing to cancelled'               => array( SM::TRIALING, SM::CANCELLED ),
			'trialing to expired'                 => array( SM::TRIALING, SM::EXPIRED ),
			'active to past due'                  => array( SM::ACTIVE, SM::PAST_DUE ),
			'active to cancel at period end'      => array( SM::ACTIVE, SM::CANCEL_AT_PERIOD_END ),
			'active to cancelled'                 => array( SM::ACTIVE, SM::CANCELLED ),
			'active to expired'                   => array( SM::ACTIVE, SM::EXPIRED ),
			'past due to active'                  => array( SM::PAST_DUE, SM::ACTIVE ),
			'past due to grace period'            => array( SM::PAST_DUE, SM::GRACE_PERIOD ),
			'past due to cancel at period end'    => array( SM::PAST_DUE, SM::CANCEL_AT_PERIOD_END ),
			'past due to cancelled'               => array( SM::PAST_DUE, SM::CANCELLED ),
			'grace period to active'              => array( SM::GRACE_PERIOD, SM::ACTIVE ),
			'grace period to cancelled'           => array( SM::GRACE_PERIOD, SM::CANCELLED ),
			'grace period to expired'             => array( SM::GRACE_PERIOD, SM::EXPIRED ),
			'cancel at period end to active'      => array( SM::CANCEL_AT_PERIOD_END, SM::ACTIVE ),
			'cancel at period end to cancelled'   => array( SM::CANCEL_AT_PERIOD_END, SM::CANCELLED ),
			'cancel at period end to expired'     => array( SM::CANCEL_AT_PERIOD_END, SM::EXPIRED ),
			're-subscribe after cancelling'       => array( SM::CANCELLED, SM::ACTIVE ),
			're-subscribe after expiring'         => array( SM::EXPIRED, SM::ACTIVE ),
		);
	}

	/**
	 * @dataProvider allowed_transitions
	 */
	public function test_permitted_transitions_are_permitted( string $from, string $to ): void {
		self::assertTrue( SM::can_transition( $from, $to ), "{$from} → {$to} should be allowed" );
	}

	/**
	 * @return array<string, array{0:string, 1:string, 2:string}>
	 */
	public static function refused_transitions(): array {
		return array(
			'a cancelled membership cannot quietly lapse into expired' => array(
				SM::CANCELLED,
				SM::EXPIRED,
				'cancellation is terminal; expiry is a different event with different member communication',
			),
			'an expired membership cannot be moved to cancelled'       => array(
				SM::EXPIRED,
				SM::CANCELLED,
				'the member did not cancel; saying they did misrepresents why they left',
			),
			'a cancelled membership cannot go straight to past due'    => array(
				SM::CANCELLED,
				SM::PAST_DUE,
				'there is no subscription left to fail a payment on',
			),
			'a cancelled membership cannot start a trial'              => array(
				SM::CANCELLED,
				SM::TRIALING,
				'a stale trial event must not resurrect a cancelled membership',
			),
			'grace period cannot slide back to past due'               => array(
				SM::GRACE_PERIOD,
				SM::PAST_DUE,
				'a repeated failure event must not restart the dunning clock',
			),
			'an active membership cannot be moved to trialing'         => array(
				SM::ACTIVE,
				SM::TRIALING,
				'a paying member must not be downgraded to a trial by a replayed event',
			),
			'an active membership cannot be sent back to pending'      => array(
				SM::ACTIVE,
				SM::PENDING,
				'a paid-up membership has no route back to unpaid',
			),
			'grace period cannot begin a trial'                        => array(
				SM::GRACE_PERIOD,
				SM::TRIALING,
				'an unpaid member cannot convert their way into a free trial',
			),
			'expired cannot re-enter grace'                            => array(
				SM::EXPIRED,
				SM::GRACE_PERIOD,
				'the grace window is over; extending it needs a new payment, not a late event',
			),
			'past due cannot start a trial'                            => array(
				SM::PAST_DUE,
				SM::TRIALING,
				'a failed payment must never become free access',
			),
		);
	}

	/**
	 * @dataProvider refused_transitions
	 */
	public function test_transitions_outside_the_matrix_fail_closed( string $from, string $to, string $why ): void {
		self::assertFalse( SM::can_transition( $from, $to ), "{$from} → {$to} must be refused: {$why}" );
	}

	public function test_an_unknown_target_state_is_refused(): void {
		self::assertFalse( SM::can_transition( SM::ACTIVE, 'refunded' ) );
		self::assertFalse( SM::can_transition( SM::ACTIVE, '' ) );
	}

	public function test_staying_in_the_same_state_is_allowed_but_is_not_a_change(): void {
		// A renewal leaves a membership active while moving its renewal date.
		// Refusing that would refuse every successful renewal.
		self::assertTrue( SM::can_transition( SM::ACTIVE, SM::ACTIVE ) );
		self::assertFalse( SM::is_change( SM::ACTIVE, SM::ACTIVE ) );
		self::assertTrue( SM::is_change( SM::ACTIVE, SM::PAST_DUE ) );
	}

	public function test_a_membership_with_no_billing_state_is_treated_as_pending(): void {
		// Not active: inferring a subscription from an active access status
		// would let a renewal event "restore" a membership that never had one.
		self::assertSame( SM::PENDING, SM::normalize_current( null ) );
		self::assertSame( SM::PENDING, SM::normalize_current( '' ) );
		self::assertSame( SM::PENDING, SM::normalize_current( 'nonsense' ) );
		self::assertTrue( SM::can_transition( null, SM::ACTIVE ) );
	}

	public function test_access_mapping_defaults_match_the_previous_release(): void {
		// Upgrading must not hand anybody access they did not have in 2.0.1,
		// where `trial` and `past_due` were not eligible statuses.
		self::assertSame( 'trial', SM::access_status_for( SM::TRIALING ) );
		self::assertSame( 'past_due', SM::access_status_for( SM::PAST_DUE ) );
		self::assertSame( 'past_due', SM::access_status_for( SM::GRACE_PERIOD ) );
		self::assertSame( 'active', SM::access_status_for( SM::ACTIVE ) );
		self::assertSame( 'cancelled', SM::access_status_for( SM::CANCELLED ) );
		self::assertSame( 'expired', SM::access_status_for( SM::EXPIRED ) );
	}

	public function test_a_scheduled_cancellation_keeps_access_until_the_period_ends(): void {
		// The member has cancelled but paid through the end of the period.
		// Taking access before then is taking back something already bought.
		self::assertSame( 'active', SM::access_status_for( SM::CANCEL_AT_PERIOD_END ) );
	}

	public function test_trial_access_is_configurable(): void {
		$GLOBALS['memberistic_test_settings']['trial_grants_access'] = 'yes';

		self::assertSame( 'active', SM::access_status_for( SM::TRIALING ) );
	}

	public function test_grace_access_is_configurable(): void {
		$GLOBALS['memberistic_test_settings']['grace_period_grants_access'] = 'yes';

		self::assertSame( 'active', SM::access_status_for( SM::GRACE_PERIOD ) );
		self::assertSame( 'active', SM::access_status_for( SM::PAST_DUE ) );
	}

	public function test_the_grace_period_length_is_configurable_and_bounded(): void {
		self::assertSame( 7 * DAY_IN_SECONDS, SM::grace_period_seconds() );

		$GLOBALS['memberistic_test_settings']['grace_period_days'] = 14;
		self::assertSame( 14 * DAY_IN_SECONDS, SM::grace_period_seconds() );

		// Zero is a legitimate choice: expire on first failure.
		$GLOBALS['memberistic_test_settings']['grace_period_days'] = 0;
		self::assertSame( 0, SM::grace_period_seconds() );

		// Negative is a configuration error that would set a deadline in the
		// past and expire every failing membership instantly.
		$GLOBALS['memberistic_test_settings']['grace_period_days'] = -30;
		self::assertSame( 0, SM::grace_period_seconds() );
	}

	public function test_stripe_statuses_map_to_billing_states(): void {
		$expected = array(
			'trialing'           => SM::TRIALING,
			'active'             => SM::ACTIVE,
			'past_due'           => SM::PAST_DUE,
			'canceled'           => SM::CANCELLED,
			// Nothing has been paid yet, so this is pending — not past_due,
			// which implies a subscription that was once good.
			'incomplete'         => SM::PENDING,
			// Never paid and Stripe has given up.
			'incomplete_expired' => SM::EXPIRED,
			// Dunning exhausted, but the subscription still exists and can be
			// recovered by paying — so expired, not the terminal cancelled.
			'unpaid'             => SM::EXPIRED,
		);

		foreach ( $expected as $stripe => $billing ) {
			self::assertSame( $billing, SM::from_provider_state( 'stripe', $stripe ), "stripe:{$stripe}" );
		}
	}

	public function test_an_unrecognised_provider_status_returns_null_rather_than_guessing(): void {
		// A provider adding a status this plugin has never seen is exactly
		// when guessing is most expensive.
		self::assertNull( SM::from_provider_state( 'stripe', 'quantum_superposition' ) );
		self::assertNull( SM::from_provider_state( 'stripe', '' ) );
		self::assertNull( SM::from_provider_state( 'nonexistent_provider', 'active' ) );
	}

	public function test_the_transition_matrix_only_names_canonical_states(): void {
		// A typo in the matrix silently creates a transition nothing can use,
		// or worse, one that fails closed for a state that should be allowed.
		$states = SM::states();

		foreach ( SM::transitions() as $from => $targets ) {
			self::assertContains( $from, $states, "matrix source state '{$from}' is not canonical" );

			foreach ( $targets as $to ) {
				self::assertContains( $to, $states, "matrix target state '{$to}' is not canonical" );
			}
		}
	}

	public function test_every_canonical_state_maps_to_an_access_status(): void {
		foreach ( SM::states() as $state ) {
			self::assertNotSame( '', SM::access_status_for( $state ), "{$state} has no access mapping" );
		}
	}
}
