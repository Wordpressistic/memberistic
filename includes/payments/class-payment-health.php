<?php
/**
 * Payment configuration and webhook health.
 *
 * One place that answers "is billing actually working on this site", because
 * the failure modes are all silent. A missing signing secret, a webhook
 * pointed at the wrong mode, an API key rotated to a different Stripe account —
 * none of them produce an error anybody sees. They produce renewals that stop
 * happening, and the first report is a member emailing to ask why their card
 * was charged but their access ended.
 *
 * Nothing here returns a secret. Values are reported as present or absent, and
 * identifiers are masked, because this output is read on screen, pasted into
 * support tickets and screenshotted.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic\Payments;

use WordPressistic\Memberistic\Payments\Providers\Stripe_Provider;

use function WordPressistic\Memberistic\memberistic_get_setting;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Payment_Health {
	/**
	 * Full diagnostic report.
	 *
	 * @return array<string, mixed>
	 */
	public static function report() {
		$mode = Stripe_Provider::environment();

		return array(
			'stripe' => array(
				'enabled'            => Stripe_Service::is_enabled(),
				'mode'               => $mode,
				'api_key_present'    => '' !== trim( (string) Stripe_Service::get_secret_key() ),
				'webhook_secret'     => array(
					'present'       => '' !== Stripe_Provider::webhook_secret(),
					'mode_specific' => Stripe_Provider::has_mode_specific_secret( $mode ),
					'test'          => '' !== Stripe_Provider::webhook_secret( 'test' ),
					'live'          => '' !== Stripe_Provider::webhook_secret( 'live' ),
				),
				'account'            => array(
					'verified'    => '' !== Stripe_Provider::expected_account_id(),
					'id_masked'   => self::mask( Stripe_Provider::expected_account_id() ),
					'verified_at' => Stripe_Provider::account_verified_at(),
				),
			),
			'events' => array(
				'last_verified_at'  => (string) get_option( 'memberistic_stripe_webhook_last_verified_at', '' ),
				'last_processed_at' => (string) get_option( 'memberistic_stripe_webhook_last_processed_at', '' ),
				'last_failed_at'    => (string) get_option( 'memberistic_stripe_webhook_last_failed_at', '' ),
				'last_event'        => self::summarise_event( Payment_Event_Repository::latest() ),
				'last_rejected'     => self::summarise_event( Payment_Event_Repository::latest_by_status( Payment_Event_Repository::STATUS_REJECTED ) ),
				'manual_review'     => Payment_Event_Repository::count_by_status( Payment_Event_Repository::STATUS_MANUAL_REVIEW ),
				'failed_retryable'  => Payment_Event_Repository::count_by_status( Payment_Event_Repository::STATUS_FAILED_RETRYABLE ),
			),
			'payments' => array(
				'transaction_conflicts' => self::transaction_conflicts(),
			),
			'problems' => self::problems(),
		);
	}

	/**
	 * Actionable problems, worst first.
	 *
	 * Each string names what is wrong and what to do about it. "Webhook health
	 * degraded" tells an administrator nothing they can act on.
	 *
	 * @return array<int, string>
	 */
	public static function problems() {
		$problems = array();

		if ( ! Stripe_Service::is_enabled() ) {
			return $problems;
		}

		$mode = Stripe_Provider::environment();

		if ( '' === trim( (string) Stripe_Service::get_secret_key() ) ) {
			$problems[] = sprintf(
				/* translators: %s: Stripe mode, "live" or "test". */
				__( 'No Stripe API key is configured for %s mode. Checkout and renewals cannot work until one is added.', 'memberistic' ),
				$mode
			);
		}

		if ( '' === Stripe_Provider::webhook_secret() ) {
			$problems[] = sprintf(
				/* translators: %s: Stripe mode, "live" or "test". */
				__( 'Webhook signing secret missing for %s mode. Every incoming Stripe event will be rejected, so renewals and cancellations will not be recorded.', 'memberistic' ),
				$mode
			);
		} elseif ( ! Stripe_Provider::has_mode_specific_secret( $mode ) ) {
			$problems[] = sprintf(
				/* translators: %s: Stripe mode, "live" or "test". */
				__( 'Stripe is in %s mode but is using the shared webhook signing secret from before 2.1.0. Add the signing secret for this mode so switching modes cannot silently break event verification.', 'memberistic' ),
				$mode
			);
		}

		if ( '' === Stripe_Provider::expected_account_id() ) {
			$problems[] = __( 'The Stripe account for these credentials has not been verified yet. Save the Stripe settings, or run wp memberistic stripe reconcile, so events from a different account can be rejected.', 'memberistic' );
		}

		$manual = Payment_Event_Repository::count_by_status( Payment_Event_Repository::STATUS_MANUAL_REVIEW );
		if ( $manual > 0 ) {
			$problems[] = sprintf(
				/* translators: %d: number of payment events awaiting review. */
				_n(
					'%d payment event needs manual review. It was verified as genuine but contradicted this site\'s records, so no membership was changed.',
					'%d payment events need manual review. They were verified as genuine but contradicted this site\'s records, so no memberships were changed.',
					$manual,
					'memberistic'
				),
				$manual
			);
		}

		$retryable = Payment_Event_Repository::count_by_status( Payment_Event_Repository::STATUS_FAILED_RETRYABLE );
		if ( $retryable > 0 ) {
			$problems[] = sprintf(
				/* translators: %d: number of payment events awaiting retry. */
				_n(
					'%d payment event could not be verified and is waiting for the provider to retry.',
					'%d payment events could not be verified and are waiting for the provider to retry.',
					$retryable,
					'memberistic'
				),
				$retryable
			);
		}

		$conflicts = self::transaction_conflicts();
		if ( $conflicts > 0 ) {
			$problems[] = sprintf(
				/* translators: %d: number of conflicting payment records. */
				_n(
					'%d payment transaction id appears on more than one payment record. Nothing was deleted; review these before treating the payment history as accurate.',
					'%d payment transaction ids appear on more than one payment record. Nothing was deleted; review these before treating the payment history as accurate.',
					$conflicts,
					'memberistic'
				),
				$conflicts
			);
		}

		$last_verified = (string) get_option( 'memberistic_stripe_webhook_last_verified_at', '' );
		if ( '' === $last_verified || strtotime( $last_verified ) < ( time() - DAY_IN_SECONDS ) ) {
			$problems[] = __( 'No signed Stripe event has been verified in the last 24 hours. If this site takes payments daily, check the webhook endpoint in the Stripe dashboard.', 'memberistic' );
		}

		return $problems;
	}

	/**
	 * How many provider transaction ids are duplicated in the payments table.
	 *
	 * @return int
	 */
	private static function transaction_conflicts() {
		$recorded = get_option( \WordPressistic\Memberistic\Database\Migrations::TXN_CONFLICTS_OPTION, array() );

		if ( ! is_array( $recorded ) || empty( $recorded['conflicts'] ) ) {
			return 0;
		}

		return count( $recorded['conflicts'] );
	}

	/**
	 * Reduce a ledger row to something safe to display.
	 *
	 * @param array<string, mixed>|null $row Ledger row.
	 * @return array<string, mixed>|null
	 */
	private static function summarise_event( $row ) {
		if ( ! is_array( $row ) ) {
			return null;
		}

		return array(
			'event_id'    => self::mask( (string) $row['event_id'] ),
			'event_type'  => (string) $row['event_type'],
			'status'      => (string) $row['status'],
			'received_at' => (string) $row['received_at'],
			'reason'      => (string) ( $row['failure_code'] ?? '' ),
		);
	}

	/**
	 * Mask an identifier for display.
	 *
	 * @param string $value Identifier.
	 * @return string
	 */
	private static function mask( $value ) {
		$value = (string) $value;

		if ( '' === $value ) {
			return '';
		}

		if ( strlen( $value ) <= 8 ) {
			return $value;
		}

		return substr( $value, 0, 8 ) . '…' . substr( $value, -4 );
	}
}
