<?php
/**
 * Unit tests for Membership_Service — the single membership authority.
 *
 * Covers the public status/plan API and, critically, the guard rails on plan
 * assignment: a plan may only be granted with verified payment evidence or by
 * an authorised admin with an audited reason. Bookings, registrations,
 * customer-record creation and pending/failed payments must never assign one.
 *
 * @package Memberistic\Tests
 */

use PHPUnit\Framework\TestCase;
use WordPressistic\Memberistic\Membership_Service;
use WordPressistic\Memberistic\Database\Memberships_Repository;
use WordPressistic\Memberistic\Database\People_Repository;
use WordPressistic\Memberistic\Database\Plans_Repository;

final class MembershipServiceTest extends TestCase {

	protected function setUp(): void {
		memberistic_tests_reset_state();

		// A real WP user must exist before assignment guards are reached, so
		// the refusals below are proven to come from the payment/authorisation
		// checks rather than from a missing account.
		$GLOBALS['memberistic_test_users'] = array(
			20 => array( 'ID' => 20, 'display_name' => 'Test Customer', 'user_email' => 'customer@example.test' ),
		);

		Plans_Repository::$by_id = array(
			1 => array( 'id' => 1, 'slug' => 'individual', 'name' => 'Individual' ),
			2 => array( 'id' => 2, 'slug' => 'guest-pass', 'name' => 'Guest Pass' ),
			3 => array( 'id' => 3, 'slug' => 'family', 'name' => 'Family' ),
		);
	}

	/** Register a live primary membership for a user. */
	private function give_membership( int $user_id, int $plan_id, string $status = 'active', string $renewal = '' ): void {
		$row = array(
			'id'            => 100 + $user_id,
			'plan_id'       => $plan_id,
			'status'        => $status,
			'start_date'    => '2026-01-01 00:00:00',
			'renewal_date'  => $renewal,
			'primary_user_id' => $user_id,
		);
		Memberships_Repository::$by_user_id[ $user_id ] = $row;
		Memberships_Repository::$by_id[ 100 + $user_id ] = $row;
	}

	/* ── Status lookup ─────────────────────────────────────────────────── */

	public function test_anonymous_user_has_no_membership(): void {
		$status = Membership_Service::get_user_membership_status( 0 );

		$this->assertFalse( $status['has_membership'] );
		$this->assertFalse( $status['is_live'] );
		$this->assertSame( 0, $status['membership_id'] );
	}

	public function test_active_member_is_live(): void {
		$this->give_membership( 5, 1 );
		$status = Membership_Service::get_user_membership_status( 5 );

		$this->assertTrue( $status['has_membership'] );
		$this->assertTrue( $status['is_live'] );
		$this->assertSame( 'individual', $status['plan_slug'] );
		$this->assertSame( 'Individual', $status['plan_name'] );
		$this->assertSame( 'primary', $status['role'] );
	}

	public function test_expired_renewal_date_is_not_live(): void {
		$this->give_membership( 6, 1, 'active', '2020-01-01 00:00:00' );
		$status = Membership_Service::get_user_membership_status( 6 );

		$this->assertTrue( $status['has_membership'] );
		$this->assertTrue( $status['expired'] );
		$this->assertFalse( $status['is_live'], 'A lapsed member must not read as live.' );
	}

	public function test_ineligible_status_is_not_live(): void {
		foreach ( array( 'trial', 'past_due', 'suspended', 'cancelled', 'needs_review' ) as $status_slug ) {
			Memberships_Repository::reset();
			$this->give_membership( 7, 1, $status_slug );

			$this->assertFalse(
				Membership_Service::get_user_membership_status( 7 )['is_live'],
				sprintf( 'Status "%s" must not count as a live membership.', $status_slug )
			);
		}
	}

	/* ── Plan checks ───────────────────────────────────────────────────── */

	public function test_user_has_active_plan_matches_slug(): void {
		$this->give_membership( 8, 1 );

		$this->assertTrue( Membership_Service::user_has_active_plan( 8, 'individual' ) );
		$this->assertTrue( Membership_Service::user_has_active_plan( 8 ), 'Empty slug means "any live plan".' );
		$this->assertFalse( Membership_Service::user_has_active_plan( 8, 'family' ) );
	}

	public function test_guest_user_detection(): void {
		$this->assertTrue( Membership_Service::is_guest_user( 0 ) );
		$this->assertTrue( Membership_Service::is_guest_user( 99 ), 'A user with no membership row is a guest.' );

		$this->give_membership( 9, 3 );
		$this->assertFalse( Membership_Service::is_guest_user( 9 ) );
	}

	/* ── Booking entitlement (delegated to Entitlement_Service) ────────── */

	public function test_eligible_member_can_book_without_paying(): void {
		$this->give_membership( 10, 1 ); // individual

		// Since 2.0.0 no plan includes bookings until an operator opts it in.
		update_option( 'memberistic_lane_included_plan_slugs', array( 'individual' ) );

		$this->assertTrue( Membership_Service::can_book_lane( 10 ) );
		$this->assertFalse( Membership_Service::requires_payment_for_booking( 10 ) );
	}

	public function test_guest_pass_holder_must_pay(): void {
		$this->give_membership( 11, 2 ); // guest-pass

		$this->assertFalse(
			Membership_Service::can_book_lane( 11 ),
			'A Guest Pass never includes free lane time.'
		);
		$this->assertTrue( Membership_Service::requires_payment_for_booking( 11 ) );
	}

	public function test_anonymous_visitor_must_pay(): void {
		$this->assertFalse( Membership_Service::can_book_lane( 0 ) );
		$this->assertTrue( Membership_Service::requires_payment_for_booking( 0 ) );
	}

	public function test_expired_member_is_treated_as_non_member(): void {
		$this->give_membership( 12, 1, 'active', '2020-01-01 00:00:00' );

		$this->assertFalse( Membership_Service::can_book_lane( 12 ) );
		$this->assertTrue( Membership_Service::requires_payment_for_booking( 12 ) );
	}

	/* ── Plan assignment guard rails ───────────────────────────────────── */

	public function test_assignment_without_payment_evidence_is_refused(): void {
		$result = Membership_Service::assign_plan_after_payment( 20, 'individual', array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'memberistic_payment_evidence_required', $result->get_error_code() );
	}

	public function test_assignment_with_unverified_payment_is_refused(): void {
		// An order reference alone is not enough — it must be verified.
		$result = Membership_Service::assign_plan_after_payment( 20, 'individual', array(
			'order_id'         => 4242,
			'payment_verified' => false,
		) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'memberistic_payment_evidence_required', $result->get_error_code() );
	}

	public function test_verified_payment_without_reference_is_refused(): void {
		$result = Membership_Service::assign_plan_after_payment( 20, 'individual', array(
			'payment_verified' => true,
		) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'memberistic_payment_evidence_required', $result->get_error_code() );
	}

	public function test_manual_assignment_requires_a_capable_actor(): void {
		$GLOBALS['memberistic_test_user_can'] = false;

		$result = Membership_Service::assign_plan_manually( 20, 'individual', 'VIP comp', 33 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'memberistic_forbidden', $result->get_error_code() );
	}

	public function test_manual_assignment_requires_a_reason(): void {
		$GLOBALS['memberistic_test_user_can'] = true;

		$result = Membership_Service::assign_plan_manually( 20, 'individual', '   ', 33 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'memberistic_reason_required', $result->get_error_code() );
	}

	public function test_removal_requires_a_reason(): void {
		$GLOBALS['memberistic_test_user_can'] = true;
		$this->give_membership( 21, 1 );

		$result = Membership_Service::remove_plan( 21, 1, '' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'memberistic_reason_required', $result->get_error_code() );
	}

	public function test_removal_refuses_when_actor_lacks_capability(): void {
		$GLOBALS['memberistic_test_user_can'] = false;
		$this->give_membership( 22, 1 );

		$result = Membership_Service::remove_plan( 22, 1, 'Chargeback', 44 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'memberistic_forbidden', $result->get_error_code() );
	}
}
