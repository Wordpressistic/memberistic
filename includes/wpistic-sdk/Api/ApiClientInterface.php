<?php
/**
 * Contract for talking to api.wpistic.com. Exists so LicenseManager and
 * UpdateClient depend on a shape, not a concrete HTTP implementation —
 * tests substitute a plain in-memory fake instead of mocking WordPress's
 * HTTP API.
 *
 * @package WPistic\Sdk
 */

namespace WPistic\Sdk\Api;

use WP_Error;

/**
 * The HTTP client contract used by the licensing and update subsystems.
 */
interface ApiClientInterface {

	/**
	 * Issue a POST request to the platform API.
	 *
	 * @param string               $path    The API path, relative to the base URL.
	 * @param array<string,mixed>  $body    The request body.
	 * @param array<string,string> $headers Extra request headers.
	 * @return array<string,mixed>|WP_Error
	 */
	public function post( string $path, array $body = array(), array $headers = array() );

	/**
	 * Issue a GET request to the platform API.
	 *
	 * @param string               $path  The API path, relative to the base URL.
	 * @param array<string,string> $query Query string parameters.
	 * @return array<string,mixed>|WP_Error
	 */
	public function get( string $path, array $query = array() );
}
