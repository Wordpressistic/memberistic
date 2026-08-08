<?php
/**
 * Advanced FFL Checkout bridge.
 *
 * OPTIONAL AND INDUSTRY-SPECIFIC. Only relevant to US firearms retailers
 * that run both a membership operation and an online FFL-transfer
 * WooCommerce storefront (the separate advanced-ffl-checkout plugin),
 * where the same person is often both a member and a transfer customer.
 * Every other kind of business can leave this integration off; it is
 * disabled by default and inert until explicitly enabled.
 *
 * Surfaces a member's own FFL transfer history on their account
 * dashboard, read-only, matched by email against advanced-ffl-checkout's
 * own `transfers` table (same-site direct read — the same pattern this
 * plugin's POS Bridge and coreSTORE Bridge use for their integrations).
 *
 * Deliberately read-only and deliberately NOT a fee-discount hook: that
 * plugin's dealer transfer fee is collected by the receiving FFL directly
 * (informational only in its checkout widget, not a WooCommerce cart line
 * item), so there's no real fee line here to discount against — see the
 * class docblock in advanced-ffl-checkout's own `Checkout` class. A future
 * version could revisit this if that changes.
 *
 * Gated by the "FFL Checkout" toggle on Memberistic → Integrations.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FFL_Checkout_Bridge {

	public static function register() {
		if ( ! Integrations_Registry::is_enabled( 'ffl_checkout' ) ) {
			return;
		}
		add_action( 'memberistic_account_dashboard_end', array( self::class, 'render_dashboard_section' ), 10, 1 );
		add_action( 'memberistic_verify_card_after', array( self::class, 'render_verify_card_section' ), 10, 2 );
	}

	/** @return bool */
	public static function is_active() {
		if ( defined( 'WPISTIC_FFL_VERSION' ) ) {
			return true;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'wpistic_ffl_transfers';
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	/**
	 * Transfers tied to a member's email, newest first.
	 *
	 * @param string $email
	 * @param int    $limit
	 * @return array<int,array<string,mixed>>
	 */
	public static function transfers_for_email( $email, $limit = 10 ) {
		if ( ! self::is_active() || '' === trim( (string) $email ) ) {
			return array();
		}
		global $wpdb;
		$t = $wpdb->prefix . 'wpistic_ffl_transfers';
		$d = $wpdb->prefix . 'wpistic_ffl_dealers';
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.transfer_ref, t.status, t.item_description, t.item_make, t.item_model,
				        t.updated_at, dl.business_name AS dealer_name
				 FROM {$t} t
				 LEFT JOIN {$d} dl ON dl.id = t.dealer_id
				 WHERE t.customer_email = %s
				 ORDER BY t.id DESC LIMIT %d",
				sanitize_email( (string) $email ),
				max( 1, min( 50, (int) $limit ) )
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Frontend "my account" dashboard section — appended after the main
	 * dashboard tiles, matches the injection point Corporate Groups already
	 * uses (`memberistic_account_dashboard_end`).
	 *
	 * @param \WP_User $user
	 */
	public static function render_dashboard_section( $user ) {
		if ( ! self::is_active() || ! $user instanceof \WP_User || ! $user->user_email ) {
			return;
		}
		$transfers = self::transfers_for_email( $user->user_email, 5 );
		if ( empty( $transfers ) ) {
			return;
		}
		?>
		<section class="memberistic-acct-block memberistic-ffl-transfers">
			<h3><?php esc_html_e( 'Your FFL Firearm Transfers', 'memberistic' ); ?></h3>
			<p class="memberistic-acct-muted"><?php esc_html_e( 'From the online store — separate from your range membership.', 'memberistic' ); ?></p>
			<table class="memberistic-acct-table">
				<tbody>
				<?php foreach ( $transfers as $t ) : ?>
					<tr>
						<td>
							<strong><?php echo esc_html( trim( ( $t['item_make'] ?: '' ) . ' ' . ( $t['item_model'] ?: '' ) ) ?: (string) $t['item_description'] ); ?></strong><br>
							<small><?php echo esc_html( (string) $t['transfer_ref'] ); ?><?php echo $t['dealer_name'] ? ' · ' . esc_html( (string) $t['dealer_name'] ) : ''; ?></small>
						</td>
						<td><span class="memberistic-badge"><?php echo esc_html( ucwords( str_replace( '_', ' ', (string) $t['status'] ) ) ); ?></span></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</section>
		<?php
	}

	/**
	 * Staff-facing QR "Member Verification" card — a quick heads-up for the
	 * counter if this member also has an in-flight FFL transfer, so staff
	 * don't need to check a second system.
	 *
	 * @param \WP_User   $user
	 * @param array|null $membership
	 */
	public static function render_verify_card_section( $user, $membership = null ) {
		if ( ! self::is_active() || ! $user instanceof \WP_User || ! $user->user_email ) {
			return;
		}
		$transfers = self::transfers_for_email( $user->user_email, 1 );
		if ( empty( $transfers ) ) {
			return;
		}
		$t = $transfers[0];
		if ( in_array( $t['status'], array( 'transferred', 'cancelled' ), true ) ) {
			return; // Closed out — nothing actionable for the counter to see.
		}
		printf(
			'<p class="memberistic-verify-note">%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: item description, 2: transfer status */
					__( 'Also has an open FFL transfer: %1$s (%2$s).', 'memberistic' ),
					trim( ( $t['item_make'] ?: '' ) . ' ' . ( $t['item_model'] ?: '' ) ) ?: (string) $t['item_description'],
					ucwords( str_replace( '_', ' ', (string) $t['status'] ) )
				)
			)
		);
	}
}
