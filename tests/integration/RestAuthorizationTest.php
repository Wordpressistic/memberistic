<?php
/**
 * REST authorization matrix (P0-5).
 *
 * Memberistic's REST surface is gated on capabilities, not on record
 * ownership: every /memberships/{id}/* route asks "may this user read member
 * data at all", never "does this user own member {id}". That is a deliberate
 * design — those routes are staff tools, and staff are meant to see every
 * member — but it means the boundary worth testing here is the capability
 * matrix, and in particular the narrower PII gate.
 *
 * The invariant this suite defends: `view_memberistic_dashboard` is held by
 * front-line roles (cashier, POS staff, instructor, kiosk operator) whose
 * duties do not include reading other members' contact details, waiver status
 * or staff notes. Those roles must be refused by pii_permissions_check() while
 * still reaching the operational endpoints they need. Getting this wrong
 * silently widens PII access to the till, which is exactly the kind of
 * regression no unit test can see.
 *
 * @package Memberistic
 */

final class RestAuthorizationTest extends Memberistic_Integration_TestCase {

	/**
	 * Routes that legitimately serve unauthenticated traffic.
	 *
	 * Both authenticate by signature inside the handler rather than by
	 * capability; WebhookSecurityTest covers that they actually do.
	 *
	 * @var string[]
	 */
	private const SIGNATURE_AUTHENTICATED = array(
		'/memberistic/v1/webhooks/stripe',
		'/memberistic/v1/webhooks/woocommerce',
	);

	/**
	 * Parameterless GET routes behind the narrower PII gate.
	 *
	 * @var string[]
	 */
	private const PII_ROUTES = array(
		'/memberistic/v1/emails/directory',
	);

	/**
	 * Roles holding view_memberistic_dashboard but NOT view_memberistic_pii.
	 *
	 * @var string[]
	 */
	private const NON_PII_ROLES = array(
		'memberistic_cashier',
		'memberistic_instructor',
		'memberistic_pos_staff',
	);

	/**
	 * Roles that are granted view_memberistic_pii.
	 *
	 * @var string[]
	 */
	private const PII_ROLES = array(
		'memberistic_manager',
		'memberistic_staff',
	);

	/**
	 * Every registered memberistic/v1 route, excluding the namespace index.
	 *
	 * WordPress core registers `/memberistic/v1` itself as a public index of
	 * the namespace. It is core's route, not the plugin's, and it is public on
	 * every WordPress site for every plugin — so it is not something this
	 * plugin's permission model can or should be asserted against.
	 *
	 * @return array<int, array{route:string, methods:string[], permission:mixed}>
	 */
	private function memberistic_routes(): array {
		$found = array();

		foreach ( rest_get_server()->get_routes() as $route => $handlers ) {
			if ( ! str_starts_with( $route, '/memberistic/v1' ) ) {
				continue;
			}

			if ( '/memberistic/v1' === $route ) {
				continue;
			}

			foreach ( $handlers as $handler ) {
				$found[] = array(
					'route'      => $route,
					'methods'    => array_keys( array_filter( $handler['methods'] ?? array() ) ),
					'permission' => $handler['permission_callback'] ?? null,
				);
			}
		}

		return $found;
	}

	/**
	 * Parameterless GET routes, minus the signature-authenticated webhooks.
	 *
	 * Routes carrying a path parameter need a real record to address and
	 * belong to RestOwnershipTest; matching them here would only prove that a
	 * made-up id 404s.
	 *
	 * @return string[]
	 */
	private function parameterless_get_routes(): array {
		$routes = array();

		foreach ( $this->memberistic_routes() as $entry ) {
			if ( ! in_array( 'GET', $entry['methods'], true ) ) {
				continue;
			}

			if ( str_contains( $entry['route'], '(?P<' ) ) {
				continue;
			}

			if ( in_array( $entry['route'], self::SIGNATURE_AUTHENTICATED, true ) ) {
				continue;
			}

			$routes[ $entry['route'] ] = $entry['route'];
		}

		return array_values( $routes );
	}

	/**
	 * Fail loudly when a hard-coded route in this file stops existing.
	 *
	 * Without this, every "expect a rejection" assertion below would keep
	 * passing after a route is renamed — a 404 satisfies "not 200" just as
	 * well as a 403 does, so the suite would go quietly blind.
	 */
	private function assertRouteExists( string $route ): void {
		$this->assertArrayHasKey(
			$route,
			rest_get_server()->get_routes(),
			"Route {$route} is not registered. This test's route list is stale — fix the list, do not delete the assertion."
		);
	}

	private function get( string $route ): WP_REST_Response {
		return rest_do_request( new WP_REST_Request( 'GET', $route ) );
	}

	public function test_the_route_list_this_suite_depends_on_still_exists(): void {
		foreach ( array_merge( self::SIGNATURE_AUTHENTICATED, self::PII_ROUTES ) as $route ) {
			$this->assertRouteExists( $route );
		}

		$this->assertNotEmpty( $this->parameterless_get_routes(), 'No parameterless GET routes were discovered.' );
	}

	/**
	 * No plugin route may be readable by an anonymous visitor.
	 */
	public function test_anonymous_is_rejected_from_every_plugin_route(): void {
		wp_set_current_user( 0 );

		$checked = 0;

		foreach ( $this->parameterless_get_routes() as $route ) {
			$status = $this->get( $route )->get_status();
			++$checked;

			$this->assertContains(
				$status,
				array( 401, 403 ),
				"Anonymous GET {$route} returned {$status}; expected 401/403."
			);
		}

		$this->assertGreaterThan( 0, $checked );
	}

	/**
	 * A logged-in user with no Memberistic role is still an outsider.
	 */
	public function test_plain_subscriber_is_rejected_from_every_plugin_route(): void {
		$this->acting_as( 'subscriber' );

		foreach ( $this->parameterless_get_routes() as $route ) {
			$status = $this->get( $route )->get_status();

			$this->assertContains(
				$status,
				array( 401, 403 ),
				"Subscriber GET {$route} returned {$status}; expected 401/403."
			);
		}
	}

	/**
	 * Administrators reach every plugin route.
	 *
	 * The negative tests above are only meaningful alongside this one:
	 * without it, a route that rejected everybody would look like a pass.
	 */
	public function test_administrator_reaches_every_plugin_route(): void {
		$this->acting_as( 'administrator' );

		foreach ( $this->parameterless_get_routes() as $route ) {
			$status = $this->get( $route )->get_status();

			$this->assertNotContains(
				$status,
				array( 401, 403 ),
				"Administrator GET {$route} was rejected with {$status}."
			);
		}
	}

	/**
	 * Invariant: dashboard access does not imply PII access.
	 */
	public function test_front_line_roles_are_refused_pii_routes(): void {
		foreach ( self::NON_PII_ROLES as $role ) {
			foreach ( self::PII_ROUTES as $route ) {
				$this->acting_as( $role );

				$this->assertFalse(
					current_user_can( 'view_memberistic_pii' ),
					"Role {$role} unexpectedly holds view_memberistic_pii."
				);

				$status = $this->get( $route )->get_status();

				$this->assertContains(
					$status,
					array( 401, 403 ),
					"Role {$role} read PII route {$route} and got {$status}; expected a rejection."
				);
			}
		}
	}

	/**
	 * The roles that are supposed to hold PII access actually can use it.
	 */
	public function test_pii_roles_reach_pii_routes(): void {
		foreach ( self::PII_ROLES as $role ) {
			foreach ( self::PII_ROUTES as $route ) {
				$this->acting_as( $role );

				$this->assertTrue(
					current_user_can( 'view_memberistic_pii' ),
					"Role {$role} is expected to hold view_memberistic_pii."
				);

				$status = $this->get( $route )->get_status();

				$this->assertNotContains(
					$status,
					array( 401, 403 ),
					"Role {$role} was refused PII route {$route} with {$status}."
				);
			}
		}
	}

	/**
	 * Webhook routes are public by permission and closed by signature.
	 *
	 * public_permissions_check() returning true is correct here, but only
	 * because the handler refuses the request before parsing it. An
	 * unauthenticated POST must never come back 200.
	 */
	public function test_webhook_routes_reject_unsigned_posts(): void {
		wp_set_current_user( 0 );

		foreach ( self::SIGNATURE_AUTHENTICATED as $route ) {
			$this->assertRouteExists( $route );

			$request = new WP_REST_Request( 'POST', $route );
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( array( 'type' => 'test.event' ) ) );

			$status = rest_do_request( $request )->get_status();

			$this->assertGreaterThanOrEqual(
				400,
				$status,
				"Unsigned POST {$route} returned {$status}; a webhook must never accept an unsigned request."
			);
		}
	}
}
