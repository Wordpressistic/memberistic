<?php
/**
 * WP-CLI Stripe incident recovery commands.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic\CLI;

use WordPressistic\Memberistic\Database\Memberships_Repository;
use WordPressistic\Memberistic\Payments\Stripe_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Stripe_Recovery_Command {
	public static function register() {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}
		\WP_CLI::add_command( 'memberistic stripe-audit', array( self::class, 'audit' ) );
		\WP_CLI::add_command( 'memberistic stripe-reconcile', array( self::class, 'reconcile' ) );
	}

	public static function audit( $args, $assoc_args ) {
		global $wpdb;
		$since = isset( $assoc_args['since'] ) ? sanitize_text_field( (string) $assoc_args['since'] ) : '2026-07-01';
		$m     = Memberships_Repository::table();
		$p     = $wpdb->prefix . 'memberistic_payments';
		$pending = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$m} WHERE status = 'pending' AND created_at >= %s", $since ) );
		$pending_with_session = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$m} WHERE status = 'pending' AND stripe_checkout_session_id <> '' AND stripe_checkout_session_id IS NOT NULL AND created_at >= %s", $since ) );
		$active_missing_refs = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$m} WHERE status = 'active' AND payment_source = 'stripe' AND (stripe_customer_id = '' OR stripe_customer_id IS NULL OR stripe_subscription_id = '' OR stripe_subscription_id IS NULL)" );
		$duplicate_payments = (int) $wpdb->get_var( "SELECT COUNT(*) FROM (SELECT gateway_transaction_id FROM {$p} WHERE payment_gateway = 'stripe' AND gateway_transaction_id <> '' GROUP BY gateway_transaction_id HAVING COUNT(*) > 1) d" );
		$manual_review = get_option( 'memberistic_stripe_manual_review', array() );

		\WP_CLI::line( 'Memberistic Stripe audit since: ' . $since );
		\WP_CLI::line( 'pending_memberships=' . $pending );
		\WP_CLI::line( 'pending_with_checkout_session=' . $pending_with_session );
		\WP_CLI::line( 'active_missing_stripe_refs=' . $active_missing_refs );
		\WP_CLI::line( 'duplicate_stripe_payment_refs=' . $duplicate_payments );
		\WP_CLI::line( 'manual_review_items=' . ( is_array( $manual_review ) ? count( $manual_review ) : 0 ) );
		\WP_CLI::line( 'last_verified=' . (string) get_option( 'memberistic_stripe_webhook_last_verified_at', '' ) );
		\WP_CLI::line( 'last_processed=' . (string) get_option( 'memberistic_stripe_webhook_last_processed_at', '' ) );
		\WP_CLI::line( 'last_failed=' . (string) get_option( 'memberistic_stripe_webhook_last_failed_at', '' ) );
	}

	public static function reconcile( $args, $assoc_args ) {
		global $wpdb;
		$since = isset( $assoc_args['since'] ) ? sanitize_text_field( (string) $assoc_args['since'] ) : '2026-07-01';
		$apply = ! empty( $assoc_args['apply'] );
		$limit = isset( $assoc_args['limit'] ) ? max( 1, min( 100, (int) $assoc_args['limit'] ) ) : 25;
		$m     = Memberships_Repository::table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, stripe_checkout_session_id FROM {$m}
				 WHERE status = 'pending'
				 AND stripe_checkout_session_id <> ''
				 AND stripe_checkout_session_id IS NOT NULL
				 AND created_at >= %s
				 ORDER BY id ASC
				 LIMIT %d",
				$since,
				$limit
			),
			ARRAY_A
		) ?: array();

		\WP_CLI::line( $apply ? 'APPLY mode' : 'DRY-RUN mode' );
		foreach ( $rows as $row ) {
			$membership_id = (int) $row['id'];
			$session_id    = (string) $row['stripe_checkout_session_id'];
			if ( ! $apply ) {
				\WP_CLI::line( sprintf( 'membership=%d checkout_session=%s action=would_check_stripe', $membership_id, self::mask( $session_id ) ) );
				continue;
			}
			$result = Stripe_Service::confirm_checkout_return( $membership_id, $session_id );
			\WP_CLI::line( sprintf( 'membership=%d checkout_session=%s state=%s', $membership_id, self::mask( $session_id ), sanitize_key( (string) ( $result['state'] ?? 'unknown' ) ) ) );
		}
	}

	private static function mask( $id ) {
		$id = sanitize_text_field( (string) $id );
		return strlen( $id ) > 10 ? substr( $id, 0, 6 ) . '...' . substr( $id, -4 ) : $id;
	}
}
