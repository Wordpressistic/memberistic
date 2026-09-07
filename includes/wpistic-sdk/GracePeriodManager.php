<?php
/**
 * Offline-resilience math: once the API stops responding, how long do
 * premium features stay on? This is pure arithmetic over timestamps the
 * caller already trusts (signature + `valid` are checked by the caller
 * before consulting this class) — `now` is always passed in so it is
 * unit-testable without touching the system clock.
 *
 * The window: the SDK expects to revalidate within `check_after` seconds.
 * A single missed check isn't fatal — grace doesn't begin until the check
 * window has silently doubled (`check_after * 2`) with no successful
 * revalidation. From that point, features stay active for exactly
 * `grace_period_days` more days. `grace_period_days` and `check_after` are
 * both server-supplied and authoritative; the constants below are only the
 * fallback if a cached response predates those fields.
 *
 * @package WPistic\Sdk
 */

namespace WPistic\Sdk;

/**
 * Computes offline grace-period status from a cached license response.
 */
class GracePeriodManager {

	private const DEFAULT_CHECK_AFTER = 43200; // 12 hours, matches LICENSE_CHECK_AFTER_SECONDS server-side.
	private const DEFAULT_GRACE_DAYS  = 7;

	/**
	 * Are premium features still active given this cached response?
	 *
	 * @param array<string,mixed> $response     Last cached (already signature-verified) response.
	 * @param int                 $validated_at Unix timestamp of the last successful validation.
	 * @param int                 $now          Current time, injected for testability.
	 */
	public function is_active( array $response, int $validated_at, int $now ): bool {
		$age = $now - $validated_at;
		return $age <= $this->active_window_seconds( $response );
	}

	/**
	 * Seconds remaining in the grace window; null when not yet in grace
	 * (still inside the normal check_after window) — the caller decides
	 * what "not in grace" means for admin-notice purposes.
	 *
	 * @param array<string,mixed> $response     Last cached (already signature-verified) response.
	 * @param int                 $validated_at Unix timestamp of the last successful validation.
	 * @param int                 $now          Current time, injected for testability.
	 * @return int|null
	 */
	public function seconds_remaining( array $response, int $validated_at, int $now ): ?int {
		$age         = $now - $validated_at;
		$grace_start = $this->grace_start_seconds( $response );

		if ( $age <= $grace_start ) {
			return null;
		}

		$grace_days = $this->grace_days( $response );
		$window_end = $grace_start + ( $grace_days * DAY_IN_SECONDS );

		return max( 0, $window_end - $age );
	}

	/**
	 * Total seconds a cached response is considered active for.
	 *
	 * @param array<string,mixed> $response Last cached (already signature-verified) response.
	 * @return int
	 */
	private function active_window_seconds( array $response ): int {
		return $this->grace_start_seconds( $response ) + ( $this->grace_days( $response ) * DAY_IN_SECONDS );
	}

	/**
	 * Seconds after validation before the grace period begins.
	 *
	 * @param array<string,mixed> $response Last cached (already signature-verified) response.
	 * @return int
	 */
	private function grace_start_seconds( array $response ): int {
		return $this->check_after( $response ) * 2;
	}

	/**
	 * The server-supplied (or default) revalidation interval, in seconds.
	 *
	 * @param array<string,mixed> $response Last cached (already signature-verified) response.
	 * @return int
	 */
	private function check_after( array $response ): int {
		return isset( $response['check_after'] ) && is_numeric( $response['check_after'] )
			? (int) $response['check_after']
			: self::DEFAULT_CHECK_AFTER;
	}

	/**
	 * The server-supplied (or default) grace period length, in days.
	 *
	 * @param array<string,mixed> $response Last cached (already signature-verified) response.
	 * @return int
	 */
	private function grace_days( array $response ): int {
		return isset( $response['grace_period_days'] ) && is_numeric( $response['grace_period_days'] )
			? (int) $response['grace_period_days']
			: self::DEFAULT_GRACE_DAYS;
	}
}
