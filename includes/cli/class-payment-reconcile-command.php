<?php
/**
 * WP-CLI reconciliation between Memberistic and the payment provider.
 *
 * Read, compare, report. Repair only when asked, and never for the cases where
 * repairing means choosing between two subscriptions that both look real —
 * that choice decides whether somebody is billed twice or loses access, and it
 * belongs to a person who can see the account.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic\CLI;

use WordPressistic\Memberistic\Database\Memberships_Repository;
use WordPressistic\Memberistic\Payments\Payment_Audit_Repository;
use WordPressistic\Memberistic\Payments\Payment_Clock;
use WordPressistic\Memberistic\Payments\Payment_Health;
use WordPressistic\Memberistic\Payments\Providers\Stripe_Provider;
use WordPressistic\Memberistic\Payments\Subscription_State_Machine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Payment_Reconcile_Command {
	public static function register() {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		\WP_CLI::add_command( 'memberistic stripe reconcile', array( self::class, 'reconcile' ) );
		\WP_CLI::add_command( 'memberistic stripe health', array( self::class, 'health' ) );
	}

	/**
	 * Compare memberships against the payment provider's current truth.
	 *
	 * ## OPTIONS
	 *
	 * [--membership=<id>]
	 * : Reconcile a single membership.
	 *
	 * [--subscription=<subscription_id>]
	 * : Reconcile whichever membership holds this provider subscription.
	 *
	 * [--all]
	 * : Reconcile every membership with a provider subscription.
	 *
	 * [--apply]
	 * : Write the safe corrections. Without this nothing is changed.
	 *
	 * [--limit=<n>]
	 * : Maximum memberships to examine with --all. Default 100.
	 *
	 * ## EXAMPLES
	 *
	 *     wp memberistic stripe reconcile --membership=42
	 *     wp memberistic stripe reconcile --all --limit=500
	 *     wp memberistic stripe reconcile --subscription=sub_123 --apply
	 *
	 * @param array<int, string>    $args       Positional args.
	 * @param array<string, mixed>  $assoc_args Options.
	 */
	public static function reconcile( $args, $assoc_args ) {
		$apply = ! empty( $assoc_args['apply'] );
		$limit = isset( $assoc_args['limit'] ) ? max( 1, min( 1000, (int) $assoc_args['limit'] ) ) : 100;

		$memberships = self::select( $assoc_args, $limit );

		if ( empty( $memberships ) ) {
			\WP_CLI::warning( 'No memberships matched. Pass --membership, --subscription or --all.' );

			return;
		}

		\WP_CLI::line( $apply ? 'APPLY mode — safe corrections will be written.' : 'REPORT mode — nothing will be changed. Add --apply to write corrections.' );

		$counts = array(
			'checked'   => 0,
			'in_sync'   => 0,
			'corrected' => 0,
			'drift'     => 0,
			'conflict'  => 0,
			'error'     => 0,
		);

		foreach ( $memberships as $membership ) {
			$counts['checked']++;
			$outcome = self::reconcile_one( $membership, $apply );
			$counts[ $outcome ]++;
		}

		\WP_CLI::line( '' );
		foreach ( $counts as $label => $value ) {
			\WP_CLI::line( sprintf( '%s=%d', $label, $value ) );
		}

		if ( $counts['conflict'] > 0 ) {
			\WP_CLI::warning( 'Conflicts were found and deliberately left alone. Each involves two subscriptions that both look real; resolving one wrongly either double-bills a member or removes their access.' );
		}
	}

	/**
	 * Print the payment health report.
	 *
	 * ## EXAMPLES
	 *
	 *     wp memberistic stripe health
	 *
	 * @param array<int, string>   $args       Positional args.
	 * @param array<string, mixed> $assoc_args Options.
	 */
	public static function health( $args, $assoc_args ) {
		$report = Payment_Health::report();

		\WP_CLI::line( 'Stripe' );
		\WP_CLI::line( '  enabled=' . ( $report['stripe']['enabled'] ? 'yes' : 'no' ) );
		\WP_CLI::line( '  mode=' . $report['stripe']['mode'] );
		\WP_CLI::line( '  api_key_present=' . ( $report['stripe']['api_key_present'] ? 'yes' : 'no' ) );
		\WP_CLI::line( '  webhook_secret_present=' . ( $report['stripe']['webhook_secret']['present'] ? 'yes' : 'no' ) );
		\WP_CLI::line( '  webhook_secret_mode_specific=' . ( $report['stripe']['webhook_secret']['mode_specific'] ? 'yes' : 'no' ) );
		\WP_CLI::line( '  account_verified=' . ( $report['stripe']['account']['verified'] ? 'yes' : 'no' ) );
		\WP_CLI::line( '  account=' . $report['stripe']['account']['id_masked'] );

		\WP_CLI::line( 'Events' );
		\WP_CLI::line( '  last_verified=' . $report['events']['last_verified_at'] );
		\WP_CLI::line( '  last_processed=' . $report['events']['last_processed_at'] );
		\WP_CLI::line( '  last_failed=' . $report['events']['last_failed_at'] );
		\WP_CLI::line( '  manual_review=' . $report['events']['manual_review'] );
		\WP_CLI::line( '  failed_retryable=' . $report['events']['failed_retryable'] );

		if ( empty( $report['problems'] ) ) {
			\WP_CLI::success( 'No configuration problems found.' );

			return;
		}

		\WP_CLI::line( '' );
		foreach ( $report['problems'] as $problem ) {
			\WP_CLI::warning( $problem );
		}
	}

	/**
	 * Choose which memberships to examine.
	 *
	 * @param array<string, mixed> $assoc_args Options.
	 * @param int                  $limit      Row cap for --all.
	 * @return array<int, array<string, mixed>>
	 */
	private static function select( array $assoc_args, $limit ) {
		global $wpdb;

		if ( ! empty( $assoc_args['membership'] ) ) {
			$row = Memberships_Repository::get( absint( $assoc_args['membership'] ) );

			return $row ? array( $row ) : array();
		}

		if ( ! empty( $assoc_args['subscription'] ) ) {
			$subscription = sanitize_text_field( (string) $assoc_args['subscription'] );
			$row          = Memberships_Repository::get_by_provider_subscription( Stripe_Provider::key(), $subscription );

			if ( ! $row ) {
				$row = Memberships_Repository::get_by_stripe_subscription_id( $subscription );
			}

			return $row ? array( $row ) : array();
		}

		if ( empty( $assoc_args['all'] ) ) {
			return array();
		}

		$table = Memberships_Repository::table();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
				"SELECT * FROM {$table}
				  WHERE ( provider_subscription_id IS NOT NULL AND provider_subscription_id <> '' )
				     OR ( stripe_subscription_id IS NOT NULL AND stripe_subscription_id <> '' )
				  ORDER BY id ASC
				  LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		return $rows ?: array();
	}

	/**
	 * Compare one membership with the provider.
	 *
	 * @param array<string, mixed> $membership Membership row.
	 * @param bool                 $apply      Whether to write corrections.
	 * @return string One of in_sync, corrected, drift, conflict, error.
	 */
	private static function reconcile_one( array $membership, $apply ) {
		$membership_id   = (int) $membership['id'];
		$subscription_id = trim( (string) ( $membership['provider_subscription_id'] ?? '' ) );

		if ( '' === $subscription_id ) {
			$subscription_id = trim( (string) ( $membership['stripe_subscription_id'] ?? '' ) );
		}

		if ( '' === $subscription_id ) {
			\WP_CLI::line( sprintf( 'membership=%d result=no_subscription', $membership_id ) );

			return 'in_sync';
		}

		$subscription = Stripe_Provider::fetch_subscription( $subscription_id );

		if ( is_wp_error( $subscription ) ) {
			\WP_CLI::line(
				sprintf(
					'membership=%d subscription=%s result=provider_error detail=%s',
					$membership_id,
					self::mask( $subscription_id ),
					$subscription->get_error_message()
				)
			);

			return 'error';
		}

		$remote_state = Stripe_Provider::billing_state_for_subscription( $subscription );
		$local_state  = Subscription_State_Machine::normalize_current( $membership['billing_status'] ?? null );

		if ( null === $remote_state ) {
			\WP_CLI::line(
				sprintf(
					'membership=%d subscription=%s result=unmapped_provider_status status=%s',
					$membership_id,
					self::mask( $subscription_id ),
					sanitize_text_field( (string) ( $subscription['status'] ?? '' ) )
				)
			);

			return 'conflict';
		}

		// The customer on the subscription is not the customer on the
		// membership. That is not drift to be smoothed over — it means one of
		// the two records is about somebody else.
		$remote_customer = isset( $subscription['customer'] ) && is_string( $subscription['customer'] ) ? $subscription['customer'] : '';
		$local_customer  = trim( (string) ( $membership['provider_customer_id'] ?? $membership['stripe_customer_id'] ?? '' ) );

		if ( '' !== $remote_customer && '' !== $local_customer && ! hash_equals( $local_customer, $remote_customer ) ) {
			\WP_CLI::line(
				sprintf(
					'membership=%d subscription=%s result=customer_conflict local=%s remote=%s',
					$membership_id,
					self::mask( $subscription_id ),
					self::mask( $local_customer ),
					self::mask( $remote_customer )
				)
			);

			return 'conflict';
		}

		if ( $local_state === $remote_state ) {
			\WP_CLI::line( sprintf( 'membership=%d subscription=%s result=in_sync state=%s', $membership_id, self::mask( $subscription_id ), $local_state ) );

			return 'in_sync';
		}

		// Drift the state machine will not allow is a conflict, not a repair.
		// Forcing it here would be a way to make an illegal transition legal by
		// running a command, which is exactly the property the matrix exists to
		// prevent.
		if ( ! Subscription_State_Machine::can_transition( $local_state, $remote_state ) ) {
			\WP_CLI::line(
				sprintf(
					'membership=%d subscription=%s result=illegal_transition local=%s remote=%s',
					$membership_id,
					self::mask( $subscription_id ),
					$local_state,
					$remote_state
				)
			);

			return 'conflict';
		}

		if ( ! $apply ) {
			\WP_CLI::line(
				sprintf(
					'membership=%d subscription=%s result=drift local=%s remote=%s action=would_correct',
					$membership_id,
					self::mask( $subscription_id ),
					$local_state,
					$remote_state
				)
			);

			return 'drift';
		}

		$fields = array(
			'billing_status'          => $remote_state,
			'payment_provider'        => Stripe_Provider::key(),
			'provider_account_id'     => Stripe_Provider::expected_account_id(),
			'last_provider_synced_at' => Payment_Clock::now(),
		);

		$period_end = Stripe_Provider::current_period_end( $subscription );
		if ( $period_end ) {
			$fields['current_period_end'] = $period_end;
		}

		$applied = Memberships_Repository::update_billing_state( $membership_id, $fields, $membership['billing_status'] ?? null );

		Payment_Audit_Repository::record(
			array(
				'provider'                 => Stripe_Provider::key(),
				'provider_account_id'      => Stripe_Provider::expected_account_id(),
				'membership_id'            => $membership_id,
				'provider_subscription_id' => $subscription_id,
				'previous_billing_status'  => $local_state,
				'new_billing_status'       => $remote_state,
				'integrity_result'         => $applied ? Payment_Audit_Repository::RESULT_ACCEPTED : Payment_Audit_Repository::RESULT_REJECTED,
				'transition_result'        => $applied ? 'applied' : 'rejected',
				'reason_code'              => Payment_Audit_Repository::REASON_VERIFIED,
				'context'                  => array( 'source' => 'cli_reconcile' ),
			)
		);

		\WP_CLI::line(
			sprintf(
				'membership=%d subscription=%s result=%s local=%s remote=%s',
				$membership_id,
				self::mask( $subscription_id ),
				$applied ? 'corrected' : 'write_failed',
				$local_state,
				$remote_state
			)
		);

		return $applied ? 'corrected' : 'error';
	}

	/**
	 * Mask an identifier for terminal output.
	 *
	 * @param string $id Identifier.
	 * @return string
	 */
	private static function mask( $id ) {
		$id = sanitize_text_field( (string) $id );

		return strlen( $id ) > 10 ? substr( $id, 0, 6 ) . '...' . substr( $id, -4 ) : $id;
	}
}
