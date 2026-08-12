<?php
/**
 * The payment event ledger, and the atomic claim that makes it idempotent.
 *
 * This replaces the capped option list Memberistic used to deduplicate Stripe
 * webhooks with, for two reasons.
 *
 * The cap was the smaller problem: it held the last 500 event ids, and a busy
 * site plus a Stripe retry storm can push an id off the end while the retry is
 * still in flight. When that happens the retry is treated as a first delivery
 * and the member is charged a second time in the payments table, emailed a
 * second receipt, and their renewal date is advanced twice.
 *
 * The larger problem was that the check and the mark were two separate
 * operations. An advisory lock was wrapped around them, which closed the
 * window on a single MySQL host, but the correctness of the whole idempotency
 * story then rested on a lock that is best-effort, connection-scoped, and
 * silently unavailable on some managed database configurations. Deduplication
 * is the property that stops a member being charged twice; it should not
 * depend on a lock being available.
 *
 * Here the database decides. A UNIQUE key over
 * (provider, provider_account_id, event_id) means the second INSERT of the same
 * event fails, whatever else is happening on the server, and the failure *is*
 * the deduplication. No lock, no window, no cap.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic\Payments;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Payment_Event_Repository {
	/** Freshly recorded, not yet worked on. */
	const STATUS_RECEIVED = 'received';

	/** A worker holds this event right now. */
	const STATUS_PROCESSING = 'processing';

	/** Verified, applied, finished. */
	const STATUS_PROCESSED = 'processed';

	/** A redelivery of an event already handled. */
	const STATUS_DUPLICATE = 'duplicate';

	/** Failed an integrity check permanently; must not be retried. */
	const STATUS_REJECTED = 'rejected';

	/** Needs a human before anything touches the membership. */
	const STATUS_MANUAL_REVIEW = 'manual_review';

	/** Failed transiently; the provider should retry. */
	const STATUS_FAILED_RETRYABLE = 'failed_retryable';

	/**
	 * How long a claim may be held before another worker may take it over.
	 *
	 * A PHP process that dies mid-event — a fatal, an OOM kill, a deploy
	 * mid-request — leaves its row in `processing` with nobody working on it.
	 * Without a takeover window every subsequent redelivery of that event would
	 * be told "another worker holds this", forever, and the event would never
	 * be applied. Fifteen minutes is comfortably longer than any request this
	 * plugin makes and far shorter than Stripe's retry schedule, which runs for
	 * three days.
	 */
	const CLAIM_TIMEOUT = 900;

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'memberistic_payment_events';
	}

	/**
	 * Claim an event for processing, atomically.
	 *
	 * @param array<string, mixed> $event {
	 *     Normalised event descriptor.
	 *
	 *     @type string $provider            Provider key.
	 *     @type string $provider_account_id Account scope; '' when the provider has none.
	 *     @type string $event_id            Provider event id.
	 *     @type string $event_type          Provider event type.
	 *     @type string $provider_created_at UTC datetime the provider created the event.
	 *     @type string $payload_hash        SHA-256 of the raw payload.
	 *     @type string $provider_customer_id
	 *     @type string $provider_subscription_id
	 * }
	 * @return array{claim:string, id:int, row:array<string,mixed>|null} Claim is
	 *         one of `claimed`, `duplicate`, `held`.
	 */
	public static function claim( array $event ) {
		global $wpdb;

		$identity = self::identity( $event );

		if ( '' === $identity['event_id'] ) {
			// An event with no id cannot be deduplicated, so it is not
			// accepted at all. Every provider this plugin speaks to sends one;
			// its absence means the payload is not what it claims to be.
			return array(
				'claim' => 'rejected',
				'id'    => 0,
				'row'   => null,
			);
		}

		$now = Payment_Clock::now();

		$row = array(
			'provider'                 => $identity['provider'],
			'provider_account_id'      => $identity['provider_account_id'],
			'event_id'                 => $identity['event_id'],
			'event_type'               => isset( $event['event_type'] ) ? substr( sanitize_text_field( (string) $event['event_type'] ), 0, 100 ) : '',
			'provider_created_at'      => isset( $event['provider_created_at'] ) ? $event['provider_created_at'] : null,
			'received_at'              => $now,
			'membership_id'            => isset( $event['membership_id'] ) ? absint( $event['membership_id'] ) : null,
			'provider_customer_id'     => isset( $event['provider_customer_id'] ) ? substr( sanitize_text_field( (string) $event['provider_customer_id'] ), 0, 191 ) : null,
			'provider_subscription_id' => isset( $event['provider_subscription_id'] ) ? substr( sanitize_text_field( (string) $event['provider_subscription_id'] ), 0, 191 ) : null,
			'payload_hash'             => isset( $event['payload_hash'] ) ? substr( preg_replace( '/[^a-f0-9]/', '', strtolower( (string) $event['payload_hash'] ) ), 0, 64 ) : null,
			'status'                   => self::STATUS_PROCESSING,
			'attempt_count'            => 1,
			'created_at'               => $now,
			'updated_at'               => $now,
		);

		// Errors are suppressed because a duplicate-key failure here is an
		// expected, meaningful outcome rather than a fault, and WordPress would
		// otherwise print it to the webhook response.
		$suppress = $wpdb->suppress_errors( true );
		$inserted = $wpdb->insert( self::table(), $row );
		$wpdb->suppress_errors( $suppress );

		if ( false !== $inserted ) {
			$row['id'] = (int) $wpdb->insert_id;

			return array(
				'claim' => 'claimed',
				'id'    => (int) $wpdb->insert_id,
				'row'   => $row,
			);
		}

		return self::resolve_existing( $identity );
	}

	/**
	 * Decide what a failed insert means by looking at the row that blocked it.
	 *
	 * @param array<string, string> $identity Provider/account/event identity.
	 * @return array{claim:string, id:int, row:array<string,mixed>|null}
	 */
	private static function resolve_existing( array $identity ) {
		global $wpdb;

		$existing = self::get_by_identity( $identity );

		if ( ! $existing ) {
			// The insert failed and no row explains why: a genuine database
			// error, not a duplicate. Reported as `held` so the provider
			// retries rather than being told the event was handled.
			return array(
				'claim' => 'held',
				'id'    => 0,
				'row'   => null,
			);
		}

		$id     = (int) $existing['id'];
		$status = (string) $existing['status'];

		// Terminal outcomes. The event has already been decided; saying so
		// again costs nothing and must not repeat any side effect.
		if ( in_array( $status, array( self::STATUS_PROCESSED, self::STATUS_DUPLICATE, self::STATUS_REJECTED, self::STATUS_MANUAL_REVIEW ), true ) ) {
			return array(
				'claim' => 'duplicate',
				'id'    => $id,
				'row'   => $existing,
			);
		}

		// Retryable, or a claim abandoned by a process that died holding it.
		// The takeover is itself a compare-and-swap, so two workers arriving at
		// an expired claim together cannot both win it.
		$taken = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
				'UPDATE `' . self::table() . '`
				    SET status = %s, attempt_count = attempt_count + 1, updated_at = %s
				  WHERE id = %d
				    AND ( status = %s OR ( status = %s AND updated_at < %s ) )',
				self::STATUS_PROCESSING,
				Payment_Clock::now(),
				$id,
				self::STATUS_FAILED_RETRYABLE,
				self::STATUS_PROCESSING,
				Payment_Clock::in( -self::CLAIM_TIMEOUT )
			)
		);

		if ( $taken ) {
			$existing['status'] = self::STATUS_PROCESSING;

			return array(
				'claim' => 'claimed',
				'id'    => $id,
				'row'   => $existing,
			);
		}

		// Another worker holds a fresh claim. The provider should come back.
		return array(
			'claim' => 'held',
			'id'    => $id,
			'row'   => $existing,
		);
	}

	/**
	 * Normalise the three columns the unique key spans.
	 *
	 * `provider_account_id` is never NULL. A provider that does not expose an
	 * account identifier gets the deterministic scope `local`, because NULL in
	 * a MySQL unique key compares unequal to itself — every delivery would
	 * insert successfully and deduplication would silently stop working.
	 *
	 * @param array<string, mixed> $event Event descriptor.
	 * @return array<string, string>
	 */
	public static function identity( array $event ) {
		$account = isset( $event['provider_account_id'] ) ? sanitize_text_field( (string) $event['provider_account_id'] ) : '';

		return array(
			'provider'            => sanitize_key( (string) ( $event['provider'] ?? '' ) ),
			'provider_account_id' => substr( '' === $account ? 'local' : $account, 0, 64 ),
			'event_id'            => substr( sanitize_text_field( (string) ( $event['event_id'] ?? '' ) ), 0, 191 ),
		);
	}

	/**
	 * Fetch a ledger row by its unique identity.
	 *
	 * @param array<string, string> $identity Provider/account/event identity.
	 * @return array<string, mixed>|null
	 */
	public static function get_by_identity( array $identity ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
				'SELECT * FROM `' . self::table() . '` WHERE provider = %s AND provider_account_id = %s AND event_id = %s LIMIT 1',
				$identity['provider'],
				$identity['provider_account_id'],
				$identity['event_id']
			),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Finish an event.
	 *
	 * @param int                  $id      Ledger row id.
	 * @param string               $status  One of the STATUS_* constants.
	 * @param array<string, mixed> $context {
	 *     @type string $failure_code    Machine-readable reason.
	 *     @type string $failure_message Human-readable reason; never a secret.
	 *     @type int    $membership_id   Membership the event resolved to.
	 * }
	 * @return bool
	 */
	public static function finish( $id, $status, array $context = array() ) {
		global $wpdb;

		$id = absint( $id );
		if ( ! $id ) {
			return false;
		}

		$data = array(
			'status'     => sanitize_key( $status ),
			'updated_at' => Payment_Clock::now(),
		);

		if ( in_array( $data['status'], array( self::STATUS_PROCESSED, self::STATUS_DUPLICATE ), true ) ) {
			$data['processed_at'] = Payment_Clock::now();
		}

		if ( isset( $context['failure_code'] ) ) {
			$data['failure_code'] = substr( sanitize_key( (string) $context['failure_code'] ), 0, 64 );
		}

		if ( isset( $context['failure_message'] ) ) {
			// Truncated and sanitised: this is written by code that has just
			// handled a provider payload, and a failure message is not a place
			// to let raw provider text through unchecked.
			$data['failure_message'] = substr( sanitize_text_field( (string) $context['failure_message'] ), 0, 500 );
		}

		if ( ! empty( $context['membership_id'] ) ) {
			$data['membership_id'] = absint( $context['membership_id'] );
		}

		if ( ! empty( $context['provider_subscription_id'] ) ) {
			$data['provider_subscription_id'] = substr( sanitize_text_field( (string) $context['provider_subscription_id'] ), 0, 191 );
		}

		return false !== $wpdb->update( self::table(), $data, array( 'id' => $id ), null, array( '%d' ) );
	}

	/**
	 * Count ledger rows in a given status.
	 *
	 * @param string $status Status to count.
	 * @return int
	 */
	public static function count_by_status( $status ) {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
				'SELECT COUNT(1) FROM `' . self::table() . '` WHERE status = %s',
				sanitize_key( $status )
			)
		);
	}

	/**
	 * Most recent ledger row in a given status, for the health screen.
	 *
	 * @param string $status Status to look for.
	 * @return array<string, mixed>|null
	 */
	public static function latest_by_status( $status ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
				'SELECT * FROM `' . self::table() . '` WHERE status = %s ORDER BY received_at DESC, id DESC LIMIT 1',
				sanitize_key( $status )
			),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Most recent ledger row of any status.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function latest() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix, no user input.
		$row = $wpdb->get_row( 'SELECT * FROM `' . self::table() . '` ORDER BY received_at DESC, id DESC LIMIT 1', ARRAY_A );

		return $row ?: null;
	}

	/**
	 * Events awaiting a human decision.
	 *
	 * @param int $limit Maximum rows.
	 * @return array<int, array<string, mixed>>
	 */
	public static function needing_manual_review( $limit = 50 ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
				'SELECT * FROM `' . self::table() . '` WHERE status = %s ORDER BY received_at DESC LIMIT %d',
				self::STATUS_MANUAL_REVIEW,
				max( 1, min( 500, (int) $limit ) )
			),
			ARRAY_A
		);

		return $rows ?: array();
	}

	/**
	 * Delete processed ledger rows older than a retention window.
	 *
	 * Only `processed` and `duplicate` rows are pruned. A rejected event, one
	 * awaiting manual review, or one still retryable is evidence about
	 * something that went wrong, and the moment it becomes inconvenient to keep
	 * is not the moment to delete it.
	 *
	 * @param int $days Retention window in days.
	 * @return int Rows removed.
	 */
	public static function prune( $days = 180 ) {
		global $wpdb;

		$days = max( 30, (int) $days );

		return (int) $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
				'DELETE FROM `' . self::table() . '` WHERE status IN ( %s, %s ) AND received_at < %s',
				self::STATUS_PROCESSED,
				self::STATUS_DUPLICATE,
				Payment_Clock::in( -1 * $days * DAY_IN_SECONDS )
			)
		);
	}
}
