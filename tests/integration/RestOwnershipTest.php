<?php
/**
 * Record-ownership boundaries on the REST surface (P0-5).
 *
 * A note on what this suite does and does not claim, because the distinction
 * matters more than the test count.
 *
 * The classic IDOR shape — member A changes an id and reads member B's record
 * — does not apply to most of this plugin's REST surface, because that surface
 * is not member-facing. Every /memberships/{id}/* route is gated on a staff
 * capability (pii_permissions_check, manage_members_permissions_check,
 * manage_payments_permissions_check). A member never reaches the ownership
 * question because they never clear the capability gate, and a user who *does*
 * clear it is staff, who are meant to read every member.
 *
 * So writing "member A cannot read member B's payments" against those routes
 * would pass — but for the wrong reason, and it would keep passing if
 * ownership scoping were removed entirely. That is worse than no test: it
 * reads like proof of an isolation guarantee the code does not make.
 *
 * What this suite tests instead:
 *
 * 1. The capability gate genuinely holds for member-role users, stated as what
 *    it is — capability enforcement, not per-record ownership.
 * 2. The one genuinely ownership-scoped route, /profile/image, which uses
 *    member_self_permissions_check and is reachable by ordinary members.
 * 3. That every route named here still exists, so a rename cannot turn these
 *    into vacuous passes.
 *
 * If member-facing read routes are ever added — a real "my membership"
 * endpoint — per-record IDOR tests belong here and this header should shrink.
 *
 * @package Memberistic
 */

final class RestOwnershipTest extends Memberistic_Integration_TestCase {

	/**
	 * Fixtures delegate to Memberistic_Record_Factory.
	 *
	 * These were four hand-rolled $wpdb->insert() calls, each repeating the
	 * required-column knowledge the schema already encodes. The factory owns
	 * that now and throws on a failed insert, so a mismatched column can no
	 * longer leave a test addressing id 0 and passing on the resulting 404.
	 */
	private function create_plan(): int {
		return Memberistic_Record_Factory::plan();
	}

	private function create_membership( int $plan_id, int $user_id ): int {
		return Memberistic_Record_Factory::membership( $plan_id, $user_id );
	}

	private function create_person( int $membership_id ): int {
		return Memberistic_Record_Factory::person( $membership_id, array( 'full_name' => 'Ownership Test Person' ) );
	}

	private function create_payment( int $membership_id ): int {
		return Memberistic_Record_Factory::payment( $membership_id );
	}

	private function assertRouteExists( string $pattern ): void {
		$this->assertArrayHasKey(
			$pattern,
			rest_get_server()->get_routes(),
			"Route {$pattern} is not registered. Fix this test's route list rather than removing the assertion — a 404 would otherwise satisfy every rejection assertion below."
		);
	}

	/**
	 * The fixtures must actually produce rows.
	 *
	 * If an insert silently fails, every "cannot read member B" test below
	 * would request id 0, get a 404, and report success. This test makes that
	 * failure mode loud instead.
	 */
	public function test_fixtures_create_real_rows(): void {
		global $wpdb;

		$user       = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$plan_id    = $this->create_plan();
		$membership = $this->create_membership( $plan_id, $user );

		$this->assertGreaterThan( 0, $plan_id );
		$this->assertGreaterThan( 0, $membership );

		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT primary_user_id FROM {$wpdb->prefix}memberistic_memberships WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$membership
			)
		);

		$this->assertSame( (int) $user, (int) $found, 'Membership fixture did not persist its owner.' );
	}

	/**
	 * A member-role user cannot read another member's records.
	 *
	 * This is capability enforcement, not ownership scoping: the subscriber is
	 * refused because they hold no Memberistic capability at all. Asserted
	 * anyway because it is the protection that is actually in force, and its
	 * removal would be a real breach.
	 */
	public function test_member_role_user_cannot_read_another_members_records(): void {
		$user_a = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user_b = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$plan_id = $this->create_plan();
		$this->create_membership( $plan_id, $user_a );
		$mem_b = $this->create_membership( $plan_id, $user_b );

		$this->create_person( $mem_b );
		$this->create_payment( $mem_b );

		$this->assertRouteExists( '/memberistic/v1/memberships/(?P<id>[\d]+)' );
		$this->assertRouteExists( '/memberistic/v1/memberships/(?P<id>[\d]+)/people' );
		$this->assertRouteExists( '/memberistic/v1/memberships/(?P<id>[\d]+)/payments' );
		$this->assertRouteExists( '/memberistic/v1/memberships/(?P<id>[\d]+)/activity' );

		wp_set_current_user( $user_a );

		$targets = array(
			"/memberistic/v1/memberships/{$mem_b}",
			"/memberistic/v1/memberships/{$mem_b}/people",
			"/memberistic/v1/memberships/{$mem_b}/payments",
			"/memberistic/v1/memberships/{$mem_b}/activity",
		);

		foreach ( $targets as $target ) {
			$status = rest_do_request( new WP_REST_Request( 'GET', $target ) )->get_status();

			$this->assertContains(
				$status,
				array( 401, 403 ),
				"Subscriber read {$target} and got {$status}; expected 401/403. A 404 here would mean the route moved, not that access was denied."
			);
		}
	}

	/**
	 * The same user cannot write to another member's records either.
	 */
	public function test_member_role_user_cannot_write_to_another_members_records(): void {
		$user_a = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user_b = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$plan_id  = $this->create_plan();
		$mem_b    = $this->create_membership( $plan_id, $user_b );
		$person_b = $this->create_person( $mem_b );

		$this->assertRouteExists( '/memberistic/v1/people/(?P<id>[\d]+)' );

		wp_set_current_user( $user_a );

		$update = new WP_REST_Request( 'POST', "/memberistic/v1/memberships/{$mem_b}" );
		$update->set_body_params( array( 'status' => 'cancelled' ) );

		$this->assertContains(
			rest_do_request( $update )->get_status(),
			array( 401, 403 ),
			'Subscriber was able to attempt a status change on another membership.'
		);

		$edit_person = new WP_REST_Request( 'POST', "/memberistic/v1/people/{$person_b}" );
		$edit_person->set_body_params( array( 'full_name' => 'Overwritten' ) );

		$this->assertContains(
			rest_do_request( $edit_person )->get_status(),
			array( 401, 403 ),
			"Subscriber was able to attempt an edit of another membership's linked person."
		);

		global $wpdb;

		$name = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT full_name FROM {$wpdb->prefix}memberistic_people WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$person_b
			)
		);

		$this->assertSame( 'Ownership Test Person', $name, 'The linked person was modified despite the request being refused.' );
	}

	/**
	 * /profile/image is the one member-facing route, and it is gated on
	 * holding an actual membership rather than merely being logged in.
	 */
	public function test_profile_image_requires_an_actual_membership(): void {
		$this->assertRouteExists( '/memberistic/v1/profile/image' );

		wp_set_current_user( 0 );

		$this->assertContains(
			rest_do_request( new WP_REST_Request( 'DELETE', '/memberistic/v1/profile/image' ) )->get_status(),
			array( 401, 403 ),
			'Anonymous callers must not reach the member profile image route.'
		);

		// Logged in, but with no Memberistic linkage at all.
		$stranger = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $stranger );

		$this->assertContains(
			rest_do_request( new WP_REST_Request( 'DELETE', '/memberistic/v1/profile/image' ) )->get_status(),
			array( 401, 403 ),
			'A subscriber with no membership must not reach the member profile image route. This gate previously fell back to edit_user, which map_meta_cap() grants any user against their own id — making it a no-op.'
		);
	}

	/**
	 * A member with a membership does clear the member-self gate.
	 *
	 * Without this the test above would pass even if the gate rejected
	 * everybody, which would be a broken feature rather than a secure one.
	 */
	public function test_profile_image_gate_admits_a_real_member(): void {
		$member  = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$plan_id = $this->create_plan();
		$this->create_membership( $plan_id, $member );

		wp_set_current_user( $member );

		$this->assertTrue(
			memberistic_user_has_membership( $member ),
			'Fixture did not produce a membership the plugin recognises for this user.'
		);

		$status = rest_do_request( new WP_REST_Request( 'DELETE', '/memberistic/v1/profile/image' ) )->get_status();

		$this->assertNotContains(
			$status,
			array( 401, 403 ),
			"A member with an active membership was refused their own profile image route with {$status}."
		);
	}
}
