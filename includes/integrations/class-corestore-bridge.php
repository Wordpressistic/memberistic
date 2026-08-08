<?php
/**
 * coreSTORE (Coreware) POS bridge.
 *
 * Pushes Memberistic membership state INTO the client's hosted coreSTORE so
 * the counter workflow needs zero new screens:
 *
 *   - the customer's price tier (`tier_id`) is set per the plan → tier map,
 *     so coreSTORE's own tier pricing applies the member discount on every
 *     sale automatically;
 *   - plan / status / expiry are written to the customer record (custom
 *     fields when mapped) so the cashier sees membership state on the
 *     customer screen they already use.
 *
 * Push happens live on membership lifecycle events, with a daily
 * reconciliation cron as the safety net, plus manual "Sync now" from the
 * Integrations screen.
 *
 * This bridge is INDEPENDENT of `POS_Bridge` (an on-premise POS):
 * different toggle, different settings, different transport. Both can run
 * at once during the transition period.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic\Integrations;

use WordPressistic\Memberistic\Database\Plans_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CoreStore_Bridge {

	const OPTION     = 'memberistic_corestore';
	const LOG_OPTION = 'memberistic_corestore_log';
	const ID_MAP     = 'memberistic_corestore_ids';
	const CRON_HOOK  = 'memberistic_corestore_reconcile';

	public static function register() {
		// Cron plumbing stays registered regardless of the toggle so a
		// scheduled event never fires into a missing callback.
		add_action( self::CRON_HOOK, array( self::class, 'reconcile' ) );

		if ( ! Integrations_Registry::is_enabled( 'corestore' ) ) {
			self::unschedule();
			return;
		}

		// Live pushes on membership lifecycle events.
		add_action( 'memberistic_membership_activated', array( self::class, 'push_membership' ), 20, 1 );
		add_action( 'memberistic_membership_expired', array( self::class, 'push_membership' ), 20, 1 );
		add_action( 'memberistic_membership_status_changed', array( self::class, 'push_membership' ), 20, 1 );
		add_action( 'memberistic_membership_payment_recorded', array( self::class, 'push_membership' ), 20, 1 );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	public static function unschedule() {
		$ts = wp_next_scheduled( self::CRON_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::CRON_HOOK );
		}
	}

	/* ─────────────────────────── settings ─────────────────────────── */

	/** @return array Normalised settings. */
	public static function settings() {
		$raw = get_option( self::OPTION, array() );
		$raw = is_array( $raw ) ? $raw : array();
		return array(
			'store_url'      => isset( $raw['store_url'] ) ? (string) $raw['store_url'] : '',
			'api_key'        => isset( $raw['api_key'] ) ? (string) $raw['api_key'] : '',
			'tier_map'       => isset( $raw['tier_map'] ) && is_array( $raw['tier_map'] ) ? array_map( 'strval', $raw['tier_map'] ) : array(),
			'lapsed_tier_id' => isset( $raw['lapsed_tier_id'] ) ? (string) $raw['lapsed_tier_id'] : '',
			'field_plan'     => isset( $raw['field_plan'] ) ? (string) $raw['field_plan'] : '',
			'field_status'   => isset( $raw['field_status'] ) ? (string) $raw['field_status'] : '',
			'field_expires'  => isset( $raw['field_expires'] ) ? (string) $raw['field_expires'] : '',
		);
	}

	public static function client() {
		$s = self::settings();
		return new CoreStore_Client( $s['store_url'], $s['api_key'] );
	}

	/* ─────────────────────────── sync core ────────────────────────── */

	/**
	 * Push one membership's state to coreSTORE. Fails soft: every outcome
	 * is written to the sync log, nothing ever blocks the caller.
	 *
	 * @param int $membership_id Membership row id.
	 * @return bool Success.
	 */
	public static function push_membership( $membership_id ) {
		$membership_id = (int) $membership_id;
		if ( $membership_id <= 0 ) {
			return false;
		}

		$client = self::client();
		if ( ! $client->is_configured() ) {
			return false; // Not an error — credentials simply not entered yet.
		}

		$m = self::membership_snapshot( $membership_id );
		if ( ! $m ) {
			self::log( 'error', sprintf( 'Membership #%d not found for sync.', $membership_id ) );
			return false;
		}
		if ( '' === $m['email'] ) {
			self::log( 'warn', sprintf( 'Membership #%d (%s) has no email — cannot match a coreSTORE customer. Skipped.', $membership_id, $m['name'] ) );
			return false;
		}

		$settings  = self::settings();
		$is_active = in_array( $m['status'], array( 'active', 'trial', 'past_due' ), true );
		$tier      = $is_active ? ( $settings['tier_map'][ (string) $m['plan_id'] ] ?? '' ) : $settings['lapsed_tier_id'];

		$payload = array(
			'first_name'   => $m['first_name'],
			'last_name'    => $m['last_name'],
			'email'        => $m['email'],
		);
		if ( '' !== $m['phone'] ) {
			$payload['phone_number'] = $m['phone'];
		}
		if ( '' !== $tier ) {
			$payload['tier_id'] = $tier;
		} elseif ( ! $is_active && '' === $settings['lapsed_tier_id'] ) {
			$payload['tier_id'] = ''; // clear back to default pricing
		}

		$custom = array();
		if ( '' !== $settings['field_plan'] ) {
			$custom[ $settings['field_plan'] ] = $is_active ? $m['plan_name'] : $m['plan_name'] . ' (lapsed)';
		}
		if ( '' !== $settings['field_status'] ) {
			$custom[ $settings['field_status'] ] = strtoupper( $m['status'] );
		}
		if ( '' !== $settings['field_expires'] ) {
			$custom[ $settings['field_expires'] ] = $m['expires'] ? substr( $m['expires'], 0, 10 ) : '';
		}
		if ( $custom ) {
			$payload['custom_fields'] = $custom;
		}

		/** Final say over the outgoing customer payload. */
		$payload = apply_filters( 'memberistic_corestore_customer_payload', $payload, $m, $is_active );

		// Resolve the coreSTORE customer: cached id → lookup by email → create.
		$ids = get_option( self::ID_MAP, array() );
		$ids = is_array( $ids ) ? $ids : array();
		$cs_id = isset( $ids[ $membership_id ] ) ? (string) $ids[ $membership_id ] : '';

		if ( '' === $cs_id ) {
			$existing = $client->find_customer_by_email( $m['email'] );
			$cs_id    = CoreStore_Client::extract_id( $existing );
		}

		if ( '' !== $cs_id ) {
			$res = $client->update_customer( $cs_id, $payload );
			if ( ! $res['ok'] ) {
				// Stale cached id (customer deleted at the register)? Retry as create.
				$cs_id = '';
			}
		}
		if ( '' === $cs_id ) {
			$res   = $client->create_customer( $payload );
			$cs_id = $res['ok'] ? CoreStore_Client::extract_id( $res['body'] ) : '';
		}

		if ( empty( $res['ok'] ) ) {
			self::log( 'error', sprintf( 'Sync failed for %s (#%d): HTTP %d %s', $m['email'], $membership_id, (int) $res['code'], (string) $res['error'] ) );
			return false;
		}

		if ( '' !== $cs_id && ( ! isset( $ids[ $membership_id ] ) || (string) $ids[ $membership_id ] !== $cs_id ) ) {
			$ids[ $membership_id ] = $cs_id;
			update_option( self::ID_MAP, $ids, false );
		}

		self::log( 'ok', sprintf(
			'%s (#%d) → coreSTORE customer %s: %s%s',
			$m['email'],
			$membership_id,
			$cs_id ?: '?',
			$is_active ? $m['plan_name'] . ' ACTIVE' : strtoupper( $m['status'] ),
			'' !== $tier ? ', tier ' . $tier : ''
		) );
		return true;
	}

	/**
	 * Reconcile every membership that matters: all current members plus
	 * anything that changed status recently (so lapses propagate even if the
	 * live hook was missed). Runs daily via cron and via "Sync now".
	 *
	 * @param int $limit Safety cap per run.
	 * @return array{synced:int,failed:int,skipped:int}
	 */
	public static function reconcile( $limit = 500 ) {
		$client = self::client();
		if ( ! $client->is_configured() ) {
			return array( 'synced' => 0, 'failed' => 0, 'skipped' => 0 );
		}

		global $wpdb;
		$rows = $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}memberistic_memberships
			 WHERE status IN ('active','trial','past_due')
			    OR updated_at >= %s
			 ORDER BY id ASC
			 LIMIT %d",
			wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 45 * DAY_IN_SECONDS ),
			max( 1, (int) $limit )
		) );

		$out = array( 'synced' => 0, 'failed' => 0, 'skipped' => 0 );
		foreach ( (array) $rows as $id ) {
			$ok = self::push_membership( (int) $id );
			if ( $ok ) {
				$out['synced']++;
			} else {
				$out['failed']++;
			}
		}
		self::log( 'ok', sprintf( 'Reconcile finished: %d synced, %d failed/skipped of %d candidates.', $out['synced'], $out['failed'], count( (array) $rows ) ) );
		return $out;
	}

	/* ─────────────────────────── helpers ─────────────────────────── */

	/**
	 * Everything the payload needs about one membership, in one query pass.
	 *
	 * @return array|null
	 */
	private static function membership_snapshot( $membership_id ) {
		global $wpdb;
		$m = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, primary_user_id, plan_id, status, renewal_date, end_date
			 FROM {$wpdb->prefix}memberistic_memberships WHERE id = %d",
			$membership_id
		), ARRAY_A );
		if ( ! $m ) {
			return null;
		}

		$plan      = Plans_Repository::get( (int) $m['plan_id'] );
		$plan_name = is_array( $plan ) && ! empty( $plan['name'] ) ? (string) $plan['name'] : ( 'Plan #' . $m['plan_id'] );

		// Identity: the WP user wins; the primary person row fills the gaps.
		$email = '';
		$name  = '';
		$phone = '';
		if ( ! empty( $m['primary_user_id'] ) ) {
			$user = get_user_by( 'id', (int) $m['primary_user_id'] );
			if ( $user ) {
				$email = (string) $user->user_email;
				$name  = trim( (string) $user->first_name . ' ' . (string) $user->last_name ) ?: (string) $user->display_name;
			}
		}
		$person = $wpdb->get_row( $wpdb->prepare(
			"SELECT full_name, email, phone FROM {$wpdb->prefix}memberistic_people
			 WHERE membership_id = %d ORDER BY (role = 'primary') DESC, id ASC LIMIT 1",
			$membership_id
		), ARRAY_A );
		if ( $person ) {
			$email = $email ?: trim( (string) $person['email'] );
			$name  = $name ?: trim( (string) $person['full_name'] );
			$phone = trim( (string) $person['phone'] );
		}

		$parts = preg_split( '/\s+/', trim( $name ), 2 );

		return array(
			'plan_id'    => (int) $m['plan_id'],
			'plan_name'  => $plan_name,
			'status'     => (string) $m['status'],
			'expires'    => $m['end_date'] ?: $m['renewal_date'],
			'email'      => $email,
			'name'       => $name,
			'first_name' => $parts[0] ?? '',
			'last_name'  => $parts[1] ?? '',
			'phone'      => $phone,
		);
	}

	/** Append to the capped sync log (newest first). */
	public static function log( $level, $message ) {
		$log = get_option( self::LOG_OPTION, array() );
		$log = is_array( $log ) ? $log : array();
		array_unshift( $log, array(
			'time'    => current_time( 'mysql' ),
			'level'   => (string) $level,
			'message' => (string) $message,
		) );
		update_option( self::LOG_OPTION, array_slice( $log, 0, 50 ), false );
	}

	/** @return array Log entries, newest first. */
	public static function get_log( $limit = 15 ) {
		$log = get_option( self::LOG_OPTION, array() );
		return array_slice( is_array( $log ) ? $log : array(), 0, max( 1, (int) $limit ) );
	}
}
