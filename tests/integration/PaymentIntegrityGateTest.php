<?php
/**
 * The Payment Integrity Gate, against a real database.
 *
 * These need MySQL rather than stubs, because the properties under test *are*
 * database properties: the atomic event claim is a unique key, the state
 * transition is a compare-and-swap, and duplicate payment suppression is an
 * index. Faking any of them would be testing the fake.
 *
 * Each test is named for the thing that goes wrong when it fails — a member
 * charged twice, a member who paid losing access, a forged event granting it.
 *
 * @package Memberistic
 */

use WordPressistic\Memberistic\Database\Memberships_Repository;
use WordPressistic\Memberistic\Database\Payments_Repository;
use WordPressistic\Memberistic\Payments\Payment_Audit_Repository;
use WordPressistic\Memberistic\Payments\Payment_Clock;
use WordPressistic\Memberistic\Payments\Payment_Event_Repository;
use WordPressistic\Memberistic\Payments\Payment_Integrity_Gate;
use WordPressistic\Memberistic\Payments\Providers\Payment_Provider;
use WordPressistic\Memberistic\Payments\Subscription_State_Machine as SM;

final class PaymentIntegrityGateTest extends Memberistic_Integration_TestCase {
	private const PROVIDER = 'testpay';
	private const SUB      = 'sub_current';
	private const CUSTOMER = 'cus_member';

	private int $plan_id;
	private int $membership_id;
	private int $user_id;

	/** @var array<int, string> Templates the gate asked to send. */
	private array $emails = array();

	public function set_up() {
		parent::set_up();

		$this->block_and_record_http();
		Memberistic_Test_Payment_Provider::reset();

		add_filter(
			'memberistic_payment_providers',
			static function ( array $providers ): array {
				$providers['testpay'] = Memberistic_Test_Payment_Provider::class;

				return $providers;
			}
		);

		// Counting through this filter rather than wp_mail: it fires once per
		// template the gate asks for, whether or not the mailer succeeds, so a
		// duplicate-suppression test cannot pass by accident on a site where
		// mail is broken.
		$this->emails = array();
		add_filter(
			'memberistic_should_send_email',
			function ( $send, $template ) {
				$this->emails[] = (string) $template;

				return $send;
			},
			10,
			2
		);

		update_option( 'memberistic_settings', array( 'currency' => 'USD' ) );

		$this->user_id       = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->plan_id       = Memberistic_Record_Factory::plan( array( 'monthly_price' => '25.00' ) );
		$this->membership_id = Memberistic_Record_Factory::membership(
			$this->plan_id,
			$this->user_id,
			array(
				'status'                   => 'active',
				'billing_status'           => SM::ACTIVE,
				'payment_provider'         => self::PROVIDER,
				'provider_subscription_id' => self::SUB,
				'provider_customer_id'     => self::CUSTOMER,
				'billing_amount'           => '25.00',
			)
		);

		Memberistic_Record_Factory::person(
			$this->membership_id,
			array(
				'email'     => 'member@example.test',
				'full_name' => 'Test Member',
				'role'      => 'primary',
			)
		);

		Memberistic_Test_Payment_Provider::set_subscription( self::SUB, 'active' );
	}

	/**
	 * Build a normalised event.
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 * @return array<string, mixed>
	 */
	private function event( array $overrides = array() ): array {
		$timestamp = Payment_Clock::timestamp();

		return array_merge(
			array(
				'provider'                 => self::PROVIDER,
				'provider_account_id'      => 'acct_test',
				'environment'              => 'live',
				'event_id'                 => 'evt_' . wp_generate_password( 12, false ),
				'event_type'               => 'invoice.payment_succeeded',
				'created_timestamp'        => $timestamp,
				'provider_created_at'      => Payment_Clock::from_timestamp( $timestamp ),
				'payload_hash'             => str_repeat( 'a', 64 ),
				'intent'                   => Payment_Provider::INTENT_RENEWAL,
				'object'                   => array( 'id' => 'in_1' ),
				'provider_customer_id'     => self::CUSTOMER,
				'provider_subscription_id' => self::SUB,
				'provider_transaction_id'  => 'pi_1',
				'amount'                   => 25.00,
				'currency'                 => 'USD',
				'membership_hint'          => $this->membership_id,
				'billing_reason'           => 'subscription_cycle',
			),
			$overrides
		);
	}

	private function process( array $event ) {
		return Payment_Integrity_Gate::process_event( self::PROVIDER, $event );
	}

	private function membership(): array {
		return Memberships_Repository::get( $this->membership_id );
	}

	private function billing_status(): ?string {
		$row = $this->membership();

		return null === $row['billing_status'] ? null : (string) $row['billing_status'];
	}

	private function payment_count(): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(1) FROM ' . Payments_Repository::table() . ' WHERE membership_id = %d',
				$this->membership_id
			)
		);
	}

	private function assertAuditReason( string $expected ): void {
		$rows = Payment_Audit_Repository::get_for_membership( $this->membership_id, 1 );

		self::assertNotEmpty( $rows, 'the decision left no audit trail' );
		self::assertSame( $expected, $rows[0]['reason_code'] );
	}

	/* ── Renewal ─────────────────────────────────────────────────────── */

	public function test_a_verified_renewal_records_the_payment_and_moves_the_renewal_date(): void {
		Memberistic_Test_Payment_Provider::set_invoice( 'in_1', 25.00 );

		$result = $this->process( $this->event() );

		self::assertSame( 'processed', $result['status'] );
		self::assertSame( SM::ACTIVE, $this->billing_status() );
		self::assertSame( 1, $this->payment_count() );
		self::assertContains( 'payment_receipt', $this->emails );
		$this->assertAuditReason( Payment_Audit_Repository::REASON_VERIFIED );
		$this->assertNoOutboundHttp();
	}

	public function test_a_renewal_is_verified_against_the_provider_not_the_event_body(): void {
		// The event claims 25.00 was paid. The provider says the invoice was
		// never settled. The provider wins, and nothing is renewed.
		Memberistic_Test_Payment_Provider::set_invoice( 'in_1', 25.00, false );

		$result = $this->process( $this->event() );

		self::assertSame( 'rejected', $result['status'] );
		self::assertSame( 0, $this->payment_count() );
		self::assertSame( array(), $this->emails );
	}

	public function test_an_underpaid_renewal_is_refused(): void {
		Memberistic_Test_Payment_Provider::set_invoice( 'in_1', 1.00 );

		$result = $this->process( $this->event( array( 'amount' => 1.00 ) ) );

		self::assertSame( 'rejected', $result['status'] );
		self::assertSame( Payment_Audit_Repository::REASON_AMOUNT_MISMATCH, $result['reason'] );
		self::assertSame( 0, $this->payment_count() );
		$this->assertAuditReason( Payment_Audit_Repository::REASON_AMOUNT_MISMATCH );
	}

	public function test_a_renewal_in_the_wrong_currency_is_refused(): void {
		Memberistic_Test_Payment_Provider::set_invoice( 'in_1', 25.00, true, 'EUR' );

		$result = $this->process( $this->event( array( 'currency' => 'EUR' ) ) );

		self::assertSame( 'rejected', $result['status'] );
		self::assertSame( Payment_Audit_Repository::REASON_CURRENCY_MISMATCH, $result['reason'] );
		self::assertSame( 0, $this->payment_count() );
	}

	public function test_a_membership_on_a_legacy_price_is_not_flagged_as_underpaying(): void {
		// The plan's price has gone up since this member joined. Their own
		// recorded billing amount is what they owe.
		Memberships_Repository::update( $this->membership_id, array( 'billing_amount' => 15.00 ) );
		Memberistic_Test_Payment_Provider::set_invoice( 'in_1', 15.00 );

		$result = $this->process( $this->event( array( 'amount' => 15.00 ) ) );

		self::assertSame( 'processed', $result['status'] );
	}

	/* ── Idempotency ─────────────────────────────────────────────────── */

	public function test_the_same_event_delivered_twice_charges_once_and_emails_once(): void {
		Memberistic_Test_Payment_Provider::set_invoice( 'in_1', 25.00 );

		$event = $this->event();

		$first = $this->process( $event );
		$sent  = count( $this->emails );

		$second = $this->process( $event );

		self::assertSame( 'processed', $first['status'] );
		self::assertSame( 'duplicate', $second['status'] );
		self::assertSame( 1, $this->payment_count(), 'a redelivery must not charge the member twice' );
		self::assertGreaterThan( 0, $sent, 'the first delivery should have sent something' );
		self::assertCount( $sent, $this->emails, 'a redelivery must not email the member again' );
	}

	public function test_a_duplicate_is_recognised_even_after_the_ledger_row_is_all_that_survives(): void {
		// Simulates a process that died after claiming and finishing the event
		// but before anything in-memory could remember it: a fresh request has
		// only the ledger to go on, which is the point of the ledger.
		Memberistic_Test_Payment_Provider::set_invoice( 'in_1', 25.00 );

		$event = $this->event();
		$this->process( $event );

		wp_cache_flush();

		self::assertSame( 'duplicate', $this->process( $event )['status'] );
		self::assertSame( 1, $this->payment_count() );
	}

	public function test_a_second_worker_holding_the_claim_is_told_to_retry(): void {
		// The first worker claims and is still processing. The second must not
		// proceed, and must not be told the event succeeded.
		$event = $this->event();
		Payment_Event_Repository::claim( $event );

		$result = $this->process( $event );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 503, $result->get_error_data()['status'] );
	}

	public function test_an_abandoned_claim_can_be_taken_over_after_the_timeout(): void {
		global $wpdb;

		Memberistic_Test_Payment_Provider::set_invoice( 'in_1', 25.00 );

		$event = $this->event();
		$claim = Payment_Event_Repository::claim( $event );

		// A process that died holding the claim leaves the row `processing`
		// forever. Without takeover the event could never be applied.
		$wpdb->update(
			Payment_Event_Repository::table(),
			array( 'updated_at' => Payment_Clock::in( -( Payment_Event_Repository::CLAIM_TIMEOUT + 60 ) ) ),
			array( 'id' => $claim['id'] )
		);

		self::assertSame( 'processed', $this->process( $event )['status'] );
	}

	/* ── Ordering ────────────────────────────────────────────────────── */

	public function test_a_late_payment_failure_cannot_undo_a_payment_that_has_since_succeeded(): void {
		Memberistic_Test_Payment_Provider::set_invoice( 'in_1', 25.00 );
		$this->process( $this->event() );

		// The provider still says the subscription is active — the card went
		// through on retry. A failure event delayed behind it must not take
		// access away from a member who has paid.
		$result = $this->process(
			$this->event(
				array(
					'intent'            => Payment_Provider::INTENT_PAYMENT_FAILED,
					'event_type'        => 'invoice.payment_failed',
					'created_timestamp' => Payment_Clock::timestamp() + 1,
					'provider_created_at' => Payment_Clock::from_timestamp( Payment_Clock::timestamp() + 1 ),
				)
			)
		);

		// The event is accepted — it was genuine, and the ledger records that
		// we saw it — but it changes nothing and tells the member nothing.
		self::assertSame( 'processed', $result['status'] );
		self::assertSame( SM::ACTIVE, $this->billing_status() );
		self::assertNotContains( 'payment_failed', $this->emails );
		self::assertNull( $this->membership()['grace_period_ends_at'] );
	}

	public function test_an_event_older_than_one_already_applied_is_refused(): void {
		Memberistic_Test_Payment_Provider::set_invoice( 'in_1', 25.00 );
		$this->process( $this->event() );

		$stale = $this->event(
			array(
				'intent'              => Payment_Provider::INTENT_CANCELLATION,
				'event_type'          => 'customer.subscription.deleted',
				'created_timestamp'   => Payment_Clock::timestamp() - 3600,
				'provider_created_at' => Payment_Clock::from_timestamp( Payment_Clock::timestamp() - 3600 ),
			)
		);

		$result = $this->process( $stale );

		self::assertSame( 'rejected', $result['status'] );
		self::assertSame( Payment_Audit_Repository::REASON_STALE_EVENT, $result['reason'] );
		self::assertSame( SM::ACTIVE, $this->billing_status() );
	}

	/* ── Stale cancellation ──────────────────────────────────────────── */

	public function test_a_cancellation_for_a_replaced_subscription_cannot_cancel_the_new_one(): void {
		// The exact regression this release exists for. The member cancelled
		// sub_old and immediately re-subscribed as sub_current. Stripe's
		// deleted event for sub_old arrives afterwards, still carrying this
		// membership's id in its metadata.
		$result = $this->process(
			$this->event(
				array(
					'intent'                   => Payment_Provider::INTENT_CANCELLATION,
					'event_type'               => 'customer.subscription.deleted',
					'provider_subscription_id' => 'sub_old',
					'membership_hint'          => $this->membership_id,
				)
			)
		);

		self::assertSame( 'rejected', $result['status'] );
		self::assertSame( Payment_Audit_Repository::REASON_STALE_SUBSCRIPTION_EVENT, $result['reason'] );
		self::assertSame( SM::ACTIVE, $this->billing_status(), 'the replacement subscription must survive' );
		self::assertSame( 'active', $this->membership()['status'] );
		self::assertNotContains( 'membership_cancelled', $this->emails );
	}

	public function test_the_stale_cancellation_is_held_for_review_rather_than_discarded(): void {
		$this->process(
			$this->event(
				array(
					'intent'                   => Payment_Provider::INTENT_CANCELLATION,
					'provider_subscription_id' => 'sub_old',
				)
			)
		);

		self::assertSame( 1, Payment_Event_Repository::count_by_status( Payment_Event_Repository::STATUS_MANUAL_REVIEW ) );
	}

	public function test_a_cancellation_for_the_current_subscription_is_applied(): void {
		$result = $this->process(
			$this->event(
				array(
					'intent'     => Payment_Provider::INTENT_CANCELLATION,
					'event_type' => 'customer.subscription.deleted',
				)
			)
		);

		self::assertSame( 'processed', $result['status'] );
		self::assertSame( SM::CANCELLED, $this->billing_status() );
		self::assertSame( 'cancelled', $this->membership()['status'] );
		self::assertContains( 'membership_cancelled', $this->emails );
	}

	/* ── Identity ────────────────────────────────────────────────────── */

	public function test_an_event_from_another_provider_account_is_refused(): void {
		$result = $this->process( $this->event( array( 'provider_account_id' => 'acct_someone_else' ) ) );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( SM::ACTIVE, $this->billing_status() );
	}

	public function test_a_test_mode_event_cannot_touch_a_live_configuration(): void {
		// Test-mode signing secrets are trivially obtainable and test-mode
		// money is not money. Without this check, anyone with a Stripe test
		// account could activate memberships for free.
		$result = $this->process( $this->event( array( 'environment' => 'test' ) ) );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( SM::ACTIVE, $this->billing_status() );
	}

	public function test_a_live_event_cannot_touch_a_test_configuration(): void {
		Memberistic_Test_Payment_Provider::$environment = 'test';

		$result = $this->process( $this->event( array( 'environment' => 'live' ) ) );

		self::assertInstanceOf( WP_Error::class, $result );
	}

	public function test_an_event_for_another_customer_is_refused(): void {
		Memberistic_Test_Payment_Provider::set_invoice( 'in_1', 25.00 );

		$result = $this->process( $this->event( array( 'provider_customer_id' => 'cus_somebody_else' ) ) );

		self::assertSame( 'rejected', $result['status'] );
		self::assertSame( Payment_Audit_Repository::REASON_CUSTOMER_MISMATCH, $result['reason'] );
	}

	public function test_an_event_naming_no_membership_we_know_is_refused(): void {
		$result = $this->process(
			$this->event(
				array(
					'provider_subscription_id' => 'sub_unknown',
					'membership_hint'          => 0,
				)
			)
		);

		self::assertSame( 'rejected', $result['status'] );
		self::assertSame( Payment_Audit_Repository::REASON_MEMBERSHIP_NOT_FOUND, $result['reason'] );
	}

	public function test_an_event_with_no_id_is_refused_because_it_cannot_be_deduplicated(): void {
		$result = $this->process( $this->event( array( 'event_id' => '' ) ) );

		self::assertInstanceOf( WP_Error::class, $result );
	}

	/* ── Dunning ─────────────────────────────────────────────────────── */

	public function test_a_failed_payment_starts_the_grace_clock(): void {
		Memberistic_Test_Payment_Provider::set_subscription( self::SUB, 'past_due' );

		$result = $this->process(
			$this->event(
				array(
					'intent'     => Payment_Provider::INTENT_PAYMENT_FAILED,
					'event_type' => 'invoice.payment_failed',
				)
			)
		);

		self::assertSame( 'processed', $result['status'] );
		self::assertSame( SM::PAST_DUE, $this->billing_status() );
		self::assertNotEmpty( $this->membership()['grace_period_ends_at'] );
		self::assertContains( 'payment_failed', $this->emails );
	}

	public function test_repeated_failures_do_not_keep_restarting_the_grace_clock(): void {
		// A subscription whose card fails every week would otherwise never
		// expire, which is a membership that has become permanently free.
		Memberistic_Test_Payment_Provider::set_subscription( self::SUB, 'past_due' );

		$this->process(
			$this->event(
				array(
					'intent'     => Payment_Provider::INTENT_PAYMENT_FAILED,
					'event_type' => 'invoice.payment_failed',
				)
			)
		);

		$deadline = $this->membership()['grace_period_ends_at'];

		$this->process(
			$this->event(
				array(
					'intent'     => Payment_Provider::INTENT_PAYMENT_FAILED,
					'event_type' => 'invoice.payment_failed',
				)
			)
		);

		self::assertSame( $deadline, $this->membership()['grace_period_ends_at'] );
	}

	public function test_the_dunning_sweep_moves_a_lapsed_membership_to_grace_then_expiry(): void {
		Memberships_Repository::update(
			$this->membership_id,
			array(
				'billing_status'       => SM::PAST_DUE,
				'status'               => 'past_due',
				'grace_period_ends_at' => Payment_Clock::in( 2 * DAY_IN_SECONDS ),
			)
		);

		Memberships_Repository::advance_dunning( Payment_Clock::now() );
		self::assertSame( SM::GRACE_PERIOD, $this->billing_status() );

		Memberships_Repository::advance_dunning( Payment_Clock::in( 3 * DAY_IN_SECONDS ) );
		self::assertSame( SM::EXPIRED, $this->billing_status() );
		self::assertSame( 'expired', $this->membership()['status'] );
	}

	public function test_the_dunning_sweep_leaves_comped_members_alone(): void {
		// A comped member does not lose access because a card on file expired.
		Memberships_Repository::update(
			$this->membership_id,
			array(
				'billing_status'       => SM::GRACE_PERIOD,
				'status'               => 'comped',
				'grace_period_ends_at' => Payment_Clock::in( -DAY_IN_SECONDS ),
			)
		);

		Memberships_Repository::advance_dunning( Payment_Clock::now() );

		self::assertSame( SM::EXPIRED, $this->billing_status() );
		self::assertSame( 'comped', $this->membership()['status'], 'a staff decision must survive a billing event' );
	}

	public function test_a_recovered_payment_clears_the_grace_deadline(): void {
		Memberships_Repository::update(
			$this->membership_id,
			array(
				'billing_status'       => SM::GRACE_PERIOD,
				'status'               => 'past_due',
				'grace_period_ends_at' => Payment_Clock::in( 3 * DAY_IN_SECONDS ),
			)
		);

		Memberistic_Test_Payment_Provider::set_invoice( 'in_1', 25.00 );

		$result = $this->process( $this->event() );

		self::assertSame( 'processed', $result['status'] );
		self::assertSame( SM::ACTIVE, $this->billing_status() );
		self::assertNull( $this->membership()['grace_period_ends_at'] );
		self::assertSame( 'active', $this->membership()['status'] );
	}

	/* ── Staff decisions ─────────────────────────────────────────────── */

	public function test_a_billing_event_never_overwrites_a_staff_owned_status(): void {
		Memberships_Repository::update( $this->membership_id, array( 'status' => 'comped' ) );

		$result = $this->process(
			$this->event(
				array(
					'intent'     => Payment_Provider::INTENT_CANCELLATION,
					'event_type' => 'customer.subscription.deleted',
				)
			)
		);

		self::assertSame( 'processed', $result['status'] );
		self::assertSame( SM::CANCELLED, $this->billing_status(), 'the billing fact is recorded' );
		self::assertSame( 'comped', $this->membership()['status'], 'the access decision is the manager\'s' );
	}

	/* ── Transitions ─────────────────────────────────────────────────── */

	public function test_a_transition_outside_the_matrix_is_refused_and_recorded(): void {
		Memberships_Repository::update( $this->membership_id, array( 'billing_status' => SM::CANCELLED ) );

		// cancelled → past_due is not in the matrix: there is no subscription
		// left to fail a payment on.
		Memberistic_Test_Payment_Provider::set_subscription( self::SUB, 'past_due' );

		$result = $this->process(
			$this->event(
				array(
					'intent'     => Payment_Provider::INTENT_PAYMENT_FAILED,
					'event_type' => 'invoice.payment_failed',
				)
			)
		);

		self::assertSame( 'rejected', $result['status'] );
		self::assertSame( Payment_Audit_Repository::REASON_INVALID_TRANSITION, $result['reason'] );
		self::assertSame( SM::CANCELLED, $this->billing_status() );
	}

	/* ── Trials ──────────────────────────────────────────────────────── */

	public function test_a_trial_activates_as_trialing_rather_than_paid(): void {
		$membership_id = Memberistic_Record_Factory::membership(
			$this->plan_id,
			self::factory()->user->create(),
			array(
				'status'         => 'pending',
				'billing_status' => SM::PENDING,
			)
		);
		Memberistic_Record_Factory::person(
			$membership_id,
			array(
				'email' => 'trial@example.test',
				'role'  => 'primary',
			)
		);

		Memberistic_Test_Payment_Provider::set_subscription( 'sub_trial', 'trialing' );

		$result = Payment_Integrity_Gate::process_event(
			self::PROVIDER,
			$this->event(
				array(
					'intent'                   => Payment_Provider::INTENT_ACTIVATION,
					'event_type'               => 'checkout.session.completed',
					'provider_subscription_id' => 'sub_trial',
					'provider_customer_id'     => 'cus_trial',
					'membership_hint'          => $membership_id,
					'amount'                   => 0.0,
				)
			)
		);

		self::assertSame( 'processed', $result['status'] );

		$row = Memberships_Repository::get( $membership_id );
		self::assertSame( SM::TRIALING, $row['billing_status'], 'a trial must not be flattened into a paid state' );
		self::assertSame( 'trial', $row['status'] );
	}

	public function test_a_trial_converting_to_paid_is_recorded(): void {
		Memberships_Repository::update( $this->membership_id, array( 'billing_status' => SM::TRIALING ) );

		$result = $this->process(
			$this->event(
				array(
					'intent'     => Payment_Provider::INTENT_SUBSCRIPTION_UPDATED,
					'event_type' => 'customer.subscription.updated',
					'object'     => array(
						'id'     => self::SUB,
						'status' => 'active',
					),
				)
			)
		);

		self::assertSame( 'processed', $result['status'] );
		self::assertSame( SM::ACTIVE, $this->billing_status() );
	}

	/* ── Cancel at period end ────────────────────────────────────────── */

	public function test_a_scheduled_cancellation_keeps_access_until_the_period_ends(): void {
		$result = $this->process(
			$this->event(
				array(
					'intent'     => Payment_Provider::INTENT_SUBSCRIPTION_UPDATED,
					'event_type' => 'customer.subscription.updated',
					'object'     => array(
						'id'                   => self::SUB,
						'status'               => 'active',
						'cancel_at_period_end' => true,
						'current_period_end'   => Payment_Clock::timestamp() + ( 10 * DAY_IN_SECONDS ),
					),
				)
			)
		);

		self::assertSame( 'processed', $result['status'] );
		self::assertSame( SM::CANCEL_AT_PERIOD_END, $this->billing_status() );
		self::assertSame( 'active', $this->membership()['status'], 'the member paid through the period' );
	}

	public function test_a_withdrawn_cancellation_returns_the_membership_to_active(): void {
		Memberships_Repository::update( $this->membership_id, array( 'billing_status' => SM::CANCEL_AT_PERIOD_END ) );

		$result = $this->process(
			$this->event(
				array(
					'intent'     => Payment_Provider::INTENT_SUBSCRIPTION_UPDATED,
					'event_type' => 'customer.subscription.updated',
					'object'     => array(
						'id'                   => self::SUB,
						'status'               => 'active',
						'cancel_at_period_end' => false,
					),
				)
			)
		);

		self::assertSame( 'processed', $result['status'] );
		self::assertSame( SM::ACTIVE, $this->billing_status() );
	}

	/* ── Recovery ────────────────────────────────────────────────────── */

	public function test_a_provider_outage_leaves_the_event_retryable(): void {
		Memberistic_Test_Payment_Provider::$unavailable = true;

		$event  = $this->event();
		$result = $this->process( $event );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 503, $result->get_error_data()['status'], 'the provider must be asked to retry' );
		self::assertSame( 1, Payment_Event_Repository::count_by_status( Payment_Event_Repository::STATUS_FAILED_RETRYABLE ) );
		self::assertSame( 0, $this->payment_count() );
		self::assertSame( array(), $this->emails );
	}

	public function test_a_retry_after_an_outage_succeeds_exactly_once(): void {
		Memberistic_Test_Payment_Provider::$unavailable = true;
		$event = $this->event();
		$this->process( $event );

		Memberistic_Test_Payment_Provider::$unavailable = false;
		Memberistic_Test_Payment_Provider::set_invoice( 'in_1', 25.00 );

		self::assertSame( 'processed', $this->process( $event )['status'] );
		self::assertSame( 1, $this->payment_count() );

		// And a third delivery still changes nothing.
		self::assertSame( 'duplicate', $this->process( $event )['status'] );
		self::assertSame( 1, $this->payment_count() );
	}

	/* ── Audit ───────────────────────────────────────────────────────── */

	public function test_every_refusal_leaves_a_reason_an_administrator_can_read(): void {
		$this->process(
			$this->event(
				array(
					'intent'                   => Payment_Provider::INTENT_CANCELLATION,
					'provider_subscription_id' => 'sub_old_replaced_abcdef',
				)
			)
		);

		$rows = Payment_Audit_Repository::get_for_membership( $this->membership_id, 5 );

		self::assertNotEmpty( $rows );
		self::assertSame( Payment_Audit_Repository::RESULT_REJECTED, $rows[0]['integrity_result'] );
		self::assertSame( Payment_Audit_Repository::REASON_STALE_SUBSCRIPTION_EVENT, $rows[0]['reason_code'] );

		// Identifiers are masked, and nothing resembling a secret is stored.
		$context = json_decode( (string) $rows[0]['context'], true );
		self::assertIsArray( $context );
		self::assertStringContainsString( '…', (string) $context['event_subscription'] );
	}

	public function test_the_audit_context_refuses_to_store_a_whole_provider_payload(): void {
		// Passing an entire provider object is the natural thing to reach for
		// while debugging, and would persist card metadata into a table
		// support staff paste into tickets.
		Payment_Audit_Repository::record(
			array(
				'provider'         => self::PROVIDER,
				'membership_id'    => $this->membership_id,
				'integrity_result' => Payment_Audit_Repository::RESULT_REJECTED,
				'reason_code'      => Payment_Audit_Repository::REASON_MANUAL_REVIEW,
				'context'          => array(
					'payload' => array(
						'card'  => array( 'number' => '4242424242424242' ),
						'email' => 'member@example.test',
					),
				),
			)
		);

		$rows = Payment_Audit_Repository::get_for_membership( $this->membership_id, 1 );

		self::assertStringNotContainsString( '4242', (string) $rows[0]['context'] );
		self::assertStringNotContainsString( 'member@example.test', (string) $rows[0]['context'] );
		self::assertStringContainsString( '[array:', (string) $rows[0]['context'] );
	}
}
