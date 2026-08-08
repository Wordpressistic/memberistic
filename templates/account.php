<?php
/**
 * Frontend account template — member dashboard.
 *
 * Sidebar + tabbed layout (Dashboard, Membership Details, Billing,
 * Additional Members, Booking History, Digital Member Card).
 *
 * Styled through the Memberistic design-token layer (see
 * assets/token-bridge.css), so it adopts the active theme's palette where
 * one is published and falls back to Memberistic's own neutral tokens
 * otherwise.
 *
 * @package Memberistic
 */

use WordPressistic\Memberistic\Database\Plans_Repository;
use function WordPressistic\Memberistic\memberistic_format_price;
use function WordPressistic\Memberistic\memberistic_get_page_url;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$plans_url   = memberistic_get_page_url( 'plans_page_id', 'memberships', '' );
if ( ! $plans_url ) { $plans_url = memberistic_get_page_url( 'plans_page_id', 'memberistic-memberships', home_url( '/memberships/' ) ); }
$support_url = home_url( '/get-support/' );
$lane_url    = memberistic_get_page_url( 'booking_page_id', 'book', home_url( '/book/' ) );
?>
<div class="memberistic-frontend memberistic-account memberistic-acct">
<?php if ( ! $current ) : ?>
	<div class="memberistic-acct-empty">
		<h3><?php esc_html_e( 'No active membership found', 'memberistic' ); ?></h3>
		<p><?php esc_html_e( 'No membership is linked to this account yet. If you joined online, sign in with the same email you used at checkout.', 'memberistic' ); ?></p>
		<a class="memberistic-acct-cta memberistic-acct-cta--primary" href="<?php echo esc_url( $plans_url ); ?>"><?php esc_html_e( 'View Membership Plans', 'memberistic' ); ?></a>
		<a class="memberistic-acct-cta memberistic-acct-signout" href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>"><?php esc_html_e( 'Sign Out', 'memberistic' ); ?></a>
	</div>
<?php else :
	$user        = wp_get_current_user();
	$display     = $user->display_name ? $user->display_name : ( $current['full_name'] ?: __( 'Member', 'memberistic' ) );
	$first       = strtok( (string) $display, ' ' );
	$initials    = strtoupper( substr( $first, 0, 1 ) . substr( (string) strrchr( ' ' . $display, ' ' ), 1, 1 ) );
	$plan        = ! empty( $current['plan_id'] ) ? Plans_Repository::get( (int) $current['plan_id'] ) : null;
	$benefits    = $plan ? json_decode( (string) $plan['benefits'], true ) : array();
	$benefits    = is_array( $benefits ) ? $benefits : array();
	$is_annual   = 'annual' === $current['billing_cycle'];
	$price       = $plan ? (float) ( $is_annual ? $plan['annual_price'] : $plan['monthly_price'] ) : 0;
	// Prefer the member's own billing_amount (legacy/grandfathered price
	// carried over at import) so "Next charge" reflects what they actually pay.
	if ( isset( $current['billing_amount'] ) && (float) $current['billing_amount'] > 0 ) {
		$price = (float) $current['billing_amount'];
	}
	$people_max  = (int) ( $current['included_people'] ?? ( $plan['included_people'] ?? 1 ) );
	$people_have = (int) ( $current['people_count'] ?? count( $people ) );
	$currency    = ! empty( $payments[0]['currency'] ) ? $payments[0]['currency'] : 'USD';
	$pay_method  = ! empty( $payments[0]['payment_method'] ) ? ucwords( str_replace( '_', ' ', $payments[0]['payment_method'] ) ) : __( 'Card on file', 'memberistic' );
	$member_id   = memberistic_member_display_id( $current );
	$since       = $current['start_date'] ? date_i18n( 'M Y', strtotime( $current['start_date'] ) ) : '—';
	$renew       = $current['renewal_date'] ? date_i18n( 'M j, Y', strtotime( $current['renewal_date'] ) ) : '—';
	$status      = (string) $current['status'];
	$status_ok   = in_array( $status, array( 'active', 'comped', 'trial' ), true );
	$member_email = $user->user_email ? $user->user_email : ( $current['email'] ?? '' );
	// Self-serve billing destination. Members with a Stripe customer go to the
	// Stripe-hosted billing portal (update card, view invoices, cancel); legacy
	// / cash / POS members (no Stripe customer) are routed to the plans page to
	// start a real recurring subscription. This replaces the old $renewal_url,
	// which resolved back to /account/ itself — the dead "Update Payment
	// Method" link members were reporting.
	$has_stripe_customer = ! empty( $current['stripe_customer_id'] );
	$manage_billing_url  = $has_stripe_customer
		? \WordPressistic\Memberistic\Payments\Stripe_Service::billing_portal_action_url()
		: $plans_url;
	// Dynamic QR payload — encodes this member's verification details, so a
	// scan at the range desk reveals their name, email and membership level.
	$qr_payload  = implode( "\n", array(
		'MEMBERS HUB — MEMBER VERIFICATION',
		'Name: ' . $display,
		'Email: ' . $member_email,
		'Membership: ' . $current['plan_name'] . ' (' . ucfirst( (string) $current['billing_cycle'] ) . ')',
		'Member ID: ' . $member_id,
		'Status: ' . ucfirst( $status ),
		'Renews: ' . $renew,
	) );
	// Dynamic QR — encodes a short verification URL (NOT the PII).
	// Staff scan -> open the URL -> see CURRENT membership status +
	// the member's profile photo on a server-rendered card. The QR
	// payload itself contains no name, email, or member id.
	$verify_url  = class_exists( '\WordPressistic\Memberistic\Utilities\Verification' ) && $user->ID
		? \WordPressistic\Memberistic\Utilities\Verification::verify_url( $user->ID )
		: '';
	$qr_svg      = ( $verify_url && class_exists( '\WordPressistic\Memberistic\Utilities\QR' ) )
		? \WordPressistic\Memberistic\Utilities\QR::svg( $verify_url, 260, '#ffffff', '#0f2044' )
		: '';
	$photo_id    = class_exists( '\WordPressistic\Memberistic\Utilities\Verification' ) && $user->ID
		? \WordPressistic\Memberistic\Utilities\Verification::get_profile_image_id( $user->ID )
		: 0;
	$photo_url   = $photo_id ? wp_get_attachment_image_url( $photo_id, 'medium' ) : '';
	?>
	<div class="memberistic-acct-statusbar">
		<span class="memberistic-acct-statuspill <?php echo $status_ok ? 'is-ok' : 'is-warn'; ?>">
			<span class="memberistic-acct-dot"></span><?php echo esc_html( strtoupper( $status_ok ? __( 'Active Member', 'memberistic' ) : $status ) ); ?>
		</span>
	</div>

	<?php
	// Notice surfaced when the billing-portal handler could not open a Stripe
	// session (portal not yet enabled in the Stripe dashboard, or a transient
	// error). Keeps members from hitting a dead end.
	$billing_notice = isset( $_GET['memberistic_billing'] ) ? sanitize_key( wp_unslash( $_GET['memberistic_billing'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	if ( 'unavailable' === $billing_notice ) :
		?>
		<div class="memberistic-acct-banner">
			<?php esc_html_e( 'Online payment management is briefly unavailable. Please try again shortly or contact the range desk to update your payment.', 'memberistic' ); ?>
		</div>
	<?php endif; ?>

	<?php
	// Waiver CTA — shown when the signed-in member's waiver is missing or
	// expired. Links to their tokenised self-serve waiver page.
	$waiver_ok = false;
	$primary   = null;
	foreach ( (array) $people as $pp ) {
		if ( 'primary' === ( $pp['role'] ?? '' ) || (int) ( $pp['wp_user_id'] ?? 0 ) === (int) $user->ID ) {
			$primary = $pp;
			break;
		}
	}
	if ( $primary && class_exists( '\WordPressistic\Memberistic\Waivers\Waiver_Service' ) ) {
		$waiver_ok = \WordPressistic\Memberistic\Waivers\Waiver_Service::is_current( $primary );
	}
	if ( ! $waiver_ok && class_exists( '\WordPressistic\Memberistic\Waivers\Waiver_Service' ) ) :
		$waiver_link = \WordPressistic\Memberistic\Waivers\Waiver_Service::waiver_url( (int) $user->ID );
		?>
		<div class="memberistic-acct-banner">
			<?php esc_html_e( 'Your range waiver is not signed. Please sign it before your next visit — it takes under a minute.', 'memberistic' ); ?>
			<a href="<?php echo esc_url( $waiver_link ); ?>"><?php esc_html_e( 'Sign waiver', 'memberistic' ); ?></a>
		</div>
	<?php endif; ?>

	<?php if ( in_array( $status, array( 'past_due', 'expired' ), true ) ) : ?>
		<div class="memberistic-acct-banner">
			<?php if ( 'past_due' === $status ) : ?>
				<?php esc_html_e( 'Your membership payment is past due — please update your payment method.', 'memberistic' ); ?>
				<a href="<?php echo esc_url( $manage_billing_url ); ?>"><?php esc_html_e( 'Update payment', 'memberistic' ); ?></a>
			<?php else : ?>
				<?php esc_html_e( 'Your membership has expired.', 'memberistic' ); ?>
				<a href="<?php echo esc_url( $manage_billing_url ); ?>"><?php esc_html_e( 'Renew now', 'memberistic' ); ?></a>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<div class="memberistic-acct-shell">
		<aside class="memberistic-acct-side">
			<div class="memberistic-acct-id">
				<span class="memberistic-acct-avatar" data-mem-avatar>
					<?php if ( $photo_url ) : ?>
						<img src="<?php echo esc_url( $photo_url ); ?>" alt="<?php echo esc_attr( $display ); ?>">
					<?php else : ?>
						<?php echo esc_html( $initials ? $initials : 'M' ); ?>
					<?php endif; ?>
				</span>
				<div>
					<strong><?php echo esc_html( $display ); ?></strong>
					<span><?php printf( esc_html__( 'Member since %s', 'memberistic' ), esc_html( $since ) ); ?></span>
					<div class="memberistic-acct-photo-actions" style="margin-top:6px;">
						<button type="button" class="memberistic-acct-photo-btn" data-mem-photo-trigger><?php echo $photo_id ? esc_html__( 'Change photo', 'memberistic' ) : esc_html__( 'Add photo', 'memberistic' ); ?></button>
						<?php if ( $photo_id ) : ?>
							<button type="button" class="memberistic-acct-photo-btn" data-mem-photo-remove><?php esc_html_e( 'Remove', 'memberistic' ); ?></button>
						<?php endif; ?>
						<input type="file" accept="image/*" data-mem-photo-input hidden>
					</div>
				</div>
			</div>
			<div class="memberistic-acct-planpill"><?php echo esc_html( $current['plan_name'] ); ?> <?php esc_html_e( 'Plan', 'memberistic' ); ?></div>
			<nav class="memberistic-acct-nav">
				<a href="#dashboard" data-tab="dashboard" class="is-active"><span class="memberistic-acct-ic">▦</span><?php esc_html_e( 'Dashboard', 'memberistic' ); ?></a>
				<a href="#book"      data-tab="book"><span class="memberistic-acct-ic">◷</span><?php esc_html_e( 'Book A Lane', 'memberistic' ); ?></a>
				<a href="#details"   data-tab="details"><span class="memberistic-acct-ic">◇</span><?php esc_html_e( 'Membership Details', 'memberistic' ); ?></a>
				<a href="#billing"   data-tab="billing"><span class="memberistic-acct-ic">▭</span><?php esc_html_e( 'Billing &amp; Payments', 'memberistic' ); ?></a>
				<a href="#members"   data-tab="members"><span class="memberistic-acct-ic">⚇</span><?php esc_html_e( 'Additional Members', 'memberistic' ); ?></a>
				<a href="#bookings"  data-tab="bookings"><span class="memberistic-acct-ic">▥</span><?php esc_html_e( 'Booking History', 'memberistic' ); ?></a>
				<?php if ( class_exists( 'WooCommerce' ) ) : ?>
					<a href="#shop"  data-tab="shop"><span class="memberistic-acct-ic">▣</span><?php esc_html_e( 'Shop Orders', 'memberistic' ); ?></a>
				<?php endif; ?>
				<a href="#card"      data-tab="card"><span class="memberistic-acct-ic">▤</span><?php esc_html_e( 'Digital Member Card', 'memberistic' ); ?></a>
				<span class="memberistic-acct-navsep"></span>
				<a href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>" class="memberistic-acct-signout"><span class="memberistic-acct-ic">⤶</span><?php esc_html_e( 'Sign Out', 'memberistic' ); ?></a>
			</nav>
		</aside>

		<div class="memberistic-acct-main">

			<!-- DASHBOARD -->
			<section class="memberistic-acct-view is-active" data-panel="dashboard">
				<header class="memberistic-acct-welcome">
					<h2><?php printf( esc_html__( 'Welcome back, %s.', 'memberistic' ), esc_html( $first ) ); ?></h2>
					<p><?php esc_html_e( 'Your range access is active and your member card is ready. Lane availability is good for today.', 'memberistic' ); ?></p>
				</header>
				<div class="memberistic-acct-stats">
					<div class="memberistic-acct-stat"><span><?php esc_html_e( 'Plan', 'memberistic' ); ?></span><strong><?php echo esc_html( $current['plan_name'] ); ?></strong></div>
					<div class="memberistic-acct-stat"><span><?php esc_html_e( 'Members', 'memberistic' ); ?></span><strong><?php echo esc_html( $people_have . '/' . $people_max ); ?></strong></div>
					<div class="memberistic-acct-stat"><span><?php esc_html_e( 'Next Renewal', 'memberistic' ); ?></span><strong><?php echo esc_html( $renew ); ?></strong></div>
					<div class="memberistic-acct-stat"><span><?php esc_html_e( 'Member Since', 'memberistic' ); ?></span><strong><?php echo esc_html( $since ); ?></strong></div>
				</div>
				<div class="memberistic-acct-actions">
					<a class="memberistic-acct-action" href="#" data-open-booking>
						<span class="memberistic-acct-ic">◷</span>
						<span><strong><?php esc_html_e( 'Book A Lane', 'memberistic' ); ?></strong><small><?php esc_html_e( 'Reserve online 24/7', 'memberistic' ); ?></small></span>
					</a>
					<div class="memberistic-acct-action is-static">
						<span class="memberistic-acct-ic">◴</span>
						<span><strong><?php esc_html_e( 'Range Hours', 'memberistic' ); ?></strong><small><?php esc_html_e( 'Mon–Thu 10–6 · Fri & Sat 10–7 · Sun 12–6', 'memberistic' ); ?></small></span>
					</div>
					<a class="memberistic-acct-action" href="#members" data-tab="members">
						<span class="memberistic-acct-ic">⚇</span>
						<span><strong><?php esc_html_e( 'Manage Members', 'memberistic' ); ?></strong><small><?php esc_html_e( 'Add, edit or remove people', 'memberistic' ); ?></small></span>
					</a>
					<a class="memberistic-acct-action" href="<?php echo esc_url( $manage_billing_url ); ?>">
						<span class="memberistic-acct-ic">▭</span>
						<span><strong><?php esc_html_e( 'Update Payment', 'memberistic' ); ?></strong><small><?php echo esc_html( $pay_method ); ?></small></span>
					</a>
				</div>
			</section>

			<?php
			/**
			 * Corporate Groups portal injection (Phase 6). Owner self-
			 * service view is gated by a per-group admin toggle.
			 */
			do_action( 'memberistic_account_dashboard_end', $user );
			?>

			<!-- BOOK A LANE — the tab acts as a "Reserve your lane" hero;
			     the actual booking form lives in a full-screen modal so
			     it has room to render its two-column date/lane layout
			     properly. Clicking the CTA (or the dashboard tile, or
			     this sidebar link) opens that modal. -->
			<section class="memberistic-acct-view" data-panel="book">
				<div class="memberistic-acct-block memberistic-acct-block--center">
					<h2><?php esc_html_e( 'Reserve Your Lane', 'memberistic' ); ?></h2>
					<p class="memberistic-acct-muted" style="margin:8px 0 22px;max-width:48ch;">
						<?php esc_html_e( 'Pick any open slot — your contact details on file are pre-filled, so it only takes a few taps.', 'memberistic' ); ?>
					</p>
					<div class="memberistic-acct-ctas memberistic-acct-ctas--center">
						<button type="button" class="memberistic-acct-cta memberistic-acct-cta--primary" data-open-booking>
							<?php esc_html_e( 'Open Booking Form', 'memberistic' ); ?>
						</button>
					</div>
				</div>
			</section>

			<!-- MEMBERSHIP DETAILS -->
			<section class="memberistic-acct-view" data-panel="details">
				<div class="memberistic-acct-block">
					<div class="memberistic-acct-blockhd">
						<h2><?php esc_html_e( 'Plan Details', 'memberistic' ); ?></h2>
						<span class="memberistic-acct-tag"><?php echo esc_html( $current['plan_name'] ); ?></span>
					</div>
					<?php if ( $benefits ) : ?>
						<ul class="memberistic-acct-benefits">
							<?php foreach ( $benefits as $benefit ) : ?>
								<li><?php echo esc_html( $benefit ); ?></li>
							<?php endforeach; ?>
						</ul>
					<?php else : ?>
						<p class="memberistic-acct-muted"><?php esc_html_e( 'Unlimited range time and full member benefits.', 'memberistic' ); ?></p>
					<?php endif; ?>
					<div class="memberistic-acct-ctas">
						<a class="memberistic-acct-cta memberistic-acct-cta--primary" href="<?php echo esc_url( $plans_url ); ?>"><?php esc_html_e( 'Change Plan', 'memberistic' ); ?></a>
						<a class="memberistic-acct-cta memberistic-acct-cta--ghost" href="<?php echo esc_url( $manage_billing_url ); ?>"><?php echo esc_html( $is_annual ? __( 'Switch to Monthly', 'memberistic' ) : __( 'Switch to Annual', 'memberistic' ) ); ?></a>
					</div>
				</div>
				<div class="memberistic-acct-block memberistic-acct-block--danger">
					<h3><?php esc_html_e( 'Cancel Membership', 'memberistic' ); ?></h3>
					<p class="memberistic-acct-muted"><?php esc_html_e( 'Cancel anytime. Access remains active until the end of your current billing period.', 'memberistic' ); ?></p>
					<a class="memberistic-acct-cta memberistic-acct-cta--ghost" href="<?php echo esc_url( $support_url ); ?>"><?php esc_html_e( 'Request Cancellation', 'memberistic' ); ?></a>
				</div>
			</section>

			<!-- BILLING -->
			<section class="memberistic-acct-view" data-panel="billing">
				<div class="memberistic-acct-block">
					<h2><?php esc_html_e( 'Next Charge', 'memberistic' ); ?></h2>
					<div class="memberistic-acct-charge">
						<div>
							<span class="memberistic-acct-amount"><?php echo esc_html( memberistic_format_price( $price, $currency ) ); ?></span>
							<span class="memberistic-acct-muted"><?php printf( esc_html__( 'on %s', 'memberistic' ), esc_html( $renew ) ); ?></span>
						</div>
						<div>
							<span class="memberistic-acct-mini"><?php esc_html_e( 'Method', 'memberistic' ); ?></span>
							<strong><?php echo esc_html( $pay_method ); ?></strong>
						</div>
						<a class="memberistic-acct-cta memberistic-acct-cta--ghost" href="<?php echo esc_url( $manage_billing_url ); ?>"><?php esc_html_e( 'Update Payment Method', 'memberistic' ); ?></a>
					</div>
				</div>
				<div class="memberistic-acct-block">
					<h2><?php esc_html_e( 'Billing History', 'memberistic' ); ?></h2>
					<?php if ( empty( $payments ) ) : ?>
						<p class="memberistic-acct-muted"><?php esc_html_e( 'No payments are recorded yet.', 'memberistic' ); ?></p>
					<?php else : ?>
						<div class="memberistic-acct-tablewrap">
							<table class="memberistic-acct-table">
								<thead><tr>
									<th><?php esc_html_e( 'Date', 'memberistic' ); ?></th>
									<th><?php esc_html_e( 'Amount', 'memberistic' ); ?></th>
									<th><?php esc_html_e( 'Method', 'memberistic' ); ?></th>
									<th><?php esc_html_e( 'Reference', 'memberistic' ); ?></th>
									<th><?php esc_html_e( 'Status', 'memberistic' ); ?></th>
								</tr></thead>
								<tbody>
									<?php foreach ( $payments as $payment ) :
										$pstatus = strtolower( (string) $payment['status'] );
										$pref    = (string) ( $payment['gateway_transaction_id'] ?? '' );
										$pmethod = $payment['payment_method'] ? ucwords( str_replace( '_', ' ', (string) $payment['payment_method'] ) ) : __( 'Card', 'memberistic' );
										?>
										<tr>
											<td><?php echo esc_html( date_i18n( 'M j, Y', strtotime( $payment['paid_at'] ?: $payment['created_at'] ) ) ); ?></td>
											<td><?php echo esc_html( memberistic_format_price( $payment['amount'], $payment['currency'] ) ); ?></td>
											<td><?php echo esc_html( $pmethod ); ?></td>
											<td><code class="memberistic-acct-ref"><?php echo esc_html( $pref ?: '—' ); ?></code></td>
											<td><span class="memberistic-acct-pill memberistic-acct-pill--<?php echo esc_attr( in_array( $pstatus, array( 'completed', 'success', 'paid' ), true ) ? 'ok' : 'warn' ); ?>"><?php echo esc_html( ucfirst( $pstatus ) ); ?></span></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					<?php endif; ?>
				</div>
			</section>

			<!-- ADDITIONAL MEMBERS -->
			<section class="memberistic-acct-view" data-panel="members">
				<div class="memberistic-acct-block">
					<div class="memberistic-acct-blockhd">
						<h2><?php esc_html_e( 'People On This Membership', 'memberistic' ); ?></h2>
						<span class="memberistic-acct-tag"><?php echo esc_html( $people_have . ' / ' . $people_max ); ?></span>
					</div>
					<?php if ( empty( $people ) ) : ?>
						<p class="memberistic-acct-muted"><?php esc_html_e( 'No people are linked to this membership yet.', 'memberistic' ); ?></p>
					<?php else : ?>
						<div class="memberistic-acct-tablewrap">
							<table class="memberistic-acct-table">
								<thead><tr>
									<th><?php esc_html_e( 'Name', 'memberistic' ); ?></th>
									<th><?php esc_html_e( 'Role', 'memberistic' ); ?></th>
									<th><?php esc_html_e( 'Waiver', 'memberistic' ); ?></th>
									<th><?php esc_html_e( 'Status', 'memberistic' ); ?></th>
								</tr></thead>
								<tbody>
									<?php foreach ( $people as $person ) : ?>
										<tr>
											<td><?php echo esc_html( $person['full_name'] ); ?></td>
											<td><?php echo esc_html( ucwords( (string) $person['role'] ) ); ?></td>
											<td><span class="memberistic-acct-pill memberistic-acct-pill--<?php echo esc_attr( 'signed' === $person['waiver_status'] ? 'ok' : 'warn' ); ?>"><?php echo esc_html( ucfirst( str_replace( '_', ' ', (string) $person['waiver_status'] ) ) ); ?></span></td>
											<td><span class="memberistic-acct-pill memberistic-acct-pill--<?php echo esc_attr( 'active' === $person['status'] ? 'ok' : 'warn' ); ?>"><?php echo esc_html( ucfirst( (string) $person['status'] ) ); ?></span></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					<?php endif; ?>
					<div class="memberistic-acct-ctas">
						<a class="memberistic-acct-cta memberistic-acct-cta--ghost" href="<?php echo esc_url( $support_url ); ?>"><?php esc_html_e( 'Add or Update a Member', 'memberistic' ); ?></a>
					</div>
				</div>
			</section>

			<!-- BOOKING HISTORY -->
			<section class="memberistic-acct-view" data-panel="bookings">
				<div class="memberistic-acct-block">
					<h2><?php esc_html_e( 'Booking &amp; Check-In History', 'memberistic' ); ?></h2>
					<?php if ( empty( $bookings ) && empty( $checkins ) ) : ?>
						<p class="memberistic-acct-muted"><?php esc_html_e( 'No booking or check-in records yet.', 'memberistic' ); ?></p>
					<?php else : ?>
						<div class="memberistic-acct-tablewrap">
							<table class="memberistic-acct-table">
								<thead><tr>
									<th><?php esc_html_e( 'Type', 'memberistic' ); ?></th>
									<th><?php esc_html_e( 'Resource', 'memberistic' ); ?></th>
									<th><?php esc_html_e( 'Status', 'memberistic' ); ?></th>
									<th><?php esc_html_e( 'Date', 'memberistic' ); ?></th>
								</tr></thead>
								<tbody>
									<?php foreach ( $bookings as $booking ) : ?>
										<tr>
											<td><?php echo esc_html( ! empty( $booking['booking_type_name'] ) ? $booking['booking_type_name'] : __( 'Lane Booking', 'memberistic' ) ); ?></td>
											<td><?php echo esc_html( $booking['resource_name'] ?? '—' ); ?></td>
											<td><span class="memberistic-acct-pill"><?php echo esc_html( ucfirst( (string) ( $booking['status'] ?? 'pending' ) ) ); ?></span></td>
											<td><?php echo esc_html( ! empty( $booking['start_at'] ) ? date_i18n( 'M j, Y', strtotime( $booking['start_at'] ) ) : '—' ); ?></td>
										</tr>
									<?php endforeach; ?>
									<?php foreach ( $checkins as $checkin ) : ?>
										<tr>
											<td><?php esc_html_e( 'Range Check-In', 'memberistic' ); ?></td>
											<td><?php echo esc_html( ucwords( str_replace( '_', ' ', (string) ( $checkin['checkin_type'] ?? '' ) ) ) ); ?></td>
											<td><span class="memberistic-acct-pill memberistic-acct-pill--ok"><?php echo esc_html( ucfirst( (string) ( $checkin['status'] ?? '' ) ) ); ?></span></td>
											<td><?php echo esc_html( ! empty( $checkin['checked_in_at'] ) ? date_i18n( 'M j, Y', strtotime( $checkin['checked_in_at'] ) ) : '—' ); ?></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					<?php endif; ?>
				</div>
			</section>

			<?php if ( class_exists( 'WooCommerce' ) ) :
				// ── SHOP — the member's WooCommerce world, inside the
				// membership dashboard: orders, downloads, addresses, and
				// the running "member savings" total. Members never need
				// to visit the separate /my-account/ page. ──
				$shop_user_id  = get_current_user_id();
				$shop_orders   = function_exists( 'wc_get_orders' ) ? wc_get_orders( array( 'customer_id' => $shop_user_id, 'limit' => 10, 'orderby' => 'date', 'order' => 'DESC' ) ) : array();
				$shop_count    = function_exists( 'wc_get_customer_order_count' ) ? (int) wc_get_customer_order_count( $shop_user_id ) : count( (array) $shop_orders );
				$shop_spent    = function_exists( 'wc_get_customer_total_spent' ) ? (float) wc_get_customer_total_spent( $shop_user_id ) : 0.0;
				$shop_saved    = class_exists( '\\WordPressistic\\Memberistic\\Integrations\\WooCommerce_Discounts' )
					? \WordPressistic\Memberistic\Integrations\WooCommerce_Discounts::total_savings( $shop_user_id ) : 0.0;
				$shop_downloads = function_exists( 'wc_get_customer_available_downloads' ) ? wc_get_customer_available_downloads( $shop_user_id ) : array();
				$addr_billing  = function_exists( 'wc_get_account_formatted_address' ) ? wc_get_account_formatted_address( 'billing' ) : '';
				$addr_shipping = function_exists( 'wc_get_account_formatted_address' ) ? wc_get_account_formatted_address( 'shipping' ) : '';
				$ep            = static function ( $endpoint ) {
					return function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( $endpoint ) : '#';
				};
			?>
			<!-- SHOP / WOOCOMMERCE -->
			<section class="memberistic-acct-view" data-panel="shop">
				<div class="memberistic-acct-stats">
					<div class="memberistic-acct-stat"><span><?php esc_html_e( 'Orders', 'memberistic' ); ?></span><strong><?php echo esc_html( number_format_i18n( $shop_count ) ); ?></strong></div>
					<div class="memberistic-acct-stat"><span><?php esc_html_e( 'Total Spent', 'memberistic' ); ?></span><strong><?php echo wp_kses_post( wc_price( $shop_spent ) ); ?></strong></div>
					<div class="memberistic-acct-stat"><span><?php esc_html_e( 'Saved As A Member', 'memberistic' ); ?></span><strong style="color:var(--ma-brass2);"><?php echo wp_kses_post( wc_price( $shop_saved ) ); ?></strong></div>
					<div class="memberistic-acct-stat"><span><?php esc_html_e( 'Downloads', 'memberistic' ); ?></span><strong><?php echo esc_html( number_format_i18n( count( (array) $shop_downloads ) ) ); ?></strong></div>
				</div>

				<div class="memberistic-acct-block">
					<h2><?php esc_html_e( 'Recent Orders', 'memberistic' ); ?></h2>
					<?php if ( empty( $shop_orders ) ) : ?>
						<p class="memberistic-acct-muted"><?php esc_html_e( 'No orders yet. Member pricing is applied automatically at checkout when you shop signed in.', 'memberistic' ); ?></p>
						<p><a class="memberistic-acct-cta memberistic-acct-cta--primary" href="<?php echo esc_url( function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/shop/' ) ); ?>"><?php esc_html_e( 'Browse The Shop', 'memberistic' ); ?></a></p>
					<?php else : ?>
						<div class="memberistic-acct-tablewrap">
							<table class="memberistic-acct-table">
								<thead><tr>
									<th><?php esc_html_e( 'Order', 'memberistic' ); ?></th>
									<th><?php esc_html_e( 'Date', 'memberistic' ); ?></th>
									<th><?php esc_html_e( 'Status', 'memberistic' ); ?></th>
									<th><?php esc_html_e( 'Total', 'memberistic' ); ?></th>
									<th><?php esc_html_e( 'Member Saving', 'memberistic' ); ?></th>
									<th></th>
								</tr></thead>
								<tbody>
								<?php foreach ( $shop_orders as $shop_order ) :
									if ( ! is_object( $shop_order ) ) { continue; }
									$saving = (float) $shop_order->get_meta( '_memberistic_member_discount' );
								?>
									<tr>
										<td>#<?php echo esc_html( $shop_order->get_order_number() ); ?></td>
										<td><?php echo esc_html( $shop_order->get_date_created() ? $shop_order->get_date_created()->date_i18n( 'M j, Y' ) : '—' ); ?></td>
										<td><span class="memberistic-acct-pill"><?php echo esc_html( wc_get_order_status_name( $shop_order->get_status() ) ); ?></span></td>
										<td><?php echo wp_kses_post( $shop_order->get_formatted_order_total() ); ?></td>
										<td><?php echo $saving > 0 ? wp_kses_post( wc_price( $saving ) ) : '—'; ?></td>
										<td><a href="<?php echo esc_url( $shop_order->get_view_order_url() ); ?>"><?php esc_html_e( 'View', 'memberistic' ); ?></a></td>
									</tr>
								<?php endforeach; ?>
								</tbody>
							</table>
						</div>
						<p style="margin-top:10px;"><a href="<?php echo esc_url( $ep( 'orders' ) ); ?>"><?php esc_html_e( 'View all orders →', 'memberistic' ); ?></a></p>
					<?php endif; ?>
				</div>

				<?php if ( ! empty( $shop_downloads ) ) : ?>
					<div class="memberistic-acct-block">
						<h2><?php esc_html_e( 'Downloads', 'memberistic' ); ?></h2>
						<div class="memberistic-acct-tablewrap">
							<table class="memberistic-acct-table">
								<thead><tr>
									<th><?php esc_html_e( 'Product', 'memberistic' ); ?></th>
									<th><?php esc_html_e( 'File', 'memberistic' ); ?></th>
									<th></th>
								</tr></thead>
								<tbody>
								<?php foreach ( (array) $shop_downloads as $dl ) : ?>
									<tr>
										<td><?php echo esc_html( (string) ( $dl['product_name'] ?? '' ) ); ?></td>
										<td><?php echo esc_html( (string) ( $dl['download_name'] ?? '' ) ); ?></td>
										<td><a href="<?php echo esc_url( (string) ( $dl['download_url'] ?? '#' ) ); ?>"><?php esc_html_e( 'Download', 'memberistic' ); ?></a></td>
									</tr>
								<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					</div>
				<?php endif; ?>

				<div class="memberistic-acct-block">
					<h2><?php esc_html_e( 'Addresses & Account', 'memberistic' ); ?></h2>
					<div class="memberistic-acct-stats">
						<div class="memberistic-acct-stat" style="text-align:left;">
							<span><?php esc_html_e( 'Billing Address', 'memberistic' ); ?></span>
							<address style="font-style:normal;margin:6px 0;"><?php echo $addr_billing ? wp_kses_post( $addr_billing ) : esc_html__( 'Not set yet.', 'memberistic' ); ?></address>
							<a href="<?php echo esc_url( $ep( 'edit-address' ) . 'billing/' ); ?>"><?php esc_html_e( 'Edit', 'memberistic' ); ?></a>
						</div>
						<div class="memberistic-acct-stat" style="text-align:left;">
							<span><?php esc_html_e( 'Shipping Address', 'memberistic' ); ?></span>
							<address style="font-style:normal;margin:6px 0;"><?php echo $addr_shipping ? wp_kses_post( $addr_shipping ) : esc_html__( 'Not set yet.', 'memberistic' ); ?></address>
							<a href="<?php echo esc_url( $ep( 'edit-address' ) . 'shipping/' ); ?>"><?php esc_html_e( 'Edit', 'memberistic' ); ?></a>
						</div>
					</div>
					<p style="margin-top:12px;">
						<a href="<?php echo esc_url( $ep( 'payment-methods' ) ); ?>"><?php esc_html_e( 'Payment methods', 'memberistic' ); ?></a>
						&nbsp;·&nbsp;
						<a href="<?php echo esc_url( $ep( 'edit-account' ) ); ?>"><?php esc_html_e( 'Account details & password', 'memberistic' ); ?></a>
					</p>
				</div>
			</section>
			<?php endif; ?>

			<!-- DIGITAL MEMBER CARD -->
			<section class="memberistic-acct-view" data-panel="card">
				<div class="memberistic-acct-block memberistic-acct-block--center">
					<h2><?php esc_html_e( 'Your Digital Member Card', 'memberistic' ); ?></h2>
					<?php
					// Pull the site's brand logo for the card. Order of
					// preference: theme's custom-logo (Appearance →
					// Customize → Site Identity), then Memberistic's
					// own logo_url setting, then nothing (fall back to
					// the brand-label text).
					$card_logo_id  = function_exists( 'get_theme_mod' ) ? (int) get_theme_mod( 'custom_logo' ) : 0;
					$card_logo_url = $card_logo_id ? wp_get_attachment_image_url( $card_logo_id, 'medium' ) : '';
					if ( ! $card_logo_url ) {
						$card_logo_url = (string) memberistic_get_setting( 'logo_url', '' );
					}
					?>
					<div class="memberistic-acct-pass" id="memberistic-acct-pass">
						<div class="memberistic-acct-pass__top">
							<span class="memberistic-acct-pass__brand">
								<?php if ( $card_logo_url ) : ?>
									<img class="memberistic-acct-pass__logo" src="<?php echo esc_url( $card_logo_url ); ?>" alt="<?php echo esc_attr( memberistic_get_brand_label() ); ?>">
								<?php else : ?>
									<?php echo esc_html( strtoupper( memberistic_get_brand_label() ) ); ?>
								<?php endif; ?>
							</span>
							<span class="memberistic-acct-pass__plan"><?php echo esc_html( strtoupper( $current['plan_name'] ) ); ?></span>
						</div>
						<?php if ( $photo_url ) : ?>
							<div class="memberistic-acct-pass__photo">
								<img src="<?php echo esc_url( $photo_url ); ?>" alt="<?php echo esc_attr( $display ); ?>">
							</div>
						<?php endif; ?>
						<div class="memberistic-acct-pass__name"><?php echo esc_html( strtoupper( $display ) ); ?></div>
						<div class="memberistic-acct-pass__body">
							<div class="memberistic-acct-pass__meta">
								<span class="memberistic-acct-mini"><?php esc_html_e( 'Member ID', 'memberistic' ); ?></span>
								<strong><?php echo esc_html( $member_id ); ?></strong>
								<span class="memberistic-acct-mini"><?php esc_html_e( 'Member Since', 'memberistic' ); ?></span>
								<strong><?php echo esc_html( $since ); ?></strong>
								<span class="memberistic-acct-mini"><?php esc_html_e( 'Renews', 'memberistic' ); ?></span>
								<strong><?php echo esc_html( $renew ); ?></strong>
							</div>
							<div class="memberistic-acct-pass__qr" role="img" aria-label="<?php esc_attr_e( 'Member verification QR code', 'memberistic' ); ?>">
								<?php
								// Primary: a fetched PNG from a well-tested QR
								// generator (api.qrserver.com). The payload here
								// is ONLY the verify URL — a random 32-char
								// token, no PII — so handing it to a 3rd-party
								// generator is safe.
								//
								// We also render the in-process SVG underneath
								// as a backup that browsers fall back to via the
								// <img> onerror handler, so the card still scans
								// even if the external service is blocked.
								if ( $verify_url ) :
									$qr_remote = add_query_arg(
										array(
											'size'   => '320x320',
											'data'   => rawurlencode( $verify_url ),
											'margin' => '0',
											'ecc'    => 'M',
										),
										'https://api.qrserver.com/v1/create-qr-code/'
									);
									?>
									<img class="memberistic-acct-pass__qr-img"
									     src="<?php echo esc_url( $qr_remote ); ?>"
									     alt=""
									     width="320" height="320"
									     style="width:100%;height:100%;display:block;"
									     onerror="this.style.display='none';var n=this.nextElementSibling;if(n)n.style.display='block';">
									<span class="memberistic-acct-pass__qr-svg" style="display:none;width:100%;height:100%;"><?php
										echo $qr_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- generated SVG with hard-coded markup
									?></span>
								<?php endif; ?>
							</div>
						</div>
					</div>
					<p class="memberistic-acct-mini memberistic-acct-passnote"><?php esc_html_e( 'Show at the range desk · or scan the QR', 'memberistic' ); ?></p>
					<div class="memberistic-acct-ctas memberistic-acct-ctas--center">
						<button type="button" class="memberistic-acct-cta memberistic-acct-cta--primary" data-print-card><?php esc_html_e( 'Download / Print Card', 'memberistic' ); ?></button>
					</div>
				</div>
			</section>

		</div>
	</div>

	<?php
	// Booking modal — rendered once at the bottom of the shell.
	// Stays hidden until [data-open-booking] is clicked. Contains
	// the live booking-engine shortcode + a wrapper carrying the
	// logged-in member's contact details for the auto-fill JS to
	// drop into the form's name/email/phone inputs.
	$member_email_modal = (string) ( $user->user_email ?? '' );
	$member_phone_modal = (string) get_user_meta( $user->ID, 'billing_phone', true );
	if ( ! $member_phone_modal ) {
		$member_phone_modal = (string) get_user_meta( $user->ID, 'memberistic_phone', true );
	}
	?>
	<div class="memberistic-acct-modal" id="memberistic-acct-modal" role="dialog" aria-modal="true" aria-labelledby="memberistic-acct-modal-title" hidden>
		<div class="memberistic-acct-modal__card">
			<div class="memberistic-acct-modal__head">
				<h2 class="memberistic-acct-modal__title" id="memberistic-acct-modal-title"><?php esc_html_e( 'Book A Lane', 'memberistic' ); ?></h2>
				<button type="button" class="memberistic-acct-modal__close" data-close-booking aria-label="<?php esc_attr_e( 'Close', 'memberistic' ); ?>">×</button>
			</div>
			<div class="memberistic-acct-modal__body">
				<div class="memberistic-acct-booking"
				     data-member-name="<?php echo esc_attr( $display ); ?>"
				     data-member-email="<?php echo esc_attr( $member_email_modal ); ?>"
				     data-member-phone="<?php echo esc_attr( $member_phone_modal ); ?>"
				     data-member-plan="<?php echo esc_attr( $current['plan_name'] ); ?>">
					<?php
					$booking_shortcode = \WordPressistic\Memberistic\Integrations\Booking_Adapter::active_shortcode();

					if ( '' !== $booking_shortcode ) {
						echo do_shortcode( '[' . $booking_shortcode . ']' );
					} else {
						echo '<p class="memberistic-acct-muted">' . sprintf(
							/* translators: %s: anchor to the standalone booking page */
							esc_html__( 'The booking module is not active. %s.', 'memberistic' ),
							'<a href="' . esc_url( $lane_url ) . '">' . esc_html__( 'Open the standalone booking page', 'memberistic' ) . '</a>'
						) . '</p>';
					}
					?>
				</div>
			</div>
		</div>
	</div>
<?php endif; ?>
</div>

<style>
.memberistic-acct{--ma-card:var(--memberistic-surface, #26252C);--ma-line:var(--memberistic-border, rgba(255,255,255,.09));--ma-line2:var(--memberistic-border-strong, rgba(255,255,255,.17));--ma-brass:var(--memberistic-primary, #C9A84C);--ma-brass2:var(--memberistic-primary-strong, #E3C06A);--ma-ember:var(--memberistic-cta, #E8802F);--ma-white:var(--memberistic-text, #F4F4F6);--ma-fog:var(--memberistic-text-soft, #CBCAD2);--ma-silver:var(--memberistic-muted, #A7A6AE);
	/* ^ Wired to the live --memberistic-*  theme --color-* token bridge
	   (token-bridge.css) instead of a hardcoded dark-only snapshot, so this
	   whole dashboard actually flips with the site's light/dark toggle
	   instead of staying permanently dark-styled, and can never drift out
	   of sync with the brand palette again (the literal hex values remain
	   only as a safety fallback if the bridge stylesheet is ever missing).
	   --ma-ok/--ma-warn/--ma-ember-text* keep their existing dark-mode
	   values below (untouched) and only get darker light-mode pairings
	   further down: they sit on a translucent wash over the page
	   background (statuspill/banner) or directly on a card (photo
	   messages, sign-out), and the plain dark-mode-tuned hex was never
	   tested against a light background because this card never used to
	   flip at all — verified below, several combinations were badly
	   under 4.5:1 (as low as ~1.3:1) once it does. */
	--ma-ok:#6FE49A;--ma-warn:#F0A565;--ma-ember-text:#E8802F;--ma-ember-text-hover:#F4A062;--ma-photo-ok:#9DE05B;
	font-family:var(--font-body,"DM Sans",-apple-system,Segoe UI,sans-serif);}
html[data-theme="light"] .memberistic-acct{--ma-ok:#0F6B32;--ma-warn:#8A5A1E;--ma-ember-text:#A65211;--ma-ember-text-hover:#B5540F;--ma-photo-ok:#0F6B32;}
.memberistic-acct *{box-sizing:border-box;}
.memberistic-acct .memberistic-acct-statusbar{margin:0 0 22px;}
.memberistic-acct-statuspill{display:inline-flex;align-items:center;gap:8px;padding:6px 14px;border-radius:999px;
	font-family:var(--font-mono,"Space Mono",monospace);font-size:10px;letter-spacing:.18em;}
.memberistic-acct-statuspill.is-ok{background:rgba(74,222,128,.1);border:1px solid rgba(74,222,128,.4);color:var(--ma-ok)!important;}
.memberistic-acct-statuspill.is-warn{background:rgba(232,128,47,.1);border:1px solid rgba(232,128,47,.4);color:var(--ma-warn)!important;}
.memberistic-acct-dot{width:7px;height:7px;border-radius:50%;background:currentColor;}
.memberistic-acct-banner{background:rgba(232,128,47,.12);border:1px solid rgba(232,128,47,.4);color:var(--ma-warn)!important;
	padding:12px 16px;border-radius:4px;margin-bottom:18px;font-size:14px;}
.memberistic-acct-banner a{color:var(--ma-brass2)!important;}

.memberistic-acct-shell{display:grid;grid-template-columns:280px 1fr;gap:22px;align-items:start;}
@media(max-width:900px){.memberistic-acct-shell{grid-template-columns:1fr;}}

.memberistic-acct-side{background:var(--ma-card)!important;border:1px solid var(--ma-line)!important;border-radius:4px;padding:22px;}
.memberistic-acct-id{display:flex;gap:13px;align-items:center;padding-bottom:18px;border-bottom:1px solid var(--ma-line);}
.memberistic-acct-id .memberistic-acct-avatar{width:48px;height:48px;border-radius:50%;flex:0 0 48px;display:grid;place-items:center;
	background:linear-gradient(135deg,var(--ma-brass),#A8862F);color:#1A191E!important;
	font-family:var(--font-display,"Bebas Neue",sans-serif);font-size:21px;letter-spacing:.04em;}
.memberistic-acct-id strong{display:block;color:var(--ma-white)!important;font-size:15px;font-weight:700;}
.memberistic-acct-id span{display:block;color:var(--ma-silver)!important;font-size:11px;
	font-family:var(--font-mono,monospace);letter-spacing:.08em;text-transform:uppercase;margin-top:3px;}
.memberistic-acct-planpill{margin:16px 0 18px;padding:9px 14px;border:1px solid var(--ma-brass)!important;border-radius:2px;
	color:var(--ma-brass2)!important;font-family:var(--font-mono,monospace);font-size:10px;letter-spacing:.2em;
	text-transform:uppercase;text-align:center;}
.memberistic-acct-nav{display:flex;flex-direction:column;gap:2px;}
.memberistic-acct-nav a{display:flex;align-items:center;gap:11px;padding:11px 12px;text-decoration:none;
	color:var(--ma-fog)!important;font-size:13px;font-weight:600;letter-spacing:.04em;text-transform:uppercase;
	border-left:2px solid transparent;border-radius:2px;transition:background .15s,color .15s,border-color .15s;}
.memberistic-acct-nav a:hover{background:rgba(255,255,255,.04);color:var(--ma-white)!important;}
.memberistic-acct-nav a.is-active{background:rgba(201,168,76,.08);color:var(--ma-brass2)!important;border-left-color:var(--ma-brass);}
.memberistic-acct-nav a.is-active .memberistic-acct-ic{color:var(--ma-brass2)!important;}
.memberistic-acct-ic{font-size:13px;color:var(--ma-silver)!important;width:16px;text-align:center;}
.memberistic-acct-navsep{height:1px;background:var(--ma-line);margin:10px 0;}
.memberistic-acct-signout{color:var(--ma-ember-text)!important;}
.memberistic-acct-signout:hover{color:var(--ma-ember-text-hover)!important;}

.memberistic-acct-main{min-width:0;}
.memberistic-acct-view{display:none;}
.memberistic-acct-view.is-active{display:block;animation:ma-fade .25s ease;}
@keyframes ma-fade{from{opacity:0;transform:translateY(6px);}to{opacity:1;transform:none;}}

.memberistic-acct-welcome h2{font-family:var(--font-display,"Bebas Neue",sans-serif)!important;
	font-size:clamp(34px,4vw,52px);color:var(--ma-white)!important;margin:0;line-height:1;letter-spacing:.02em;}
.memberistic-acct-welcome p{color:var(--ma-fog)!important;margin:10px 0 24px;font-size:15px;line-height:1.6;max-width:60ch;}

.memberistic-acct-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:14px;}
@media(max-width:680px){.memberistic-acct-stats{grid-template-columns:1fr 1fr;}}
.memberistic-acct-stat{background:var(--ma-card)!important;border:1px solid var(--ma-line)!important;padding:18px;border-radius:4px;}
.memberistic-acct-stat span{display:block;font-family:var(--font-mono,monospace);font-size:10px;letter-spacing:.18em;
	text-transform:uppercase;color:var(--ma-silver)!important;margin-bottom:8px;}
.memberistic-acct-stat strong{display:block;font-family:var(--font-display,"Bebas Neue",sans-serif)!important;
	font-size:26px;color:var(--ma-white)!important;line-height:1;letter-spacing:.02em;}
/* Billing/Shipping address block (Shop tab) renders a bare <address> with
   no class — it was relying purely on inheritance with no explicit color
   of its own. Give it one explicitly instead of leaving it to whatever
   happens to cascade down, matching every other text node in this card. */
.memberistic-acct-stat address{display:block;color:var(--ma-fog)!important;font-size:14px;line-height:1.6;}

.memberistic-acct-actions{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
@media(max-width:680px){.memberistic-acct-actions{grid-template-columns:1fr;}}
.memberistic-acct-action{display:flex;align-items:center;gap:14px;padding:18px;text-decoration:none;
	background:var(--ma-card)!important;border:1px solid var(--ma-line)!important;border-radius:4px;
	transition:border-color .15s,transform .15s;}
.memberistic-acct-action:hover{border-color:var(--ma-brass)!important;transform:translateY(-2px);}
.memberistic-acct-action.is-static{cursor:default;}
.memberistic-acct-action.is-static:hover{border-color:var(--ma-line)!important;transform:none;}
.memberistic-acct-action .memberistic-acct-ic{font-size:20px;color:var(--ma-brass2)!important;width:24px;}
.memberistic-acct-action strong{display:block;color:var(--ma-white)!important;font-size:15px;font-weight:700;}
.memberistic-acct-action small{display:block;color:var(--ma-silver)!important;font-size:12px;margin-top:3px;}

.memberistic-acct-block{background:var(--ma-card)!important;border:1px solid var(--ma-line)!important;
	border-radius:4px;padding:26px;margin-bottom:16px;}
.memberistic-acct-block--center{text-align:center;}
.memberistic-acct-block--danger{border-color:rgba(232,128,47,.3)!important;}
.memberistic-acct-block--danger h3{color:var(--ma-ember-text)!important;}
.memberistic-acct-block h2,.memberistic-acct-block h3{font-family:var(--font-display,"Bebas Neue",sans-serif)!important;
	color:var(--ma-white)!important;margin:0 0 14px;letter-spacing:.02em;font-size:28px;line-height:1;}
.memberistic-acct-blockhd{display:flex;justify-content:space-between;align-items:center;gap:14px;margin-bottom:14px;}
.memberistic-acct-blockhd h2{margin:0;}
.memberistic-acct-tag{font-family:var(--font-mono,monospace);font-size:10px;letter-spacing:.16em;text-transform:uppercase;
	color:#1A191E!important;background:var(--ma-brass)!important;padding:5px 11px;border-radius:2px;}
.memberistic-acct-muted{color:var(--ma-silver)!important;font-size:14px;line-height:1.6;margin:0 0 16px;}

.memberistic-acct-benefits{list-style:none;margin:0 0 20px;padding:0;display:grid;gap:11px;}
.memberistic-acct-benefits li{position:relative;padding-left:24px;color:var(--ma-fog)!important;font-size:14px;line-height:1.5;}
.memberistic-acct-benefits li::before{content:"\25C6";position:absolute;left:0;top:1px;color:var(--ma-brass2)!important;font-size:10px;}

.memberistic-acct-ctas{display:flex;gap:12px;flex-wrap:wrap;}
.memberistic-acct-ctas--center{justify-content:center;}
.memberistic-acct-cta{display:inline-block;padding:13px 22px;border-radius:2px;text-decoration:none;cursor:pointer;
	font-family:var(--font-condensed,"Barlow Condensed",sans-serif)!important;font-weight:600;font-size:13px;
	letter-spacing:.1em;text-transform:uppercase;border:1px solid transparent;}
.memberistic-acct-cta--primary{background:var(--ma-ember)!important;color:var(--memberistic-primary-ink, #1A191E)!important;}
.memberistic-acct-cta--primary:hover{background:#F4933F!important;}
.memberistic-acct-cta--ghost{background:transparent!important;border-color:var(--ma-brass)!important;color:var(--ma-brass2)!important;}
.memberistic-acct-cta--ghost:hover{background:rgba(201,168,76,.08)!important;color:var(--ma-white)!important;}

.memberistic-acct-charge{display:flex;flex-wrap:wrap;gap:24px;align-items:center;justify-content:space-between;}
.memberistic-acct-amount{display:block;font-family:var(--font-display,"Bebas Neue",sans-serif)!important;
	font-size:48px;color:var(--ma-brass2)!important;line-height:1;}
.memberistic-acct-mini{display:block;font-family:var(--font-mono,monospace);font-size:10px;letter-spacing:.16em;
	text-transform:uppercase;color:var(--ma-silver)!important;margin-bottom:4px;}
.memberistic-acct-charge strong{color:var(--ma-white)!important;font-size:15px;}

.memberistic-acct-tablewrap{overflow-x:auto;}
.memberistic-acct-table{width:100%;border-collapse:collapse;}
.memberistic-acct-table th{text-align:left;font-family:var(--font-mono,monospace);font-size:10px;letter-spacing:.14em;
	text-transform:uppercase;color:var(--ma-silver)!important;padding:10px 12px;border-bottom:1px solid var(--ma-line2);}
.memberistic-acct-table td{padding:13px 12px;border-bottom:1px solid var(--ma-line);color:var(--ma-fog)!important;font-size:14px;}
.memberistic-acct-table tr:last-child td{border-bottom:0;}
.memberistic-acct-pill{display:inline-block;padding:3px 9px;border-radius:2px;font-family:var(--font-mono,monospace);
	font-size:10px;letter-spacing:.08em;text-transform:uppercase;background:rgba(255,255,255,.07);color:var(--ma-fog)!important;}
.memberistic-acct-pill--ok{background:rgba(74,222,128,.12);color:var(--ma-ok)!important;}
.memberistic-acct-pill--warn{background:rgba(232,128,47,.14);color:var(--ma-warn)!important;}

.memberistic-acct-pass{max-width:420px;margin:18px auto 0;text-align:left;border-radius:8px;padding:22px;
	/* This "physical card" gradient is deliberately fixed-dark in BOTH
	   color modes (like a real membership/credit card face), so it does
	   NOT reference --ma-card. Re-pin --ma-white/--ma-brass2/--ma-silver
	   back to their dark-mode-appropriate values in this one scope only —
	   otherwise the __brand/__name/__meta text (which reads those same
	   vars) would inherit the light-mode flip from .memberistic-acct and
	   render near-black-on-near-black (~1.1:1) once the card starts
	   following the toggle everywhere else. */
	--ma-white:#F4F4F6;--ma-brass2:#E3C06A;--ma-silver:#A7A6AE;
	background:linear-gradient(135deg,#2E2C25,#1C1B1F 70%);border:1px solid var(--ma-brass)!important;
	box-shadow:0 18px 50px rgba(0,0,0,.45);}
.memberistic-acct-pass__top{display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;}
.memberistic-acct-pass__brand{font-family:var(--font-display,"Bebas Neue",sans-serif)!important;
	color:var(--ma-white)!important;font-size:20px;letter-spacing:.06em;}
.memberistic-acct-pass__plan{font-family:var(--font-mono,monospace);font-size:9px;letter-spacing:.16em;
	border:1px solid var(--ma-brass)!important;color:var(--ma-brass2)!important;padding:4px 9px;border-radius:2px;}
.memberistic-acct-pass__name{font-family:var(--font-display,"Bebas Neue",sans-serif)!important;
	color:var(--ma-white)!important;font-size:30px;letter-spacing:.03em;line-height:1;margin-bottom:16px;}
.memberistic-acct-pass__body{display:flex;justify-content:space-between;gap:16px;align-items:flex-end;}
.memberistic-acct-pass__meta strong{display:block;color:var(--ma-white)!important;font-size:13px;margin-bottom:10px;}
.memberistic-acct-pass__meta strong:last-child{margin-bottom:0;}
.memberistic-acct-pass__qr{width:96px;height:96px;border-radius:4px;background:#fff;padding:5px;flex:0 0 96px;}
.memberistic-acct-passnote{margin:16px 0 18px;text-align:center;}

.memberistic-acct-empty{background:var(--ma-card)!important;border:1px solid var(--ma-line)!important;border-radius:4px;
	padding:40px;text-align:center;}
.memberistic-acct-empty h3{font-family:var(--font-display,"Bebas Neue",sans-serif)!important;color:var(--ma-white)!important;
	font-size:28px;margin:0 0 10px;}
.memberistic-acct-empty p{color:var(--ma-silver)!important;margin:0 0 20px;}

@media print{
	/* Hide EVERYTHING outside the digital card so the printed page is
	   ONLY the branded member card — no nav, no admin bar, no header,
	   no footer. */
	body > *:not(.memberistic-acct){display:none!important;}
	#wpadminbar,header,footer,nav,
	.memberistic-acct-side,.memberistic-acct-statusbar,.memberistic-acct-passnote,
	.memberistic-acct-ctas,.memberistic-acct-block > h2,
	.memberistic-acct-view:not([data-panel="card"]){display:none!important;}
	.memberistic-acct,.memberistic-acct-shell,.memberistic-acct-main,
	.memberistic-acct-view[data-panel="card"]{display:block!important;background:#fff!important;padding:0!important;margin:0!important;}
	.memberistic-acct-block{padding:0!important;background:#fff!important;border:0!important;}
	/* Re-anchor the card so it prints centered without surrounding chrome. */
	.memberistic-acct-pass{margin:24px auto!important;box-shadow:none!important;
		background:linear-gradient(135deg,#1A191E,#2A2934)!important;
		color:#fff!important;-webkit-print-color-adjust:exact!important;
		print-color-adjust:exact!important;}
	.memberistic-acct-pass *{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important;}
	@page{margin:12mm;}
}
/* Profile photo: avatar slot, digital-card hero photo, upload controls */
.memberistic-acct-avatar img{width:100%;height:100%;object-fit:cover;border-radius:50%;}
.memberistic-acct-pass__logo{display:block;max-height:32px;width:auto;max-width:180px;object-fit:contain;}
.memberistic-acct-pass__photo{width:84px;height:84px;border-radius:50%;overflow:hidden;margin:14px auto 6px;border:3px solid rgba(255,255,255,.18);background:rgba(255,255,255,.06);}
.memberistic-acct-pass__photo img{width:100%;height:100%;object-fit:cover;display:block;}
.memberistic-acct-photo-actions{display:flex;gap:8px;flex-wrap:wrap;}
.memberistic-acct-photo-btn{background:none;border:1px solid rgba(255,255,255,.2);color:inherit;font-size:11px;letter-spacing:.06em;text-transform:uppercase;padding:4px 10px;border-radius:4px;cursor:pointer;}
.memberistic-acct-photo-btn:hover{background:rgba(255,255,255,.06);}
.memberistic-acct-photo-msg{font-size:12px;color:var(--ma-silver);margin-top:6px;display:none;}
.memberistic-acct-photo-msg.is-err{color:var(--ma-ember-text);display:block;}
.memberistic-acct-photo-msg.is-ok{color:var(--ma-photo-ok);display:block;}
@media print{ .memberistic-acct-photo-actions{display:none!important;} }

/* ============================================================
 * MOBILE RESPONSIVE — dashboard, tabs, embedded booking, card.
 * Most members use a phone, so every panel needs to be usable
 * under 480px without horizontal scroll.
 * ============================================================ */
/* Booking form inside the dashboard panel — DO NOT force a single
   column layout (the booking engine's two-column "info + date
   picker" design needs room to breathe). Just sandbox it so its
   long descriptive copy doesn't break the dashboard width. */
.memberistic-acct-booking{ width:100%; overflow:visible; }
.memberistic-acct-booking input,
.memberistic-acct-booking select,
.memberistic-acct-booking textarea{
  font-size:16px !important; /* prevents iOS focus zoom */
}
.memberistic-acct-booking button{ min-height:44px; }

/* Modal overlay that hosts the same shortcode at full width. Used
   when the user clicks Book A Lane from the dashboard tile or the
   sidebar — gives the booking form a wide 1100px canvas to render
   in instead of being crammed inside the dashboard's main column. */
.memberistic-acct-modal{
  position:fixed; inset:0; z-index:99999; display:none;
  background:rgba(15,17,21,.92); backdrop-filter:blur(4px);
  padding:24px; overflow-y:auto;
  -webkit-overflow-scrolling:touch;
}
.memberistic-acct-modal.is-open{ display:block; }
.memberistic-acct-modal__card{
  max-width:1100px; margin:0 auto; background:#1A1F26;
  border:1px solid #2A323D; border-radius:8px;
  box-shadow:0 24px 80px rgba(0,0,0,.6);
  position:relative; padding:28px;
}
.memberistic-acct-modal__head{
  display:flex; justify-content:space-between; align-items:center;
  margin-bottom:18px; padding-bottom:14px;
  border-bottom:1px solid #2A323D;
}
.memberistic-acct-modal__title{
  font-family:var(--font-display,"Bebas Neue",sans-serif);
  font-size:28px; color:#fff; margin:0; letter-spacing:.04em;
}
.memberistic-acct-modal__close{
  background:transparent; border:1px solid rgba(255,255,255,.2);
  color:#fff; width:38px; height:38px; border-radius:50%;
  cursor:pointer; font-size:18px; line-height:1;
  display:grid; place-items:center;
}
.memberistic-acct-modal__close:hover{ background:rgba(255,255,255,.08); border-color:#C9A84C; color:#C9A84C; }
.memberistic-acct-modal__body{ min-height:300px; }
body.memberistic-modal-open{ overflow:hidden; }

<?php
/*
 * Layout overrides for a booking form embedded in the account modal.
 *
 * These target the mapped booking plugin's own class names, so they are
 * only emitted when an adapter declares a CSS prefix — a site running a
 * different booking plugin never ships CSS for markup that is not there.
 *
 * Effect: stack the booking shell inside the modal (info card on top at
 * full width, date/time picker below at full width), stretch the aside
 * card to the full row rather than leaving it narrow, and give the date
 * picker the full row width so its last column is not clipped.
 */
$booking_css_prefix = \WordPressistic\Memberistic\Integrations\Booking_Adapter::css_prefix();

if ( '' !== $booking_css_prefix ) :
	$bp = preg_replace( '/[^a-z0-9_-]/', '', $booking_css_prefix );
	?>
.memberistic-acct-modal .<?php echo $bp; ?>-shell{
  display:grid !important;
  grid-template-columns:1fr !important;
  gap:18px !important;
}
.memberistic-acct-modal .<?php echo $bp; ?>-aside,
.memberistic-acct-modal .<?php echo $bp; ?>-stage{ width:100%; min-width:0; }
.memberistic-acct-modal .<?php echo $bp; ?>-aside__card{ height:auto; }
.memberistic-acct-modal .<?php echo $bp; ?>-aside__features{ display:flex; flex-wrap:wrap; gap:14px 22px; margin-top:10px; }
.memberistic-acct-modal .<?php echo $bp; ?>-aside__features li{ flex:0 0 auto; }
.memberistic-acct-modal .<?php echo $bp; ?>-stage__panel{ min-width:0; }
.memberistic-acct-modal .<?php echo $bp; ?>-stage table,
.memberistic-acct-modal .<?php echo $bp; ?>-stage [class*="calendar"],
.memberistic-acct-modal .<?php echo $bp; ?>-stage [class*="cal-"]{ width:100% !important; max-width:100% !important; }
<?php endif; ?>

@media (max-width:760px){
  .memberistic-acct-modal{ padding:0; }
  .memberistic-acct-modal__card{ border-radius:0; min-height:100vh; padding:18px; }
  .memberistic-acct-modal__title{ font-size:22px; }
}

@media (max-width:680px){
  .memberistic-acct{ padding:12px !important; }
  .memberistic-acct-shell{ gap:14px !important; }
  .memberistic-acct-side{ padding:16px !important; }
  .memberistic-acct-main{ padding:16px !important; }
  .memberistic-acct-block{ padding:16px !important; }
  .memberistic-acct-welcome h2{ font-size:26px !important; line-height:1.1 !important; }
  .memberistic-acct-nav{ display:grid; grid-template-columns:1fr 1fr; gap:6px; }
  .memberistic-acct-nav a{ padding:10px 8px !important; font-size:12px !important; }
  .memberistic-acct-nav .memberistic-acct-navsep{ display:none; }
  .memberistic-acct-stats{ gap:8px !important; }
  .memberistic-acct-stat{ padding:14px !important; }
  .memberistic-acct-stat strong{ font-size:18px !important; }
  /* Member card scales down so it fits 320–480px phones */
  .memberistic-acct-pass{ max-width:100% !important; padding:18px !important; }
  .memberistic-acct-pass__name{ font-size:22px !important; }
  .memberistic-acct-pass__body{ flex-direction:column !important; gap:18px !important; align-items:center !important; }
  .memberistic-acct-pass__qr{ width:140px !important; height:140px !important; }
  .memberistic-acct-pass__photo{ width:72px !important; height:72px !important; }
}
@media (max-width:420px){
  .memberistic-acct-nav{ grid-template-columns:1fr; }
  .memberistic-acct-stats{ grid-template-columns:1fr !important; }
  .memberistic-acct-id{ flex-direction:column !important; text-align:center !important; }
}
</style>
<script>
(function(){
	var root=document.querySelector('.memberistic-acct');
	if(!root)return;
	var tabs=root.querySelectorAll('[data-tab]');
	var panels=root.querySelectorAll('[data-panel]');
	function show(name){
		var found=false;
		panels.forEach(function(p){var on=p.getAttribute('data-panel')===name;p.classList.toggle('is-active',on);if(on)found=true;});
		if(!found){show('dashboard');return;}
		root.querySelectorAll('.memberistic-acct-nav a[data-tab]').forEach(function(a){
			a.classList.toggle('is-active',a.getAttribute('data-tab')===name);
		});
	}
	tabs.forEach(function(t){
		t.addEventListener('click',function(e){
			var name=t.getAttribute('data-tab');
			e.preventDefault();
			if(history.replaceState){history.replaceState(null,'','#'+name);}
			show(name);
			window.scrollTo({top:root.getBoundingClientRect().top+window.pageYOffset-90,behavior:'smooth'});
		});
	});
	var initial=(location.hash||'').replace('#','');
	show(initial||'dashboard');
	/* ──────────────────────────────────────────────────────────
	 * Digital card download
	 * Builds a SELF-CONTAINED popup window with print-optimized
	 * light theme + brand strip — every style is inlined, so the
	 * download works regardless of:
	 *   • whether the user enables "Background graphics" in print
	 *   • CORS-restricted QR images (we re-fetch the same URL)
	 *   • the parent page's CSS being broken / cached badly
	 *
	 * Previously the button called window.print() on the dashboard
	 * itself, which relied on @media print to hide chrome and on
	 * background-graphics print to keep the dark card readable.
	 * That produced a blank/black PDF whenever the browser stripped
	 * backgrounds, which most do by default.
	 * ────────────────────────────────────────────────────────── */
	var printBtn = root.querySelector('[data-print-card]');
	if (printBtn) {
		printBtn.addEventListener('click', function (e) {
			e.preventDefault();
			var pass = document.getElementById('memberistic-acct-pass');
			if (!pass) return;
			// Pull the card's data so the popup can rebuild it with
			// clean inlined CSS instead of cloning live DOM (which
			// would drag dashboard CSS along and re-create the bug).
			var brandEl = pass.querySelector('.memberistic-acct-pass__brand');
			var logoImg = pass.querySelector('.memberistic-acct-pass__logo');
			var planEl  = pass.querySelector('.memberistic-acct-pass__plan');
			var photoImg = pass.querySelector('.memberistic-acct-pass__photo img');
			var nameEl  = pass.querySelector('.memberistic-acct-pass__name');
			var qrImg   = pass.querySelector('.memberistic-acct-pass__qr-img');
			var qrSvgEl = pass.querySelector('.memberistic-acct-pass__qr-svg');
			var metaText = '';
			pass.querySelectorAll('.memberistic-acct-pass__meta > *').forEach(function (el) {
				metaText += '<div style="margin-bottom:8px;">' + el.outerHTML.replace(/class="[^"]*"/g, '') + '</div>';
			});
			var logoHtml = '';
			if (logoImg) {
				logoHtml = '<img src="' + logoImg.src + '" alt="" style="max-height:44px;width:auto;max-width:240px;display:block;">';
			} else if (brandEl) {
				logoHtml = '<div style="font-family:Impact,\'Bebas Neue\',Arial Black,sans-serif;font-size:24px;letter-spacing:.04em;color:#C9A84C;">' + (brandEl.textContent || '').trim() + '</div>';
			}
			var photoHtml = '';
			if (photoImg) {
				photoHtml = '<img src="' + photoImg.src + '" alt="" style="width:130px;height:130px;border-radius:50%;object-fit:cover;border:4px solid #C9A84C;display:block;margin:0 auto 16px;">';
			}
			var qrHtml = '';
			if (qrImg) {
				qrHtml = '<img src="' + qrImg.src + '" alt="QR" style="width:180px;height:180px;display:block;margin:0 auto;background:#fff;padding:8px;border:1px solid #ddd;">';
			} else if (qrSvgEl) {
				qrHtml = '<div style="width:180px;height:180px;margin:0 auto;background:#fff;padding:8px;border:1px solid #ddd;">' + qrSvgEl.innerHTML + '</div>';
			}
			var plan = (planEl && planEl.textContent || '').trim();
			var name = (nameEl && nameEl.textContent || '').trim();

			// Build the popup. Light cream background + brass header
			// strip so it prints beautifully on ANY printer without
			// needing "background graphics" enabled.
			var html =
				'<!doctype html><html><head><meta charset="utf-8">' +
				'<title>' + (name || 'Member') + ' — Digital Card</title>' +
				'<style>' +
				'@page{size:auto;margin:14mm;}' +
				'*{box-sizing:border-box;}' +
				'body{margin:0;padding:32px;background:#F4EFE4;color:#1A1A1A;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;}' +
				'.card{max-width:520px;margin:0 auto;background:#fff;border:1px solid #C9A84C;border-radius:8px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.08);}' +
				'.card__head{background:#0F1115;padding:20px 24px;display:flex;justify-content:space-between;align-items:center;border-bottom:3px solid #C9A84C;-webkit-print-color-adjust:exact;print-color-adjust:exact;}' +
				'.card__head .plan{font-family:Consolas,"Courier New",monospace;font-size:11px;letter-spacing:.22em;color:#C9A84C;text-transform:uppercase;}' +
				'.card__body{padding:28px 24px;text-align:center;}' +
				'.card__name{font-family:Impact,"Bebas Neue",Arial Black,sans-serif;font-size:28px;letter-spacing:.04em;color:#0F1115;margin:0 0 4px;text-transform:uppercase;}' +
				'.card__planlabel{font-family:Consolas,"Courier New",monospace;font-size:11px;letter-spacing:.22em;color:#7C7C82;text-transform:uppercase;margin:0 0 22px;}' +
				'.card__meta{text-align:left;border-top:1px solid #E6E0CF;padding:18px 24px;background:#FAF6EB;-webkit-print-color-adjust:exact;print-color-adjust:exact;font-size:13px;}' +
				'.card__meta .memberistic-acct-mini{font-family:Consolas,"Courier New",monospace;font-size:10px;letter-spacing:.18em;text-transform:uppercase;color:#7C7C82;display:block;}' +
				'.card__meta strong{color:#1A1A1A;font-size:14px;display:block;margin:2px 0 6px;}' +
				'.card__qrwrap{padding:18px 24px 24px;text-align:center;background:#fff;}' +
				'.card__note{text-align:center;font-family:Consolas,"Courier New",monospace;font-size:10px;letter-spacing:.22em;text-transform:uppercase;color:#7C7C82;padding:12px 24px 24px;}' +
				'@media print{body{background:#fff;padding:0;}.card{box-shadow:none;}}' +
				'</style></head><body>' +
				'<div class="card">' +
				'  <div class="card__head"><div>' + logoHtml + '</div><div class="plan">' + plan + '</div></div>' +
				'  <div class="card__body">' +
				     photoHtml +
				'    <h1 class="card__name">' + name + '</h1>' +
				'    <p class="card__planlabel">' + plan + ' MEMBER</p>' +
				'  </div>' +
				'  <div class="card__qrwrap">' + qrHtml + '</div>' +
				'  <div class="card__meta">' + metaText + '</div>' +
				'  <div class="card__note">Show at the range desk · or scan the QR</div>' +
				'</div>' +
				'<script>window.addEventListener("load",function(){setTimeout(function(){window.focus();window.print();},300);});<\/script>' +
				'</body></html>';

			var w = window.open('', 'memberistic-card-print', 'width=620,height=820');
			if (!w) {
				// Popup blocked — fall back to inline print so the
				// button is never dead.
				window.print();
				return;
			}
			w.document.open();
			w.document.write(html);
			w.document.close();
		});
	}

	/* ──────────────────────────────────────────────────────────
	 * Booking modal + auto-fill
	 *
	 * The dashboard "Book A Lane" tile, the sidebar's "Book A
	 * Lane" tab, and the "Open Booking Form" CTA inside that tab
	 * all share one [data-open-booking] hook — clicking any of
	 * them opens the modal. The modal hosts the live booking-
	 * engine shortcode at full width (max 1100px), so the form's
	 * two-column "info + date picker" layout renders properly
	 * instead of being squeezed into the dashboard's narrow main
	 * column.
	 *
	 * Auto-fill is idempotent — only populates empty fields, so a
	 * member who tweaks the email never sees it bounced back.
	 * ────────────────────────────────────────────────────────── */
	var modal = document.getElementById('memberistic-acct-modal');
	function prefillBooking(){
		if(!modal) return;
		var wrap = modal.querySelector('.memberistic-acct-booking');
		if(!wrap) return;
		var name = wrap.getAttribute('data-member-name')  || '';
		var mail = wrap.getAttribute('data-member-email') || '';
		var tel  = wrap.getAttribute('data-member-phone') || '';
		// Match by field name first (most booking forms use customer_name /
		// customer_email / customer_phone), then by the mapped plugin's own
		// field ids, then by input type — so other booking plugins and
		// future form variants keep working without code changes here.
		var idp = <?php echo wp_json_encode( \WordPressistic\Memberistic\Integrations\Booking_Adapter::css_prefix() ); ?>;
		var byId = function(suffix){ return idp ? ['#' + idp + '-' + suffix] : []; };
		[
			{val:name, sel:[
				'input[name="customer_name"]','input[name="full_name"]','input[name="name"]'
			].concat(byId('customer-name'), ['input[autocomplete="name"]'])},
			{val:mail, sel:[
				'input[name="customer_email"]','input[name="email"]'
			].concat(byId('customer-email'), ['input[type="email"]'])},
			{val:tel,  sel:[
				'input[name="customer_phone"]','input[name="phone"]'
			].concat(byId('customer-phone'), ['input[type="tel"]','input[autocomplete="tel"]'])}
		].forEach(function(group){
			if(!group.val) return;
			group.sel.some(function(sel){
				var el = wrap.querySelector(sel);
				if(el && !el.value){ el.value = group.val; el.dispatchEvent(new Event('change',{bubbles:true})); return true; }
				return false;
			});
		});
	}
	function openBooking(){
		if(!modal) return;
		modal.removeAttribute('hidden');
		modal.classList.add('is-open');
		document.body.classList.add('memberistic-modal-open');
		// The booking engine may hydrate its inputs after first
		// render — re-run prefill twice to catch lazy mounts.
		setTimeout(prefillBooking, 50);
		setTimeout(prefillBooking, 400);
	}
	function closeBooking(){
		if(!modal) return;
		modal.classList.remove('is-open');
		modal.setAttribute('hidden','');
		document.body.classList.remove('memberistic-modal-open');
	}
	document.querySelectorAll('[data-open-booking]').forEach(function(btn){
		btn.addEventListener('click', function(e){ e.preventDefault(); openBooking(); });
	});
	if(modal){
		modal.addEventListener('click', function(e){
			if(e.target === modal) closeBooking(); // click backdrop to close
		});
		var closeBtn = modal.querySelector('[data-close-booking]');
		if(closeBtn) closeBtn.addEventListener('click', closeBooking);
		document.addEventListener('keydown', function(e){
			if(e.key === 'Escape' && modal.classList.contains('is-open')) closeBooking();
		});
	}

	/* ──────────────────────────────────────────────────────────
	 * Profile photo upload
	 * Picks a file via the hidden <input type=file>, POSTs to
	 * /wp-json/memberistic/v1/profile/image, swaps the avatar
	 * + card photo on success. Reload guarantees the digital
	 * card + verification page also pick up the new photo.
	 * ────────────────────────────────────────────────────────── */
	var photoTrigger = root.querySelector('[data-mem-photo-trigger]');
	var photoInput   = root.querySelector('[data-mem-photo-input]');
	var photoRemove  = root.querySelector('[data-mem-photo-remove]');
	function showMsg(text, kind) {
		var msg = root.querySelector('.memberistic-acct-photo-msg');
		if (!msg) {
			msg = document.createElement('div');
			msg.className = 'memberistic-acct-photo-msg';
			var actions = root.querySelector('.memberistic-acct-photo-actions');
			if (actions && actions.parentNode) actions.parentNode.appendChild(msg);
		}
		msg.textContent = text;
		msg.className = 'memberistic-acct-photo-msg is-' + (kind || 'ok');
	}
	function nonce() {
		return (window.wpApiSettings && window.wpApiSettings.nonce) || '';
	}
	function restRoot() {
		return (window.wpApiSettings && window.wpApiSettings.root) || (location.origin + '/wp-json/');
	}
	if (photoTrigger && photoInput) {
		photoTrigger.addEventListener('click', function(){ photoInput.click(); });
		photoInput.addEventListener('change', function(){
			if (!photoInput.files || !photoInput.files[0]) return;
			var file = photoInput.files[0];
			if (file.size > 5 * 1024 * 1024) { showMsg('Image too large — please pick something under 5 MB.', 'err'); return; }
			var fd = new FormData();
			fd.append('file', file);
			showMsg('Uploading…', 'ok');
			fetch(restRoot() + 'memberistic/v1/profile/image', {
				method: 'POST',
				credentials: 'include',
				headers: nonce() ? { 'X-WP-Nonce': nonce() } : {},
				body: fd
			}).then(function(r){ return r.json().then(function(j){ return {ok:r.ok, body:j}; }); })
			  .then(function(res){
				if (!res.ok || !res.body || !res.body.success) {
					showMsg((res.body && (res.body.message || res.body.code)) || 'Upload failed.', 'err');
					return;
				}
				showMsg('Photo updated. Reloading…', 'ok');
				setTimeout(function(){ location.reload(); }, 600);
			  }).catch(function(){ showMsg('Network error. Try again.', 'err'); });
		});
	}
	if (photoRemove) {
		photoRemove.addEventListener('click', function(){
			if (!confirm('Remove your profile photo?')) return;
			fetch(restRoot() + 'memberistic/v1/profile/image', {
				method: 'DELETE',
				credentials: 'include',
				headers: nonce() ? { 'X-WP-Nonce': nonce() } : {}
			}).then(function(){ location.reload(); });
		});
	}
})();
</script>
