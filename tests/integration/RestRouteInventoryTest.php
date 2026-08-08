<?php
/**
 * REST route inventory and permission-callback enforcement.
 *
 * This is the first slice of P0-5. It does not yet test authorization
 * outcomes per role — that needs the full matrix described in the backlog —
 * but it does enforce invariant I6 mechanically across every registered
 * route, on every WordPress version in the matrix.
 *
 * Doing this before the memberships controller is split is deliberate: the
 * refactor's main risk is a route silently losing its guard, and this is the
 * test that would catch it.
 *
 * @package Memberistic
 */

/**
 * @group integration
 * @group rest
 * @group security
 */
class RestRouteInventoryTest extends Memberistic_Integration_TestCase {

	/**
	 * Every registered memberistic/v1 route, flattened to one entry per
	 * route × handler.
	 *
	 * @return array<int, array{route: string, methods: string, callback: mixed, permission: mixed}>
	 */
	private function memberistic_routes(): array {
		// Forces rest_api_init, which is where controllers register.
		$server = rest_get_server();
		$routes = $server->get_routes();

		$found = array();

		foreach ( $routes as $route => $handlers ) {
			if ( ! str_starts_with( $route, '/memberistic/v1' ) ) {
				continue;
			}

			// The namespace index — `/memberistic/v1` exactly — is registered
			// by WordPress core, not by the plugin. It is the API discovery
			// document every namespace gets (`/wp/v2` behaves identically),
			// it is public by design, and it exposes route schemas rather than
			// member data. Holding plugin rules against a core-owned route
			// would be asserting the wrong thing.
			if ( '/memberistic/v1' === $route ) {
				continue;
			}

			foreach ( $handlers as $handler ) {
				$found[] = array(
					'route'      => $route,
					'methods'    => implode( ',', array_keys( array_filter( $handler['methods'] ?? array() ) ) ),
					'callback'   => $handler['callback'] ?? null,
					'permission' => $handler['permission_callback'] ?? null,
				);
			}
		}

		return $found;
	}

	public function test_routes_are_registered(): void {
		$routes = $this->memberistic_routes();

		$this->assertNotEmpty( $routes, 'No memberistic/v1 routes registered.' );
	}

	/**
	 * Invariant I6 — every route has a real permission model.
	 */
	public function test_every_route_declares_a_permission_callback(): void {
		foreach ( $this->memberistic_routes() as $entry ) {
			$this->assertNotNull(
				$entry['permission'],
				"Route {$entry['route']} ({$entry['methods']}) has no permission_callback."
			);

			$this->assertNotEmpty(
				$entry['permission'],
				"Route {$entry['route']} ({$entry['methods']}) has an empty permission_callback."
			);
		}
	}

	/**
	 * Invariant I6 — `__return_true` is banned outright.
	 *
	 * Webhook routes are the only unauthenticated entry points and they
	 * authenticate by signature inside their own permission callback, before
	 * the payload is parsed. That is a named callback, not `__return_true`,
	 * so this assertion holds for them too.
	 */
	public function test_no_route_uses_return_true(): void {
		foreach ( $this->memberistic_routes() as $entry ) {
			$permission = $entry['permission'];

			$name = is_string( $permission ) ? $permission : '';

			if ( is_array( $permission ) && isset( $permission[1] ) ) {
				$name = (string) $permission[1];
			}

			$this->assertNotSame(
				'__return_true',
				$name,
				"Route {$entry['route']} ({$entry['methods']}) uses __return_true as its permission_callback."
			);
		}
	}

	/**
	 * An anonymous request must never reach a route that requires a
	 * capability. This walks every GET route with no required arguments and
	 * asserts the response is 401/403 rather than data.
	 *
	 * Routes that legitimately serve anonymous traffic are allow-listed by
	 * prefix with the reason stated, so adding a new public route is a
	 * deliberate act that shows up in review.
	 */
	public function test_anonymous_requests_are_rejected_on_protected_routes(): void {
		wp_set_current_user( 0 );

		$public_prefixes = array(
			// Webhook receivers authenticate by signature, not by user, and
			// must stay reachable unauthenticated for Stripe and WooCommerce
			// to deliver at all.
			'/memberistic/v1/webhook',
			'/memberistic/v1/stripe/webhook',
			'/memberistic/v1/woocommerce/webhook',
		);

		$checked = 0;

		foreach ( $this->memberistic_routes() as $entry ) {
			if ( ! str_contains( $entry['methods'], 'GET' ) ) {
				continue;
			}

			// Routes with path parameters need a real resource to address;
			// they belong to the IDOR suite (P0-5), not this smoke test.
			if ( str_contains( $entry['route'], '(?P<' ) ) {
				continue;
			}

			foreach ( $public_prefixes as $prefix ) {
				if ( str_starts_with( $entry['route'], $prefix ) ) {
					continue 2;
				}
			}

			$response = rest_do_request( new WP_REST_Request( 'GET', $entry['route'] ) );
			++$checked;

			$this->assertContains(
				$response->get_status(),
				array( 400, 401, 403, 404 ),
				"Anonymous GET {$entry['route']} returned {$response->get_status()}; expected a rejection."
			);
		}

		$this->assertGreaterThan( 0, $checked, 'No parameterless GET routes were exercised.' );
	}

	/**
	 * Emit the route inventory as a test artifact.
	 *
	 * P0-5 asks for a machine-readable matrix of namespace, method, path,
	 * permission callback and capability. Generating it from the live server
	 * keeps it correct by construction — a hand-maintained table would be
	 * wrong the first time a route changed.
	 */
	public function test_route_inventory_can_be_generated(): void {
		$inventory = array();

		foreach ( $this->memberistic_routes() as $entry ) {
			$permission = $entry['permission'];

			if ( is_array( $permission ) ) {
				$class      = is_object( $permission[0] ) ? get_class( $permission[0] ) : (string) $permission[0];
				$permission = $class . '::' . ( $permission[1] ?? '?' );
			} elseif ( $permission instanceof Closure ) {
				$permission = 'Closure';
			}

			$inventory[] = array(
				'route'      => $entry['route'],
				'methods'    => $entry['methods'],
				'permission' => is_string( $permission ) ? $permission : gettype( $permission ),
			);
		}

		$this->assertNotEmpty( $inventory );

		$target = getenv( 'MEMBERISTIC_ROUTE_INVENTORY' );

		if ( $target ) {
			file_put_contents( $target, wp_json_encode( $inventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		}
	}
}
