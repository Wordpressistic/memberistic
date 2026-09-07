<?php
/**
 * Retry policy for the WPistic HTTP client: retry network failures and
 * 5xx responses, never 4xx (those are the platform telling us something
 * about the request itself, not a transient condition), with a small
 * capped exponential backoff between attempts.
 *
 * @package WPistic\Sdk
 */

namespace WPistic\Sdk\Api;

use WP_Error;

/**
 * Decides whether and how long to wait before retrying a failed request.
 */
class RetryHandler {

	private const MAX_DELAY_SECONDS = 30;

	/**
	 * Total attempts allowed, including the first.
	 *
	 * @var int
	 */
	private $max_attempts;

	/**
	 * Constructor.
	 *
	 * @param int $max_attempts Total attempts allowed, including the first. 2 = one retry.
	 */
	public function __construct( int $max_attempts = 2 ) {
		$this->max_attempts = max( 1, $max_attempts );
	}

	/**
	 * Whether another attempt should be made after the given result.
	 *
	 * @param int                          $attempt          1-based count of attempts already made.
	 * @param WP_Error|array<string,mixed> $response_or_error Result of the attempt just made.
	 * @return bool
	 */
	public function should_retry( int $attempt, $response_or_error ): bool {
		if ( $attempt >= $this->max_attempts ) {
			return false;
		}
		if ( $response_or_error instanceof WP_Error ) {
			return true;
		}
		return $this->status_code( $response_or_error ) >= 500;
	}

	/**
	 * Exponential backoff, capped: 2^attempt seconds, never more than
	 * MAX_DELAY_SECONDS.
	 *
	 * @param int $attempt 0-based count of attempts already made.
	 * @return int
	 */
	public function delay_for_attempt( int $attempt ): int {
		$attempt = max( 0, $attempt );
		return (int) min( self::MAX_DELAY_SECONDS, 2 ** $attempt );
	}

	/**
	 * Extract the HTTP status code from a response or error.
	 *
	 * @param mixed $response_or_error The WP_Error or response array from the attempt.
	 * @return int
	 */
	private function status_code( $response_or_error ): int {
		if ( function_exists( 'wp_remote_retrieve_response_code' ) && ( is_array( $response_or_error ) || is_object( $response_or_error ) ) ) {
			$code = wp_remote_retrieve_response_code( $response_or_error );
			if ( '' !== $code ) {
				return (int) $code;
			}
		}
		if ( is_array( $response_or_error ) && isset( $response_or_error['response']['code'] ) ) {
			return (int) $response_or_error['response']['code'];
		}
		return 0;
	}
}
