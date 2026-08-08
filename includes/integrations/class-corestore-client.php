<?php
/**
 * Minimal HTTP client for the coreSTORE (Coreware) REST API.
 *
 * coreSTORE is built on the PHP Point of Sale platform, whose API is keyed
 * by a per-store API key sent as an `x-api-key` header against
 * `{store}/index.php/api/v1/...`. This client wraps exactly the surface the
 * bridge needs: find / create / update customers, and a connection test.
 *
 * Completely independent from the on-premise POS bridge (`POS_Bridge`) — that
 * one talks to our own in-house POS plugin over WP filters; this one talks
 * to the client's hosted coreSTORE over HTTPS.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CoreStore_Client {

	/** @var string e.g. https://client.corestore.online */
	private $store_url;

	/** @var string */
	private $api_key;

	public function __construct( $store_url, $api_key ) {
		$this->store_url = untrailingslashit( trim( (string) $store_url ) );
		$this->api_key   = trim( (string) $api_key );
	}

	/** Are both credentials present? */
	public function is_configured() {
		return '' !== $this->store_url && '' !== $this->api_key;
	}

	/**
	 * API base. Filterable because self-hosted / newer coreSTORE builds can
	 * expose the API without the index.php front controller.
	 */
	private function base() {
		$base = $this->store_url . '/index.php/api/v1';
		return (string) apply_filters( 'memberistic_corestore_api_base', $base, $this->store_url );
	}

	/**
	 * Perform a request.
	 *
	 * @param string     $method GET|POST|PUT
	 * @param string     $path   e.g. 'customers' or 'customers/123'
	 * @param array|null $body   JSON body for POST/PUT, query args for GET.
	 * @return array{ok:bool,code:int,body:mixed,error:string}
	 */
	public function request( $method, $path, $body = null ) {
		if ( ! $this->is_configured() ) {
			return array( 'ok' => false, 'code' => 0, 'body' => null, 'error' => __( 'coreSTORE store URL / API key not configured.', 'memberistic' ) );
		}

		$url  = $this->base() . '/' . ltrim( $path, '/' );
		$args = array(
			'method'  => strtoupper( $method ),
			'timeout' => 20,
			'headers' => array(
				'x-api-key'    => $this->api_key,
				'accept'       => 'application/json',
				'content-type' => 'application/json',
			),
		);

		if ( 'GET' === $args['method'] && is_array( $body ) && $body ) {
			$url = add_query_arg( array_map( 'rawurlencode', array_map( 'strval', $body ) ), $url );
		} elseif ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return array( 'ok' => false, 'code' => 0, 'body' => null, 'error' => $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$json = json_decode( $raw, true );

		return array(
			'ok'    => $code >= 200 && $code < 300,
			'code'  => $code,
			'body'  => null !== $json ? $json : $raw,
			'error' => $code >= 200 && $code < 300 ? '' : substr( $raw, 0, 500 ),
		);
	}

	/** Cheap connectivity + auth probe. */
	public function test_connection() {
		return $this->request( 'GET', 'customers', array( 'limit' => 1 ) );
	}

	/**
	 * Find a customer by exact email. The API's search matches broadly, so
	 * results are re-filtered for an exact (case-insensitive) email match.
	 *
	 * @return array|null Customer row or null.
	 */
	public function find_customer_by_email( $email ) {
		$email = trim( (string) $email );
		if ( '' === $email ) {
			return null;
		}
		$res = $this->request( 'GET', 'customers', array( 'search' => $email, 'limit' => 25 ) );
		if ( ! $res['ok'] || ! is_array( $res['body'] ) ) {
			return null;
		}
		// Responses may be a bare list or wrapped (e.g. {data:[...]}).
		$rows = isset( $res['body'][0] ) || array() === $res['body'] ? $res['body'] : ( $res['body']['data'] ?? $res['body']['customers'] ?? array() );
		foreach ( (array) $rows as $row ) {
			if ( is_array( $row ) && isset( $row['email'] ) && 0 === strcasecmp( trim( (string) $row['email'] ), $email ) ) {
				return $row;
			}
		}
		return null;
	}

	/** Create a customer. Returns the response envelope. */
	public function create_customer( array $data ) {
		return $this->request( 'POST', 'customers', $data );
	}

	/** Update a customer by coreSTORE person/customer id. */
	public function update_customer( $customer_id, array $data ) {
		return $this->request( 'PUT', 'customers/' . rawurlencode( (string) $customer_id ), $data );
	}

	/**
	 * Pull the id out of a customer row / create response. Different builds
	 * name it person_id, customer_id, or id.
	 */
	public static function extract_id( $row ) {
		if ( ! is_array( $row ) ) {
			return '';
		}
		foreach ( array( 'person_id', 'customer_id', 'id' ) as $key ) {
			if ( isset( $row[ $key ] ) && '' !== (string) $row[ $key ] ) {
				return (string) $row[ $key ];
			}
		}
		// Create responses sometimes wrap the row.
		foreach ( array( 'data', 'customer' ) as $wrap ) {
			if ( isset( $row[ $wrap ] ) && is_array( $row[ $wrap ] ) ) {
				$id = self::extract_id( $row[ $wrap ] );
				if ( '' !== $id ) {
					return $id;
				}
			}
		}
		return '';
	}
}
