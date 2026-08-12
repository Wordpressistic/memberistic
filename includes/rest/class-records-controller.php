<?php
/**
 * Records REST controller — read-only list endpoints for the React admin pages.
 *
 * Exposes:
 *  - GET /memberistic/v1/payments      (status, gateway, membership, search, from/to, limit)
 *  - GET /memberistic/v1/checkins      (type, membership, search, from/to, limit)
 *  - GET /memberistic/v1/activity      (type, membership, search, from/to, limit)
 *  - GET /memberistic/v1/activity/types  (list of valid activity_type slugs)
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic\REST;

use WordPressistic\Memberistic\Database\Activity_Repository;
use WordPressistic\Memberistic\Database\Checkins_Repository;
use WordPressistic\Memberistic\Database\Payments_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Records_Controller extends REST_Controller {
	/**
	 * Shared list-filter argument schema.
	 *
	 * @param array<string, mixed> $extras Extra args.
	 * @return array<string, mixed>
	 */
	private function list_args( $extras = array() ) {
		$base = array(
			'search'        => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'membership_id' => array(
				'type'              => 'integer',
				'required'          => false,
				'sanitize_callback' => 'absint',
			),
			'from'          => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'to'            => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'limit'         => array(
				'type'              => 'integer',
				'required'          => false,
				'minimum'           => 1,
				'maximum'           => 500,
				'default'           => 100,
				'sanitize_callback' => 'absint',
			),
			'offset'        => array(
				'type'              => 'integer',
				'required'          => false,
				'minimum'           => 0,
				'default'           => 0,
				'sanitize_callback' => 'absint',
			),
		);

		return array_merge( $base, $extras );
	}

	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/payments',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_payments' ),
				'permission_callback' => array( $this, 'payments_permissions_check' ),
				'args'                => $this->list_args(
					array(
						'status'          => array(
							'type'     => 'string',
							'required' => false,
							'enum'     => array( 'pending', 'completed', 'failed', 'refunded' ),
						),
						'payment_gateway' => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_key',
						),
					)
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/checkins',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_checkins' ),
				'permission_callback' => array( $this, 'checkins_permissions_check' ),
				'args'                => $this->list_args(
					array(
						'checkin_type' => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_key',
						),
					)
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/activity',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_activity' ),
				'permission_callback' => array( $this, 'admin_permissions_check' ),
				'args'                => $this->list_args(
					array(
						'activity_type' => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_key',
						),
					)
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/activity/types',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_activity_types' ),
				'permission_callback' => array( $this, 'admin_permissions_check' ),
			)
		);
	}

	public function payments_permissions_check() {
		if (
			current_user_can( 'manage_memberistic_payments' )
			|| current_user_can( 'manage_memberistic' )
			|| current_user_can( 'view_memberistic_dashboard' )
			|| current_user_can( 'manage_options' )
		) {
			return true;
		}

		return new \WP_Error(
			'memberistic_rest_forbidden',
			__( 'You are not allowed to view Memberistic payments.', 'memberistic' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	public function checkins_permissions_check() {
		if (
			current_user_can( 'memberistic_checkin_members' )
			|| current_user_can( 'manage_memberistic' )
			|| current_user_can( 'view_memberistic_dashboard' )
			|| current_user_can( 'manage_options' )
		) {
			return true;
		}

		return new \WP_Error(
			'memberistic_rest_forbidden',
			__( 'You are not allowed to view Memberistic check-ins.', 'memberistic' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	public function get_payments( $request ) {
		$args = array_filter(
			array(
				'status'          => $request->get_param( 'status' ),
				'payment_gateway' => $request->get_param( 'payment_gateway' ),
				'membership_id'   => $request->get_param( 'membership_id' ),
				'search'          => $request->get_param( 'search' ),
				'from'            => $request->get_param( 'from' ),
				'to'              => $request->get_param( 'to' ),
				'limit'           => $request->get_param( 'limit' ),
				'offset'          => $request->get_param( 'offset' ),
			),
			static function ( $value ) {
				return null !== $value && '' !== $value;
			}
		);

		$count_args = $args;
		unset( $count_args['limit'], $count_args['offset'] );

		$items    = Payments_Repository::get_all( $args );
		$total    = Payments_Repository::count_all( $count_args );
		$per_page = isset( $args['limit'] ) ? max( 1, (int) $args['limit'] ) : 100;

		$response = rest_ensure_response( $items );
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) max( 1, (int) ceil( $total / $per_page ) ) );

		return $response;
	}

	public function get_checkins( $request ) {
		$args = array_filter(
			array(
				'checkin_type'  => $request->get_param( 'checkin_type' ),
				'membership_id' => $request->get_param( 'membership_id' ),
				'search'        => $request->get_param( 'search' ),
				'from'          => $request->get_param( 'from' ),
				'to'            => $request->get_param( 'to' ),
				'limit'         => $request->get_param( 'limit' ),
			),
			static function ( $value ) {
				return null !== $value && '' !== $value;
			}
		);

		return rest_ensure_response( Checkins_Repository::get_all( $args ) );
	}

	public function get_activity( $request ) {
		$args = array_filter(
			array(
				'activity_type' => $request->get_param( 'activity_type' ),
				'membership_id' => $request->get_param( 'membership_id' ),
				'search'        => $request->get_param( 'search' ),
				'from'          => $request->get_param( 'from' ),
				'to'            => $request->get_param( 'to' ),
				'limit'         => $request->get_param( 'limit' ),
			),
			static function ( $value ) {
				return null !== $value && '' !== $value;
			}
		);

		return rest_ensure_response( Activity_Repository::get_all( $args ) );
	}

	public function get_activity_types() {
		return rest_ensure_response( Activity_Repository::types() );
	}
}
