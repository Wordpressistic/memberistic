<?php
/**
 * Members page — React mount point.
 *
 * All member CRUD lives in the React app (assets/admin-members.js) on top of
 * the /memberships REST routes. This file emits the page chrome + mount node
 * and handles legacy GET-based status links from old bookmarks.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic\Admin;

use WordPressistic\Memberistic\Database\Activity_Repository;
use WordPressistic\Memberistic\Database\Memberships_Repository;
use function WordPressistic\Memberistic\memberistic_admin_url;
use function WordPressistic\Memberistic\memberistic_current_user_can;
use function WordPressistic\Memberistic\memberistic_verify_admin_nonce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Members_Page {
	/**
	 * Handle legacy member admin actions.
	 */
	public static function handle_actions() {
		if ( empty( $_REQUEST['page'] ) || 'memberistic-members' !== sanitize_key( wp_unslash( $_REQUEST['page'] ) ) ) {
			return;
		}

		if ( isset( $_GET['memberistic_action'], $_GET['membership_id'], $_GET['_wpnonce'] ) ) {
			self::handle_status_action();
		}
	}

	/**
	 * Render the React members console.
	 *
	 * Old ?action=view&id=N URLs are no longer server-rendered — they pass an
	 * `initialSelectedId` setting through to the React app which auto-opens the
	 * detail panel. ?action=add still auto-opens the create panel.
	 */
	public static function render() {
		if ( ! memberistic_current_user_can( 'view_memberistic_members' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'memberistic' ) );
		}

		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

		if ( $id && ! Memberships_Repository::get( $id ) ) {
			wp_safe_redirect( memberistic_admin_url( 'memberistic-members', array( 'memberistic_notice' => 'member_not_found', 'memberistic_notice_type' => 'error' ) ) );
			exit;
		}

		?>
		<div class="wrap memberistic-wrap">
			<div id="memberistic-members-app" class="memberistic-react-root">
				<div class="memberistic-react-loading">
					<p><?php esc_html_e( 'Loading members…', 'memberistic' ); ?></p>
					<noscript>
						<p><?php esc_html_e( 'The Memberistic members console requires JavaScript.', 'memberistic' ); ?></p>
					</noscript>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Handle legacy activate/pause/cancel GET links from old bookmarks.
	 */
	private static function handle_status_action() {
		$membership_id = absint( $_GET['membership_id'] );
		$action        = sanitize_key( wp_unslash( $_GET['memberistic_action'] ) );

		if ( ! memberistic_current_user_can( 'edit_memberistic_members' ) || ! memberistic_verify_admin_nonce( 'memberistic_member_' . $action . '_' . $membership_id ) ) {
			wp_safe_redirect( memberistic_admin_url( 'memberistic-members', array( 'memberistic_notice' => 'invalid_request', 'memberistic_notice_type' => 'error' ) ) );
			exit;
		}

		$status_map = array(
			'activate' => 'active',
			'pause'    => 'paused',
			'cancel'   => 'cancelled',
		);
		$type_map = array(
			'activate' => 'membership_activated',
			'pause'    => 'membership_paused',
			'cancel'   => 'membership_cancelled',
		);

		if ( isset( $status_map[ $action ] ) ) {
			// Stripe first, local status second — a cancel only lands
			// locally once remote billing is confirmed stopped. On failure
			// the membership keeps its status; a retry is queued and will
			// finish the cancel automatically when Stripe confirms.
			if ( 'cancel' === $action ) {
				$remote = \WordPressistic\Memberistic\Payments\Stripe_Service::cancel_remote_first( $membership_id );
				if ( is_wp_error( $remote ) ) {
					wp_safe_redirect( memberistic_admin_url( 'memberistic-members', array( 'id' => $membership_id, 'memberistic_notice' => 'stripe_cancel_failed', 'memberistic_notice_type' => 'error' ) ) );
					exit;
				}
			}
			Memberships_Repository::change_status( $membership_id, $status_map[ $action ] );
			Activity_Repository::log(
				array(
					'membership_id' => $membership_id,
					'activity_type' => $type_map[ $action ],
					'title'         => sprintf(
						/* translators: %s: membership status */
						__( 'Membership marked %s', 'memberistic' ),
						$status_map[ $action ]
					),
					'created_by'    => get_current_user_id(),
				)
			);
		}

		wp_safe_redirect( memberistic_admin_url( 'memberistic-members', array( 'id' => $membership_id, 'memberistic_notice' => 'member_saved' ) ) );
		exit;
	}
}
