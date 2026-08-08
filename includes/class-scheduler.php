<?php
/**
 * Cron scheduler — drives renewal reminders, auto-expiry, and waiver
 * follow-up emails for memberships that need attention.
 *
 * Hooks (all daily):
 *  - memberistic_daily_renewal_reminders → emails members 30/7/1 days out.
 *  - memberistic_daily_expire_memberships → flips active memberships past
 *    their renewal_date into the 'expired' status.
 *  - memberistic_daily_waiver_followup → emails active members whose waiver
 *    is still missing.
 *
 * Schedules are registered on plugins_loaded and on activation; deactivation
 * clears them.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic;

use WordPressistic\Memberistic\Database\Activity_Repository;
use WordPressistic\Memberistic\Database\Email_Logs_Repository;
use WordPressistic\Memberistic\Database\Memberships_Repository;
use WordPressistic\Memberistic\Database\People_Repository;
use WordPressistic\Memberistic\Emails\Email_Service;
use WordPressistic\Memberistic\Payments\Stripe_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scheduler {
	const HOOK_RENEWAL_REMINDERS = 'memberistic_daily_renewal_reminders';
	const HOOK_AUTO_EXPIRE       = 'memberistic_daily_expire_memberships';
	const HOOK_WAIVER_FOLLOWUP   = 'memberistic_daily_waiver_followup';
	const HOOK_PRUNE_LOGS        = 'memberistic_daily_prune_logs';
	const HOOK_BACKFILL_RENEWALS = 'memberistic_daily_backfill_renewals';
	const HOOK_PRUNE_RATE_LIMITS = 'memberistic_hourly_prune_rate_limits';

	public static function register() {
		add_action( self::HOOK_RENEWAL_REMINDERS, array( self::class, 'run_renewal_reminders' ) );
		add_action( self::HOOK_AUTO_EXPIRE, array( self::class, 'run_auto_expire' ) );
		add_action( self::HOOK_WAIVER_FOLLOWUP, array( self::class, 'run_waiver_followup' ) );
		add_action( self::HOOK_PRUNE_LOGS, array( self::class, 'run_prune_logs' ) );
		add_action( self::HOOK_BACKFILL_RENEWALS, array( self::class, 'run_backfill_renewals' ) );
		add_action( self::HOOK_PRUNE_RATE_LIMITS, array( self::class, 'run_prune_rate_limits' ) );

		self::ensure_scheduled();
	}

	public static function ensure_scheduled() {
		$start = strtotime( 'tomorrow 06:00' );

		foreach (
			array(
				self::HOOK_RENEWAL_REMINDERS,
				self::HOOK_AUTO_EXPIRE,
				self::HOOK_WAIVER_FOLLOWUP,
				self::HOOK_PRUNE_LOGS,
				self::HOOK_BACKFILL_RENEWALS,
			) as $hook
		) {
			if ( ! wp_next_scheduled( $hook ) ) {
				wp_schedule_event( $start ?: time() + DAY_IN_SECONDS, 'daily', $hook );
			}
		}

		if ( ! wp_next_scheduled( self::HOOK_PRUNE_RATE_LIMITS ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::HOOK_PRUNE_RATE_LIMITS );
		}
	}

	/**
	 * Retention pruner. Removes Email_Logs rows older than N days
	 * (default 90, filterable). Keeps the table size bounded so
	 * the admin "Recent emails" view stays performant.
	 */
	public static function run_prune_logs() {
		$days = (int) apply_filters( 'memberistic_email_log_retention_days', 90 );
		Email_Logs_Repository::prune_older_than( $days );

		self::run_retention_purge();
	}

	/**
	 * Enforce the configured data-retention windows.
	 *
	 * Both windows default to 0, meaning "keep indefinitely". That default
	 * is deliberate: a plugin update that silently started deleting a
	 * business's visit history would be destructive and irreversible, and
	 * the right retention period is a policy decision the operator makes,
	 * not one this plugin can infer. Set a window in Settings, or via the
	 * filters below, to turn purging on.
	 *
	 * Only log-style records are ever purged. Memberships, people, waivers,
	 * documents, and payments are never touched here — those are the
	 * business's records, and removing them is what the privacy eraser and
	 * uninstall routine are for.
	 *
	 * @since 2.0.0
	 *
	 * @return array{checkins:int,activity:int} Rows deleted per surface.
	 */
	public static function run_retention_purge() {
		global $wpdb;

		$deleted = array(
			'checkins' => 0,
			'activity' => 0,
		);

		/**
		 * Filters how many days of check-in history to keep.
		 *
		 * @since 2.0.0
		 *
		 * @param int $days Days to retain. 0 (default) keeps everything.
		 */
		$checkin_days = (int) apply_filters(
			'memberistic_checkin_retention_days',
			(int) memberistic_get_setting( 'checkin_retention_days', 0 )
		);

		/**
		 * Filters how many days of activity history to keep.
		 *
		 * @since 2.0.0
		 *
		 * @param int $days Days to retain. 0 (default) keeps everything.
		 */
		$activity_days = (int) apply_filters(
			'memberistic_activity_retention_days',
			(int) memberistic_get_setting( 'activity_retention_days', 0 )
		);

		if ( $checkin_days > 0 ) {
			$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $checkin_days * DAY_IN_SECONDS ) );

			$deleted['checkins'] = (int) $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->prefix}memberistic_checkins WHERE checked_in_at < %s LIMIT 5000",
					$cutoff
				)
			);
		}

		if ( $activity_days > 0 ) {
			$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $activity_days * DAY_IN_SECONDS ) );

			$deleted['activity'] = (int) $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->prefix}memberistic_activity WHERE created_at < %s LIMIT 5000",
					$cutoff
				)
			);
		}

		return $deleted;
	}

	public static function run_prune_rate_limits() {
		Stripe_Service::prune_expired_rate_limits();
	}

	/**
	 * Daily safety-net: give any active/trial membership still missing a
	 * renewal date one anchored on its plan cycle. New members get this at
	 * create/activation time; this catches imported + legacy rows so the
	 * dashboard, waiver card and admin table never disagree.
	 */
	public static function run_backfill_renewals() {
		Memberships_Repository::backfill_missing_renewals( 200 );
	}

	public static function clear_scheduled() {
		foreach (
			array(
				self::HOOK_RENEWAL_REMINDERS,
				self::HOOK_AUTO_EXPIRE,
				self::HOOK_WAIVER_FOLLOWUP,
				self::HOOK_PRUNE_LOGS,
				self::HOOK_BACKFILL_RENEWALS,
				self::HOOK_PRUNE_RATE_LIMITS,
			) as $hook
		) {
			$timestamp = wp_next_scheduled( $hook );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, $hook );
			}
			wp_clear_scheduled_hook( $hook );
		}
	}

	/**
	 * Email active members at 30, 7, and 1 day out from renewal_date.
	 *
	 * De-duplication uses a per-membership/per-template option flag so the
	 * same window only fires once per renewal cycle.
	 */
	public static function run_renewal_reminders() {
		// Window tiers: 30-day reminder fires when renewal is between
		// 8 and 30 days out, 7-day between 2 and 7, 1-day for the
		// last day. The narrower buckets prevent overlap so each
		// membership only matches one template per run.
		$windows = array(
			array( 'days' => 30, 'lo' => 8,  'hi' => 30, 'template' => 'expiring_30_days' ),
			array( 'days' => 7,  'lo' => 2,  'hi' => 7,  'template' => 'expiring_7_days'  ),
			array( 'days' => 1,  'lo' => 0,  'hi' => 1,  'template' => 'expiring_tomorrow' ),
		);

		foreach ( $windows as $w ) {
			$rows = Memberships_Repository::get_renewing_in_days( $w['hi'] );

			foreach ( $rows as $row ) {
				// Constrain to this bucket's lower bound.
				$days_out = (int) ceil( ( strtotime( $row['renewal_date'] ) - current_time( 'timestamp' ) ) / DAY_IN_SECONDS );
				if ( $days_out < $w['lo'] || $days_out > $w['hi'] ) {
					continue;
				}

				// Audit A7: dedupe against the persistent email_logs
				// table instead of fragile transients (which get
				// evicted on object-cache flush, causing repeat
				// sends after every container restart on Redis
				// hosting). Look back 14 days — comfortably longer
				// than any window so a 30-day reminder won't repeat
				// even if cron stalls for a week.
				if ( Email_Logs_Repository::was_sent_for_membership(
					(int) $row['id'],
					$w['template'],
					wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 14 * DAY_IN_SECONDS )
				) ) {
					continue;
				}

				Email_Service::send_membership_email( (int) $row['id'], $w['template'] );

				/**
				 * Fires when a membership enters a renewal-reminder window
				 * (30/7/1 days out). Lets add-ons — e.g. the Messageistic
				 * SMS bridge — send their own reminders. Dedup above means
				 * this fires once per membership per window.
				 *
				 * @param int $membership_id Membership row ID.
				 * @param int $days_out      Whole days until renewal_date.
				 */
				do_action( 'memberistic_membership_expiring', (int) $row['id'], $days_out );
			}
		}
	}

	/**
	 * Flip active memberships whose renewal_date has passed into 'expired'
	 * and notify the primary member.
	 */
	public static function run_auto_expire() {
		$expired = Memberships_Repository::get_active_expired();

		foreach ( $expired as $row ) {
			$membership_id = (int) $row['id'];

			// Recurring Stripe memberships should not be hard-expired just because
			// the local renewal_date is stale. A delayed/missed Stripe webhook can
			// leave the site one cycle behind even when Stripe will retry or has
			// already charged the member. Ask Stripe directly what the
			// subscription's real state is before acting; only fall back to the
			// fixed grace-period guess if Stripe can't be reached.
			if ( self::row_has_recurring_billing( $row ) ) {
				$stripe_status = self::reconcile_recurring_with_stripe( $row );

				if ( 'active' === $stripe_status ) {
					// Stripe confirms the subscription is current — renewal_date
					// was just synced from Stripe's current_period_end. Nothing
					// to do; the missed webhook is now caught up.
					continue;
				}

				if ( null === $stripe_status ) {
					Activity_Repository::log(
						array(
							'membership_id' => $membership_id,
							'activity_type' => 'stripe_reconciliation_inconclusive',
							'title'         => __( 'Stripe reconciliation inconclusive; membership left active', 'memberistic' ),
						)
					);
					// Stripe lookup was inconclusive (API disabled/unreachable) —
					// fall back to the short grace window as a safety net.
					continue;
				}

				if ( 'expire' !== $stripe_status ) {
					// 'past_due', or an inconclusive lookup that's past its grace
					// window — mark past_due rather than silently expiring.
					Memberships_Repository::change_status( $membership_id, 'past_due' );

					Activity_Repository::log(
						array(
							'membership_id' => $membership_id,
							'activity_type' => 'payment_past_due',
							'title'         => 'past_due' === $stripe_status
								? __( 'Recurring membership marked past due (Stripe subscription is past_due/unpaid)', 'memberistic' )
								: __( 'Recurring membership marked past due after renewal grace period', 'memberistic' ),
						)
					);

					if ( ! Email_Logs_Repository::was_sent_for_membership(
						$membership_id,
						'payment_failed',
						wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 7 * DAY_IN_SECONDS )
					) ) {
						Email_Service::send_membership_email( $membership_id, 'payment_failed' );
					}

					continue;
				}

				// 'expire' — Stripe confirms the subscription is canceled /
				// incomplete_expired, so this is a genuine end-of-membership,
				// not a lagging webhook. Fall through to the hard-expire below.
			}

			Memberships_Repository::change_status( $membership_id, 'expired' );

			Activity_Repository::log(
				array(
					'membership_id' => $membership_id,
					'activity_type' => 'membership_expired',
					'title'         => __( 'Membership expired automatically', 'memberistic' ),
				)
			);

			Email_Service::send_membership_email( $membership_id, 'membership_expired' );

			/**
			 * Fires after a membership is auto-expired. Companion hook for
			 * SMS/CRM bridges (the email above stays email-only).
			 *
			 * @param int $membership_id Membership row ID.
			 */
			do_action( 'memberistic_membership_expired', $membership_id );
		}
	}

	private static function row_has_recurring_billing( $row ) {
		$payment_source = isset( $row['payment_source'] ) ? (string) $row['payment_source'] : '';
		return ! empty( $row['stripe_subscription_id'] ) || 'stripe' === $payment_source || 'stripe_subscription' === $payment_source;
	}

	/**
	 * Ask Stripe for the real state of a recurring membership's subscription
	 * instead of guessing from a fixed local grace period.
	 *
	 * @param array $row Membership row (needs id, stripe_subscription_id).
	 * @return string|null 'active' (renewal_date synced from Stripe),
	 *                     'past_due', 'expire', or null when Stripe isn't
	 *                     configured/reachable and the caller should fall
	 *                     back to the fixed grace-period heuristic.
	 */
	private static function reconcile_recurring_with_stripe( $row ) {
		if ( ! class_exists( Stripe_Service::class ) || ! Stripe_Service::is_enabled() ) {
			return null;
		}

		$subscription_id = isset( $row['stripe_subscription_id'] ) ? trim( (string) $row['stripe_subscription_id'] ) : '';
		if ( '' === $subscription_id ) {
			return null;
		}

		$subscription = Stripe_Service::get_subscription( $subscription_id );
		if ( is_wp_error( $subscription ) || empty( $subscription['status'] ) ) {
			return null;
		}

		$status = (string) $subscription['status'];

		if ( in_array( $status, array( 'active', 'trialing' ), true ) ) {
			if ( ! empty( $subscription['current_period_end'] ) ) {
				Memberships_Repository::update(
					(int) $row['id'],
					array( 'renewal_date' => wp_date( 'Y-m-d H:i:s', (int) $subscription['current_period_end'] ) )
				);
			}
			return 'active';
		}

		if ( in_array( $status, array( 'past_due', 'unpaid', 'incomplete' ), true ) ) {
			return 'past_due';
		}

		if ( in_array( $status, array( 'canceled', 'incomplete_expired' ), true ) ) {
			return 'expire';
		}

		return null;
	}

	private static function is_beyond_recurring_grace_period( $row ) {
		$renewal_timestamp = ! empty( $row['renewal_date'] ) ? strtotime( (string) $row['renewal_date'] ) : false;
		if ( ! $renewal_timestamp ) {
			return true;
		}

		$grace_days = (int) apply_filters( 'memberistic_recurring_expiry_grace_days', 3, $row );
		$grace_days = max( 0, min( 30, $grace_days ) );

		return current_time( 'timestamp' ) > ( $renewal_timestamp + $grace_days * DAY_IN_SECONDS );
	}

	/**
	 * Nudge active members whose primary or linked-member waiver is still
	 * missing — once per week per membership.
	 */
	public static function run_waiver_followup() {
		// First, expire any signed waivers past their validity window so they
		// re-enter the "missing/expired" pool and get re-nudged below.
		if ( class_exists( '\WordPressistic\Memberistic\Waivers\Waiver_Service' ) ) {
			\WordPressistic\Memberistic\Waivers\Waiver_Service::expire_due();
			// Heads-up BEFORE expiry (default 30 days out, once per signing
			// cycle) so members renew instead of arriving with a dead waiver.
			\WordPressistic\Memberistic\Waivers\Waiver_Service::send_renewal_reminders();
		}

		$rows = People_Repository::get_active_missing_waiver();

		foreach ( $rows as $row ) {
			$key = sprintf( 'memberistic_waiver_followup_%d', (int) $row['membership_id'] );

			if ( get_transient( $key ) ) {
				continue;
			}

			Email_Service::send_membership_email( (int) $row['membership_id'], 'waiver_missing' );
			set_transient( $key, 1, WEEK_IN_SECONDS );
		}
	}
}
