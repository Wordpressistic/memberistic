<?php
/**
 * Saved Views REST controller.
 *
 * Per-user named filter buckets stored in user_meta — e.g. "Past-due last 7 days"
 * for the Members list, "Failed payments this month" for the Payments list, etc.
 *
 * Schema (in user_meta `memberistic_saved_views`):
 *   array<int, array{
 *     id: string,        // UUID v4
 *     context: string,   // members|payments|checkins|activity
 *     name: string,
 *     filters: array<string, scalar>,
 *     created_at: string,
 *   }>
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic\REST;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Saved_Views_Controller extends REST_Controller {
	const META_KEY  = 'memberistic_saved_views';
	const CONTEXTS  = array( 'members', 'payments', 'checkins', 'activity' );
	const MAX_VIEWS = 50;

	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/saved-views',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'user_permissions_check' ),
					'args'                => array(
						'context' => array(
							'type'     => 'string',
							'required' => false,
							'enum'     => self::CONTEXTS,
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'user_permissions_check' ),
					'args'                => array(
						'context' => array(
							'type'     => 'string',
							'required' => true,
							'enum'     => self::CONTEXTS,
						),
						'name'    => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'filters' => array(
							'type'     => 'object',
							'required' => false,
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/saved-views/(?P<id>[a-f0-9-]+)',
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete_item' ),
				'permission_callback' => array( $this, 'user_permissions_check' ),
			)
		);
	}

	public function user_permissions_check() {
		if (
			current_user_can( 'view_memberistic_dashboard' )
			|| current_user_can( 'manage_memberistic' )
			|| current_user_can( 'manage_options' )
		) {
			return true;
		}

		return new \WP_Error(
			'memberistic_rest_forbidden',
			__( 'You are not allowed to manage saved views.', 'memberistic' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	private function load() {
		$stored = get_user_meta( get_current_user_id(), self::META_KEY, true );
		return is_array( $stored ) ? $stored : array();
	}

	private function save( $views ) {
		update_user_meta( get_current_user_id(), self::META_KEY, array_values( $views ) );
	}

	public function get_items( $request ) {
		$context = sanitize_key( (string) $request->get_param( 'context' ) );
		$views   = $this->load();

		if ( $context ) {
			$views = array_values(
				array_filter(
					$views,
					static function ( $row ) use ( $context ) {
						return is_array( $row ) && ( $row['context'] ?? '' ) === $context;
					}
				)
			);
		}

		return rest_ensure_response( $views );
	}

	public function create_item( $request ) {
		$context = sanitize_key( (string) $request->get_param( 'context' ) );
		$name    = trim( (string) $request->get_param( 'name' ) );
		$filters = $request->get_param( 'filters' );
		$filters = is_array( $filters ) ? $this->sanitize_filters( $filters ) : array();

		if ( ! in_array( $context, self::CONTEXTS, true ) ) {
			return new \WP_Error( 'memberistic_view_invalid_context', __( 'Unknown saved-view context.', 'memberistic' ), array( 'status' => 400 ) );
		}

		if ( '' === $name ) {
			return new \WP_Error( 'memberistic_view_invalid_name', __( 'Saved views need a name.', 'memberistic' ), array( 'status' => 400 ) );
		}

		$views = $this->load();

		if ( count( $views ) >= self::MAX_VIEWS ) {
			return new \WP_Error(
				'memberistic_view_limit',
				sprintf(
					/* translators: %d: maximum number of saved views allowed per user */
					__( 'You already have %d saved views. Delete one before adding another.', 'memberistic' ),
					self::MAX_VIEWS
				),
				array( 'status' => 400 )
			);
		}

		$entry = array(
			'id'         => wp_generate_uuid4(),
			'context'    => $context,
			'name'       => $name,
			'filters'    => $filters,
			'created_at' => current_time( 'mysql' ),
		);

		$views[] = $entry;
		$this->save( $views );

		$response = rest_ensure_response( $entry );
		$response->set_status( 201 );
		return $response;
	}

	public function delete_item( $request ) {
		$id    = sanitize_text_field( (string) $request['id'] );
		$views = $this->load();
		$next  = array();
		$found = false;

		foreach ( $views as $view ) {
			if ( is_array( $view ) && ( $view['id'] ?? '' ) === $id ) {
				$found = true;
				continue;
			}
			$next[] = $view;
		}

		if ( ! $found ) {
			return new \WP_Error( 'memberistic_view_not_found', __( 'Saved view not found.', 'memberistic' ), array( 'status' => 404 ) );
		}

		$this->save( $next );

		return rest_ensure_response( array( 'deleted' => true ) );
	}

	/**
	 * Strip filters down to scalar values to keep stored payloads small and safe.
	 *
	 * @param array<string, mixed> $filters Raw filter map.
	 * @return array<string, scalar>
	 */
	private function sanitize_filters( $filters ) {
		$clean = array();

		foreach ( $filters as $key => $value ) {
			$key = sanitize_key( (string) $key );

			if ( '' === $key ) {
				continue;
			}

			if ( is_bool( $value ) ) {
				$clean[ $key ] = $value;
				continue;
			}

			if ( is_int( $value ) || is_float( $value ) ) {
				$clean[ $key ] = $value;
				continue;
			}

			if ( is_string( $value ) ) {
				$clean[ $key ] = sanitize_text_field( $value );
				continue;
			}

			// Drop arrays / objects — saved views only support flat filter maps.
		}

		return $clean;
	}
}
