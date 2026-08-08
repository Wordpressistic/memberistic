<?php
/**
 * Payments admin page — React mount point.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic\Admin;

use function WordPressistic\Memberistic\memberistic_current_user_can;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Payments_Page {
	public static function render() {
		if ( ! memberistic_current_user_can( 'manage_memberistic_payments' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'memberistic' ) );
		}
		?>
		<div class="wrap memberistic-wrap">
			<div id="memberistic-payments-app" class="memberistic-react-root">
				<div class="memberistic-react-loading">
					<p><?php esc_html_e( 'Loading payments…', 'memberistic' ); ?></p>
					<noscript>
						<p><?php esc_html_e( 'The Memberistic payments console requires JavaScript.', 'memberistic' ); ?></p>
					</noscript>
				</div>
			</div>
		</div>
		<?php
	}
}
