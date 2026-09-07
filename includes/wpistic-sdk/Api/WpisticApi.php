<?php
/**
 * HTTP client for api.wpistic.com using the WordPress HTTP API.
 * TLS verified, timeouts enforced, retries delegated to RetryHandler
 * (network failure or 5xx only, never 4xx), errors normalized to
 * WP_Error with the platform's error codes.
 *
 * @package WPistic\Sdk
 */

namespace WPistic\Sdk\Api;

use WP_Error;

/**
 * Default ApiClientInterface implementation backed by wp_remote_request().
 */
class WpisticApi implements ApiClientInterface {

	/**
	 * The base API URL, without a trailing slash.
	 *
	 * @var string
	 */
	private $api_url;

	/**
	 * The consuming product's slug, sent in the User-Agent header.
	 *
	 * @var string
	 */
	private $product_slug;

	/**
	 * The consuming product's version, sent in the User-Agent header.
	 *
	 * @var string
	 */
	private $product_version;

	/**
	 * The retry policy applied to every request.
	 *
	 * @var RetryHandler
	 */
	private $retry;

	/**
	 * Constructor.
	 *
	 * @param string            $api_url         The base API URL.
	 * @param string            $product_slug    The consuming product's slug.
	 * @param string            $product_version The consuming product's version.
	 * @param RetryHandler|null $retry           Optional retry policy override; defaults to a new RetryHandler.
	 */
	public function __construct( string $api_url, string $product_slug, string $product_version, ?RetryHandler $retry = null ) {
		$this->api_url         = rtrim( $api_url, '/' );
		$this->product_slug    = $product_slug;
		$this->product_version = $product_version;
		$this->retry           = $retry ?? new RetryHandler();
	}

	/**
	 * POST JSON to a platform endpoint.
	 *
	 * @param string               $path    e.g. '/api/v1/licenses/activate'.
	 * @param array<string,mixed>  $body    The request body.
	 * @param array<string,string> $headers Extra request headers.
	 * @return array<string,mixed>|WP_Error Decoded JSON on success.
	 */
	public function post( string $path, array $body = array(), array $headers = array() ) {
		return $this->request( 'POST', $this->api_url . $path, $body, $headers );
	}

	/**
	 * GET a platform endpoint.
	 *
	 * @param string               $path  The API path, relative to the base URL.
	 * @param array<string,string> $query Query string parameters.
	 * @return array<string,mixed>|WP_Error
	 */
	public function get( string $path, array $query = array() ) {
		$url = $this->api_url . $path;
		if ( ! empty( $query ) ) {
			$url = add_query_arg( array_map( 'rawurlencode', $query ), $url );
		}
		return $this->request( 'GET', $url, null, array() );
	}

	/**
	 * Perform the HTTP request, with retries, and normalize the result.
	 *
	 * @param string                   $method  The HTTP method.
	 * @param string                   $url     The full request URL.
	 * @param array<string,mixed>|null $body    The request body, or null for none.
	 * @param array<string,string>     $headers Extra request headers.
	 * @return array<string,mixed>|WP_Error
	 */
	private function request( string $method, string $url, $body, array $headers ) {
		$args = array(
			'method'      => $method,
			'timeout'     => 15,
			'redirection' => 2,
			'sslverify'   => true,
			'headers'     => array_merge(
				array(
					'Accept'           => 'application/json',
					'Content-Type'     => 'application/json',
					'User-Agent'       => sprintf(
						'WPistic-SDK/%1$s (%2$s; WP %3$s; PHP %4$s)',
						$this->product_version,
						$this->product_slug,
						get_bloginfo( 'version' ),
						PHP_VERSION
					),
					'X-Correlation-Id' => self::correlation_id(),
				),
				$headers
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$attempt = 0;
		while ( true ) {
			++$attempt;
			$response = wp_remote_request( $url, $args );

			if ( ! $this->retry->should_retry( $attempt, $response ) ) {
				break;
			}
			usleep( $this->retry->delay_for_attempt( $attempt ) * 1000000 );
		}

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'wpistic_network_error', __( 'Could not reach the WPistic licensing service.', 'wpistic-sdk' ), $response );
		}

		$status = wp_remote_retrieve_response_code( $response );
		$json   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status >= 400 ) {
			$code    = 'wpistic_api_error';
			$message = __( 'The WPistic licensing service returned an error.', 'wpistic-sdk' );
			if ( is_array( $json ) && isset( $json['error'] ) && is_array( $json['error'] ) ) {
				$code    = isset( $json['error']['code'] ) ? 'wpistic_' . sanitize_key( (string) $json['error']['code'] ) : $code;
				$message = isset( $json['error']['message'] ) ? sanitize_text_field( (string) $json['error']['message'] ) : $message;
			}
			return new WP_Error( $code, $message, array( 'status' => $status ) );
		}

		if ( ! is_array( $json ) ) {
			return new WP_Error( 'wpistic_invalid_response', __( 'Unexpected response from the WPistic licensing service.', 'wpistic-sdk' ) );
		}

		return $json;
	}

	/**
	 * Generate a per-request correlation ID for the X-Correlation-Id header.
	 *
	 * @return string
	 */
	private static function correlation_id(): string {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return wp_generate_uuid4();
		}
		return md5( uniqid( 'wpistic', true ) );
	}
}
