<?php
/**
 * Plans REST controller.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic\REST;

use WordPressistic\Memberistic\Database\Plans_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plans_Controller extends REST_Controller {
	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/plans',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'admin_permissions_check' ),
					'args'                => array(
						'status' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
							'required'          => false,
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'manage_plans_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/plans/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'admin_permissions_check' ),
					'args'                => array(
						'id' => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'required'          => true,
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'manage_plans_permissions_check' ),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'manage_plans_permissions_check' ),
				),
			)
		);
	}

	public function manage_plans_permissions_check() {
		if ( current_user_can( 'manage_memberistic_plans' ) || current_user_can( 'manage_options' ) ) {
			return true;
		}

		return new \WP_Error( 'memberistic_rest_forbidden', __( 'You are not allowed to manage Memberistic plans.', 'memberistic' ), array( 'status' => rest_authorization_required_code() ) );
	}

	/**
	 * Get plans.
	 */
	public function get_items( $request ) {
		$args = array();

		if ( $request->get_param( 'status' ) ) {
			$args['status'] = sanitize_key( (string) $request->get_param( 'status' ) );
		}

		return rest_ensure_response( Plans_Repository::get_all( $args ) );
	}

	/**
	 * Get one plan.
	 */
	public function get_item( $request ) {
		$plan = Plans_Repository::get( absint( $request['id'] ) );

		if ( ! $plan ) {
			return new \WP_Error( 'memberistic_plan_not_found', __( 'Plan not found.', 'memberistic' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( $plan );
	}

	public function create_item( $request ) {
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : $request->get_params();
		$id     = Plans_Repository::create( $this->prepare_plan_data( $params, true ) );

		if ( ! $id ) {
			return new \WP_Error( 'memberistic_plan_create_failed', __( 'Plan could not be created.', 'memberistic' ), array( 'status' => 500 ) );
		}

		$response = rest_ensure_response( Plans_Repository::get( $id ) );
		$response->set_status( 201 );
		return $response;
	}

	public function update_item( $request ) {
		$id     = absint( $request['id'] );
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : $request->get_params();

		if ( ! Plans_Repository::get( $id ) ) {
			return new \WP_Error( 'memberistic_plan_not_found', __( 'Plan not found.', 'memberistic' ), array( 'status' => 404 ) );
		}

		Plans_Repository::update( $id, $this->prepare_plan_data( $params, false ) );
		return rest_ensure_response( Plans_Repository::get( $id ) );
	}

	public function delete_item( $request ) {
		$id = absint( $request['id'] );

		if ( ! Plans_Repository::get( $id ) ) {
			return new \WP_Error( 'memberistic_plan_not_found', __( 'Plan not found.', 'memberistic' ), array( 'status' => 404 ) );
		}

		if ( ! Plans_Repository::delete( $id ) ) {
			return new \WP_Error( 'memberistic_plan_delete_blocked', __( 'Plan could not be deleted because memberships are attached.', 'memberistic' ), array( 'status' => 400 ) );
		}

		return rest_ensure_response( array( 'deleted' => true ) );
	}

	private function prepare_plan_data( $params, $creating = false ) {
		$data = array();

		$defaults = array(
			'name'            => '',
			'slug'            => '',
			'description'     => '',
			'monthly_price'   => 0,
			'annual_price'    => 0,
			'included_people' => 1,
			'benefits'        => '[]',
			'settings'        => '{}',
			'is_featured'     => 0,
			'sort_order'      => 0,
			'status'          => 'active',
		);

		if ( $creating ) {
			$params = array_merge( $defaults, $params );
		}

		foreach ( array( 'name', 'description' ) as $field ) {
			if ( array_key_exists( $field, $params ) ) {
				$data[ $field ] = 'description' === $field ? sanitize_textarea_field( (string) $params[ $field ] ) : sanitize_text_field( (string) $params[ $field ] );
			}
		}

		foreach ( array( 'monthly_price', 'annual_price' ) as $field ) {
			if ( array_key_exists( $field, $params ) ) {
				$data[ $field ] = $params[ $field ];
			}
		}

		if ( array_key_exists( 'slug', $params ) ) {
			$data['slug'] = sanitize_title( (string) $params['slug'] );
		}
		if ( array_key_exists( 'included_people', $params ) ) {
			$data['included_people'] = absint( $params['included_people'] );
		}
		if ( array_key_exists( 'benefits', $params ) ) {
			$data['benefits'] = is_array( $params['benefits'] ) ? wp_json_encode( array_map( 'sanitize_text_field', $params['benefits'] ) ) : (string) $params['benefits'];
		}
		if ( array_key_exists( 'settings', $params ) ) {
			$data['settings'] = is_array( $params['settings'] ) ? wp_json_encode( $params['settings'] ) : (string) $params['settings'];
		}
		if ( array_key_exists( 'is_featured', $params ) ) {
			$data['is_featured'] = ! empty( $params['is_featured'] ) ? 1 : 0;
		}
		if ( array_key_exists( 'sort_order', $params ) ) {
			$data['sort_order'] = absint( $params['sort_order'] );
		}
		if ( array_key_exists( 'status', $params ) ) {
			$data['status'] = sanitize_key( (string) $params['status'] );
		}

		return $data;
	}
}
