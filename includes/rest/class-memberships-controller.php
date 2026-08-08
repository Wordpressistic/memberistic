<?php
/**
 * Memberships REST controller.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic\REST;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Memberships_Controller extends REST_Controller {
	/**
	 * Shared `id` URL argument schema.
	 *
	 * @return array<string, mixed>
	 */
	private function id_arg() {
		return array(
			'id' => array(
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
				'validate_callback' => static function ( $value ) {
					return is_numeric( $value ) && (int) $value > 0;
				},
			),
		);
	}

	/**
	 * Register membership routes.
	 */
	public function register_routes() {
		// Profile image upload — current logged-in member uploads/replaces
		// their own photo. Lands in the WP media library, attachment id
		// stored in user_meta. Pulled by the account dashboard avatar +
		// the public verify page.
		register_rest_route(
			$this->namespace,
			'/profile/image',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'upload_profile_image' ),
					'permission_callback' => array( $this, 'member_self_permissions_check' ),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_profile_image' ),
					'permission_callback' => array( $this, 'member_self_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/memberships',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'admin_permissions_check' ),
					'args'                => array(
						'search'           => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'status'           => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_key',
						),
						'plan_id'          => array(
							'type'              => 'integer',
							'required'          => false,
							'sanitize_callback' => 'absint',
						),
						'billing_cycle'    => array(
							'type'     => 'string',
							'required' => false,
							'enum'     => array( 'monthly', 'annual' ),
						),
						'waiver_status'    => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_key',
						),
						'expiring_in_days' => array(
							'type'              => 'integer',
							'required'          => false,
							'minimum'           => 1,
							'maximum'           => 365,
							'sanitize_callback' => 'absint',
						),
						'checked_in_today' => array(
							'type'     => 'boolean',
							'required' => false,
						),
						'created_from'     => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'created_to'       => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'limit'            => array(
							'type'              => 'integer',
							'required'          => false,
							'minimum'           => 1,
							'maximum'           => 500,
							'default'           => 100,
							'sanitize_callback' => 'absint',
						),
						'offset'           => array(
							'type'              => 'integer',
							'required'          => false,
							'minimum'           => 0,
							'default'           => 0,
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'manage_members_permissions_check' ),
					'args'                => array(
						'plan_id'       => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'full_name'     => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'email'         => array(
							'type'              => 'string',
							'required'          => false,
							'format'            => 'email',
							'sanitize_callback' => 'sanitize_email',
						),
						'phone'         => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'billing_cycle' => array(
							'type'              => 'string',
							'required'          => false,
							'enum'              => array( 'monthly', 'annual' ),
							'default'           => 'monthly',
						),
						'status'        => array(
							'type'     => 'string',
							'required' => false,
							'enum'     => array( 'pending', 'active', 'past_due', 'expired', 'cancelled', 'paused', 'comped', 'trial', 'suspended', 'needs_review' ),
							'default'  => 'pending',
						),
						'start_date'    => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'renewal_date'  => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'waiver_status' => array(
							'type'     => 'string',
							'required' => false,
							'enum'     => array( 'missing', 'signed', 'expired', 'needs_review', 'rejected' ),
							'default'  => 'missing',
						),
						'notes'         => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_textarea_field',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/memberships/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'pii_permissions_check' ),
					'args'                => $this->id_arg(),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'manage_members_permissions_check' ),
					'args'                => $this->id_arg(),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'manage_members_permissions_check' ),
					'args'                => $this->id_arg(),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/memberships/(?P<id>[\d]+)/people',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_people' ),
					'permission_callback' => array( $this, 'pii_permissions_check' ),
					'args'                => $this->id_arg(),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'add_person' ),
					'permission_callback' => array( $this, 'manage_members_permissions_check' ),
					'args'                => array_merge(
						$this->id_arg(),
						array(
							'full_name'    => array(
								'type'              => 'string',
								'required'          => true,
								'sanitize_callback' => 'sanitize_text_field',
							),
							'email'        => array(
								'type'              => 'string',
								'required'          => false,
								'format'            => 'email',
								'sanitize_callback' => 'sanitize_email',
							),
							'phone'        => array(
								'type'              => 'string',
								'required'          => false,
								'sanitize_callback' => 'sanitize_text_field',
							),
							'relationship' => array(
								'type'              => 'string',
								'required'          => false,
								'sanitize_callback' => 'sanitize_text_field',
							),
							'waiver_status' => array(
								'type'     => 'string',
								'required' => false,
								'enum'     => array( 'missing', 'signed', 'expired', 'needs_review', 'rejected' ),
								'default'  => 'missing',
							),
						)
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/memberships/(?P<id>[\d]+)/payments',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_payments' ),
					'permission_callback' => array( $this, 'pii_permissions_check' ),
					'args'                => $this->id_arg(),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'add_payment' ),
					'permission_callback' => array( $this, 'manage_payments_permissions_check' ),
					'args'                => array_merge(
						$this->id_arg(),
						array(
							'amount'         => array(
								'type'     => 'number',
								'required' => true,
								'minimum'  => 0,
							),
							'currency'       => array(
								'type'              => 'string',
								'required'          => false,
								'sanitize_callback' => 'sanitize_text_field',
								'default'           => 'USD',
							),
							'payment_method' => array(
								'type'              => 'string',
								'required'          => false,
								'sanitize_callback' => 'sanitize_key',
								'default'           => 'manual',
							),
							'status'         => array(
								'type'     => 'string',
								'required' => false,
								'enum'     => array( 'pending', 'completed', 'failed', 'refunded' ),
								'default'  => 'completed',
							),
							'paid_at'        => array(
								'type'              => 'string',
								'required'          => false,
								'sanitize_callback' => 'sanitize_text_field',
							),
						)
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/memberships/(?P<id>[\d]+)/activity',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_activity' ),
				'permission_callback' => array( $this, 'pii_permissions_check' ),
				'args'                => $this->id_arg(),
			)
		);

		register_rest_route(
			$this->namespace,
			'/memberships/(?P<id>[\d]+)/bookings',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_bookings' ),
				'permission_callback' => array( $this, 'pii_permissions_check' ),
				'args'                => $this->id_arg(),
			)
		);

		foreach ( array( 'renew', 'cancel', 'upgrade' ) as $action ) {
			$args = $this->id_arg();

			if ( 'upgrade' === $action ) {
				$args['plan_id'] = array(
					'type'              => 'integer',
					'required'          => true,
					'sanitize_callback' => 'absint',
				);
			}

			register_rest_route(
				$this->namespace,
				'/memberships/(?P<id>[\d]+)/' . $action,
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, $action . '_membership' ),
					'permission_callback' => array( $this, 'manage_members_permissions_check' ),
					'args'                => $args,
				)
			);
		}

		register_rest_route(
			$this->namespace,
			'/memberships/(?P<id>[\d]+)/checkins',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'add_checkin' ),
				'permission_callback' => array( $this, 'checkin_permissions_check' ),
				'args'                => array_merge(
					$this->id_arg(),
					array(
						'person_id'    => array(
							'type'              => 'integer',
							'required'          => false,
							'sanitize_callback' => 'absint',
						),
						'checkin_type' => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_key',
							'default'           => 'walk_in',
						),
						'notes'        => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_textarea_field',
						),
					)
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/memberships/(?P<id>[\d]+)/notes',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'add_note' ),
				'permission_callback' => array( $this, 'notes_permissions_check' ),
				'args'                => array_merge(
					$this->id_arg(),
					array(
						'note' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_textarea_field',
						),
					)
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/memberships/(?P<id>[\d]+)/emails',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'send_email' ),
				'permission_callback' => array( $this, 'manage_members_permissions_check' ),
				'args'                => array_merge(
					$this->id_arg(),
					array(
						'template' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_key',
						),
					)
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/email-templates',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_email_templates' ),
				'permission_callback' => array( $this, 'admin_permissions_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/people/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_person' ),
					'permission_callback' => array( $this, 'manage_members_permissions_check' ),
					'args'                => array_merge(
						$this->id_arg(),
						array(
							'full_name'         => array(
								'type'              => 'string',
								'required'          => false,
								'sanitize_callback' => 'sanitize_text_field',
							),
							'email'             => array(
								'type'              => 'string',
								'required'          => false,
								'format'            => 'email',
								'sanitize_callback' => 'sanitize_email',
							),
							'phone'             => array(
								'type'              => 'string',
								'required'          => false,
								'sanitize_callback' => 'sanitize_text_field',
							),
							'date_of_birth'     => array(
								'type'              => 'string',
								'required'          => false,
								'sanitize_callback' => 'sanitize_text_field',
							),
							'relationship'      => array(
								'type'              => 'string',
								'required'          => false,
								'sanitize_callback' => 'sanitize_text_field',
							),
							'waiver_status'     => array(
								'type'     => 'string',
								'required' => false,
								'enum'     => array( 'missing', 'signed', 'expired', 'needs_review', 'rejected' ),
							),
							'waiver_signed_at'  => array(
								'type'              => 'string',
								'required'          => false,
								'sanitize_callback' => 'sanitize_text_field',
							),
							'waiver_expires_at' => array(
								'type'              => 'string',
								'required'          => false,
								'sanitize_callback' => 'sanitize_text_field',
							),
							'status'            => array(
								'type'     => 'string',
								'required' => false,
								'enum'     => array( 'active', 'inactive', 'removed' ),
							),
							'notes'             => array(
								'type'              => 'string',
								'required'          => false,
								'sanitize_callback' => 'sanitize_textarea_field',
							),
						)
					),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_person' ),
					'permission_callback' => array( $this, 'manage_members_permissions_check' ),
					'args'                => $this->id_arg(),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/memberships/stats',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_stats' ),
				'permission_callback' => array( $this, 'admin_permissions_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/memberships/bulk-waiver',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'bulk_waiver' ),
				'permission_callback' => array( $this, 'manage_members_permissions_check' ),
				'args'                => array(
					'membership_ids' => array(
						'type'     => 'array',
						'required' => true,
						'items'    => array( 'type' => 'integer' ),
					),
					'waiver_status'  => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => array( 'missing', 'signed', 'expired', 'needs_review', 'rejected' ),
					),
					'scope'          => array(
						'type'     => 'string',
						'required' => false,
						'enum'     => array( 'all', 'primary' ),
						'default'  => 'all',
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/plans/stats',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_plan_stats' ),
				'permission_callback' => array( $this, 'admin_permissions_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/payments/stats',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_payments_stats' ),
				'permission_callback' => array( $this, 'admin_permissions_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/emails/directory',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_email_directory' ),
				'permission_callback' => array( $this, 'pii_permissions_check' ),
				'args'                => array(
					'search'        => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
					'status'        => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_key' ),
					'waiver_status' => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_key' ),
					'limit'         => array( 'type' => 'integer', 'required' => false, 'minimum' => 1, 'maximum' => 1000, 'default' => 200, 'sanitize_callback' => 'absint' ),
					'offset'        => array( 'type' => 'integer', 'required' => false, 'minimum' => 0, 'default' => 0, 'sanitize_callback' => 'absint' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/emails/stats',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_email_stats' ),
				'permission_callback' => array( $this, 'admin_permissions_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/webhooks/stripe',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'stripe_webhook' ),
				'permission_callback' => array( $this, 'public_permissions_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/webhooks/woocommerce',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'woocommerce_webhook' ),
				'permission_callback' => array( $this, 'public_permissions_check' ),
			)
		);
	}

	/**
	 * Members may manage their OWN profile image only. We additionally require
	 * the calling user to be linked to a Memberistic membership (or hold an
	 * admin cap). Prevents random subscribers from blasting uploads into the
	 * site's media library via this endpoint.
	 */
	public function member_self_permissions_check() {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error(
				'memberistic_rest_forbidden',
				__( 'You must be logged in.', 'memberistic' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		$user_id = get_current_user_id();
		// Require an active Memberistic linkage. NOTE: this previously also
		// fell back to current_user_can('edit_user', $user_id) when the
		// membership helper was missing — but map_meta_cap() grants
		// edit_user unconditionally when a user checks it against their OWN
		// id, so that fallback made the whole gate a no-op for any logged-in
		// user regardless of role. memberistic_user_has_membership() is now
		// a real, always-defined function (see includes/utilities/global-
		// functions.php), so there's no missing-helper case to fall back for.
		if ( memberistic_user_has_membership( $user_id ) ) {
			return true;
		}
		return new \WP_Error(
			'memberistic_rest_forbidden',
			__( 'You do not have an active membership.', 'memberistic' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	public function manage_members_permissions_check() {
		if ( current_user_can( 'manage_memberistic_members' ) || current_user_can( 'create_memberistic_members' ) || current_user_can( 'manage_options' ) ) {
			return true;
		}

		return new \WP_Error(
			'memberistic_rest_forbidden',
			__( 'You are not allowed to manage Memberistic memberships.', 'memberistic' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	public function manage_payments_permissions_check() {
		if ( current_user_can( 'manage_memberistic_payments' ) || current_user_can( 'manage_options' ) ) {
			return true;
		}

		return new \WP_Error( 'memberistic_rest_forbidden', __( 'You are not allowed to manage Memberistic payments.', 'memberistic' ), array( 'status' => rest_authorization_required_code() ) );
	}

	public function checkin_permissions_check() {
		if ( current_user_can( 'memberistic_checkin_members' ) || current_user_can( 'manage_options' ) ) {
			return true;
		}

		return new \WP_Error( 'memberistic_rest_forbidden', __( 'You are not allowed to check in Memberistic members.', 'memberistic' ), array( 'status' => rest_authorization_required_code() ) );
	}

	public function notes_permissions_check() {
		if ( current_user_can( 'memberistic_add_notes' ) || current_user_can( 'manage_options' ) ) {
			return true;
		}

		return new \WP_Error( 'memberistic_rest_forbidden', __( 'You are not allowed to add Memberistic notes.', 'memberistic' ), array( 'status' => rest_authorization_required_code() ) );
	}

	public function get_items( $request ) {
		$args = array_filter(
			array(
				'search'           => $request->get_param( 'search' ),
				'status'           => $request->get_param( 'status' ),
				'plan_id'          => $request->get_param( 'plan_id' ),
				'billing_cycle'    => $request->get_param( 'billing_cycle' ),
				'waiver_status'    => $request->get_param( 'waiver_status' ),
				'expiring_in_days' => $request->get_param( 'expiring_in_days' ),
				'checked_in_today' => $request->get_param( 'checked_in_today' ),
				'created_from'     => $request->get_param( 'created_from' ),
				'created_to'       => $request->get_param( 'created_to' ),
				'limit'            => $request->get_param( 'limit' ),
				'offset'           => $request->get_param( 'offset' ),
			),
			static function ( $value ) {
				return null !== $value && '' !== $value && false !== $value;
			}
		);

		$count_args = $args;
		unset( $count_args['limit'], $count_args['offset'] );

		$items    = \WordPressistic\Memberistic\Database\Memberships_Repository::get_all( $args );
		$total    = \WordPressistic\Memberistic\Database\Memberships_Repository::count_all( $count_args );
		$per_page = isset( $args['limit'] ) ? max( 1, (int) $args['limit'] ) : 100;

		$response = rest_ensure_response( $items );
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) max( 1, (int) ceil( $total / $per_page ) ) );

		return $response;
	}

	public function get_stats() {
		$counts        = \WordPressistic\Memberistic\Database\Memberships_Repository::counts_by_status();
		$now_year      = (int) gmdate( 'Y' );
		$now_month     = (int) gmdate( 'm' );
		$prev_ts       = strtotime( '-1 month', strtotime( gmdate( 'Y-m-01' ) ) );
		$prev_year     = (int) gmdate( 'Y', $prev_ts );
		$prev_month    = (int) gmdate( 'm', $prev_ts );
		$new_this      = \WordPressistic\Memberistic\Database\Memberships_Repository::count_created_in_month( $now_year, $now_month );
		$new_prev      = \WordPressistic\Memberistic\Database\Memberships_Repository::count_created_in_month( $prev_year, $prev_month );

		$growth = null;
		if ( $new_prev > 0 ) {
			$growth = round( ( ( $new_this - $new_prev ) / $new_prev ) * 100, 1 );
		} elseif ( $new_this > 0 ) {
			$growth = 100.0;
		}

		return rest_ensure_response(
			array(
				'counts'           => $counts,
				'new_this_month'   => $new_this,
				'new_prev_month'   => $new_prev,
				'new_growth_pct'   => $growth,
				'waiver_missing'   => \WordPressistic\Memberistic\Database\Memberships_Repository::count_waiver_missing(),
				'expiring_30_days' => \WordPressistic\Memberistic\Database\Memberships_Repository::count_expiring_soon( 30 ),
			)
		);
	}

	public function bulk_waiver( $request ) {
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : $request->get_params();

		$ids    = isset( $params['membership_ids'] ) && is_array( $params['membership_ids'] ) ? array_map( 'absint', $params['membership_ids'] ) : array();
		$status = isset( $params['waiver_status'] ) ? sanitize_key( (string) $params['waiver_status'] ) : '';
		$scope  = isset( $params['scope'] ) ? sanitize_key( (string) $params['scope'] ) : 'all';

		$allowed = array( 'missing', 'signed', 'expired', 'needs_review', 'rejected' );
		if ( ! in_array( $status, $allowed, true ) ) {
			return new \WP_Error( 'memberistic_bulk_waiver_status_invalid', __( 'Invalid waiver status.', 'memberistic' ), array( 'status' => 400 ) );
		}
		$ids = array_values( array_filter( array_unique( $ids ) ) );
		if ( empty( $ids ) ) {
			return new \WP_Error( 'memberistic_bulk_waiver_no_ids', __( 'No memberships were selected.', 'memberistic' ), array( 'status' => 400 ) );
		}

		$updated      = 0;
		$people_count = 0;
		$signed_at    = current_time( 'mysql' );

		foreach ( $ids as $mid ) {
			$mid = absint( $mid );
			if ( ! $mid ) {
				continue;
			}
			$people = \WordPressistic\Memberistic\Database\People_Repository::get_by_membership( $mid );
			if ( empty( $people ) ) {
				continue;
			}
			foreach ( $people as $person ) {
				if ( 'primary' === $scope && 'primary' !== ( $person['role'] ?? '' ) ) {
					continue;
				}
				$data = array( 'waiver_status' => $status );
				if ( 'signed' === $status && empty( $person['waiver_signed_at'] ) ) {
					$data['waiver_signed_at'] = $signed_at;
				}
				\WordPressistic\Memberistic\Database\People_Repository::update( (int) $person['id'], $data );
				$people_count++;
			}

			$activity_type = 'signed' === $status ? 'waiver_signed' : ( in_array( $status, array( 'expired', 'needs_review', 'rejected' ), true ) ? 'waiver_expired' : 'membership_status_changed' );
			\WordPressistic\Memberistic\Database\Activity_Repository::log(
				array(
					'membership_id' => $mid,
					'activity_type' => $activity_type,
					'title'         => sprintf(
						/* translators: %s: waiver status. */
						__( 'Bulk update — waiver set to %s', 'memberistic' ),
						str_replace( '_', ' ', $status )
					),
					'created_by'    => get_current_user_id(),
				)
			);
			$updated++;
		}

		return rest_ensure_response(
			array(
				'updated_memberships' => $updated,
				'updated_people'      => $people_count,
				'waiver_status'       => $status,
			)
		);
	}

	public function get_plan_stats() {
		return rest_ensure_response( \WordPressistic\Memberistic\Database\Memberships_Repository::counts_per_plan() );
	}

	public function get_payments_stats() {
		return rest_ensure_response( \WordPressistic\Memberistic\Database\Payments_Repository::stats_summary() );
	}

	public function get_email_directory( $request ) {
		global $wpdb;

		$search        = $request->get_param( 'search' );
		$status        = $request->get_param( 'status' );
		$waiver_status = $request->get_param( 'waiver_status' );
		$limit         = max( 1, min( 1000, absint( $request->get_param( 'limit' ) ) ?: 200 ) );
		$offset        = max( 0, absint( $request->get_param( 'offset' ) ) );

		$people      = $wpdb->prefix . 'memberistic_people';
		$memberships = $wpdb->prefix . 'memberistic_memberships';
		$plans       = $wpdb->prefix . 'memberistic_plans';

		$where      = array( "p.email <> ''" );
		$where_args = array();

		if ( $status ) {
			$where[]      = 'm.status = %s';
			$where_args[] = sanitize_key( (string) $status );
		}
		if ( $waiver_status ) {
			$where[]      = 'p.waiver_status = %s';
			$where_args[] = sanitize_key( (string) $waiver_status );
		}
		if ( $search ) {
			$like         = '%' . $wpdb->esc_like( sanitize_text_field( (string) $search ) ) . '%';
			$where[]      = '(p.full_name LIKE %s OR p.email LIKE %s OR p.phone LIKE %s OR m.membership_uuid LIKE %s)';
			$where_args[] = $like;
			$where_args[] = $like;
			$where_args[] = $like;
			$where_args[] = $like;
		}

		$where_sql = ' WHERE ' . implode( ' AND ', $where );
		$count_sql = "SELECT COUNT(*) FROM {$people} p LEFT JOIN {$memberships} m ON m.id = p.membership_id LEFT JOIN {$plans} pl ON pl.id = m.plan_id {$where_sql}";
		$rows_sql  = "SELECT p.id, p.full_name, p.email, p.phone, p.role, p.waiver_status, p.waiver_signed_at, p.waiver_expires_at, p.created_at AS person_created_at, m.id AS membership_id, m.membership_uuid, m.status AS membership_status, m.billing_cycle, m.renewal_date, pl.name AS plan_name FROM {$people} p LEFT JOIN {$memberships} m ON m.id = p.membership_id LEFT JOIN {$plans} pl ON pl.id = m.plan_id {$where_sql} ORDER BY p.full_name ASC LIMIT {$limit} OFFSET {$offset}";

		if ( $where_args ) {
			$count_sql = $wpdb->prepare( $count_sql, $where_args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$rows_sql  = $wpdb->prepare( $rows_sql, $where_args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		$total = (int) $wpdb->get_var( $count_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows  = $wpdb->get_results( $rows_sql, ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$response = rest_ensure_response( $rows );
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) max( 1, (int) ceil( $total / $limit ) ) );

		return $response;
	}

	public function get_email_stats() {
		global $wpdb;

		$logs        = $wpdb->prefix . 'memberistic_email_logs';
		$people      = $wpdb->prefix . 'memberistic_people';
		$memberships = $wpdb->prefix . 'memberistic_memberships';

		// Site-local cutoffs anchored on current_time('timestamp'). Was UTC
		// gmdate — mis-rolled around evening at Mesa AZ (UTC-7) and other
		// non-UTC sites, flipping today's stats to "tomorrow".
		$now_ts      = current_time( 'timestamp' );
		$today_start = current_time( 'Y-m-d 00:00:00' );
		$week_start  = wp_date( 'Y-m-d 00:00:00', strtotime( '-6 days', $now_ts ) );
		$month_start = wp_date( 'Y-m-01 00:00:00', $now_ts );

		$sent_today = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$logs} WHERE sent_at >= %s", $today_start ) );
		$sent_week  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$logs} WHERE sent_at >= %s", $week_start ) );
		$sent_month = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$logs} WHERE sent_at >= %s", $month_start ) );
		$total_sent = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$logs}" );
		$total_failed = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$logs} WHERE status <> 'sent'" );

		$with_email = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$people} WHERE email <> ''" );
		$without_email = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$people} WHERE email = '' OR email IS NULL" );

		$delivery_rate = $total_sent > 0 ? round( ( ( $total_sent - $total_failed ) / $total_sent ) * 100, 1 ) : null;

		return rest_ensure_response(
			array(
				'sent_today'         => $sent_today,
				'sent_this_week'     => $sent_week,
				'sent_this_month'    => $sent_month,
				'total_sent'         => $total_sent,
				'total_failed'       => $total_failed,
				'delivery_rate_pct'  => $delivery_rate,
				'people_with_email'  => $with_email,
				'people_without_email' => $without_email,
			)
		);
	}

	public function get_item( $request ) {
		$id         = absint( $request['id'] );
		$membership = \WordPressistic\Memberistic\Database\Memberships_Repository::get_with_summary( $id );

		if ( ! $membership ) {
			return new \WP_Error( 'memberistic_membership_not_found', __( 'Membership not found.', 'memberistic' ), array( 'status' => 404 ) );
		}

		$membership['people']   = \WordPressistic\Memberistic\Database\People_Repository::get_by_membership( $id );
		$membership['payments'] = \WordPressistic\Memberistic\Database\Payments_Repository::get_by_membership( $id );
		$membership['checkins'] = \WordPressistic\Memberistic\Database\Checkins_Repository::get_by_membership( $id );
		$membership['notes']    = \WordPressistic\Memberistic\Database\Notes_Repository::get_by_membership( $id );
		$membership['activity'] = \WordPressistic\Memberistic\Database\Activity_Repository::get_by_membership( $id );

		return rest_ensure_response( $membership );
	}

	public function create_item( $request ) {
		$params    = $request->get_json_params();
		$params    = is_array( $params ) ? $params : $request->get_params();
		$plan_id   = absint( isset( $params['plan_id'] ) ? $params['plan_id'] : 0 );
		$full_name = isset( $params['full_name'] ) ? sanitize_text_field( (string) $params['full_name'] ) : '';
		$plan      = $plan_id ? \WordPressistic\Memberistic\Database\Plans_Repository::get( $plan_id ) : null;

		if ( ! $plan || '' === $full_name ) {
			return new \WP_Error( 'memberistic_invalid_membership', __( 'A valid plan and primary member name are required.', 'memberistic' ), array( 'status' => 400 ) );
		}

		$membership_id = \WordPressistic\Memberistic\Database\Memberships_Repository::create(
			array(
				'plan_id'        => $plan_id,
				'billing_cycle'  => isset( $params['billing_cycle'] ) ? sanitize_key( (string) $params['billing_cycle'] ) : 'monthly',
				'status'         => isset( $params['status'] ) ? sanitize_key( (string) $params['status'] ) : 'pending',
				'start_date'     => isset( $params['start_date'] ) ? sanitize_text_field( (string) $params['start_date'] ) : '',
				'renewal_date'   => isset( $params['renewal_date'] ) ? sanitize_text_field( (string) $params['renewal_date'] ) : '',
				'payment_source' => 'staff',
				'notes'          => isset( $params['notes'] ) ? sanitize_textarea_field( (string) $params['notes'] ) : '',
				'created_by'     => get_current_user_id(),
			)
		);

		if ( ! $membership_id ) {
			return new \WP_Error( 'memberistic_membership_create_failed', __( 'Membership could not be created.', 'memberistic' ), array( 'status' => 500 ) );
		}

		$person_id = \WordPressistic\Memberistic\Database\People_Repository::create(
			array(
				'membership_id' => $membership_id,
				'role'          => 'primary',
				'full_name'     => $full_name,
				'email'         => isset( $params['email'] ) ? sanitize_email( (string) $params['email'] ) : '',
				'phone'         => isset( $params['phone'] ) ? sanitize_text_field( (string) $params['phone'] ) : '',
				'waiver_status' => isset( $params['waiver_status'] ) ? sanitize_key( (string) $params['waiver_status'] ) : 'missing',
				'status'        => 'active',
			)
		);

		\WordPressistic\Memberistic\Database\Activity_Repository::log(
			array(
				'membership_id' => $membership_id,
				'person_id'     => $person_id,
				'activity_type' => 'membership_created',
				'title'         => __( 'Membership created', 'memberistic' ),
				'created_by'    => get_current_user_id(),
			)
		);

		// Give the new member a WP login + a "set your password" email so they
		// can sign in and reach their digital card. Staff-created members used
		// to get a membership row with no account at all. Only provisions when
		// an email is on file; idempotent and won't email twice.
		if ( ! empty( $params['email'] ) && is_email( (string) $params['email'] ) ) {
			\WordPressistic\Memberistic\Account_Provisioner::ensure_user_for_membership( (int) $membership_id, true );
		}

		$response = rest_ensure_response( \WordPressistic\Memberistic\Database\Memberships_Repository::get_with_summary( $membership_id ) );
		$response->set_status( 201 );

		return $response;
	}

	public function update_item( $request ) {
		$id     = absint( $request['id'] );
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : $request->get_params();

		$existing = \WordPressistic\Memberistic\Database\Memberships_Repository::get( $id );
		if ( ! $existing ) {
			return new \WP_Error( 'memberistic_membership_not_found', __( 'Membership not found.', 'memberistic' ), array( 'status' => 404 ) );
		}

		// Audit C11: previously this loop accepted every field including
		// stripe_customer_id, stripe_subscription_id, woo_subscription_id,
		// pos_customer_id, primary_user_id — which let any caller with
		// the manage_members capability (incl. memberistic_staff) rewrite
		// a membership's Stripe linkage and hijack it.
		//
		// The fields below are the ONLY ones safe to update from a UI
		// edit. Stripe / WooCommerce / POS identity fields are written
		// only by their respective webhook handlers (which verify the
		// request is genuinely from the upstream provider) and are
		// removed from the allow-list here. primary_user_id is also
		// removed — re-parenting a membership to a different WP user
		// must go through the dedicated /memberships/{id}/people
		// endpoints, which audit-trail the change properly.
		$allowed_fields = array(
			'plan_id',
			'billing_cycle',
			'status',
			'start_date',
			'renewal_date',
			'end_date',
			'cancelled_at',
			'notes',
			'payment_source',
		);
		$allowed_fields = apply_filters( 'memberistic_membership_updatable_fields', $allowed_fields );

		$data            = array();
		$rejected_fields = array();
		foreach ( $params as $key => $value ) {
			if ( in_array( $key, array( 'id', 'membership_id' ), true ) ) {
				continue; // route param, ignore
			}
			if ( in_array( $key, $allowed_fields, true ) ) {
				$data[ $key ] = $value;
			} else {
				$rejected_fields[] = $key;
			}
		}

		// If the caller tried to write any locked field, refuse the
		// whole request so they get a clear signal rather than silent
		// partial success.
		if ( ! empty( $rejected_fields ) ) {
			return new \WP_Error(
				'memberistic_membership_update_forbidden_field',
				sprintf(
					/* translators: %s = comma-separated field names */
					__( 'These fields cannot be updated via this endpoint: %s.', 'memberistic' ),
					implode( ', ', $rejected_fields )
				),
				array( 'status' => 400, 'forbidden_fields' => $rejected_fields )
			);
		}

		// Route status changes through change_status() so the canonical
		// memberistic_membership_status_changed hook fires (Stripe cancel
		// propagation, coreSTORE bridge). A raw update() here used to skip
		// the hook, so cancelling from the members app never reached Stripe.
		$new_status = null;
		if ( array_key_exists( 'status', $data ) ) {
			$new_status = \WordPressistic\Memberistic\memberistic_validate_status( $data['status'], (string) $existing['status'] );
			unset( $data['status'] );
		}

		if ( ! empty( $data ) ) {
			\WordPressistic\Memberistic\Database\Memberships_Repository::update( $id, $data );
		}

		if ( null !== $new_status && $new_status !== (string) $existing['status'] ) {
			\WordPressistic\Memberistic\Database\Memberships_Repository::change_status( $id, $new_status );
		}

		return rest_ensure_response( \WordPressistic\Memberistic\Database\Memberships_Repository::get_with_summary( $id ) );
	}

	public function delete_item( $request ) {
		$id = absint( $request['id'] );

		if ( ! \WordPressistic\Memberistic\Database\Memberships_Repository::get( $id ) ) {
			return new \WP_Error( 'memberistic_membership_not_found', __( 'Membership not found.', 'memberistic' ), array( 'status' => 404 ) );
		}

		\WordPressistic\Memberistic\Database\Memberships_Repository::delete( $id );
		return rest_ensure_response( array( 'deleted' => true ) );
	}

	public function upload_profile_image( $request ) {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return new \WP_Error( 'memberistic_not_logged_in', __( 'Please log in to update your profile photo.', 'memberistic' ), array( 'status' => 401 ) );
		}
		if ( empty( $_FILES['file'] ) ) {
			return new \WP_Error( 'memberistic_no_file', __( 'No file uploaded.', 'memberistic' ), array( 'status' => 400 ) );
		}
		// Restrict to image MIME types.
		$file = $_FILES['file'];
		$check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );
		if ( empty( $check['type'] ) || 0 !== strpos( $check['type'], 'image/' ) ) {
			return new \WP_Error( 'memberistic_invalid_image', __( 'Please upload a JPG, PNG, GIF, or WebP image.', 'memberistic' ), array( 'status' => 400 ) );
		}
		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		if ( ! function_exists( 'media_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}
		$attachment_id = media_handle_upload( 'file', 0 );
		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}
		\WordPressistic\Memberistic\Utilities\Verification::set_profile_image_id( $user_id, $attachment_id );
		return rest_ensure_response( array(
			'success'       => true,
			'attachment_id' => (int) $attachment_id,
			'url'           => wp_get_attachment_image_url( $attachment_id, 'medium' ),
		) );
	}

	public function delete_profile_image( $request ) {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return new \WP_Error( 'memberistic_not_logged_in', '', array( 'status' => 401 ) );
		}
		\WordPressistic\Memberistic\Utilities\Verification::set_profile_image_id( $user_id, 0 );
		return rest_ensure_response( array( 'success' => true ) );
	}

	public function get_people( $request ) {
		return rest_ensure_response( \WordPressistic\Memberistic\Database\People_Repository::get_by_membership( absint( $request['id'] ) ) );
	}

	public function add_person( $request ) {
		$membership_id = absint( $request['id'] );
		$params        = $request->get_json_params();
		$params        = is_array( $params ) ? $params : $request->get_params();

		if ( ! \WordPressistic\Memberistic\Database\Memberships_Repository::get( $membership_id ) ) {
			return new \WP_Error( 'memberistic_membership_not_found', __( 'Membership not found.', 'memberistic' ), array( 'status' => 404 ) );
		}

		if ( ! \WordPressistic\Memberistic\Database\People_Repository::can_add_person( $membership_id ) ) {
			return new \WP_Error( 'memberistic_person_limit_reached', __( 'This membership plan has reached its included people limit.', 'memberistic' ), array( 'status' => 400 ) );
		}

		$full_name = isset( $params['full_name'] ) ? sanitize_text_field( (string) $params['full_name'] ) : '';

		if ( '' === $full_name ) {
			return new \WP_Error( 'memberistic_invalid_person', __( 'Full name is required.', 'memberistic' ), array( 'status' => 400 ) );
		}

		$person_id = \WordPressistic\Memberistic\Database\People_Repository::create(
			array(
				'membership_id' => $membership_id,
				'role'          => 'linked',
				'full_name'     => $full_name,
				'email'         => isset( $params['email'] ) ? sanitize_email( (string) $params['email'] ) : '',
				'phone'         => isset( $params['phone'] ) ? sanitize_text_field( (string) $params['phone'] ) : '',
				'relationship'  => isset( $params['relationship'] ) ? sanitize_text_field( (string) $params['relationship'] ) : '',
				'waiver_status' => isset( $params['waiver_status'] ) ? sanitize_key( (string) $params['waiver_status'] ) : 'missing',
				'status'        => 'active',
			)
		);

		if ( ! $person_id ) {
			return new \WP_Error( 'memberistic_person_create_failed', __( 'Linked member could not be created.', 'memberistic' ), array( 'status' => 500 ) );
		}

		\WordPressistic\Memberistic\Database\Activity_Repository::log(
			array(
				'membership_id' => $membership_id,
				'person_id'     => $person_id,
				'activity_type' => 'linked_member_added',
				'title'         => __( 'Linked member added', 'memberistic' ),
				'created_by'    => get_current_user_id(),
			)
		);

		\WordPressistic\Memberistic\Emails\Email_Service::send_membership_email(
			$membership_id,
			'linked_member_added',
			array( 'linked_member_name' => $full_name )
		);

		$response = rest_ensure_response( \WordPressistic\Memberistic\Database\People_Repository::get( $person_id ) );
		$response->set_status( 201 );

		return $response;
	}

	public function add_payment( $request ) {
		$membership_id = absint( $request['id'] );
		$params        = $request->get_json_params();
		$params        = is_array( $params ) ? $params : $request->get_params();

		if ( ! \WordPressistic\Memberistic\Database\Memberships_Repository::get( $membership_id ) ) {
			return new \WP_Error( 'memberistic_membership_not_found', __( 'Membership not found.', 'memberistic' ), array( 'status' => 404 ) );
		}

		$payment_id = \WordPressistic\Memberistic\Database\Payments_Repository::create(
			array(
				'membership_id'   => $membership_id,
				'amount'          => isset( $params['amount'] ) ? $params['amount'] : 0,
				'currency'        => isset( $params['currency'] ) ? sanitize_text_field( (string) $params['currency'] ) : 'USD',
				'payment_method'  => isset( $params['payment_method'] ) ? sanitize_key( (string) $params['payment_method'] ) : 'manual',
				'payment_gateway' => 'manual',
				'status'          => isset( $params['status'] ) ? sanitize_key( (string) $params['status'] ) : 'completed',
				'paid_at'         => isset( $params['paid_at'] ) ? sanitize_text_field( (string) $params['paid_at'] ) : current_time( 'mysql' ),
				'raw_response'    => array( 'source' => 'rest' ),
			)
		);

		if ( ! $payment_id ) {
			return new \WP_Error( 'memberistic_payment_create_failed', __( 'Payment could not be recorded.', 'memberistic' ), array( 'status' => 500 ) );
		}

		\WordPressistic\Memberistic\Database\Activity_Repository::log(
			array(
				'membership_id'       => $membership_id,
				'activity_type'       => 'payment_completed',
				'title'               => __( 'Manual payment recorded', 'memberistic' ),
				'related_object_type' => 'payment',
				'related_object_id'   => $payment_id,
				'created_by'          => get_current_user_id(),
			)
		);

		$response = rest_ensure_response( array( 'id' => $payment_id ) );
		$response->set_status( 201 );
		return $response;
	}

	public function get_payments( $request ) {
		return rest_ensure_response( \WordPressistic\Memberistic\Database\Payments_Repository::get_by_membership( absint( $request['id'] ) ) );
	}

	public function get_activity( $request ) {
		return rest_ensure_response( \WordPressistic\Memberistic\Database\Activity_Repository::get_by_membership( absint( $request['id'] ) ) );
	}

	public function get_bookings( $request ) {
		$membership_id = absint( $request['id'] );

		return rest_ensure_response(
			array(
				'bookings'  => \WordPressistic\Memberistic\Integrations\Booking_Engine::get_bookings_for_membership( $membership_id ),
				'checkins'  => \WordPressistic\Memberistic\Database\Checkins_Repository::get_by_membership( $membership_id ),
			)
		);
	}

	public function renew_membership( $request ) {
		$id         = absint( $request['id'] );
		$membership = \WordPressistic\Memberistic\Database\Memberships_Repository::get( $id );

		if ( ! $membership ) {
			return new \WP_Error( 'memberistic_membership_not_found', __( 'Membership not found.', 'memberistic' ), array( 'status' => 404 ) );
		}

		// Audit B19: previous code computed the new renewal date from
		// time() rather than from the EXISTING renewal_date. Members
		// who renewed early lost paid time; members who renewed late
		// silently got an extra free window. Now we anchor the new
		// renewal off max(current renewal_date, now) so:
		//   - Early renewal:  new = current_renewal + 1 cycle
		//                     (member keeps the time they paid for)
		//   - Late renewal:   new = now + 1 cycle
		//                     (no retroactive window, no "free month")
		$now_local      = current_time( 'mysql' );
		$current        = ! empty( $membership['renewal_date'] ) ? $membership['renewal_date'] : '';
		$anchor         = ( $current && $current > $now_local ) ? $current : $now_local;
		$billing_cycle  = ! empty( $membership['billing_cycle'] ) ? $membership['billing_cycle'] : 'monthly';
		$renewal_date   = \WordPressistic\Memberistic\Integrations\WooCommerce_Bridge::compute_next_renewal( $billing_cycle, $anchor );

		\WordPressistic\Memberistic\Database\Memberships_Repository::update( $id, array( 'status' => 'active', 'renewal_date' => $renewal_date ) );
		do_action( 'memberistic_membership_activated', $id );
		\WordPressistic\Memberistic\Database\Activity_Repository::log( array( 'membership_id' => $id, 'activity_type' => 'membership_renewed', 'title' => __( 'Membership renewed', 'memberistic' ), 'created_by' => get_current_user_id() ) );
		\WordPressistic\Memberistic\Emails\Email_Service::send_membership_email( $id, 'membership_renewed' );

		return rest_ensure_response( \WordPressistic\Memberistic\Database\Memberships_Repository::get_with_summary( $id ) );
	}

	public function cancel_membership( $request ) {
		$id = absint( $request['id'] );

		if ( ! \WordPressistic\Memberistic\Database\Memberships_Repository::get( $id ) ) {
			return new \WP_Error( 'memberistic_membership_not_found', __( 'Membership not found.', 'memberistic' ), array( 'status' => 404 ) );
		}

		// Stripe first, local status second: remote billing must be
		// confirmed stopped before the membership reads "cancelled". If
		// Stripe fails, the membership stays in its current status, a retry
		// is queued (which completes the cancel automatically on success),
		// and the operator can pass force=true to cancel locally anyway.
		$remote = \WordPressistic\Memberistic\Payments\Stripe_Service::cancel_remote_first( $id );
		$force  = rest_sanitize_boolean( $request->get_param( 'force' ) );
		if ( is_wp_error( $remote ) && ! $force ) {
			return new \WP_Error(
				'memberistic_stripe_cancel_failed',
				sprintf(
					/* translators: %s = Stripe error message */
					__( 'The membership was NOT cancelled: Stripe could not stop the subscription (%s). A retry is queued and will finish the cancellation automatically once Stripe confirms. To cancel locally anyway (billing may continue until a retry succeeds), repeat with force=true.', 'memberistic' ),
					$remote->get_error_message()
				),
				array( 'status' => 502 )
			);
		}

		\WordPressistic\Memberistic\Database\Memberships_Repository::update( $id, array( 'cancelled_at' => current_time( 'mysql' ) ) );
		\WordPressistic\Memberistic\Database\Memberships_Repository::change_status( $id, 'cancelled' );
		\WordPressistic\Memberistic\Database\Activity_Repository::log( array( 'membership_id' => $id, 'activity_type' => 'membership_cancelled', 'title' => __( 'Membership cancelled', 'memberistic' ), 'created_by' => get_current_user_id() ) );
		\WordPressistic\Memberistic\Emails\Email_Service::send_membership_email( $id, 'membership_cancelled' );

		return rest_ensure_response( \WordPressistic\Memberistic\Database\Memberships_Repository::get_with_summary( $id ) );
	}

	public function upgrade_membership( $request ) {
		$id     = absint( $request['id'] );
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : $request->get_params();
		$plan_id = isset( $params['plan_id'] ) ? absint( $params['plan_id'] ) : 0;

		if ( ! $plan_id || ! \WordPressistic\Memberistic\Database\Plans_Repository::get( $plan_id ) ) {
			return new \WP_Error( 'memberistic_plan_not_found', __( 'Plan not found.', 'memberistic' ), array( 'status' => 404 ) );
		}

		\WordPressistic\Memberistic\Database\Memberships_Repository::update( $id, array( 'plan_id' => $plan_id, 'status' => 'active' ) );
		do_action( 'memberistic_membership_activated', $id );
		\WordPressistic\Memberistic\Database\Activity_Repository::log( array( 'membership_id' => $id, 'activity_type' => 'membership_status_changed', 'title' => __( 'Membership plan changed', 'memberistic' ), 'created_by' => get_current_user_id() ) );

		return rest_ensure_response( \WordPressistic\Memberistic\Database\Memberships_Repository::get_with_summary( $id ) );
	}

	public function add_checkin( $request ) {
		$membership_id = absint( $request['id'] );
		$params        = $request->get_json_params();
		$params        = is_array( $params ) ? $params : $request->get_params();
		$person_id     = isset( $params['person_id'] ) ? absint( $params['person_id'] ) : 0;

		if ( ! \WordPressistic\Memberistic\Database\Memberships_Repository::get( $membership_id ) ) {
			return new \WP_Error( 'memberistic_membership_not_found', __( 'Membership not found.', 'memberistic' ), array( 'status' => 404 ) );
		}

		if ( ! $person_id ) {
			$people = \WordPressistic\Memberistic\Database\People_Repository::get_by_membership( $membership_id );
			$person_id = ! empty( $people[0]['id'] ) ? absint( $people[0]['id'] ) : 0;
		}

		$checkin_id = \WordPressistic\Memberistic\Database\Checkins_Repository::create(
			array(
				'membership_id' => $membership_id,
				'person_id'     => $person_id,
				'checkin_type'  => isset( $params['checkin_type'] ) ? sanitize_key( (string) $params['checkin_type'] ) : 'walk_in',
				'notes'         => isset( $params['notes'] ) ? sanitize_textarea_field( (string) $params['notes'] ) : '',
			)
		);

		if ( ! $checkin_id ) {
			return new \WP_Error( 'memberistic_checkin_create_failed', __( 'Check-in could not be recorded.', 'memberistic' ), array( 'status' => 500 ) );
		}

		\WordPressistic\Memberistic\Database\Activity_Repository::log(
			array(
				'membership_id'       => $membership_id,
				'person_id'           => $person_id,
				'activity_type'       => 'checkin_created',
				'title'               => __( 'Member checked in', 'memberistic' ),
				'related_object_type' => 'checkin',
				'related_object_id'   => $checkin_id,
				'created_by'          => get_current_user_id(),
			)
		);

		$response = rest_ensure_response( array( 'id' => $checkin_id ) );
		$response->set_status( 201 );
		return $response;
	}

	public function add_note( $request ) {
		$membership_id = absint( $request['id'] );
		$params        = $request->get_json_params();
		$params        = is_array( $params ) ? $params : $request->get_params();

		if ( ! \WordPressistic\Memberistic\Database\Memberships_Repository::get( $membership_id ) ) {
			return new \WP_Error( 'memberistic_membership_not_found', __( 'Membership not found.', 'memberistic' ), array( 'status' => 404 ) );
		}

		$note_id = \WordPressistic\Memberistic\Database\Notes_Repository::create(
			array(
				'membership_id' => $membership_id,
				'note'          => isset( $params['note'] ) ? sanitize_textarea_field( (string) $params['note'] ) : '',
				'visibility'    => 'staff_only',
			)
		);

		if ( ! $note_id ) {
			return new \WP_Error( 'memberistic_note_create_failed', __( 'Note could not be recorded.', 'memberistic' ), array( 'status' => 500 ) );
		}

		\WordPressistic\Memberistic\Database\Activity_Repository::log(
			array(
				'membership_id'       => $membership_id,
				'activity_type'       => 'staff_note_added',
				'title'               => __( 'Staff note added', 'memberistic' ),
				'related_object_type' => 'note',
				'related_object_id'   => $note_id,
				'created_by'          => get_current_user_id(),
			)
		);

		$response = rest_ensure_response( array( 'id' => $note_id ) );
		$response->set_status( 201 );
		return $response;
	}

	public function send_email( $request ) {
		$id       = absint( $request['id'] );
		$template = sanitize_key( (string) $request->get_param( 'template' ) );

		if ( ! \WordPressistic\Memberistic\Database\Memberships_Repository::get( $id ) ) {
			return new \WP_Error( 'memberistic_membership_not_found', __( 'Membership not found.', 'memberistic' ), array( 'status' => 404 ) );
		}

		if ( ! \WordPressistic\Memberistic\Emails\Email_Service::template_exists( $template ) ) {
			return new \WP_Error( 'memberistic_template_unknown', __( 'Unknown email template.', 'memberistic' ), array( 'status' => 400 ) );
		}

		$sent = \WordPressistic\Memberistic\Emails\Email_Service::send_membership_email( $id, $template );

		if ( ! $sent ) {
			return new \WP_Error(
				'memberistic_email_failed',
				__( 'The email could not be sent. The primary member may not have a valid email address.', 'memberistic' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response( array( 'sent' => true, 'template' => $template ) );
	}

	public function get_email_templates() {
		return rest_ensure_response( \WordPressistic\Memberistic\Emails\Email_Service::templates() );
	}

	public function update_person( $request ) {
		$id     = absint( $request['id'] );
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : $request->get_params();

		$existing = \WordPressistic\Memberistic\Database\People_Repository::get( $id );

		if ( ! $existing ) {
			return new \WP_Error( 'memberistic_person_not_found', __( 'Linked member not found.', 'memberistic' ), array( 'status' => 404 ) );
		}

		$updatable = array( 'full_name', 'email', 'phone', 'date_of_birth', 'relationship', 'waiver_status', 'waiver_signed_at', 'waiver_expires_at', 'status', 'notes' );
		$data      = array();

		foreach ( $updatable as $field ) {
			if ( array_key_exists( $field, $params ) ) {
				$data[ $field ] = $params[ $field ];
			}
		}

		$old_waiver_status = isset( $existing['waiver_status'] ) ? (string) $existing['waiver_status'] : '';
		$new_waiver_status = array_key_exists( 'waiver_status', $data ) ? (string) $data['waiver_status'] : $old_waiver_status;

		if ( 'signed' === $new_waiver_status && empty( $data['waiver_signed_at'] ) && empty( $existing['waiver_signed_at'] ) ) {
			$data['waiver_signed_at'] = current_time( 'mysql' );
		}

		\WordPressistic\Memberistic\Database\People_Repository::update( $id, $data );

		if ( $new_waiver_status && $new_waiver_status !== $old_waiver_status ) {
			$activity_type = 'signed' === $new_waiver_status
				? 'waiver_signed'
				: ( in_array( $new_waiver_status, array( 'expired', 'rejected', 'needs_review' ), true ) ? 'waiver_expired' : 'membership_status_changed' );

			\WordPressistic\Memberistic\Database\Activity_Repository::log(
				array(
					'membership_id' => (int) $existing['membership_id'],
					'person_id'     => $id,
					'activity_type' => $activity_type,
					'title'         => sprintf(
						/* translators: 1: person name, 2: new waiver status. */
						__( 'Waiver status for %1$s set to %2$s', 'memberistic' ),
						$existing['full_name'] ?: __( 'linked member', 'memberistic' ),
						str_replace( '_', ' ', $new_waiver_status )
					),
					'created_by'    => get_current_user_id(),
				)
			);
		}

		return rest_ensure_response( \WordPressistic\Memberistic\Database\People_Repository::get( $id ) );
	}

	public function delete_person( $request ) {
		$id       = absint( $request['id'] );
		$existing = \WordPressistic\Memberistic\Database\People_Repository::get( $id );

		if ( ! $existing ) {
			return new \WP_Error( 'memberistic_person_not_found', __( 'Linked member not found.', 'memberistic' ), array( 'status' => 404 ) );
		}

		if ( 'primary' === ( $existing['role'] ?? '' ) ) {
			return new \WP_Error( 'memberistic_person_primary_protected', __( 'The primary member cannot be removed.', 'memberistic' ), array( 'status' => 400 ) );
		}

		\WordPressistic\Memberistic\Database\People_Repository::delete( $id );

		\WordPressistic\Memberistic\Database\Activity_Repository::log(
			array(
				'membership_id' => (int) $existing['membership_id'],
				'person_id'     => $id,
				'activity_type' => 'linked_member_removed',
				'title'         => __( 'Linked member removed', 'memberistic' ),
				'created_by'    => get_current_user_id(),
			)
		);

		return rest_ensure_response( array( 'deleted' => true ) );
	}

	/**
	 * WooCommerce webhook receiver — verifies signature against the configured
	 * shared secret, then defers to the Bridge to sync the order.
	 *
	 * Set the same secret in WooCommerce > Settings > Advanced > Webhooks to enable.
	 */
	public function woocommerce_webhook( $request ) {
		$bridge = \WordPressistic\Memberistic\Integrations\WooCommerce_Bridge::class;

		if ( ! $bridge::is_enabled() ) {
			return new \WP_Error( 'memberistic_woocommerce_disabled', __( 'WooCommerce bridge is not enabled.', 'memberistic' ), array( 'status' => 503 ) );
		}

		$secret = trim( (string) memberistic_get_setting( 'woocommerce_webhook_secret', '' ) );
		$header = isset( $_SERVER['HTTP_X_WC_WEBHOOK_SIGNATURE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_WC_WEBHOOK_SIGNATURE'] ) ) : '';
		$body   = $request->get_body();

		// SECURITY: an empty secret previously short-circuited the signature
		// check, leaving the webhook endpoint effectively open-auth — any
		// unauthenticated POST would call sync_completed_order(). Refuse the
		// request when the secret has not been configured.
		if ( '' === $secret ) {
			return new \WP_Error(
				'memberistic_webhook_secret_missing',
				__( 'Webhook secret is not configured', 'memberistic' ),
				array( 'status' => 503 )
			);
		}

		$expected = base64_encode( hash_hmac( 'sha256', $body, $secret, true ) );

		if ( '' === $header || ! hash_equals( $expected, $header ) ) {
			return new \WP_Error( 'memberistic_woo_bad_signature', __( 'Invalid WooCommerce webhook signature.', 'memberistic' ), array( 'status' => 400 ) );
		}

		$payload = json_decode( $body, true );

		if ( ! is_array( $payload ) ) {
			return new \WP_Error( 'memberistic_woo_bad_payload', __( 'Invalid WooCommerce webhook payload.', 'memberistic' ), array( 'status' => 400 ) );
		}

		$order_id = isset( $payload['id'] ) ? absint( $payload['id'] ) : 0;

		if ( $order_id ) {
			$bridge::sync_completed_order( $order_id );
		}

		return rest_ensure_response( array( 'received' => true ) );
	}

	public function stripe_webhook( $request ) {
		if ( ! \WordPressistic\Memberistic\Payments\Stripe_Service::webhook_is_configured() ) {
			return new \WP_Error(
				'memberistic_stripe_not_configured',
				__( 'Stripe webhook is not configured on this site.', 'memberistic' ),
				array( 'status' => 503 )
			);
		}

		$payload = $request->get_body();
		$header  = $request->get_header( 'stripe-signature' );
		if ( '' === (string) $header ) {
			$header = $request->get_header( 'stripe_signature' );
		}
		if ( '' === (string) $header && isset( $_SERVER['HTTP_STRIPE_SIGNATURE'] ) ) {
			$header = sanitize_text_field( wp_unslash( $_SERVER['HTTP_STRIPE_SIGNATURE'] ) );
		}

		if ( '' === $payload || '' === $header ) {
			return new \WP_Error( 'memberistic_stripe_bad_signature', __( 'Invalid Stripe webhook signature.', 'memberistic' ), array( 'status' => 400 ) );
		}

		if ( ! \WordPressistic\Memberistic\Payments\Stripe_Service::verify_webhook_signature( $payload, $header ) ) {
			return new \WP_Error( 'memberistic_stripe_bad_signature', __( 'Invalid Stripe webhook signature.', 'memberistic' ), array( 'status' => 400 ) );
		}
		update_option( 'memberistic_stripe_webhook_last_verified_at', current_time( 'mysql' ), false );

		$event = json_decode( $payload, true );

		if ( ! is_array( $event ) ) {
			return new \WP_Error( 'memberistic_stripe_bad_payload', __( 'Invalid Stripe webhook payload.', 'memberistic' ), array( 'status' => 400 ) );
		}

		update_option( 'memberistic_stripe_webhook_last_received_at', current_time( 'mysql' ), false );
		$result = \WordPressistic\Memberistic\Payments\Stripe_Service::process_webhook_event( $event );
		if ( is_wp_error( $result ) ) {
			update_option( 'memberistic_stripe_webhook_last_failed_at', current_time( 'mysql' ), false );
			return $result;
		}
		update_option( 'memberistic_stripe_webhook_last_processed_at', current_time( 'mysql' ), false );

		return rest_ensure_response( array( 'received' => true, 'result' => $result ) );
	}
}
