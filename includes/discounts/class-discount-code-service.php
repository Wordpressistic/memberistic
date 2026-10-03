<?php
/**
 * Discount code business logic: validation against Memberistic's own
 * business rules (plan/cycle scope, usage caps, expiry), Stripe Coupon +
 * Promotion Code sync, and redemption recording.
 *
 * Design note: Memberistic owns which plans/cycles a code applies to and
 * how many times it can be used — validated here, server-side, against
 * our own tables, before Stripe is ever contacted. Plan-level scoping
 * can't be delegated to Stripe: membership checkout uses an inline
 * price_data line item created fresh per session (see
 * Stripe_Service::create_checkout_session()), not a stable Stripe
 * Product per plan, so a Stripe Coupon has nothing to restrict itself
 * to.
 *
 * The actual discount MATH and the recurring-discount DURATION
 * (once / repeating N months / forever across subscription renewals) are
 * still delegated to a real Stripe Coupon + Promotion Code, attached to
 * the Checkout Session explicitly via `discounts[0][promotion_code]` —
 * not `allow_promotion_codes`, which would let a customer type ANY
 * active code onto ANY plan on Stripe's own hosted page, bypassing the
 * plan/cycle restriction entirely. This keeps money math where the rest
 * of this plugin already puts it (server-calculated, Stripe-confirmed —
 * see docs/FEATURES.md "Payment policy") while still giving staff full
 * control over which plans a code applies to.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic\Discounts;

use WordPressistic\Memberistic\Database\Activity_Repository;
use WordPressistic\Memberistic\Database\Discount_Codes_Repository;
use WordPressistic\Memberistic\Database\Discount_Redemptions_Repository;
use WordPressistic\Memberistic\Database\Memberships_Repository;
use WordPressistic\Memberistic\Database\People_Repository;
use WordPressistic\Memberistic\Database\Plans_Repository;
use WordPressistic\Memberistic\Integrations\Integrations_Registry;
use WordPressistic\Memberistic\Payments\Stripe_Service;
use function WordPressistic\Memberistic\memberistic_get_setting;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
final class Discount_Code_Service {

	/**
	 * Validate a customer-typed code against a specific checkout attempt.
	 * Never contacts Stripe — every check here is against Memberistic's
	 * own data, so an invalid code fails fast before checkout is touched.
	 *
	 * @param string $raw_code      Whatever the customer typed.
	 * @param array  $plan          The plan row being purchased.
	 * @param string $billing_cycle 'monthly'|'annual'.
	 * @param string $email         Checkout email.
	 * @return array{ok:bool,message?:string,code?:array}
	 */
	public static function validate_for_checkout( $raw_code, array $plan, $billing_cycle, $email ) {
		if ( ! Integrations_Registry::is_enabled( 'discount_codes' ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'Discount codes are not currently accepted.', 'memberistic' ),
			);
		}

		$code = Discount_Codes_Repository::get_by_code( $raw_code );
		if ( ! $code ) {
			return array(
				'ok'      => false,
				'message' => __( 'That discount code was not recognized.', 'memberistic' ),
			);
		}
		if ( 'active' !== $code['status'] ) {
			return array(
				'ok'      => false,
				'message' => __( 'That discount code is no longer active.', 'memberistic' ),
			);
		}
		if ( empty( $code['stripe_promotion_code_id'] ) ) {
			// Never synced (or sync failed) — refusing here means a
			// customer can never redeem a code that would apply no actual
			// discount at Stripe.
			return array(
				'ok'      => false,
				'message' => __( 'That discount code is not ready yet. Please try again shortly or contact us.', 'memberistic' ),
			);
		}

		$now = time();
		if ( ! empty( $code['starts_at'] ) && strtotime( $code['starts_at'] ) > $now ) {
			return array(
				'ok'      => false,
				'message' => __( 'That discount code is not active yet.', 'memberistic' ),
			);
		}
		if ( ! empty( $code['expires_at'] ) && strtotime( $code['expires_at'] ) < $now ) {
			return array(
				'ok'      => false,
				'message' => __( 'That discount code has expired.', 'memberistic' ),
			);
		}

		if ( 'specific_plans' === $code['applies_to'] && ! in_array( (int) $plan['id'], $code['plan_ids'], true ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'That discount code does not apply to the selected plan.', 'memberistic' ),
			);
		}
		if ( 'all' !== $code['billing_cycles'] && $code['billing_cycles'] !== $billing_cycle ) {
			return array(
				'ok'      => false,
				'message' => __( 'That discount code does not apply to the selected billing cycle.', 'memberistic' ),
			);
		}

		if ( null !== $code['max_redemptions'] && $code['current_redemptions'] >= $code['max_redemptions'] ) {
			return array(
				'ok'      => false,
				'message' => __( 'That discount code has reached its usage limit.', 'memberistic' ),
			);
		}

		if ( is_email( $email ) ) {
			$used = Discount_Redemptions_Repository::count_by_code_and_email( $code['id'], $email );
			if ( $used >= $code['max_redemptions_per_user'] ) {
				return array(
					'ok'      => false,
					'message' => __( 'That discount code has already been used with this email address.', 'memberistic' ),
				);
			}
		}

		return array(
			'ok'   => true,
			'code' => $code,
		);
	}

	/**
	 * Percent/fixed discount math for ADMIN PREVIEW ONLY (e.g. "$49.99 →
	 * $39.99 with 20% off" shown while configuring a code). The amount a
	 * customer is actually charged always comes from Stripe's own Coupon
	 * calculation on the Checkout Session — this is never used as, or
	 * trusted as, the final charged amount.
	 */
	public static function preview_amount( $amount, array $code ) {
		$amount = (float) $amount;
		if ( 'percent' === $code['discount_type'] ) {
			$discounted = $amount * ( 1 - min( 100, max( 0, $code['discount_value'] ) ) / 100 );
		} else {
			$discounted = $amount - $code['discount_value'];
		}
		return round( max( 0, $discounted ), 2 );
	}

	/**
	 * Create the Stripe Coupon + Promotion Code behind a discount code
	 * row. Called on save whenever the code is active and not yet synced.
	 * Stripe Coupons are immutable once created (percent_off/amount_off/
	 * duration can't change), so editing an ALREADY-SYNCED code's amount
	 * or duration must create a brand new Coupon + Promotion Code and
	 * retire the old one — past redemptions already recorded locally are
	 * untouched either way, since they store their own discount snapshot.
	 *
	 * @return array{ok:bool,stripe_coupon_id?:string,stripe_promotion_code_id?:string,error?:string}
	 */
	public static function sync_to_stripe( array $code ) {
		if ( ! Stripe_Service::is_enabled() ) {
			return array(
				'ok'    => false,
				'error' => __( 'Stripe is not connected — enable the Stripe Checkout integration first.', 'memberistic' ),
			);
		}

		$coupon_payload = array(
			'duration' => $code['duration_type'],
			'name'     => $code['code'],
		);
		if ( 'percent' === $code['discount_type'] ) {
			$coupon_payload['percent_off'] = (string) min( 100, max( 0.01, $code['discount_value'] ) );
		} else {
			$currency                     = strtolower( (string) memberistic_get_setting( 'currency', 'USD' ) );
			$coupon_payload['amount_off'] = (int) round( max( 0.01, $code['discount_value'] ) * 100 );
			$coupon_payload['currency']   = in_array( $currency, array( 'usd', 'eur', 'gbp', 'cad', 'aud' ), true ) ? $currency : 'usd';
		}
		if ( 'repeating' === $code['duration_type'] ) {
			$coupon_payload['duration_in_months'] = max( 1, (int) $code['duration_in_months'] );
		}
		if ( null !== $code['max_redemptions'] ) {
			// Defense in depth — Memberistic already enforces this locally
			// before Stripe is ever contacted, but a coupon-level cap means
			// Stripe itself refuses an over-limit redemption even if a bug
			// or a race ever let one slip past the local check.
			$coupon_payload['max_redemptions'] = (int) $code['max_redemptions'];
		}

		$coupon = Stripe_Service::request( 'POST', '/coupons', $coupon_payload );
		if ( is_wp_error( $coupon ) || empty( $coupon['id'] ) ) {
			return array(
				'ok'    => false,
				'error' => is_wp_error( $coupon ) ? $coupon->get_error_message() : __( 'Stripe did not return a coupon id.', 'memberistic' ),
			);
		}

		$promo_payload = array(
			'coupon' => $coupon['id'],
			'code'   => $code['code'],
		);
		if ( ! empty( $code['expires_at'] ) ) {
			$promo_payload['expires_at'] = (int) strtotime( $code['expires_at'] . ' UTC' );
		}
		if ( null !== $code['max_redemptions'] ) {
			$promo_payload['max_redemptions'] = (int) $code['max_redemptions'];
		}

		$promo = Stripe_Service::request( 'POST', '/promotion_codes', $promo_payload );
		if ( is_wp_error( $promo ) || empty( $promo['id'] ) ) {
			return array(
				'ok'    => false,
				'error' => is_wp_error( $promo ) ? $promo->get_error_message() : __( 'Stripe did not return a promotion code id.', 'memberistic' ),
			);
		}

		return array(
			'ok'                       => true,
			'stripe_coupon_id'         => (string) $coupon['id'],
			'stripe_promotion_code_id' => (string) $promo['id'],
		);
	}

	/** Deactivate the Stripe-side promotion code so it can no longer be redeemed there either. */
	public static function deactivate_on_stripe( array $code ) {
		if ( empty( $code['stripe_promotion_code_id'] ) || ! Stripe_Service::is_enabled() ) {
			return;
		}
		Stripe_Service::request( 'POST', '/promotion_codes/' . rawurlencode( $code['stripe_promotion_code_id'] ), array( 'active' => 'false' ) );
	}

	/** Re-activate the Stripe-side promotion code when a code is turned back on. */
	public static function activate_on_stripe( array $code ) {
		if ( empty( $code['stripe_promotion_code_id'] ) || ! Stripe_Service::is_enabled() ) {
			return;
		}
		Stripe_Service::request( 'POST', '/promotion_codes/' . rawurlencode( $code['stripe_promotion_code_id'] ), array( 'active' => 'true' ) );
	}

	/**
	 * Record a confirmed redemption — called only from the Stripe webhook
	 * after checkout.session.completed, i.e. only after verified payment
	 * evidence, matching the plugin's "only verified payment evidence"
	 * principle used everywhere else. Idempotent per Stripe checkout
	 * session id, so a webhook retry can never double-count.
	 *
	 * @param array $code    A Discount_Codes_Repository row.
	 * @param array $context membership_id, plan_id, email, billing_cycle,
	 *                       amount_before, amount_discounted, amount_after,
	 *                       currency, stripe_checkout_session_id.
	 */
	public static function record_redemption( array $code, array $context ) {
		$session_id = (string) ( $context['stripe_checkout_session_id'] ?? '' );
		if ( '' !== $session_id && Discount_Redemptions_Repository::exists_for_session( $session_id ) ) {
			return; // Already recorded — webhook retry.
		}

		Discount_Redemptions_Repository::create(
			array(
				'discount_code_id'           => $code['id'],
				'membership_id'              => $context['membership_id'] ?? null,
				'plan_id'                    => $context['plan_id'] ?? null,
				'email'                      => $context['email'] ?? null,
				'billing_cycle'              => $context['billing_cycle'] ?? null,
				'discount_type'              => $code['discount_type'],
				'discount_value'             => $code['discount_value'],
				'amount_before'              => $context['amount_before'] ?? null,
				'amount_discounted'          => $context['amount_discounted'] ?? null,
				'amount_after'               => $context['amount_after'] ?? null,
				'currency'                   => $context['currency'] ?? 'USD',
				'stripe_checkout_session_id' => $session_id,
			)
		);

		$claimed = Discount_Codes_Repository::claim_redemption( $code['id'] );

		Activity_Repository::log(
			array(
				'activity_type'       => 'discount_code_redeemed',
				'title'               => sprintf(
					/* translators: %s: discount code */
					__( 'Discount code "%s" redeemed', 'memberistic' ),
					$code['code']
				),
				'description'         => $claimed
					? sprintf(
						/* translators: 1: amount before, 2: amount after, 3: currency */
						__( '%1$s → %2$s %3$s', 'memberistic' ),
						number_format_i18n( (float) ( $context['amount_before'] ?? 0 ), 2 ),
						number_format_i18n( (float) ( $context['amount_after'] ?? 0 ), 2 ),
						strtoupper( (string) ( $context['currency'] ?? 'USD' ) )
					)
					// The local usage counter/cap was already exhausted between
					// checkout start and payment confirmation (e.g. two
					// customers redeemed concurrently near the limit). The
					// redemption row still stands — the customer legitimately
					// paid the discounted price Stripe already charged; this
					// only means current_redemptions couldn't increment
					// further. Stripe's own coupon-level max_redemptions (set
					// in sync_to_stripe()) is what actually stops any THIRD
					// customer from redeeming.
					: __( 'Recorded after the local usage counter had already reached its configured limit — the customer was still charged the discounted price Stripe confirmed.', 'memberistic' ),
				'membership_id'       => $context['membership_id'] ?? null,
				'related_object_type' => 'discount_code',
				'related_object_id'   => (string) $code['id'],
			)
		);
	}

	/**
	 * Record a redemption only after Payment_Integrity_Gate has accepted the
	 * checkout event. The event is used as evidence for the session id and
	 * verified amount; the membership, person, plan and code are re-read from
	 * Memberistic's own tables.
	 *
	 * @param int   $membership_id Membership id.
	 * @param array $event         Normalised provider event.
	 */
	public static function record_verified_redemption( $membership_id, array $event ) {
		$object  = isset( $event['object'] ) && is_array( $event['object'] ) ? $event['object'] : array();
		$code_id = absint( $object['metadata']['discount_code_id'] ?? 0 );
		if ( ! $code_id ) {
			return;
		}

		$code       = Discount_Codes_Repository::get( $code_id );
		$membership = Memberships_Repository::get( absint( $membership_id ) );
		if ( ! $code || ! $membership ) {
			return;
		}

		$plan = Plans_Repository::get( (int) $membership['plan_id'] );
		if ( ! $plan ) {
			return;
		}

		$cycle        = sanitize_key( (string) ( $membership['billing_cycle'] ?? 'monthly' ) );
		$amount_before = 'annual' === $cycle ? (float) $plan['annual_price'] : (float) $plan['monthly_price'];
		$amount_after  = isset( $event['amount'] ) && is_numeric( $event['amount'] ) ? (float) $event['amount'] : null;
		if ( null === $amount_after ) {
			return;
		}

		// The gate has already compared the provider amount to this calculation.
		// Keep the guard here too so a future caller cannot create a misleading
		// redemption row by invoking this action directly.
		$expected_after = self::preview_amount( $amount_before, $code );
		if ( abs( $expected_after - $amount_after ) > 0.01 ) {
			return;
		}

		$person = People_Repository::get_primary_by_membership( (int) $membership_id );
		$session_id = ! empty( $object['id'] ) ? sanitize_text_field( (string) $object['id'] ) : '';

		self::record_redemption(
			$code,
			array(
				'membership_id'              => (int) $membership_id,
				'plan_id'                    => (int) $membership['plan_id'],
				'email'                      => $person['email'] ?? '',
				'billing_cycle'              => $cycle,
				'amount_before'              => $amount_before,
				'amount_discounted'          => max( 0, round( $amount_before - $amount_after, 2 ) ),
				'amount_after'               => $amount_after,
				'currency'                   => $event['currency'] ?? memberistic_get_setting( 'currency', 'USD' ),
				'stripe_checkout_session_id' => $session_id,
			)
		);
	}
}
