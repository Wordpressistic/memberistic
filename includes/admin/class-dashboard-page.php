<?php
/**
 * Dashboard page — React mount point.
 *
 * The legacy server-rendered dashboard has been replaced by a live React app
 * that talks to the REST controllers. This file only emits the page chrome
 * and the mount node; all data fetching happens client-side.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic\Admin;

use function WordPressistic\Memberistic\memberistic_current_user_can;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Dashboard_Page {
	/**
	 * The DOM id the React app mounts on.
	 */
	const ROOT_ID = 'memberistic-dashboard-app';

	/**
	 * Render dashboard.
	 */
	public static function render() {
		if ( ! memberistic_current_user_can( 'view_memberistic_dashboard' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'memberistic' ) );
		}
		?>
		<div class="wrap memberistic-wrap">
			<div id="<?php echo esc_attr( self::ROOT_ID ); ?>" class="memberistic-react-root">
				<div class="memberistic-react-loading">
					<p><?php esc_html_e( 'Loading the Memberistic dashboard…', 'memberistic' ); ?></p>
					<noscript>
						<p><?php esc_html_e( 'The Memberistic dashboard requires JavaScript. Please enable it to view live metrics.', 'memberistic' ); ?></p>
					</noscript>
				</div>
			</div>
		</div>
		<?php
	}
}
