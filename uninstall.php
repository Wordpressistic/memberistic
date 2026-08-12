<?php
/**
 * Uninstall handler.
 *
 * Runs only when the plugin is DELETED from wp-admin, never on deactivation.
 *
 * Default behaviour is to keep everything. Deleting a plugin is a routine
 * troubleshooting step — swap it out, put it back — and a plugin that
 * silently destroys a business's membership records, signed waivers, and
 * payment history the first time someone does that is not recoverable from.
 * Data is removed only when the site owner has explicitly opted in via
 * Settings → "Delete all data on uninstall".
 *
 * With the opt-in on, this removes every table, option, transient, user
 * meta key, scheduled event, role, and generated page this plugin created.
 * Multisite is handled per-site so a network delete does not leave orphan
 * tables behind on every subsite.
 *
 * @package Memberistic
 */

// WP_UNINSTALL_PLUGIN is the guard that matters here: WordPress defines it
// immediately before including this file, so its absence means the file was
// reached some other way and nothing below should run.
//
// The ABSPATH check is redundant against it — WordPress is fully loaded by the
// time an uninstall happens — but Plugin Check's direct-access rule looks for
// ABSPATH specifically and does not accept WP_UNINSTALL_PLUGIN as equivalent.
// That is a fair rule to enforce uniformly rather than special-case, and the
// cost of satisfying it is three lines.
if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Remove all Memberistic data for the current site.
 *
 * @return void
 */
function memberistic_uninstall_site() {
	global $wpdb;

	$settings = get_option( 'memberistic_settings', array() );

	$delete_data = is_array( $settings )
		&& isset( $settings['delete_data_on_uninstall'] )
		&& 'yes' === $settings['delete_data_on_uninstall'];

	if ( ! $delete_data ) {
		return;
	}

	// --- Tables ---------------------------------------------------------
	// Hard-coded list, matched against a strict pattern. Table names are
	// never taken from settings or request input, so nothing here is
	// attacker-influenced.
	$tables = array(
		'memberistic_activity',
		'memberistic_checkins',
		'memberistic_documents',
		'memberistic_email_logs',
		'memberistic_integrations',
		'memberistic_logs',
		'memberistic_memberships',
		'memberistic_notes',
		'memberistic_payments',
		'memberistic_people',
		'memberistic_plans',
		'memberistic_rate_limits',
		'memberistic_waiver_signatures',
		'memberistic_waiver_versions',
		'memberistic_waivers_archive',
		// Corporate module.
		'memberistic_corporate_groups',
		'memberistic_group_members',
		'memberistic_group_payments',
		'memberistic_payment_links',
	);

	foreach ( $tables as $table ) {
		if ( ! preg_match( '/^memberistic_[a-z_]+$/', $table ) ) {
			continue;
		}

		$table_name = esc_sql( $wpdb->prefix . $table );
		$wpdb->query( "DROP TABLE IF EXISTS `{$table_name}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed list, validated above.
	}

	// --- Generated pages ------------------------------------------------
	// Only the check-in page, because it is the only page this plugin
	// creates on its own. Pages the admin made are theirs, not ours.
	$checkin_page_id = (int) get_option( 'memberistic_checkin_page_id', 0 );

	if ( $checkin_page_id > 0 ) {
		wp_delete_post( $checkin_page_id, true );
	}

	// --- Options --------------------------------------------------------
	$options = array(
		'memberistic_settings',
		'memberistic_version',
		'memberistic_db_version',
		'memberistic_checkin_page_id',
		'memberistic_waiver_text',
		'memberistic_waiver_validity_days',
		'memberistic_waiver_renewal_reminder_days',
		'memberistic_lane_included_plan_slugs',
		'memberistic_lane_eligible_statuses',
		'memberistic_stripe_manual_review',
		'memberistic_stripe_webhook_last_processed_at',
		'memberistic_stripe_webhook_last_received_at',
		'memberistic_stripe_webhook_last_verified_at',
		'memberistic_stripe_webhook_last_failed_at',
		'memberistic_corestore_ids',
	);

	foreach ( $options as $option ) {
		delete_option( $option );
	}

	// Sweep any remaining memberistic_* options and transients added by
	// integrations or future versions, so nothing is orphaned in wp_options.
	$wpdb->query(
		"DELETE FROM {$wpdb->options}
		 WHERE option_name LIKE 'memberistic\\_%'
		    OR option_name LIKE '\\_transient\\_memberistic\\_%'
		    OR option_name LIKE '\\_transient\\_timeout\\_memberistic\\_%'
		    OR option_name LIKE '\\_site\\_transient\\_memberistic\\_%'
		    OR option_name LIKE '\\_site\\_transient\\_timeout\\_memberistic\\_%'"
	);

	// --- User meta ------------------------------------------------------
	$wpdb->query(
		"DELETE FROM {$wpdb->usermeta}
		 WHERE meta_key LIKE 'memberistic\\_%'
		    OR meta_key LIKE '\\_memberistic\\_%'"
	);

	// --- Scheduled events -----------------------------------------------
	$cron_hooks = array(
		'memberistic_daily_renewal_reminders',
		'memberistic_daily_expire_memberships',
		'memberistic_daily_waiver_followup',
		'memberistic_daily_prune_logs',
		'memberistic_daily_backfill_renewals',
		'memberistic_hourly_prune_rate_limits',
		'memberistic_corestore_reconcile',
	);

	foreach ( $cron_hooks as $hook ) {
		wp_clear_scheduled_hook( $hook );
	}

	// --- Roles ----------------------------------------------------------
	// Swept by prefix rather than from a fixed list: alongside the six
	// staff roles, this plugin creates 'memberistic_member' and one
	// 'memberistic_plan_<slug>' role per plan at runtime, so the set is
	// not knowable ahead of time.
	$wp_roles = wp_roles();

	foreach ( array_keys( (array) $wp_roles->roles ) as $role ) {
		if ( 0 === strpos( $role, 'memberistic_' ) ) {
			remove_role( $role );
		}
	}

	// --- Capabilities on surviving roles --------------------------------
	// Roles this plugin did not create (administrator, editor, custom
	// roles) still exist, so strip our capabilities off them individually.
	// Re-read after the removals above so we only walk what is left.
	$wp_roles = wp_roles();

	foreach ( $wp_roles->role_objects as $role_object ) {
		if ( empty( $role_object->capabilities ) ) {
			continue;
		}

		foreach ( array_keys( $role_object->capabilities ) as $cap ) {
			if ( 0 === strpos( $cap, 'memberistic_' ) ) {
				$role_object->remove_cap( $cap );
			}
		}
	}
}

if ( is_multisite() ) {
	// Per-site: every subsite has its own prefixed tables, options, and
	// roles. A network delete has to clean each of them, not just whichever
	// site happened to be current.
	$site_ids = get_sites(
		array(
			'fields'                 => 'ids',
			'number'                 => 0,
			'update_site_meta_cache' => false,
		)
	);

	foreach ( $site_ids as $site_id ) {
		switch_to_blog( (int) $site_id );
		memberistic_uninstall_site();
		restore_current_blog();
	}
} else {
	memberistic_uninstall_site();
}
