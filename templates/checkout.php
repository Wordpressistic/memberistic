<?php
/**
 * Checkout template.
 *
 * @package Memberistic
 */

use WordPressistic\Memberistic\Payments\Stripe_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Resolve billing cycle from URL param, then shortcode attr, defaulting to monthly.
$selected_cycle = 'monthly';
if ( ! empty( $_GET['memberistic_cycle'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
	$_cycle = sanitize_key( wp_unslash( $_GET['memberistic_cycle'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
	if ( in_array( $_cycle, array( 'monthly', 'annual' ), true ) ) {
		$selected_cycle = $_cycle;
	}
} elseif ( ! empty( $atts['cycle'] ) && in_array( $atts['cycle'], array( 'monthly', 'annual' ), true ) ) {
	$selected_cycle = $atts['cycle'];
}

// Pre-fill from logged-in user.
$prefill_name  = '';
$prefill_email = '';
if ( is_user_logged_in() ) {
	$current_user  = wp_get_current_user();
	$prefill_name  = $current_user->display_name ?: trim( $current_user->first_name . ' ' . $current_user->last_name );
	$prefill_email = $current_user->user_email;
}
?>
<div class="memberistic-frontend memberistic-co-shell">
	<div class="memberistic-co-step-pip">
		<span class="dot cur"></span><span><?php esc_html_e( 'Plan', 'memberistic' ); ?></span>
		<span class="dot cur"></span><span><?php esc_html_e( 'Account', 'memberistic' ); ?></span>
		<span class="dot cur"></span><span><?php esc_html_e( 'Payment', 'memberistic' ); ?></span>
		<span class="dot"></span><span><?php esc_html_e( 'Confirm', 'memberistic' ); ?></span>
	</div>
	<h2 class="memberistic-co-title"><?php esc_html_e( 'COMPLETE YOUR ENROLLMENT', 'memberistic' ); ?></h2>
	<p class="memberistic-co-sub"><?php esc_html_e( 'Almost there. Set up your account and membership details, then continue to secure Stripe checkout.', 'memberistic' ); ?></p>

	<?php if ( ! Stripe_Service::is_enabled() ) : ?>
		<div class="memberistic-placeholder">
			<p><?php esc_html_e( 'Online checkout is not enabled yet. Please contact staff to start your membership.', 'memberistic' ); ?></p>
		</div>
	<?php elseif ( empty( $plans ) ) : ?>
		<div class="memberistic-placeholder">
			<p><?php esc_html_e( 'No membership plans are currently available. Please check back soon or contact staff.', 'memberistic' ); ?></p>
		</div>
	<?php else : ?>
		<?php
		$summary_plan  = $selected_plan ? $selected_plan : $plans[0];
		$summary_price = 'annual' === $selected_cycle ? (float) $summary_plan['annual_price'] : (float) $summary_plan['monthly_price'];
		?>
		<div class="memberistic-co-summary">
			<div class="row">
				<div>
					<div class="name" id="memberistic-summary-name"><?php echo esc_html( strtoupper( $summary_plan['name'] ) ); ?></div>
					<div class="tag" id="memberistic-summary-tag"><?php echo esc_html( 'annual' === $selected_cycle ? __( 'Annual Billing', 'memberistic' ) : __( 'Monthly Billing', 'memberistic' ) ); ?></div>
				</div>
				<div class="price" id="memberistic-summary-price">$<?php echo esc_html( number_format( $summary_price, 2 ) ); ?><span>/<?php echo esc_html( 'annual' === $selected_cycle ? __( 'yr', 'memberistic' ) : __( 'mo', 'memberistic' ) ); ?></span></div>
			</div>
		</div>

		<form method="post" action="<?php echo esc_url( Stripe_Service::checkout_action_url() ); ?>" class="memberistic-co-form memberistic-checkout-form">
			<?php wp_nonce_field( 'memberistic_checkout' ); ?>
			<input type="hidden" name="memberistic_action" value="start_checkout">

			<div class="memberistic-co-section">
				<h3><span class="n">1</span> <?php esc_html_e( 'Account Creation', 'memberistic' ); ?></h3>
				<label class="memberistic-co-label" for="memberistic_plan_id"><?php esc_html_e( 'Membership Plan', 'memberistic' ); ?></label>
				<select id="memberistic_plan_id" name="plan_id" required>
					<?php foreach ( $plans as $plan ) : ?>
						<option value="<?php echo esc_attr( (int) $plan['id'] ); ?>" <?php selected( $summary_plan && (int) $summary_plan['id'] === (int) $plan['id'] ); ?>>
							<?php
							$monthly = number_format( (float) $plan['monthly_price'], 2 );
							$annual  = number_format( (float) $plan['annual_price'], 2 );
							echo esc_html( $plan['name'] . ' - $' . $monthly . '/mo or $' . $annual . '/yr' );
							?>
						</option>
					<?php endforeach; ?>
				</select>

				<label class="memberistic-co-label" for="memberistic_cycle"><?php esc_html_e( 'Billing Cycle', 'memberistic' ); ?></label>
				<select id="memberistic_cycle" name="billing_cycle">
					<option value="monthly" <?php selected( 'monthly', $selected_cycle ); ?>><?php esc_html_e( 'Monthly', 'memberistic' ); ?></option>
					<option value="annual" <?php selected( 'annual', $selected_cycle ); ?>><?php esc_html_e( 'Annual', 'memberistic' ); ?></option>
				</select>

				<label class="memberistic-co-label" for="memberistic_full_name"><?php esc_html_e( 'Full Name', 'memberistic' ); ?></label>
				<input id="memberistic_full_name" name="full_name" type="text" required value="<?php echo esc_attr( $prefill_name ); ?>">

				<label class="memberistic-co-label" for="memberistic_email"><?php esc_html_e( 'Email Address', 'memberistic' ); ?></label>
				<input id="memberistic_email" name="email" type="email" required value="<?php echo esc_attr( $prefill_email ); ?>"<?php echo $prefill_email ? ' readonly' : ''; ?>>
			</div>

			<div class="memberistic-co-section">
				<h3><span class="n">2</span> <?php esc_html_e( 'Member Details', 'memberistic' ); ?></h3>
				<label class="memberistic-co-label" for="memberistic_phone"><?php esc_html_e( 'Phone', 'memberistic' ); ?></label>
				<input id="memberistic_phone" name="phone" type="tel" placeholder="(602) 555 1234">
			</div>

			<div class="memberistic-co-section">
				<h3><span class="n">3</span> <?php esc_html_e( 'Payment', 'memberistic' ); ?></h3>
				<p class="memberistic-co-note"><?php esc_html_e( 'Payment is processed securely on Stripe in the next step.', 'memberistic' ); ?></p>
			</div>

			<div class="memberistic-co-section memberistic-co-section--terms">
				<label class="memberistic-checkbox-line">
					<input name="terms_acceptance" type="checkbox" value="yes" required>
					<span><?php esc_html_e( 'I agree to the range safety waiver and membership terms. I confirm I am legally eligible for firearms range access where required by law.', 'memberistic' ); ?></span>
				</label>
			</div>

			<div class="memberistic-co-submit-wrap">
				<button type="submit" class="memberistic-auth-btn memberistic-auth-btn--primary"><?php esc_html_e( 'Complete Membership Enrollment ->', 'memberistic' ); ?></button>
			</div>
		</form>
	<?php endif; ?>
</div>
<script>
(function () {
	var form = document.querySelector('.memberistic-checkout-form');
	if (!form) return;
	var planSelect    = form.querySelector('[name="plan_id"]');
	var cycleSelect   = form.querySelector('[name="billing_cycle"]');
	var summaryName   = document.getElementById('memberistic-summary-name');
	var summaryTag    = document.getElementById('memberistic-summary-tag');
	var summaryPrice  = document.getElementById('memberistic-summary-price');
	var submitButton  = form.querySelector('[type="submit"]');
	var submitLabel   = submitButton ? submitButton.textContent : '';
	var submitting    = false;

	var planData = {};
	<?php foreach ( $plans as $plan ) : ?>
	planData[<?php echo (int) $plan['id']; ?>] = {
		name:    <?php echo wp_json_encode( $plan['name'] ); ?>,
		monthly: <?php echo esc_js( number_format( (float) $plan['monthly_price'], 2 ) ); ?>,
		annual:  <?php echo esc_js( number_format( (float) $plan['annual_price'], 2 ) ); ?>
	};
	<?php endforeach; ?>

	function updateSummary() {
		if (!summaryName || !summaryTag || !summaryPrice || !planSelect || !cycleSelect) return;
		var planId = parseInt(planSelect.value, 10);
		var cycle  = cycleSelect.value;
		var p      = planData[planId];
		if (!p) return;
		summaryName.textContent = (p.name || '').toUpperCase();
		summaryTag.textContent = cycle === 'annual' ? '<?php echo esc_js( __( 'Annual Billing', 'memberistic' ) ); ?>' : '<?php echo esc_js( __( 'Monthly Billing', 'memberistic' ) ); ?>';
		summaryPrice.innerHTML = '$' + priceFor(cycle, p) + '<span>/' + (cycle === 'annual' ? 'yr' : 'mo') + '</span>';
	}

	function priceFor(cycle, p) {
		return cycle === 'annual' ? p.annual : p.monthly;
	}

	if (planSelect)  planSelect.addEventListener('change', updateSummary);
	if (cycleSelect) cycleSelect.addEventListener('change', updateSummary);
	form.addEventListener('submit', function (event) {
		if (submitting) {
			event.preventDefault();
			return;
		}
		if (typeof form.checkValidity === 'function' && !form.checkValidity()) {
			return;
		}
		submitting = true;
		if (submitButton) {
			submitButton.disabled = true;
			submitButton.setAttribute('aria-disabled', 'true');
			submitButton.textContent = '<?php echo esc_js( __( 'Connecting to secure checkout', 'memberistic' ) ); ?>' + '\u2026';
		}
		window.setTimeout(function () {
			if (submitButton && !document.hidden) {
				submitButton.disabled = false;
				submitButton.setAttribute('aria-disabled', 'false');
				submitButton.textContent = submitLabel;
				submitting = false;
			}
		}, 15000);
	});
	updateSummary();
})();
</script>
