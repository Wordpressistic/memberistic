<?php
/**
 * Settings REST controller.
 *
 * Exposes the memberistic_settings option for the React Settings app:
 *  - GET  /memberistic/v1/settings        Return the current option array.
 *  - PUT  /memberistic/v1/settings        Sanitize and save partial updates.
 *  - POST /memberistic/v1/settings/pages  Create / remap branded frontend pages.
 *  - GET  /memberistic/v1/settings/pages-options  List candidate pages for dropdowns.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic\REST;

use WordPressistic\Memberistic\Admin\Settings_Page;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Settings_Controller extends REST_Controller {
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/settings',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'settings_permissions_check' ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => array( $this, 'settings_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/settings/pages',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_pages' ),
				'permission_callback' => array( $this, 'settings_permissions_check' ),
				'args'                => array(
					'remap' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/settings/pages-options',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_pages_options' ),
				'permission_callback' => array( $this, 'settings_permissions_check' ),
			)
		);
	}

	public function settings_permissions_check() {
		if ( current_user_can( 'manage_memberistic_settings' ) || current_user_can( 'manage_options' ) ) {
			return true;
		}

		return new \WP_Error(
			'memberistic_rest_forbidden',
			__( 'You are not allowed to manage Memberistic settings.', 'memberistic' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	public function get_settings() {
		$stored = get_option( 'memberistic_settings', array() );
		$stored = is_array( $stored ) ? $stored : array();

		// Pass through the existing sanitize_callback to normalize defaults.
		$normalized = Settings_Page::sanitize_settings( $stored );

		// Mask secret fields and surface which ones are locked by a
		// wp-config.php constant so the React admin can disable the
		// inputs instead of trying to overwrite them.
		$locked = array();
		foreach ( memberistic_secret_setting_keys() as $key ) {
			if ( isset( $normalized[ $key ] ) ) {
				$normalized[ $key ] = memberistic_mask_secret( (string) $normalized[ $key ] );
			}
			if ( memberistic_setting_is_locked_by_constant( $key ) ) {
				$locked[] = $key;
			}
		}
		$normalized['_locked_secrets'] = $locked;

		// Integration registry snapshot so the React Integrations tab can
		// render every module (not just WooCommerce). Underscore-prefixed,
		// so the sanitizer drops it if the app echoes it back on PUT.
		$registry = '\\WordPressistic\\Memberistic\\Integrations\\Integrations_Registry';
		if ( class_exists( $registry ) ) {
			$cards = array();
			foreach ( $registry::definitions() as $key => $def ) {
				$cards[] = array(
					'key'         => $key,
					'name'        => (string) $def['name'],
					'desc'        => (string) $def['desc'],
					'icon'        => (string) $def['icon'],
					'setting'     => (string) $def['setting'],
					'default'     => (string) $def['default'],
					'coming_soon' => ! empty( $def['coming_soon'] ),
					'available'   => $registry::is_available( $key ),
					'enabled'     => $registry::is_enabled( $key ),
					'dep_label'   => isset( $def['dep_label'] ) ? (string) $def['dep_label'] : '',
				);
			}
			$normalized['_integrations'] = $cards;
		}

		return rest_ensure_response( $normalized );
	}

	public function update_settings( $request ) {
		$incoming = $request->get_json_params();
		$incoming = is_array( $incoming ) ? $incoming : $request->get_params();
		$stored   = get_option( 'memberistic_settings', array() );
		$stored   = is_array( $stored ) ? $stored : array();

		$merged    = array_merge( $stored, $incoming );
		$sanitized = Settings_Page::sanitize_settings( $merged );

		update_option( 'memberistic_settings', $sanitized, false );

		// Return the same enriched + masked payload as GET so the React app
		// keeps its `_integrations` snapshot (and never receives raw secrets).
		return $this->get_settings();
	}

	public function create_pages( $request ) {
		$remap = (bool) $request->get_param( 'remap' );
		Settings_Page::create_required_pages( $remap );

		$stored = get_option( 'memberistic_settings', array() );
		return rest_ensure_response( is_array( $stored ) ? $stored : array() );
	}

	public function get_pages_options() {
		$pages   = get_pages( array( 'sort_column' => 'post_title', 'sort_order' => 'asc', 'number' => 500 ) );
		$options = array();

		foreach ( (array) $pages as $page ) {
			$options[] = array(
				'id'    => (int) $page->ID,
				'title' => $page->post_title ?: sprintf( '#%d', (int) $page->ID ),
				'slug'  => $page->post_name,
			);
		}

		return rest_ensure_response( $options );
	}
}
