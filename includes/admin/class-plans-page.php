<?php
/**
 * Plans page — React mount point.
 *
 * All plan CRUD lives in the React app (assets/admin-plans.js) on top of the
 * /plans REST routes. This file only handles the page-level capability check
 * and any deactivate/delete GET actions still emitted by older bookmarks.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic\Admin;

use WordPressistic\Memberistic\Database\Plans_Repository;
use function WordPressistic\Memberistic\memberistic_admin_url;
use function WordPressistic\Memberistic\memberistic_current_user_can;
use function WordPressistic\Memberistic\memberistic_verify_admin_nonce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plans_Page {
	/**
	 * Handle legacy deactivate/delete GET actions for old bookmarks.
	 */
	public static function handle_actions() {
		if ( empty( $_REQUEST['page'] ) || 'memberistic-plans' !== sanitize_key( wp_unslash( $_REQUEST['page'] ) ) ) {
			return;
		}

		if ( ! memberistic_current_user_can( 'manage_memberistic_plans' ) ) {
			return;
		}

		if ( ! isset( $_GET['memberistic_action'], $_GET['plan_id'] ) ) {
			return;
		}

		$action  = sanitize_key( wp_unslash( $_GET['memberistic_action'] ) );
		$plan_id = absint( $_GET['plan_id'] );

		if ( ! memberistic_verify_admin_nonce( 'memberistic_plan_' . $action . '_' . $plan_id ) ) {
			wp_safe_redirect( memberistic_admin_url( 'memberistic-plans', array( 'memberistic_notice' => 'invalid_request', 'memberistic_notice_type' => 'error' ) ) );
			exit;
		}

		if ( 'deactivate' === $action ) {
			Plans_Repository::update( $plan_id, array( 'status' => 'inactive' ) );
			wp_safe_redirect( memberistic_admin_url( 'memberistic-plans', array( 'memberistic_notice' => 'plan_deactivated' ) ) );
			exit;
		}

		if ( 'delete' === $action ) {
			$deleted = Plans_Repository::delete( $plan_id );
			wp_safe_redirect(
				memberistic_admin_url(
					'memberistic-plans',
					array(
						'memberistic_notice'      => $deleted ? 'plan_deleted' : 'plan_delete_blocked',
						'memberistic_notice_type' => $deleted ? 'success' : 'warning',
					)
				)
			);
			exit;
		}
	}

	/**
	 * Render the React mount point. Old ?action=add|edit URLs receive an
	 * `editPlanId` setting that auto-opens the editor.
	 */
	public static function render() {
		if ( ! memberistic_current_user_can( 'manage_memberistic_plans' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'memberistic' ) );
		}
		?>
		<div class="wrap memberistic-wrap">
			<div id="memberistic-plans-app" class="memberistic-react-root">
				<div class="memberistic-react-loading">
					<p><?php esc_html_e( 'Loading plans…', 'memberistic' ); ?></p>
					<noscript>
						<p><?php esc_html_e( 'The Memberistic plans console requires JavaScript.', 'memberistic' ); ?></p>
					</noscript>
				</div>
			</div>
		</div>
		<?php
	}
}
