<?php
/**
 * Staff dashboard template.
 *
 * @package Memberistic
 */

use WordPressistic\Memberistic\Database\People_Repository;
use WordPressistic\Memberistic\Frontend\Staff_Dashboard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="memberistic-frontend memberistic-staff-dashboard">
	<div class="memberistic-staff-header">
		<div>
			<p><?php esc_html_e( 'Front Desk', 'memberistic' ); ?></p>
			<h2><?php esc_html_e( 'Memberistic Staff Dashboard', 'memberistic' ); ?></h2>
		</div>
		<div class="memberistic-staff-user">
			<?php echo esc_html( wp_get_current_user()->display_name ); ?>
		</div>
	</div>

	<?php if ( $notice && Staff_Dashboard::notice_text( $notice ) ) : ?>
		<div class="memberistic-staff-notice memberistic-staff-notice--<?php echo esc_attr( 'error' === $notice_type ? 'error' : 'success' ); ?>">
			<?php echo esc_html( Staff_Dashboard::notice_text( $notice ) ); ?>
		</div>
		<div class="memberistic-toast" data-memberistic-toast="<?php echo esc_attr( 'error' === $notice_type ? 'error' : 'success' ); ?>">
			<div>
				<strong><?php echo esc_html( 'error' === $notice_type ? __( 'Action Needed', 'memberistic' ) : __( 'Action Complete', 'memberistic' ) ); ?></strong>
				<p><?php echo esc_html( Staff_Dashboard::notice_text( $notice ) ); ?></p>
			</div>
			<button type="button" data-memberistic-toast-close><?php esc_html_e( 'Close', 'memberistic' ); ?></button>
		</div>
	<?php endif; ?>

	<div class="memberistic-staff-kpis">
		<div><span><?php esc_html_e( 'Active Members', 'memberistic' ); ?></span><strong><?php echo esc_html( number_format_i18n( $stats['active'] ) ); ?></strong></div>
		<div><span><?php esc_html_e( 'Pending Members', 'memberistic' ); ?></span><strong><?php echo esc_html( number_format_i18n( $stats['pending'] ) ); ?></strong></div>
		<div><span><?php esc_html_e( 'Check-Ins Today', 'memberistic' ); ?></span><strong><?php echo esc_html( number_format_i18n( $stats['checkins_today'] ) ); ?></strong></div>
		<div><span><?php esc_html_e( 'Waivers Needed', 'memberistic' ); ?></span><strong><?php echo esc_html( number_format_i18n( $stats['waivers'] ) ); ?></strong></div>
	</div>

	<div class="memberistic-staff-grid">
		<section class="memberistic-staff-panel">
			<h3><?php esc_html_e( 'Create Walk-In Membership', 'memberistic' ); ?></h3>
			<form method="post" class="memberistic-staff-form">
				<?php wp_nonce_field( 'memberistic_staff_dashboard' ); ?>
				<input type="hidden" name="memberistic_staff_action" value="create_membership">
				<label>
					<span><?php esc_html_e( 'Plan', 'memberistic' ); ?></span>
					<select name="plan_id" required>
						<option value=""><?php esc_html_e( 'Select plan', 'memberistic' ); ?></option>
						<?php foreach ( $plans as $plan ) : ?>
							<option value="<?php echo esc_attr( (int) $plan['id'] ); ?>"><?php echo esc_html( $plan['name'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label>
					<span><?php esc_html_e( 'Billing', 'memberistic' ); ?></span>
					<select name="billing_cycle">
						<option value="monthly"><?php esc_html_e( 'Monthly', 'memberistic' ); ?></option>
						<option value="annual"><?php esc_html_e( 'Annual', 'memberistic' ); ?></option>
					</select>
				</label>
				<label>
					<span><?php esc_html_e( 'Status', 'memberistic' ); ?></span>
					<select name="status">
						<option value="active"><?php esc_html_e( 'Active', 'memberistic' ); ?></option>
						<option value="pending"><?php esc_html_e( 'Pending', 'memberistic' ); ?></option>
						<option value="trial"><?php esc_html_e( 'Trial', 'memberistic' ); ?></option>
						<option value="comped"><?php esc_html_e( 'Comped', 'memberistic' ); ?></option>
					</select>
				</label>
				<label>
					<span><?php esc_html_e( 'Full Name', 'memberistic' ); ?></span>
					<input type="text" name="full_name" required>
				</label>
				<label>
					<span><?php esc_html_e( 'Email', 'memberistic' ); ?></span>
					<input type="email" name="email">
				</label>
				<label>
					<span><?php esc_html_e( 'Phone', 'memberistic' ); ?></span>
					<input type="text" name="phone">
				</label>
				<label>
					<span><?php esc_html_e( 'Waiver', 'memberistic' ); ?></span>
					<select name="waiver_status">
						<option value="missing"><?php esc_html_e( 'Missing', 'memberistic' ); ?></option>
						<option value="signed"><?php esc_html_e( 'Signed', 'memberistic' ); ?></option>
						<option value="needs_review"><?php esc_html_e( 'Needs Review', 'memberistic' ); ?></option>
					</select>
				</label>
				<label class="memberistic-staff-wide">
					<span><?php esc_html_e( 'Staff Notes', 'memberistic' ); ?></span>
					<textarea name="notes" rows="3"></textarea>
				</label>
				<button type="submit" class="memberistic-plan-button"><?php esc_html_e( 'Create Membership', 'memberistic' ); ?></button>
			</form>
		</section>

		<section class="memberistic-staff-panel">
			<h3><?php esc_html_e( 'Find Members', 'memberistic' ); ?></h3>
			<form method="get" class="memberistic-staff-search">
				<input type="search" name="memberistic_staff_search" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Name, email, phone, or member ID', 'memberistic' ); ?>">
				<select name="memberistic_staff_status">
					<option value=""><?php esc_html_e( 'Any status', 'memberistic' ); ?></option>
					<?php foreach ( array( 'active', 'pending', 'paused', 'cancelled', 'trial', 'comped' ) as $member_status ) : ?>
						<option value="<?php echo esc_attr( $member_status ); ?>" <?php selected( $status, $member_status ); ?>><?php echo esc_html( ucfirst( $member_status ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="submit"><?php esc_html_e( 'Search', 'memberistic' ); ?></button>
			</form>

			<div class="memberistic-staff-list">
				<?php foreach ( array_slice( $members, 0, 12 ) as $member ) : ?>
					<?php $primary = People_Repository::get_primary_by_membership( (int) $member['id'] ); ?>
					<article class="memberistic-staff-member">
						<div>
							<strong><?php echo esc_html( $member['full_name'] ?: $member['membership_uuid'] ); ?></strong>
							<span><?php echo esc_html( $member['plan_name'] ?: __( 'No plan', 'memberistic' ) ); ?> · <?php echo esc_html( ucfirst( $member['status'] ) ); ?></span>
							<small><?php echo esc_html( trim( (string) $member['email'] . ' ' . (string) $member['phone'] ) ); ?></small>
						</div>
						<?php if ( $primary ) : ?>
							<form method="post">
								<?php wp_nonce_field( 'memberistic_staff_dashboard' ); ?>
								<input type="hidden" name="memberistic_staff_action" value="record_checkin">
								<input type="hidden" name="membership_id" value="<?php echo esc_attr( (int) $member['id'] ); ?>">
								<input type="hidden" name="person_id" value="<?php echo esc_attr( (int) $primary['id'] ); ?>">
								<input type="hidden" name="checkin_type" value="walk_in">
								<button type="submit"><?php esc_html_e( 'Check In', 'memberistic' ); ?></button>
							</form>
						<?php endif; ?>
					</article>
				<?php endforeach; ?>
				<?php if ( empty( $members ) ) : ?>
					<p class="memberistic-staff-empty"><?php esc_html_e( 'No members found.', 'memberistic' ); ?></p>
				<?php endif; ?>
			</div>
		</section>
	</div>

	<div class="memberistic-staff-grid memberistic-staff-grid--bottom">
		<section class="memberistic-staff-panel">
			<h3><?php esc_html_e( 'Recent Bookings', 'memberistic' ); ?></h3>
			<div class="memberistic-staff-table-wrap">
				<table>
					<thead><tr><th><?php esc_html_e( 'Customer', 'memberistic' ); ?></th><th><?php esc_html_e( 'Booking', 'memberistic' ); ?></th><th><?php esc_html_e( 'Status', 'memberistic' ); ?></th></tr></thead>
					<tbody>
						<?php foreach ( $recent_bookings as $booking ) : ?>
							<tr>
								<td><?php echo esc_html( $booking['customer_name'] ?? '' ); ?><br><small><?php echo esc_html( $booking['customer_email'] ?? '' ); ?></small></td>
								<td><?php echo esc_html( $booking['booking_type_name'] ?? '' ); ?><br><small><?php echo esc_html( trim( (string) ( $booking['resource_name'] ?? '' ) . ' ' . (string) ( $booking['start_at'] ?? '' ) ) ); ?></small></td>
								<td><?php echo esc_html( ucfirst( (string) ( $booking['status'] ?? 'pending' ) ) ); ?></td>
							</tr>
						<?php endforeach; ?>
						<?php if ( empty( $recent_bookings ) ) : ?>
							<tr><td colspan="3"><?php esc_html_e( 'No booking records found yet.', 'memberistic' ); ?></td></tr>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
		</section>

		<section class="memberistic-staff-panel">
			<h3><?php esc_html_e( 'Recent Check-Ins', 'memberistic' ); ?></h3>
			<div class="memberistic-staff-table-wrap">
				<table>
					<thead><tr><th><?php esc_html_e( 'Member', 'memberistic' ); ?></th><th><?php esc_html_e( 'Type', 'memberistic' ); ?></th><th><?php esc_html_e( 'Time', 'memberistic' ); ?></th></tr></thead>
					<tbody>
						<?php foreach ( array_slice( $recent_checkins, 0, 12 ) as $checkin ) : ?>
							<tr>
								<td><?php echo esc_html( $checkin['full_name'] ?: $checkin['membership_uuid'] ); ?></td>
								<td><?php echo esc_html( ucwords( str_replace( '_', ' ', $checkin['checkin_type'] ) ) ); ?></td>
								<td><?php echo esc_html( $checkin['checked_in_at'] ); ?></td>
							</tr>
						<?php endforeach; ?>
						<?php if ( empty( $recent_checkins ) ) : ?>
							<tr><td colspan="3"><?php esc_html_e( 'No check-ins recorded yet.', 'memberistic' ); ?></td></tr>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
		</section>
	</div>
</div>
