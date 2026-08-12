<?php
/**
 * Immutable audit trail for payment-integrity decisions.
 *
 * One row per decision, written whether the decision was to act or to refuse.
 * The refusals are the valuable half: when a member says their access
 * disappeared, or an administrator asks why a renewal did not apply, the
 * answer is a row saying which check failed and what the event claimed.
 * Without it the only evidence is an absence, and an absence cannot be
 * distinguished from an event that never arrived.
 *
 * What must never be written here: API keys, webhook signing secrets, card
 * numbers, full provider payloads, or any personal data beyond the identifiers
 * needed to find the records involved. The audit trail is read by support
 * staff and exported in bug reports; treat every column as if it will end up
 * in a screenshot.
 *
 * Rows are written once and never updated. A correction is a new row.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic\Payments;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Payment_Audit_Repository {
	/** Every check passed and the transition was applied. */
	const REASON_VERIFIED = 'verified';

	/** The event had already been decided. */
	const REASON_DUPLICATE = 'duplicate';

	/** The signature did not verify against the configured secret. */
	const REASON_INVALID_SIGNATURE = 'invalid_signature';

	/** The event came from a provider account this site is not configured for. */
	const REASON_WRONG_ACCOUNT = 'wrong_account';

	/** A test event addressed to live records, or the reverse. */
	const REASON_WRONG_ENVIRONMENT = 'wrong_environment';

	/** No membership could be resolved from the event. */
	const REASON_MEMBERSHIP_NOT_FOUND = 'membership_not_found';

	/** The event's subscription is not the membership's current subscription. */
	const REASON_SUBSCRIPTION_MISMATCH = 'subscription_mismatch';

	/** The event's customer is not the membership's customer. */
	const REASON_CUSTOMER_MISMATCH = 'customer_mismatch';

	/** The event's plan/price is not the membership's plan. */
	const REASON_PLAN_MISMATCH = 'plan_mismatch';

	/** The amount paid is not the amount expected. */
	const REASON_AMOUNT_MISMATCH = 'amount_mismatch';

	/** The currency is not the currency expected. */
	const REASON_CURRENCY_MISMATCH = 'currency_mismatch';

	/** An older event arrived after a newer one had been applied. */
	const REASON_STALE_EVENT = 'stale_event';

	/** An event referring to a subscription the membership has moved on from. */
	const REASON_STALE_SUBSCRIPTION_EVENT = 'stale_subscription_event';

	/** The requested state change is not in the transition matrix. */
	const REASON_INVALID_TRANSITION = 'invalid_transition';

	/** The provider could not be reached to confirm the event. */
	const REASON_PROVIDER_UNAVAILABLE = 'provider_unavailable';

	/** Contradictory evidence; a human must decide. */
	const REASON_MANUAL_REVIEW = 'manual_review';

	/** The payload was not the shape the provider documents. */
	const REASON_MALFORMED_EVENT = 'malformed_event';

	/** Verified, but nothing needed to change. */
	const REASON_NO_CHANGE = 'no_change';

	/** Integrity outcome: the event was accepted. */
	const RESULT_ACCEPTED = 'accepted';

	/** Integrity outcome: the event was refused, permanently. */
	const RESULT_REJECTED = 'rejected';

	/** Integrity outcome: the event could not be decided yet. */
	const RESULT_DEFERRED = 'deferred';

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'memberistic_payment_audit';
	}

	/**
	 * Write one audit row.
	 *
	 * @param array<string, mixed> $entry {
	 *     @type string      $event_id                 Provider event id.
	 *     @type string      $event_type               Provider event type.
	 *     @type string      $provider                 Provider key.
	 *     @type string      $provider_account_id      Account scope.
	 *     @type int         $membership_id            Membership involved, if resolved.
	 *     @type string      $provider_subscription_id Subscription involved.
	 *     @type string|null $previous_billing_status  State before.
	 *     @type string|null $new_billing_status       State requested or reached.
	 *     @type string      $integrity_result         One of the RESULT_* constants.
	 *     @type string      $transition_result        applied|rejected|unchanged.
	 *     @type string      $reason_code              One of the REASON_* constants.
	 *     @type array       $context                  Non-sensitive detail.
	 * }
	 * @return int Row id, or 0 on failure.
	 */
	public static function record( array $entry ) {
		global $wpdb;

		$row = array(
			'event_id'                 => isset( $entry['event_id'] ) ? substr( sanitize_text_field( (string) $entry['event_id'] ), 0, 191 ) : null,
			'event_type'               => isset( $entry['event_type'] ) ? substr( sanitize_text_field( (string) $entry['event_type'] ), 0, 100 ) : null,
			'provider'                 => sanitize_key( (string) ( $entry['provider'] ?? '' ) ),
			'provider_account_id'      => substr( sanitize_text_field( (string) ( $entry['provider_account_id'] ?? '' ) ), 0, 64 ),
			'membership_id'            => ! empty( $entry['membership_id'] ) ? absint( $entry['membership_id'] ) : null,
			'provider_subscription_id' => isset( $entry['provider_subscription_id'] ) ? substr( sanitize_text_field( (string) $entry['provider_subscription_id'] ), 0, 191 ) : null,
			'previous_billing_status'  => isset( $entry['previous_billing_status'] ) ? substr( sanitize_key( (string) $entry['previous_billing_status'] ), 0, 32 ) : null,
			'new_billing_status'       => isset( $entry['new_billing_status'] ) ? substr( sanitize_key( (string) $entry['new_billing_status'] ), 0, 32 ) : null,
			'integrity_result'         => substr( sanitize_key( (string) ( $entry['integrity_result'] ?? '' ) ), 0, 32 ),
			'transition_result'        => isset( $entry['transition_result'] ) ? substr( sanitize_key( (string) $entry['transition_result'] ), 0, 32 ) : null,
			'reason_code'              => substr( sanitize_key( (string) ( $entry['reason_code'] ?? '' ) ), 0, 64 ),
			'context'                  => self::encode_context( isset( $entry['context'] ) ? $entry['context'] : array() ),
			'created_at'               => Payment_Clock::now(),
		);

		$inserted = $wpdb->insert( self::table(), $row );

		$id = false === $inserted ? 0 : (int) $wpdb->insert_id;

		/**
		 * Fires after a payment-integrity decision is recorded.
		 *
		 * Intended for shipping the audit trail somewhere durable — a SIEM, an
		 * external log. The array passed is the sanitised row, not the raw
		 * provider payload.
		 *
		 * @param array<string, mixed> $row Audit row as stored.
		 * @param int                  $id  Row id, 0 when the write failed.
		 */
		do_action( 'memberistic_payment_audit_recorded', $row, $id );

		return $id;
	}

	/**
	 * Reduce arbitrary context to something safe to store and show.
	 *
	 * Scalars only, keys allow-listed by shape, values truncated. Callers pass
	 * things like amounts and masked ids; this exists so that a future caller
	 * passing an entire Stripe object — the natural thing to reach for while
	 * debugging — cannot quietly persist card details or an email address into
	 * a table support staff paste into tickets.
	 *
	 * @param mixed $context Raw context.
	 * @return string JSON, always.
	 */
	private static function encode_context( $context ) {
		if ( ! is_array( $context ) ) {
			$context = array( 'note' => $context );
		}

		$clean = array();

		foreach ( $context as $key => $value ) {
			if ( count( $clean ) >= 20 ) {
				break;
			}

			$key = substr( sanitize_key( (string) $key ), 0, 40 );
			if ( '' === $key ) {
				continue;
			}

			if ( is_bool( $value ) ) {
				$clean[ $key ] = $value ? 'true' : 'false';
				continue;
			}

			if ( is_int( $value ) || is_float( $value ) ) {
				$clean[ $key ] = $value;
				continue;
			}

			if ( is_scalar( $value ) ) {
				$clean[ $key ] = substr( sanitize_text_field( (string) $value ), 0, 191 );
				continue;
			}

			// Arrays and objects are summarised rather than serialised: the
			// shape is usually all the reader needs, and the contents are
			// exactly what must not be written here.
			$clean[ $key ] = is_array( $value ) ? sprintf( '[array:%d]', count( $value ) ) : '[object]';
		}

		return (string) wp_json_encode( $clean );
	}

	/**
	 * Audit rows for one membership, newest first.
	 *
	 * @param int $membership_id Membership id.
	 * @param int $limit         Maximum rows.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_for_membership( $membership_id, $limit = 50 ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
				'SELECT * FROM `' . self::table() . '` WHERE membership_id = %d ORDER BY created_at DESC, id DESC LIMIT %d',
				absint( $membership_id ),
				max( 1, min( 500, (int) $limit ) )
			),
			ARRAY_A
		);

		return $rows ?: array();
	}

	/**
	 * Most recent audit row carrying a given integrity result.
	 *
	 * @param string $result One of the RESULT_* constants.
	 * @return array<string, mixed>|null
	 */
	public static function latest_by_result( $result ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
				'SELECT * FROM `' . self::table() . '` WHERE integrity_result = %s ORDER BY created_at DESC, id DESC LIMIT 1',
				sanitize_key( $result )
			),
			ARRAY_A
		);

		return $row ?: null;
	}
}
