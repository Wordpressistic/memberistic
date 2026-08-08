<?php
/**
 * Shared base class for Memberistic integration tests.
 *
 * @package Memberistic
 */

/**
 * Adds the two things nearly every Memberistic integration test needs: a way
 * to assert that no outbound HTTP request happened, and helpers for the plugin
 * roles the capability model is built around.
 */
abstract class Memberistic_Integration_TestCase extends WP_UnitTestCase {

	/**
	 * Outbound HTTP requests captured while the interceptor is armed.
	 *
	 * @var array<int, string>
	 */
	protected array $captured_http = array();

	/**
	 * Intercept every outbound HTTP request and refuse to let it leave.
	 *
	 * `pre_http_request` short-circuits WP_Http before any transport runs, so
	 * this both records the attempt and guarantees the test suite itself never
	 * touches the network — which keeps CI deterministic and keeps a
	 * misconfigured integration from quietly calling a third party during a
	 * test run.
	 */
	protected function block_and_record_http(): void {
		$this->captured_http = array();

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				$this->captured_http[] = $url;

				return new WP_Error(
					'memberistic_test_http_blocked',
					'Outbound HTTP is blocked during integration tests.'
				);
			},
			-PHP_INT_MAX,
			3
		);
	}

	/**
	 * Assert that no outbound HTTP request was attempted.
	 */
	protected function assertNoOutboundHttp( string $message = '' ): void {
		$this->assertSame(
			array(),
			$this->captured_http,
			$message ?: 'Expected zero outbound HTTP requests, got: ' . implode( ', ', $this->captured_http )
		);
	}

	/**
	 * Create a user with one of the plugin's roles and make them current.
	 */
	protected function acting_as( string $role ): int {
		$user_id = self::factory()->user->create( array( 'role' => $role ) );
		wp_set_current_user( $user_id );

		return $user_id;
	}

	/**
	 * Every table the schema is expected to create.
	 *
	 * @return array<int, string>
	 */
	protected function expected_tables(): array {
		return array(
			'memberistic_plans',
			'memberistic_memberships',
			'memberistic_people',
			'memberistic_payments',
			'memberistic_activity',
			'memberistic_checkins',
			'memberistic_notes',
			'memberistic_logs',
			'memberistic_rate_limits',
			'memberistic_email_logs',
			'memberistic_integrations',
			'memberistic_waiver_signatures',
			'memberistic_documents',
			'memberistic_waiver_versions',
			'memberistic_waivers_archive',
		);
	}
}
