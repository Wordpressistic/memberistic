<?php
/**
 * Discount Codes admin panel — rendered inside the Integrations page,
 * right alongside the coreSTORE and WooCommerce Member Discounts panels
 * (Admin_Menu::render_integrations()). The Integrations grid toggle
 * turns the FEATURE on/off at checkout; this panel is where staff
 * actually create and manage individual codes and see who redeemed
 * them — the same split already used for the Waiver Manager integration
 * toggle vs. its own always-visible admin console.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic\Admin;

use WordPressistic\Memberistic\Database\Discount_Codes_Repository;
use WordPressistic\Memberistic\Database\Discount_Redemptions_Repository;
use WordPressistic\Memberistic\Database\Plans_Repository;
use WordPressistic\Memberistic\Discounts\Discount_Code_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
final class Discount_Codes_Page {

	const NONCE_ACTION = 'memberistic_discount_code';

	/**
	 * Render the panel. Must be called AFTER the main Integrations <form>
	 * has closed — every action here uses its own admin-post.php form or
	 * link, which cannot nest inside that form.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_memberistic_settings' ) ) {
			return;
		}

		$editing = null;
		if ( isset( $_GET['discount_code_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$editing = Discount_Codes_Repository::get( absint( $_GET['discount_code_id'] ) );
		}

		$viewing_usage = null;
		if ( isset( $_GET['discount_code_usage'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$viewing_usage = Discount_Codes_Repository::get( absint( $_GET['discount_code_usage'] ) );
		}

		$codes = Discount_Codes_Repository::get_all();
		$plans = Plans_Repository::get_all( array( 'status' => 'active' ) );
		?>
		<div class="memberistic-addon-card" style="margin-top:24px;max-width:1040px;">
			<h2><?php esc_html_e( 'Discount Codes', 'memberistic' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Coupon codes customers can enter at membership checkout for a percent or fixed discount. Turn the Discount Codes switch above on to accept codes at checkout — codes can be created and managed here either way.', 'memberistic' ); ?></p>

			<?php self::render_notice(); ?>

			<?php if ( $viewing_usage ) : ?>
				<?php self::render_usage( $viewing_usage ); ?>
			<?php else : ?>
				<?php self::render_form( $editing, $plans ); ?>
				<?php self::render_list( $codes ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_notice() {
		if ( ! isset( $_GET['dc_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$notice = sanitize_key( wp_unslash( $_GET['dc_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$map    = array(
			'saved'       => array( 'success', __( 'Discount code saved.', 'memberistic' ) ),
			'sync_failed' => array( 'error', __( 'Saved locally, but Stripe sync failed — see the error shown below the code. It will not work at checkout until it syncs; edit and save again to retry.', 'memberistic' ) ),
			'error'       => array( 'error', __( 'Please check the code and try again — it may be blank or already in use by another discount code.', 'memberistic' ) ),
		);
		if ( ! isset( $map[ $notice ] ) ) {
			return;
		}
		printf(
			'<div class="notice notice-%1$s inline"><p>%2$s</p></div>',
			esc_attr( $map[ $notice ][0] ),
			esc_html( $map[ $notice ][1] )
		);
	}

	private static function render_form( $editing, array $plans ) {
		$v = $editing ?: array(
			'id'                       => 0,
			'code'                     => '',
			'description'              => '',
			'discount_type'            => 'percent',
			'discount_value'           => '',
			'applies_to'               => 'all',
			'plan_ids'                 => array(),
			'billing_cycles'           => 'all',
			'duration_type'            => 'once',
			'duration_in_months'       => '',
			'max_redemptions'          => '',
			'max_redemptions_per_user' => 1,
			'starts_at'                => '',
			'expires_at'               => '',
			'status'                   => 'active',
			'sync_error'               => '',
		);
		?>
		<h3><?php echo $editing ? esc_html__( 'Edit Discount Code', 'memberistic' ) : esc_html__( 'Add Discount Code', 'memberistic' ); ?></h3>
		<?php if ( ! empty( $v['sync_error'] ) ) : ?>
			<div class="notice notice-error inline"><p><strong><?php esc_html_e( 'Stripe sync error:', 'memberistic' ); ?></strong> <?php echo esc_html( $v['sync_error'] ); ?></p></div>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="memberistic_save_discount_code" />
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) (int) $v['id'] ); ?>" />
			<?php wp_nonce_field( self::NONCE_ACTION, 'memberistic_discount_code_nonce' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="dc-code"><?php esc_html_e( 'Code', 'memberistic' ); ?></label></th>
					<td><input id="dc-code" name="code" value="<?php echo esc_attr( $v['code'] ); ?>" class="regular-text" style="text-transform:uppercase;" placeholder="SUMMER20" required>
						<p class="description"><?php esc_html_e( 'Letters, numbers, - and _ only. Always matched uppercase, whatever the customer types.', 'memberistic' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><label for="dc-desc"><?php esc_html_e( 'Description', 'memberistic' ); ?></label></th>
					<td><input id="dc-desc" name="description" value="<?php echo esc_attr( $v['description'] ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Staff-facing note — not shown to customers', 'memberistic' ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Discount', 'memberistic' ); ?></th>
					<td>
						<select name="discount_type">
							<option value="percent" <?php selected( $v['discount_type'], 'percent' ); ?>><?php esc_html_e( 'Percent off', 'memberistic' ); ?></option>
							<option value="fixed" <?php selected( $v['discount_type'], 'fixed' ); ?>><?php esc_html_e( 'Fixed amount off', 'memberistic' ); ?></option>
						</select>
						<input type="number" step="0.01" min="0.01" name="discount_value" value="<?php echo esc_attr( (string) $v['discount_value'] ); ?>" style="width:100px;" required>
						<p class="description"><?php esc_html_e( 'E.g. 20 for 20% off, or 15.00 for $15.00 off.', 'memberistic' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Applies to plans', 'memberistic' ); ?></th>
					<td>
						<label><input type="radio" name="applies_to" value="all" <?php checked( $v['applies_to'], 'all' ); ?> class="dc-applies-radio"> <?php esc_html_e( 'All plans', 'memberistic' ); ?></label><br>
						<label><input type="radio" name="applies_to" value="specific_plans" <?php checked( $v['applies_to'], 'specific_plans' ); ?> class="dc-applies-radio"> <?php esc_html_e( 'Only these plans:', 'memberistic' ); ?></label>
						<div style="margin:6px 0 0 24px;">
							<?php foreach ( (array) $plans as $plan ) : ?>
								<label style="display:block;"><input type="checkbox" name="plan_ids[]" value="<?php echo esc_attr( (string) $plan['id'] ); ?>" <?php checked( in_array( (int) $plan['id'], (array) $v['plan_ids'], true ) ); ?>> <?php echo esc_html( $plan['name'] ); ?></label>
							<?php endforeach; ?>
							<?php if ( ! $plans ) : ?>
								<p class="description"><?php esc_html_e( 'No active plans yet.', 'memberistic' ); ?></p>
							<?php endif; ?>
						</div>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="dc-cycle"><?php esc_html_e( 'Billing cycle', 'memberistic' ); ?></label></th>
					<td>
						<select id="dc-cycle" name="billing_cycles">
							<option value="all" <?php selected( $v['billing_cycles'], 'all' ); ?>><?php esc_html_e( 'Monthly and annual', 'memberistic' ); ?></option>
							<option value="monthly" <?php selected( $v['billing_cycles'], 'monthly' ); ?>><?php esc_html_e( 'Monthly only', 'memberistic' ); ?></option>
							<option value="annual" <?php selected( $v['billing_cycles'], 'annual' ); ?>><?php esc_html_e( 'Annual only', 'memberistic' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Applies for', 'memberistic' ); ?></th>
					<td>
						<select name="duration_type">
							<option value="once" <?php selected( $v['duration_type'], 'once' ); ?>><?php esc_html_e( 'First billing cycle only', 'memberistic' ); ?></option>
							<option value="repeating" <?php selected( $v['duration_type'], 'repeating' ); ?>><?php esc_html_e( 'A set number of months', 'memberistic' ); ?></option>
							<option value="forever" <?php selected( $v['duration_type'], 'forever' ); ?>><?php esc_html_e( 'Every renewal, forever', 'memberistic' ); ?></option>
						</select>
						<input type="number" min="1" name="duration_in_months" value="<?php echo esc_attr( (string) $v['duration_in_months'] ); ?>" style="width:70px;" placeholder="<?php esc_attr_e( 'months', 'memberistic' ); ?>">
						<p class="description"><?php esc_html_e( 'Handled by Stripe on every renewal — the discount stops (or keeps applying) automatically, no cron job involved. "Months" only matters when "a set number of months" is selected.', 'memberistic' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Usage limits', 'memberistic' ); ?></th>
					<td>
						<label><?php esc_html_e( 'Total redemptions:', 'memberistic' ); ?> <input type="number" min="1" name="max_redemptions" value="<?php echo esc_attr( (string) $v['max_redemptions'] ); ?>" style="width:90px;" placeholder="<?php esc_attr_e( 'unlimited', 'memberistic' ); ?>"></label>
						&nbsp;&nbsp;
						<label><?php esc_html_e( 'Per customer (by email):', 'memberistic' ); ?> <input type="number" min="1" name="max_redemptions_per_user" value="<?php echo esc_attr( (string) $v['max_redemptions_per_user'] ); ?>" style="width:70px;"></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Active window', 'memberistic' ); ?></th>
					<td>
						<label><?php esc_html_e( 'Starts:', 'memberistic' ); ?> <input type="datetime-local" name="starts_at" value="<?php echo esc_attr( self::to_local_input( $v['starts_at'] ) ); ?>"></label>
						&nbsp;&nbsp;
						<label><?php esc_html_e( 'Expires:', 'memberistic' ); ?> <input type="datetime-local" name="expires_at" value="<?php echo esc_attr( self::to_local_input( $v['expires_at'] ) ); ?>"></label>
						<p class="description"><?php esc_html_e( 'Leave either blank for no start delay / no expiry.', 'memberistic' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="dc-status"><?php esc_html_e( 'Status', 'memberistic' ); ?></label></th>
					<td>
						<select id="dc-status" name="status">
							<option value="active" <?php selected( $v['status'], 'active' ); ?>><?php esc_html_e( 'Active', 'memberistic' ); ?></option>
							<option value="inactive" <?php selected( $v['status'], 'inactive' ); ?>><?php esc_html_e( 'Inactive', 'memberistic' ); ?></option>
						</select>
					</td>
				</tr>
			</table>
			<p class="submit">
				<button type="submit" class="button button-primary"><?php echo $editing ? esc_html__( 'Save Changes', 'memberistic' ) : esc_html__( 'Create Discount Code', 'memberistic' ); ?></button>
				<?php if ( $editing ) : ?>
					<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=memberistic-integrations' ) ); ?>"><?php esc_html_e( 'Cancel', 'memberistic' ); ?></a>
				<?php endif; ?>
			</p>
		</form>
		<?php
	}

	private static function render_list( array $codes ) {
		?>
		<h3><?php esc_html_e( 'All Discount Codes', 'memberistic' ); ?></h3>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Code', 'memberistic' ); ?></th>
					<th><?php esc_html_e( 'Discount', 'memberistic' ); ?></th>
					<th><?php esc_html_e( 'Applies To', 'memberistic' ); ?></th>
					<th><?php esc_html_e( 'Duration', 'memberistic' ); ?></th>
					<th><?php esc_html_e( 'Usage', 'memberistic' ); ?></th>
					<th><?php esc_html_e( 'Status', 'memberistic' ); ?></th>
					<th><?php esc_html_e( 'Expires', 'memberistic' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'memberistic' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! $codes ) : ?>
					<tr><td colspan="8"><?php esc_html_e( 'No discount codes yet — add one above.', 'memberistic' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $codes as $code ) : ?>
					<tr>
						<td><code><?php echo esc_html( $code['code'] ); ?></code></td>
						<td><?php echo 'percent' === $code['discount_type'] ? esc_html( rtrim( rtrim( number_format( (float) $code['discount_value'], 2 ), '0' ), '.' ) . '%' ) : esc_html( '$' . number_format( (float) $code['discount_value'], 2 ) ); ?></td>
						<td><?php echo 'all' === $code['applies_to'] ? esc_html__( 'All plans', 'memberistic' ) : esc_html( sprintf( /* translators: %d: plan count */ _n( '%d plan', '%d plans', count( $code['plan_ids'] ), 'memberistic' ), count( $code['plan_ids'] ) ) ); ?></td>
						<td><?php echo esc_html( self::duration_label( $code ) ); ?></td>
						<td><?php echo esc_html( (string) $code['current_redemptions'] ) . ' / ' . ( null === $code['max_redemptions'] ? '∞' : esc_html( (string) $code['max_redemptions'] ) ); ?></td>
						<td>
							<?php if ( ! empty( $code['sync_error'] ) ) : ?>
								<span style="color:#b32d2e;font-weight:600;" title="<?php echo esc_attr( $code['sync_error'] ); ?>"><?php esc_html_e( 'Sync failed', 'memberistic' ); ?></span>
							<?php elseif ( 'active' === $code['status'] && empty( $code['stripe_promotion_code_id'] ) ) : ?>
								<span style="color:#996800;font-weight:600;"><?php esc_html_e( 'Syncing…', 'memberistic' ); ?></span>
							<?php elseif ( 'active' === $code['status'] ) : ?>
								<span style="color:#0a7c3f;font-weight:600;"><?php esc_html_e( 'Active', 'memberistic' ); ?></span>
							<?php else : ?>
								<span style="color:#646970;"><?php esc_html_e( 'Inactive', 'memberistic' ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo $code['expires_at'] ? esc_html( mysql2date( get_option( 'date_format' ), $code['expires_at'] ) ) : '—'; ?></td>
						<td>
							<a class="button button-small" href="<?php echo esc_url( add_query_arg( array( 'page' => 'memberistic-integrations', 'discount_code_id' => $code['id'] ), admin_url( 'admin.php' ) ) . '#discount-codes' ); ?>"><?php esc_html_e( 'Edit', 'memberistic' ); ?></a>
							<a class="button button-small" href="<?php echo esc_url( add_query_arg( array( 'page' => 'memberistic-integrations', 'discount_code_usage' => $code['id'] ), admin_url( 'admin.php' ) ) . '#discount-codes' ); ?>"><?php esc_html_e( 'Usage', 'memberistic' ); ?></a>
							<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=memberistic_toggle_discount_code&id=' . (int) $code['id'] ), self::NONCE_ACTION ) ); ?>" onclick="return confirm('<?php echo 'active' === $code['status'] ? esc_js( __( 'Deactivate this code? It will stop working at checkout immediately.', 'memberistic' ) ) : esc_js( __( 'Reactivate this code?', 'memberistic' ) ); ?>');"><?php echo 'active' === $code['status'] ? esc_html__( 'Deactivate', 'memberistic' ) : esc_html__( 'Activate', 'memberistic' ); ?></a>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private static function render_usage( array $code ) {
		$rows  = Discount_Redemptions_Repository::get_by_code( $code['id'] );
		$stats = Discount_Redemptions_Repository::stats_for_code( $code['id'] );
		?>
		<h3>
			<?php echo esc_html( sprintf( /* translators: %s: discount code */ __( 'Usage — %s', 'memberistic' ), $code['code'] ) ); ?>
			<a class="button" style="margin-left:10px;" href="<?php echo esc_url( admin_url( 'admin.php?page=memberistic-integrations#discount-codes' ) ); ?>"><?php esc_html_e( '← Back to Discount Codes', 'memberistic' ); ?></a>
		</h3>
		<p>
			<strong><?php esc_html_e( 'Total redemptions:', 'memberistic' ); ?></strong> <?php echo esc_html( (string) $stats['redemptions'] ); ?>
			&nbsp;&nbsp;
			<strong><?php esc_html_e( 'Total discount given:', 'memberistic' ); ?></strong> $<?php echo esc_html( number_format( $stats['total_discounted'], 2 ) ); ?>
		</p>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Redeemed', 'memberistic' ); ?></th>
					<th><?php esc_html_e( 'Email', 'memberistic' ); ?></th>
					<th><?php esc_html_e( 'Membership', 'memberistic' ); ?></th>
					<th><?php esc_html_e( 'Cycle', 'memberistic' ); ?></th>
					<th><?php esc_html_e( 'Before', 'memberistic' ); ?></th>
					<th><?php esc_html_e( 'Discount', 'memberistic' ); ?></th>
					<th><?php esc_html_e( 'After', 'memberistic' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="7"><?php esc_html_e( 'Not redeemed yet.', 'memberistic' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $rows as $r ) : ?>
					<tr>
						<td><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $r['redeemed_at'] ) ); ?></td>
						<td><?php echo esc_html( (string) ( $r['email'] ?? '' ) ); ?></td>
						<td>
							<?php if ( ! empty( $r['membership_id'] ) ) : ?>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=memberistic-members&membership_id=' . (int) $r['membership_id'] ) ); ?>">#<?php echo esc_html( (string) $r['membership_id'] ); ?></a>
							<?php else : ?>
								—
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( ucfirst( (string) ( $r['billing_cycle'] ?? '' ) ) ); ?></td>
						<td>$<?php echo esc_html( number_format( (float) ( $r['amount_before'] ?? 0 ), 2 ) ); ?></td>
						<td>-$<?php echo esc_html( number_format( (float) ( $r['amount_discounted'] ?? 0 ), 2 ) ); ?></td>
						<td>$<?php echo esc_html( number_format( (float) ( $r['amount_after'] ?? 0 ), 2 ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private static function duration_label( array $code ) {
		if ( 'forever' === $code['duration_type'] ) {
			return __( 'Every renewal', 'memberistic' );
		}
		if ( 'repeating' === $code['duration_type'] ) {
			return sprintf(
				/* translators: %d: number of months */
				_n( '%d month', '%d months', (int) $code['duration_in_months'], 'memberistic' ),
				(int) $code['duration_in_months']
			);
		}
		return __( 'First cycle only', 'memberistic' );
	}

	private static function to_local_input( $mysql_datetime ) {
		if ( empty( $mysql_datetime ) ) {
			return '';
		}
		$ts = strtotime( (string) $mysql_datetime );
		return $ts ? gmdate( 'Y-m-d\TH:i', $ts ) : '';
	}

	/**
	 * Persist a create/update (admin-post handler). Stripe Coupons are
	 * immutable once created, so a money-relevant edit to an
	 * already-synced code retires the old Promotion Code and mints a new
	 * Coupon + Promotion Code rather than silently keep serving the old
	 * discount at checkout.
	 */
	public static function handle_save() {
		if ( ! current_user_can( 'manage_memberistic_settings' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage discount codes.', 'memberistic' ) );
		}
		check_admin_referer( self::NONCE_ACTION, 'memberistic_discount_code_nonce' );

		$id     = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$before = $id ? Discount_Codes_Repository::get( $id ) : null;

		$code_str = strtoupper( trim( sanitize_text_field( wp_unslash( $_POST['code'] ?? '' ) ) ) );
		if ( '' === $code_str ) {
			self::redirect( array( 'dc_notice' => 'error' ) );
		}

		$existing_by_code = Discount_Codes_Repository::get_by_code( $code_str );
		if ( $existing_by_code && (int) $existing_by_code['id'] !== $id ) {
			self::redirect( array( 'dc_notice' => 'error' ) );
		}

		$data = array(
			'code'                     => $code_str,
			'description'              => sanitize_text_field( wp_unslash( $_POST['description'] ?? '' ) ),
			'discount_type'            => sanitize_key( wp_unslash( $_POST['discount_type'] ?? 'percent' ) ),
			'discount_value'           => (float) ( $_POST['discount_value'] ?? 0 ),
			'applies_to'               => sanitize_key( wp_unslash( $_POST['applies_to'] ?? 'all' ) ),
			'plan_ids'                 => isset( $_POST['plan_ids'] ) && is_array( $_POST['plan_ids'] ) ? array_map( 'absint', wp_unslash( $_POST['plan_ids'] ) ) : array(),
			'billing_cycles'           => sanitize_key( wp_unslash( $_POST['billing_cycles'] ?? 'all' ) ),
			'duration_type'            => sanitize_key( wp_unslash( $_POST['duration_type'] ?? 'once' ) ),
			'duration_in_months'       => '' !== trim( (string) ( $_POST['duration_in_months'] ?? '' ) ) ? absint( $_POST['duration_in_months'] ) : null,
			'max_redemptions'          => '' !== trim( (string) ( $_POST['max_redemptions'] ?? '' ) ) ? absint( $_POST['max_redemptions'] ) : null,
			'max_redemptions_per_user' => '' !== trim( (string) ( $_POST['max_redemptions_per_user'] ?? '' ) ) ? absint( $_POST['max_redemptions_per_user'] ) : 1,
			'starts_at'                => sanitize_text_field( wp_unslash( $_POST['starts_at'] ?? '' ) ),
			'expires_at'               => sanitize_text_field( wp_unslash( $_POST['expires_at'] ?? '' ) ),
			'status'                   => sanitize_key( wp_unslash( $_POST['status'] ?? 'active' ) ),
			'created_by'               => get_current_user_id(),
		);

		if ( $data['discount_value'] <= 0 ) {
			self::redirect( array( 'dc_notice' => 'error' ) );
		}

		if ( $id ) {
			Discount_Codes_Repository::update( $id, $data );
		} else {
			$id = Discount_Codes_Repository::create( $data );
			if ( ! $id ) {
				self::redirect( array( 'dc_notice' => 'error' ) );
			}
		}

		$row    = Discount_Codes_Repository::get( $id );
		$notice = 'saved';

		$money_fields  = array( 'code', 'discount_type', 'discount_value', 'duration_type', 'duration_in_months', 'max_redemptions', 'expires_at' );
		$money_changed = false;
		if ( $before ) {
			foreach ( $money_fields as $f ) {
				if ( ( $before[ $f ] ?? null ) !== ( $row[ $f ] ?? null ) ) {
					$money_changed = true;
					break;
				}
			}
		}

		if ( 'active' === $row['status'] ) {
			if ( empty( $row['stripe_promotion_code_id'] ) || $money_changed ) {
				if ( ! empty( $row['stripe_promotion_code_id'] ) && $money_changed ) {
					Discount_Code_Service::deactivate_on_stripe( $row );
				}
				$sync = Discount_Code_Service::sync_to_stripe( $row );
				if ( ! empty( $sync['ok'] ) ) {
					Discount_Codes_Repository::update(
						$id,
						array(
							'stripe_coupon_id'         => $sync['stripe_coupon_id'],
							'stripe_promotion_code_id' => $sync['stripe_promotion_code_id'],
							'sync_error'               => null,
						)
					);
				} else {
					Discount_Codes_Repository::update( $id, array( 'sync_error' => $sync['error'] ?? __( 'Unknown Stripe error.', 'memberistic' ) ) );
					$notice = 'sync_failed';
				}
			} elseif ( ! $before || 'active' !== $before['status'] ) {
				// Reactivated with no money-relevant change — re-enable the
				// existing Promotion Code instead of minting a new one.
				Discount_Code_Service::activate_on_stripe( $row );
			}
		} elseif ( ( ! $before || 'active' === $before['status'] ) && ! empty( $row['stripe_promotion_code_id'] ) ) {
			Discount_Code_Service::deactivate_on_stripe( $row );
		}

		self::redirect( array( 'dc_notice' => $notice ) );
	}

	/** Admin-post handler: flip active/inactive (and mirror to Stripe). */
	public static function handle_toggle() {
		if ( ! current_user_can( 'manage_memberistic_settings' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage discount codes.', 'memberistic' ) );
		}
		check_admin_referer( self::NONCE_ACTION );

		$id  = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$row = $id ? Discount_Codes_Repository::get( $id ) : null;
		if ( ! $row ) {
			self::redirect( array( 'dc_notice' => 'error' ) );
		}

		$new_status = 'active' === $row['status'] ? 'inactive' : 'active';
		Discount_Codes_Repository::update( $id, array( 'status' => $new_status ) );

		if ( 'inactive' === $new_status ) {
			Discount_Code_Service::deactivate_on_stripe( $row );
		} elseif ( ! empty( $row['stripe_promotion_code_id'] ) ) {
			Discount_Code_Service::activate_on_stripe( $row );
		} else {
			$sync = Discount_Code_Service::sync_to_stripe( Discount_Codes_Repository::get( $id ) );
			if ( ! empty( $sync['ok'] ) ) {
				Discount_Codes_Repository::update(
					$id,
					array(
						'stripe_coupon_id'         => $sync['stripe_coupon_id'],
						'stripe_promotion_code_id' => $sync['stripe_promotion_code_id'],
						'sync_error'               => null,
					)
				);
			} else {
				Discount_Codes_Repository::update( $id, array( 'sync_error' => $sync['error'] ?? '' ) );
			}
		}

		self::redirect( array( 'dc_notice' => 'saved' ) );
	}

	private static function redirect( array $extra_args = array() ) {
		wp_safe_redirect( add_query_arg( array_merge( array( 'page' => 'memberistic-integrations' ), $extra_args ), admin_url( 'admin.php' ) ) . '#discount-codes' );
		exit;
	}
}
