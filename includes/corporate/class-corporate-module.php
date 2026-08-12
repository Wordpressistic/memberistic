<?php
/**
 * Corporate / Group Membership — Phase 1.
 *
 * A module INSIDE Memberistic (not a separate plugin) so it reuses
 * the existing members, people+waivers, check-ins, QR verification,
 * email logs, activity log, and Stripe infrastructure instead of
 * duplicating eight tables. See docs/CORPORATE_GROUPS_PLAN.md for
 * the full audit + phased plan.
 *
 * Phase 1 delivers: the group-specific schema (groups, members,
 * group-level payments, payment links), capabilities, a repository
 * layer, and the admin "Corporate Groups" list + create flow with
 * nonces, capability checks, sanitization, and activity logging.
 *
 * Later phases (member linking + confirmations, payment links,
 * waiver automation, QR/check-in, front-end UX, reports) build on
 * this foundation and are scoped in the plan doc.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic\Corporate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Module bootstrap + schema owner.
 */
final class Corporate_Module {

	/** Bump when any corporate table changes. */
	const DB_VERSION = '1.1.0';
	const DB_OPTION  = 'memberistic_corporate_db_version';

	/**
	 * Wire the module. Called from the main plugin's register_hooks.
	 */
	public static function register() {
		// Idempotent schema guard — runs on admin_init, cheap.
		add_action( 'admin_init', array( __CLASS__, 'maybe_install' ) );
		// Capabilities onto admin/staff roles.
		add_action( 'admin_init', array( Corporate_Capabilities::class, 'maybe_assign' ) );
		// Phase 2 — branded email template + merge tags for group members.
		add_filter( 'memberistic_email_template_subject', array( Corporate_Emails::class, 'subject' ), 10, 3 );
		add_filter( 'memberistic_email_template_body',    array( Corporate_Emails::class, 'body' ),    10, 3 );
		add_filter( 'memberistic_email_merge_tags',       array( Corporate_Emails::class, 'merge_tags' ), 10, 4 );
		// Phase 3 — public payment-link endpoint + WC paid hooks.
		add_action( 'template_redirect', array( Corporate_Pay::class, 'maybe_handle_public_pay' ), 1 );
		add_action( 'woocommerce_payment_complete',        array( Corporate_Pay::class, 'on_wc_paid' ) );
		add_action( 'woocommerce_order_status_completed',  array( Corporate_Pay::class, 'on_wc_paid' ) );
		add_action( 'woocommerce_order_status_processing', array( Corporate_Pay::class, 'on_wc_paid' ) );
		// Stripe webhook backstop for payment links.
		add_action( 'memberistic_stripe_webhook_event', array( Corporate_Pay::class, 'on_stripe_webhook' ), 10, 2 );
		// Phase 5 — staff QR check-in (attaches to the verify page) +
		// non-member Guest Pass registration.
		add_action( 'memberistic_verify_request',    array( Corporate_Checkin::class, 'maybe_record' ), 10, 3 );
		add_action( 'memberistic_verify_card_after', array( Corporate_Checkin::class, 'staff_panel' ), 10, 2 );
		add_shortcode( 'memberistic_guest_pass', array( Corporate_Guest_Service::class, 'shortcode' ) );
		add_action( 'init', array( Corporate_Guest_Service::class, 'maybe_handle_submit' ) );
		// NOTE: automatic Guest Pass enrollment from bookings and WooCommerce
		// product purchases is intentionally REMOVED. Booking a lane or buying
		// a product must never create a Memberistic membership row — those
		// customers are classified as Range Guests by the booking engine
		// (user-meta segment, no membership). The explicit
		// [memberistic_guest_pass] registration form above remains the only
		// way to issue a Guest Pass, and even a sold Guest Pass never includes
		// free lane time (see Entitlement_Service). Existing auto-created
		// rows are handled by `wp memberistic guest-pass-audit`.
		// Phase 6 — front-end group portal (owner self-service gated by
		// the per-group owner_portal toggle) + member group context.
		add_shortcode( 'memberistic_group_portal', array( Corporate_Portal::class, 'shortcode' ) );
		add_action( 'memberistic_account_dashboard_end', array( Corporate_Portal::class, 'render_for_user' ) );
		add_action( 'init', array( Corporate_Portal::class, 'maybe_handle_owner_action' ) );
		// Phase 4 — public waiver signing endpoint.
		// NOTE: the public ?memberistic_waiver=TOKEN endpoint is now owned by
		// the generalized all-member Waivers module
		// (Waivers\Waiver_Public::register), which serves every member and
		// still mirrors signatures onto corporate group_members. The legacy
		// corporate-only handler below is intentionally left unregistered to
		// avoid double-handling the same request.
		// Payment separation — flag membership-payment WC orders so they
		// are never counted as product sales; Memberistic is the single
		// source of truth for membership revenue.
		add_action( 'woocommerce_admin_order_data_after_order_details', array( Corporate_WC_Separation::class, 'admin_order_badge' ) );
		add_filter( 'woocommerce_analytics_update_order_stats_data', array( Corporate_WC_Separation::class, 'exclude_from_analytics' ), 10, 2 );
		// Admin UI.
		if ( is_admin() ) {
			add_action( 'admin_menu', array( Corporate_Admin::class, 'menu' ), 30 );
			add_action( 'admin_menu', array( Corporate_Reports::class, 'menu' ), 31 );
			add_action( 'admin_post_memberistic_corp_create', array( Corporate_Admin::class, 'handle_create' ) );
			add_action( 'admin_post_memberistic_corp_update', array( Corporate_Admin::class, 'handle_update' ) );
			add_action( 'admin_post_memberistic_corp_delete', array( Corporate_Admin::class, 'handle_delete' ) );
			add_action( 'admin_post_memberistic_corp_add_member',  array( Corporate_Admin::class, 'handle_add_member' ) );
			add_action( 'admin_post_memberistic_corp_bulk_members', array( Corporate_Admin::class, 'handle_bulk_members' ) );
			add_action( 'admin_post_memberistic_corp_remove_member', array( Corporate_Admin::class, 'handle_remove_member' ) );
			add_action( 'admin_post_memberistic_corp_resend',       array( Corporate_Admin::class, 'handle_resend' ) );
			add_action( 'admin_post_memberistic_corp_record_payment', array( Corporate_Admin::class, 'handle_record_payment' ) );
			add_action( 'admin_post_memberistic_corp_create_link',    array( Corporate_Admin::class, 'handle_create_link' ) );
			add_action( 'admin_post_memberistic_corp_resend_link',    array( Corporate_Admin::class, 'handle_resend_link' ) );
			add_action( 'admin_post_memberistic_corp_mark_link_paid', array( Corporate_Admin::class, 'handle_mark_link_paid' ) );
			add_action( 'admin_post_memberistic_corp_void_link',      array( Corporate_Admin::class, 'handle_void_link' ) );
			add_action( 'admin_post_memberistic_corp_resend_waiver',   array( Corporate_Admin::class, 'handle_resend_waiver' ) );
			add_action( 'admin_post_memberistic_corp_waiver_exception', array( Corporate_Admin::class, 'handle_waiver_exception' ) );
			add_action( 'admin_enqueue_scripts', array( Corporate_Admin::class, 'assets' ) );
		}
	}

	public static function maybe_install() {
		if ( get_option( self::DB_OPTION ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Create / upgrade the four group-specific tables. Additive,
	 * dbDelta-safe, never destructive.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$p       = $wpdb->prefix;

		$groups = "CREATE TABLE {$p}memberistic_corporate_groups (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			group_name VARCHAR(191) NOT NULL DEFAULT '',
			company_name VARCHAR(191) NOT NULL DEFAULT '',
			primary_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			plan_key VARCHAR(100) NOT NULL DEFAULT 'corporate',
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			seats_total INT UNSIGNED NOT NULL DEFAULT 0,
			seats_used INT UNSIGNED NOT NULL DEFAULT 0,
			max_future_seats INT UNSIGNED NOT NULL DEFAULT 0,
			custom_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
			payment_status VARCHAR(20) NOT NULL DEFAULT 'unpaid',
			payment_method VARCHAR(30) NOT NULL DEFAULT '',
			visibility VARCHAR(20) NOT NULL DEFAULT 'private',
			owner_portal TINYINT(1) NOT NULL DEFAULT 0,
			admin_notes LONGTEXT NULL,
			created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY primary_user_id (primary_user_id),
			KEY status (status),
			KEY visibility (visibility)
		) {$charset};";

		$members = "CREATE TABLE {$p}memberistic_group_members (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			group_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			membership_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			role VARCHAR(20) NOT NULL DEFAULT 'member',
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			waiver_status VARCHAR(50) NOT NULL DEFAULT 'missing',
			qr_token VARCHAR(64) NOT NULL DEFAULT '',
			invited_at DATETIME NULL,
			joined_at DATETIME NULL,
			removed_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY group_id (group_id),
			KEY user_id (user_id),
			KEY status (status),
			UNIQUE KEY group_user (group_id, user_id)
		) {$charset};";

		$payments = "CREATE TABLE {$p}memberistic_group_payments (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			group_id BIGINT UNSIGNED NOT NULL,
			amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
			payment_method VARCHAR(30) NOT NULL DEFAULT '',
			payment_status VARCHAR(20) NOT NULL DEFAULT 'completed',
			payment_reference VARCHAR(191) NOT NULL DEFAULT '',
			wc_order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			payment_link_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			notes LONGTEXT NULL,
			created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY group_id (group_id),
			KEY payment_status (payment_status)
		) {$charset};";

		$links = "CREATE TABLE {$p}memberistic_payment_links (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			group_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
			description VARCHAR(255) NOT NULL DEFAULT '',
			recipient_email VARCHAR(191) NOT NULL DEFAULT '',
			token VARCHAR(64) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'unpaid',
			wc_order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			expires_at DATETIME NULL,
			paid_at DATETIME NULL,
			created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token (token),
			KEY group_id (group_id),
			KEY status (status)
		) {$charset};";

		dbDelta( $groups );
		dbDelta( $members );
		dbDelta( $payments );
		dbDelta( $links );

		update_option( self::DB_OPTION, self::DB_VERSION );
	}
}

/**
 * Capabilities for the corporate module.
 */
final class Corporate_Capabilities {

	const CAPS = array(
		'manage_memberistic_groups',
		'view_memberistic_groups',
		'manage_memberistic_group_payments',
	);

	const OPTION = 'memberistic_corporate_caps_assigned';

	public static function maybe_assign() {
		if ( '1' === get_option( self::OPTION ) ) {
			return;
		}
		// Admins get all; the existing memberistic staff role (if any)
		// gets view + manage. Falls back to administrator only.
		foreach ( array( 'administrator' ) as $role_name ) {
			$role = get_role( $role_name );
			if ( $role ) {
				foreach ( self::CAPS as $cap ) {
					$role->add_cap( $cap );
				}
			}
		}
		// Memberistic staff role, if present, gets view + payments.
		$staff = get_role( 'memberistic_staff' );
		if ( $staff ) {
			$staff->add_cap( 'view_memberistic_groups' );
			$staff->add_cap( 'manage_memberistic_groups' );
		}
		update_option( self::OPTION, '1' );
	}

	/** Primary capability gate for the admin screens. */
	public static function manage_cap() {
		// Fall back to manage_options so a brand-new install (before
		// caps are assigned) still lets an admin in.
		return current_user_can( 'manage_memberistic_groups' ) ? 'manage_memberistic_groups' : 'manage_options';
	}
}

/**
 * Data access for corporate groups + group payments.
 */
final class Corporate_Groups_Repository {

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'memberistic_corporate_groups';
	}
	public static function payments_table() {
		global $wpdb;
		return $wpdb->prefix . 'memberistic_group_payments';
	}

	/**
	 * Allowed enum values — single source of truth for validation
	 * AND for the admin <select> options.
	 */
	public static function statuses() {
		return array( 'pending', 'active', 'expired', 'cancelled' );
	}
	public static function payment_statuses() {
		return array( 'unpaid', 'partial', 'deposit', 'paid', 'comped' );
	}
	public static function payment_methods() {
		return array( 'cash', 'pos', 'card', 'payment_link', 'manual_comp' );
	}
	public static function visibilities() {
		return array( 'private', 'call_for_details', 'public' );
	}

	/**
	 * Create a group from sanitized input. Returns new ID or 0.
	 *
	 * @param array $data Sanitized fields.
	 */
	public static function create( array $data ) {
		global $wpdb;
		$now = current_time( 'mysql' );
		$row = self::sanitize( $data );
		$row['seats_used'] = 0;
		$row['created_by'] = get_current_user_id();
		$row['created_at'] = $now;
		$row['updated_at'] = $now;

		$ok = $wpdb->insert( self::table(), $row );
		if ( ! $ok ) {
			return 0;
		}
		$id = (int) $wpdb->insert_id;

		// Record the initial group-level payment if an amount + method
		// were supplied (e.g. the $600 POS sale).
		$amount = isset( $data['initial_payment'] ) ? (float) $data['initial_payment'] : 0;
		if ( $amount > 0 ) {
			self::add_payment( $id, array(
				'amount'            => $amount,
				'payment_method'    => $row['payment_method'],
				'payment_status'    => 'completed',
				'payment_reference' => isset( $data['payment_reference'] ) ? sanitize_text_field( (string) $data['payment_reference'] ) : '',
				'notes'             => __( 'Initial payment recorded at group creation.', 'memberistic' ),
			) );
		}

		self::log_activity( $id, 'group_created', sprintf(
			/* translators: %s: group name */
			__( 'Corporate group "%s" created.', 'memberistic' ),
			$row['group_name']
		) );

		return $id;
	}

	/**
	 * Update an existing group.
	 */
	public static function update( $id, array $data ) {
		global $wpdb;
		$id  = (int) $id;
		$row = self::sanitize( $data );
		$row['updated_at'] = current_time( 'mysql' );
		$ok = $wpdb->update( self::table(), $row, array( 'id' => $id ) );
		if ( false !== $ok ) {
			self::log_activity( $id, 'group_updated', __( 'Corporate group updated.', 'memberistic' ) );
		}
		return false !== $ok;
	}

	public static function get( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id ) );
	}

	public static function all( $args = array() ) {
		global $wpdb;
		$per  = max( 1, (int) ( $args['per_page'] ?? 50 ) );
		$page = max( 1, (int) ( $args['paged'] ?? 1 ) );
		$off  = ( $page - 1 ) * $per;
		return $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . ' ORDER BY created_at DESC LIMIT %d OFFSET %d',
			$per,
			$off
		) );
	}

	public static function count() {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() );
	}

	/** The group this user is the primary payer/owner of, if any. */
	public static function get_by_owner( $user_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . " WHERE primary_user_id = %d AND status != 'cancelled' ORDER BY id DESC LIMIT 1",
			(int) $user_id
		) );
	}

	/**
	 * Delete a group and everything tied to it: the member LINKS,
	 * the group-level payments, and the payment links. The members'
	 * own WordPress accounts and individual Memberistic memberships
	 * are NOT touched — they remain as standalone members; only the
	 * group container and its links/records are removed.
	 */
	public static function delete( $id ) {
		global $wpdb;
		$id = (int) $id;
		if ( $id <= 0 ) {
			return false;
		}
		$wpdb->delete( Corporate_Members_Repository::table(),       array( 'group_id' => $id ), array( '%d' ) );
		$wpdb->delete( self::payments_table(),                      array( 'group_id' => $id ), array( '%d' ) );
		$wpdb->delete( Corporate_Payment_Links_Repository::table(), array( 'group_id' => $id ), array( '%d' ) );
		$ok = $wpdb->delete( self::table(), array( 'id' => $id ), array( '%d' ) );
		return false !== $ok;
	}

	/**
	 * Sum of completed payments for a group.
	 */
	public static function paid_total( $group_id ) {
		global $wpdb;
		return (float) $wpdb->get_var( $wpdb->prepare(
			'SELECT COALESCE(SUM(amount),0) FROM ' . self::payments_table() . " WHERE group_id = %d AND payment_status = 'completed'",
			(int) $group_id
		) );
	}

	public static function add_payment( $group_id, array $data ) {
		global $wpdb;
		$row = array(
			'group_id'          => (int) $group_id,
			'amount'            => round( (float) ( $data['amount'] ?? 0 ), 2 ),
			'payment_method'    => in_array( $data['payment_method'] ?? '', self::payment_methods(), true ) ? $data['payment_method'] : 'cash',
			'payment_status'    => sanitize_key( (string) ( $data['payment_status'] ?? 'completed' ) ),
			'payment_reference' => sanitize_text_field( (string) ( $data['payment_reference'] ?? '' ) ),
			'wc_order_id'       => (int) ( $data['wc_order_id'] ?? 0 ),
			'payment_link_id'   => (int) ( $data['payment_link_id'] ?? 0 ),
			'notes'             => sanitize_textarea_field( (string) ( $data['notes'] ?? '' ) ),
			'created_by'        => get_current_user_id(),
			'created_at'        => current_time( 'mysql' ),
		);
		return $wpdb->insert( self::payments_table(), $row ) ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Whitelist + sanitize incoming group fields.
	 */
	private static function sanitize( array $d ) {
		$status  = in_array( $d['status'] ?? '', self::statuses(), true ) ? $d['status'] : 'pending';
		$pstatus = in_array( $d['payment_status'] ?? '', self::payment_statuses(), true ) ? $d['payment_status'] : 'unpaid';
		$method  = in_array( $d['payment_method'] ?? '', self::payment_methods(), true ) ? $d['payment_method'] : '';
		$vis     = in_array( $d['visibility'] ?? '', self::visibilities(), true ) ? $d['visibility'] : 'private';

		$seats_total = max( 0, (int) ( $d['seats_total'] ?? 0 ) );
		$max_future  = max( $seats_total, (int) ( $d['max_future_seats'] ?? 0 ) );

		return array(
			'group_name'       => sanitize_text_field( (string) ( $d['group_name'] ?? '' ) ),
			'company_name'     => sanitize_text_field( (string) ( $d['company_name'] ?? '' ) ),
			'primary_user_id'  => (int) ( $d['primary_user_id'] ?? 0 ),
			'plan_key'         => sanitize_key( (string) ( $d['plan_key'] ?? 'corporate' ) ),
			'status'           => $status,
			'seats_total'      => $seats_total,
			'max_future_seats' => $max_future,
			'custom_price'     => round( (float) ( $d['custom_price'] ?? 0 ), 2 ),
			'payment_status'   => $pstatus,
			'payment_method'   => $method,
			'visibility'       => $vis,
			'owner_portal'     => ! empty( $d['owner_portal'] ) ? 1 : 0,
			'admin_notes'      => sanitize_textarea_field( (string) ( $d['admin_notes'] ?? '' ) ),
		);
	}

	/**
	 * Log to the shared Memberistic activity table so group events
	 * appear in the same timeline as membership events. Uses the
	 * generic 'staff_note_added' type with a corporate object type
	 * (the activity table validates type against its own whitelist).
	 */
	public static function log_activity( $group_id, $action, $message ) {
		if ( ! class_exists( '\WordPressistic\Memberistic\Database\Activity_Repository' ) ) {
			return;
		}
		\WordPressistic\Memberistic\Database\Activity_Repository::log( array(
			'activity_type'       => 'staff_note_added',
			'title'               => 'corporate:' . sanitize_key( $action ),
			'description'         => $message,
			'related_object_type' => 'corporate_group',
			'related_object_id'   => (string) (int) $group_id,
		) );
	}
}

/**
 * Admin UI — Corporate Groups list + create/edit.
 */
final class Corporate_Admin {

	const PAGE = 'memberistic-corporate-groups';

	public static function menu() {
		add_submenu_page(
			'memberistic-dashboard',
			__( 'Corporate Groups', 'memberistic' ),
			__( 'Corporate Groups', 'memberistic' ),
			Corporate_Capabilities::manage_cap(),
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	public static function assets( $hook ) {
		// Covers both the Corporate Groups page AND the Corporate
		// Reports page (both share the .memberistic-corp-wrap shell).
		if ( false === strpos( (string) $hook, 'corporate' ) ) {
			return;
		}
		$css = self::admin_css();
		wp_register_style( 'memberistic-corp-inline', false );
		wp_enqueue_style( 'memberistic-corp-inline' );
		wp_add_inline_style( 'memberistic-corp-inline', $css );
	}

	/**
	 * Premium tactical-luxury admin styling for the Corporate
	 * console: animated tabs, lifting cards, clean fields + focus
	 * rings, refined status badges, and a proper toggle for the
	 * owner-portal checkbox (which previously stretched to 100%
	 * width and rendered as a broken bar).
	 */
	private static function admin_css() {
		return '
		/* ===== Palette + shell ===== */
		.memberistic-corp-wrap{--memberistic-corp-ink:#0F1115;--memberistic-corp-panel:#161A21;--memberistic-corp-line:#E2E1E4;--memberistic-corp-brass:#C9A84C;--memberistic-corp-brass-d:#A8862F;--memberistic-corp-ember:#E8802F;--memberistic-corp-ok:#5a8a2c;--memberistic-corp-okbg:rgba(157,224,91,.16);--memberistic-corp-warnbg:rgba(232,128,47,.14);--memberistic-corp-nobg:rgba(232,80,80,.12);--memberistic-corp-muted:#646970;max-width:1180px;}
		.memberistic-corp-wrap h1{font-size:26px;font-weight:700;letter-spacing:-.01em;color:var(--memberistic-corp-ink);display:flex;align-items:center;gap:12px;}
		.memberistic-corp-wrap .page-title-action{border-radius:6px!important;border:1px solid var(--memberistic-corp-brass)!important;background:var(--memberistic-corp-brass)!important;color:#1a1408!important;font-weight:600!important;transition:transform .15s ease,box-shadow .15s ease,background .15s ease;}
		.memberistic-corp-wrap .page-title-action:hover{background:var(--memberistic-corp-brass-d)!important;transform:translateY(-1px);box-shadow:0 6px 16px rgba(168,134,47,.3);}
		.memberistic-corp-wrap > p.description{color:var(--memberistic-corp-muted);font-size:13.5px;margin:6px 0 4px;}

		/* ===== Animated nav tabs ===== */
		.memberistic-corp-wrap .nav-tab-wrapper{border-bottom:1px solid var(--memberistic-corp-line);display:flex;gap:2px;padding:0;margin:18px 0 0;}
		.memberistic-corp-wrap .nav-tab{position:relative;background:transparent;border:0;margin:0;padding:13px 20px;font-size:13.5px;font-weight:600;color:var(--memberistic-corp-muted);border-radius:8px 8px 0 0;transition:color .2s ease,background .2s ease;overflow:hidden;}
		.memberistic-corp-wrap .nav-tab:hover{color:var(--memberistic-corp-ink);background:rgba(201,168,76,.07);}
		.memberistic-corp-wrap .nav-tab::after{content:"";position:absolute;left:14px;right:14px;bottom:0;height:3px;border-radius:3px 3px 0 0;background:var(--memberistic-corp-brass);transform:scaleX(0);transform-origin:center;transition:transform .28s cubic-bezier(.4,0,.2,1);}
		.memberistic-corp-wrap .nav-tab-active,.memberistic-corp-wrap .nav-tab-active:focus{color:var(--memberistic-corp-ink);background:#fff;box-shadow:none;}
		.memberistic-corp-wrap .nav-tab-active::after{transform:scaleX(1);}

		/* ===== Cards (lift on hover) ===== */
		.memberistic-corp-card{background:#fff;border:1px solid var(--memberistic-corp-line);border-radius:12px;padding:22px 24px;margin:18px 0;box-shadow:0 1px 2px rgba(16,17,21,.04);transition:transform .2s cubic-bezier(.4,0,.2,1),box-shadow .2s ease,border-color .2s ease;}
		.memberistic-corp-card:hover{transform:translateY(-2px);box-shadow:0 12px 30px rgba(16,17,21,.08);border-color:#d3cdbb;}
		.memberistic-corp-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:16px;}
		.memberistic-corp-grid .memberistic-corp-card{margin:0;animation:memberisticCardIn .4s cubic-bezier(.4,0,.2,1) both;}
		.memberistic-corp-grid .memberistic-corp-card:nth-child(2){animation-delay:.05s;}
		.memberistic-corp-grid .memberistic-corp-card:nth-child(3){animation-delay:.1s;}
		.memberistic-corp-grid .memberistic-corp-card:nth-child(4){animation-delay:.15s;}
		.memberistic-corp-grid .memberistic-corp-card:nth-child(5){animation-delay:.2s;}
		.memberistic-corp-grid .memberistic-corp-card:nth-child(6){animation-delay:.25s;}
		@keyframes memberisticCardIn{from{opacity:0;transform:translateY(10px);}to{opacity:1;transform:translateY(0);}}
		/* Stat-card numbers (overview + reports) */
		.memberistic-corp-card .description{text-transform:uppercase;letter-spacing:.1em;font-size:10.5px!important;color:var(--memberistic-corp-muted)!important;font-weight:600;}
		.memberistic-corp-card > div[style*="font-size:24px"],.memberistic-corp-card > div[style*="font-size:26px"]{color:var(--memberistic-corp-ink);}

		/* ===== Forms ===== */
		.memberistic-corp-field{margin:0 0 4px;}
		.memberistic-corp-field label{display:block;font-weight:600;color:var(--memberistic-corp-ink);margin:0 0 6px;font-size:13px;}
		.memberistic-corp-field input:not([type=checkbox]):not([type=radio]),
		.memberistic-corp-field select,
		.memberistic-corp-field textarea{width:100%;border:1px solid var(--memberistic-corp-line);border-radius:8px;padding:9px 12px;font-size:14px;background:#fff;transition:border-color .15s ease,box-shadow .15s ease;box-shadow:none;}
		.memberistic-corp-field input:not([type=checkbox]):focus,
		.memberistic-corp-field select:focus,
		.memberistic-corp-field textarea:focus{border-color:var(--memberistic-corp-brass);box-shadow:0 0 0 3px rgba(201,168,76,.18);outline:none;}

		/* ===== Owner-portal toggle (was a broken full-width red bar) ===== */
		.memberistic-corp-wrap input[type=checkbox]{appearance:none;-webkit-appearance:none;width:44px!important;height:24px;min-width:44px;border-radius:999px;background:#cfcfd4;border:0;position:relative;cursor:pointer;transition:background .2s ease;margin:0;flex:0 0 44px;box-shadow:none;}
		.memberistic-corp-wrap input[type=checkbox]::before{content:"";position:absolute;top:2px;left:2px;width:20px;height:20px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.3);transition:transform .2s cubic-bezier(.4,0,.2,1);}
		.memberistic-corp-wrap input[type=checkbox]:checked{background:var(--memberistic-corp-brass);}
		.memberistic-corp-wrap input[type=checkbox]:checked::before{transform:translateX(20px);}
		.memberistic-corp-wrap input[type=checkbox]:focus{box-shadow:0 0 0 3px rgba(201,168,76,.25);}

		/* ===== Status badges ===== */
		.memberistic-corp-badge{display:inline-block;padding:4px 11px;border-radius:999px;font-size:11px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;line-height:1.4;}
		.memberistic-corp-badge.active,.memberistic-corp-badge.paid{background:var(--memberistic-corp-okbg);color:var(--memberistic-corp-ok);border:1px solid rgba(157,224,91,.55);}
		.memberistic-corp-badge.pending,.memberistic-corp-badge.partial,.memberistic-corp-badge.deposit{background:var(--memberistic-corp-warnbg);color:#b5611f;border:1px solid rgba(232,128,47,.5);}
		.memberistic-corp-badge.expired,.memberistic-corp-badge.cancelled{background:rgba(120,120,120,.12);color:#777;border:1px solid #d0d0d3;}
		.memberistic-corp-badge.unpaid{background:var(--memberistic-corp-nobg);color:#b53030;border:1px solid rgba(232,80,80,.45);}

		/* ===== Tables ===== */
		.memberistic-corp-wrap .wp-list-table{border:1px solid var(--memberistic-corp-line);border-radius:12px;overflow:hidden;box-shadow:0 1px 2px rgba(16,17,21,.04);}
		.memberistic-corp-wrap .wp-list-table thead th{background:#FAF7EF;color:var(--memberistic-corp-ink);font-size:11px;letter-spacing:.08em;text-transform:uppercase;border-bottom:1px solid var(--memberistic-corp-line);padding:13px 14px;}
		.memberistic-corp-wrap .wp-list-table tbody td{padding:13px 14px;border-bottom:1px solid #f0eff2;vertical-align:middle;}
		.memberistic-corp-wrap .wp-list-table tbody tr{transition:background .15s ease;}
		.memberistic-corp-wrap .wp-list-table tbody tr:hover{background:rgba(201,168,76,.05);}
		.memberistic-corp-wrap .wp-list-table tbody tr:last-child td{border-bottom:0;}
		.memberistic-corp-wrap .wp-list-table a{color:var(--memberistic-corp-brass-d);font-weight:600;text-decoration:none;}
		.memberistic-corp-wrap .wp-list-table a:hover{color:var(--memberistic-corp-ember);text-decoration:underline;}

		/* ===== Buttons ===== */
		.memberistic-corp-wrap .button-primary{background:var(--memberistic-corp-brass)!important;border-color:var(--memberistic-corp-brass-d)!important;color:#1a1408!important;border-radius:8px!important;font-weight:600!important;box-shadow:none!important;transition:transform .15s ease,box-shadow .15s ease,background .15s ease;padding:4px 18px!important;height:auto!important;line-height:2!important;}
		.memberistic-corp-wrap .button-primary:hover{background:var(--memberistic-corp-brass-d)!important;transform:translateY(-1px);box-shadow:0 6px 16px rgba(168,134,47,.3);}
		.memberistic-corp-wrap .button-small{border-radius:6px!important;transition:border-color .15s ease,color .15s ease,background .15s ease;}
		.memberistic-corp-wrap .button-small:hover{border-color:var(--memberistic-corp-brass)!important;color:var(--memberistic-corp-brass-d)!important;}

		/* ===== Headings + section spacing ===== */
		.memberistic-corp-wrap h2{font-size:17px;font-weight:700;color:var(--memberistic-corp-ink);margin:30px 0 10px;}
		.memberistic-corp-wrap h3{font-size:14px;font-weight:700;color:var(--memberistic-corp-ink);margin:0 0 10px;}
		.memberistic-corp-wrap > a[href*="page=memberistic-corporate"]{display:inline-block;margin:8px 0;color:var(--memberistic-corp-brass-d);font-weight:600;text-decoration:none;}

		@media (prefers-reduced-motion: reduce){
			.memberistic-corp-wrap *{animation:none!important;transition:none!important;}
		}
';
	}

	/**
	 * Route: list (default), new, or single.
	 */
	public static function render() {
		if ( ! current_user_can( Corporate_Capabilities::manage_cap() ) ) {
			wp_die( esc_html__( 'You do not have permission to manage corporate groups.', 'memberistic' ) );
		}
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'list'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'new' === $view ) {
			self::render_form();
		} elseif ( 'single' === $view ) {
			self::render_single();
		} else {
			self::render_list();
		}
	}

	private static function render_list() {
		$groups = Corporate_Groups_Repository::all();
		$new_url = admin_url( 'admin.php?page=' . self::PAGE . '&view=new' );
		?>
		<div class="wrap memberistic-corp-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Corporate Groups', 'memberistic' ); ?></h1>
			<a href="<?php echo esc_url( $new_url ); ?>" class="page-title-action"><?php esc_html_e( 'Create Group', 'memberistic' ); ?></a>
			<?php if ( isset( $_GET['created'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Corporate group created.', 'memberistic' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Corporate group updated.', 'memberistic' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['deleted'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Corporate group deleted. The members keep their individual accounts and memberships.', 'memberistic' ); ?></p></div>
			<?php endif; ?>

			<p class="description"><?php esc_html_e( 'One organization pays once; each member keeps their own account, waiver, and QR check-in. Group plans stay hidden from public pricing unless set to "Call for Details" or "Public".', 'memberistic' ); ?></p>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Group', 'memberistic' ); ?></th>
						<th><?php esc_html_e( 'Primary Payer', 'memberistic' ); ?></th>
						<th><?php esc_html_e( 'Seats', 'memberistic' ); ?></th>
						<th><?php esc_html_e( 'Paid', 'memberistic' ); ?></th>
						<th><?php esc_html_e( 'Payment', 'memberistic' ); ?></th>
						<th><?php esc_html_e( 'Status', 'memberistic' ); ?></th>
						<th><?php esc_html_e( 'Created', 'memberistic' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $groups ) ) : ?>
						<tr><td colspan="7"><?php esc_html_e( 'No corporate groups yet. Click “Create Group” to set one up.', 'memberistic' ); ?></td></tr>
					<?php else : foreach ( $groups as $g ) :
						$payer = $g->primary_user_id ? get_userdata( $g->primary_user_id ) : null;
						$paid  = Corporate_Groups_Repository::paid_total( $g->id );
						$single_url = admin_url( 'admin.php?page=' . self::PAGE . '&view=single&id=' . (int) $g->id );
						$delete_url = wp_nonce_url( admin_url( 'admin-post.php?action=memberistic_corp_delete&group_id=' . (int) $g->id ), 'memberistic_corp_delete_' . (int) $g->id );
					?>
						<tr>
							<td>
								<strong><a href="<?php echo esc_url( $single_url ); ?>"><?php echo esc_html( $g->group_name ?: $g->company_name ?: ( '#' . $g->id ) ); ?></a></strong>
								<?php if ( $g->company_name && $g->company_name !== $g->group_name ) : ?><br><span class="description"><?php echo esc_html( $g->company_name ); ?></span><?php endif; ?>
								<div class="row-actions">
									<span class="edit"><a href="<?php echo esc_url( $single_url ); ?>"><?php esc_html_e( 'Manage', 'memberistic' ); ?></a> | </span>
									<span class="trash"><a href="<?php echo esc_url( $delete_url ); ?>" style="color:#b32d2e;" onclick="return confirm('<?php echo esc_js( __( 'Delete this group? The group + its payment records are removed. Members KEEP their individual accounts, memberships, waivers, and QR codes.', 'memberistic' ) ); ?>');"><?php esc_html_e( 'Delete', 'memberistic' ); ?></a></span>
								</div>
							</td>
							<td><?php echo $payer ? esc_html( $payer->display_name . ' (' . $payer->user_email . ')' ) : '&mdash;'; ?></td>
							<?php /* translators: %d: the highest number of seats the group may grow to. */ ?>
							<td><?php echo esc_html( (int) $g->seats_used . ' / ' . (int) $g->seats_total ); ?><?php if ( (int) $g->max_future_seats > (int) $g->seats_total ) : ?> <span class="description">(<?php echo esc_html( sprintf( __( 'up to %d', 'memberistic' ), (int) $g->max_future_seats ) ); ?>)</span><?php endif; ?></td>
							<td>$<?php echo esc_html( number_format( $paid, 2 ) ); ?><?php if ( (float) $g->custom_price > 0 ) : ?> <span class="description">/ $<?php echo esc_html( number_format( (float) $g->custom_price, 2 ) ); ?></span><?php endif; ?></td>
							<td><span class="memberistic-corp-badge <?php echo esc_attr( $g->payment_status ); ?>"><?php echo esc_html( ucfirst( $g->payment_status ) ); ?></span></td>
							<td><span class="memberistic-corp-badge <?php echo esc_attr( $g->status ); ?>"><?php echo esc_html( ucfirst( $g->status ) ); ?></span></td>
							<td><?php echo esc_html( mysql2date( get_option( 'date_format' ), $g->created_at ) ); ?></td>
						</tr>
					<?php endforeach; endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private static function render_form( $group = null, $wrap = true ) {
		$is_edit = ( $group instanceof \stdClass );
		$action  = $is_edit ? 'memberistic_corp_update' : 'memberistic_corp_create';
		$nonce   = $is_edit ? 'memberistic_corp_update_' . (int) $group->id : 'memberistic_corp_create';
		$back    = admin_url( 'admin.php?page=' . self::PAGE );
		$val     = function ( $field, $default = '' ) use ( $group, $is_edit ) {
			return $is_edit && isset( $group->$field ) ? $group->$field : $default;
		};
		// "Create group from selected members" — IDs handed over from
		// the Members screen bulk action. Validated to ints; prefills
		// the seat count and seeds a hidden field for handle_create.
		$from_members = array();
		if ( ! $is_edit && isset( $_GET['from_members'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$from_members = array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_GET['from_members'] ) ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		$from_count = count( $from_members );
		// $wrap=false when rendered inside the tabbed single-group view
		// (which already opened .wrap + heading), to avoid nesting.
		if ( $wrap ) : ?>
		<div class="wrap memberistic-corp-wrap">
			<h1><?php echo $is_edit ? esc_html__( 'Edit Corporate Group', 'memberistic' ) : esc_html__( 'Create Corporate Group', 'memberistic' ); ?></h1>
			<a href="<?php echo esc_url( $back ); ?>">&larr; <?php esc_html_e( 'Back to all groups', 'memberistic' ); ?></a>
		<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="memberistic-corp-card">
				<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
				<?php if ( $is_edit ) : ?><input type="hidden" name="group_id" value="<?php echo (int) $group->id; ?>"><?php endif; ?>
				<?php wp_nonce_field( $nonce ); ?>
				<?php if ( $from_count > 0 ) : ?>
					<input type="hidden" name="from_members" value="<?php echo esc_attr( implode( ',', $from_members ) ); ?>">
					<div class="notice notice-info inline" style="margin:0 0 16px;border-radius:8px;">
						<?php /* translators: %d: number of members selected for the move. */ ?>
						<p><strong><?php echo esc_html( sprintf( _n( '%d selected member', '%d selected members', $from_count, 'memberistic' ), $from_count ) ); ?></strong> <?php esc_html_e( 'will be added to this group once you create it. Their existing accounts, waivers, and QR codes are kept — they are simply linked into the group. Make sure “Seats Purchased” is at least this number.', 'memberistic' ); ?></p>
					</div>
				<?php endif; ?>

				<div class="memberistic-corp-grid">
					<div class="memberistic-corp-field">
						<label for="group_name"><?php esc_html_e( 'Group / Display Name', 'memberistic' ); ?> *</label>
						<input type="text" id="group_name" name="group_name" required value="<?php echo esc_attr( $val( 'group_name' ) ); ?>">
					</div>
					<div class="memberistic-corp-field">
						<label for="company_name"><?php esc_html_e( 'Company Name', 'memberistic' ); ?></label>
						<input type="text" id="company_name" name="company_name" value="<?php echo esc_attr( $val( 'company_name' ) ); ?>">
					</div>
					<div class="memberistic-corp-field">
						<label for="primary_user_id"><?php esc_html_e( 'Primary Payer (WP user)', 'memberistic' ); ?></label>
						<?php
						wp_dropdown_users( array(
							'name'             => 'primary_user_id',
							'id'               => 'primary_user_id',
							'selected'         => (int) $val( 'primary_user_id', 0 ),
							'show_option_none' => __( '— Select payer —', 'memberistic' ),
							'option_none_value'=> 0,
							'show'             => 'display_name_with_login',
						) );
						?>
					</div>
					<div class="memberistic-corp-field">
						<label for="plan_key"><?php esc_html_e( 'Plan Key', 'memberistic' ); ?></label>
						<input type="text" id="plan_key" name="plan_key" value="<?php echo esc_attr( $val( 'plan_key', 'corporate' ) ); ?>">
					</div>
					<div class="memberistic-corp-field">
						<label for="seats_total"><?php esc_html_e( 'Seats Purchased', 'memberistic' ); ?></label>
						<input type="number" min="0" id="seats_total" name="seats_total" value="<?php echo esc_attr( $val( 'seats_total', $from_count > 0 ? max( 10, $from_count ) : 10 ) ); ?>">
					</div>
					<div class="memberistic-corp-field">
						<label for="max_future_seats"><?php esc_html_e( 'Max Future Seats', 'memberistic' ); ?></label>
						<input type="number" min="0" id="max_future_seats" name="max_future_seats" value="<?php echo esc_attr( $val( 'max_future_seats', 60 ) ); ?>">
					</div>
					<div class="memberistic-corp-field">
						<label for="custom_price"><?php esc_html_e( 'Custom Price ($)', 'memberistic' ); ?></label>
						<input type="number" step="0.01" min="0" id="custom_price" name="custom_price" value="<?php echo esc_attr( $val( 'custom_price', '600.00' ) ); ?>">
					</div>
					<div class="memberistic-corp-field">
						<label for="status"><?php esc_html_e( 'Group Status', 'memberistic' ); ?></label>
						<select id="status" name="status">
							<?php foreach ( Corporate_Groups_Repository::statuses() as $s ) : ?>
								<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $val( 'status', 'pending' ), $s ); ?>><?php echo esc_html( ucfirst( $s ) ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="memberistic-corp-field">
						<label for="payment_status"><?php esc_html_e( 'Payment Status', 'memberistic' ); ?></label>
						<select id="payment_status" name="payment_status">
							<?php foreach ( Corporate_Groups_Repository::payment_statuses() as $s ) : ?>
								<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $val( 'payment_status', 'unpaid' ), $s ); ?>><?php echo esc_html( ucfirst( $s ) ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="memberistic-corp-field">
						<label for="payment_method"><?php esc_html_e( 'Payment Method', 'memberistic' ); ?></label>
						<select id="payment_method" name="payment_method">
							<option value=""><?php esc_html_e( '— none —', 'memberistic' ); ?></option>
							<?php foreach ( Corporate_Groups_Repository::payment_methods() as $s ) : ?>
								<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $val( 'payment_method', '' ), $s ); ?>><?php echo esc_html( ucwords( str_replace( '_', ' ', $s ) ) ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="memberistic-corp-field">
						<label for="visibility"><?php esc_html_e( 'Public Visibility', 'memberistic' ); ?></label>
						<select id="visibility" name="visibility">
							<?php
							$vis_labels = array(
								'private'          => __( 'Private (hidden from site)', 'memberistic' ),
								'call_for_details' => __( 'Call for Details (CTA only)', 'memberistic' ),
								'public'           => __( 'Public (listed)', 'memberistic' ),
							);
							foreach ( Corporate_Groups_Repository::visibilities() as $s ) : ?>
								<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $val( 'visibility', 'private' ), $s ); ?>><?php echo esc_html( $vis_labels[ $s ] ?? $s ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
				</div>

				<div class="memberistic-corp-field" style="margin-top:14px;">
					<label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;font-weight:600;">
						<input type="checkbox" name="owner_portal" value="1" <?php checked( (int) $val( 'owner_portal', 0 ), 1 ); ?> style="margin-top:3px;">
						<span><?php esc_html_e( 'Allow the group owner to manage this group from their account (view members, seats, payment, and invite people).', 'memberistic' ); ?><br>
						<span style="font-weight:400;color:#646970;font-size:12px;"><?php esc_html_e( 'When off, the owner only sees their own membership — no group management front-end.', 'memberistic' ); ?></span></span>
					</label>
				</div>

				<?php if ( ! $is_edit ) : ?>
				<hr>
				<p><strong><?php esc_html_e( 'Record initial payment (optional)', 'memberistic' ); ?></strong> — <?php esc_html_e( 'e.g. the in-store POS/cash sale.', 'memberistic' ); ?></p>
				<div class="memberistic-corp-grid">
					<div class="memberistic-corp-field">
						<label for="initial_payment"><?php esc_html_e( 'Amount Paid ($)', 'memberistic' ); ?></label>
						<input type="number" step="0.01" min="0" id="initial_payment" name="initial_payment" value="600.00">
					</div>
					<div class="memberistic-corp-field">
						<label for="payment_reference"><?php esc_html_e( 'POS Receipt / Reference', 'memberistic' ); ?></label>
						<input type="text" id="payment_reference" name="payment_reference" placeholder="<?php esc_attr_e( 'e.g. POS-2026-00481', 'memberistic' ); ?>">
					</div>
				</div>
				<?php endif; ?>

				<div class="memberistic-corp-field" style="margin-top:14px;">
					<label for="admin_notes"><?php esc_html_e( 'Internal Admin Notes', 'memberistic' ); ?></label>
					<textarea id="admin_notes" name="admin_notes" rows="3"><?php echo esc_textarea( $val( 'admin_notes' ) ); ?></textarea>
				</div>

				<p style="margin-top:18px;">
					<button type="submit" class="button button-primary"><?php echo $is_edit ? esc_html__( 'Save Changes', 'memberistic' ) : esc_html__( 'Create Group', 'memberistic' ); ?></button>
				</p>
			</form>
		<?php if ( $wrap ) : ?>
		</div>
		<?php endif; ?>
		<?php
	}

	private static function render_single() {
		$id    = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$group = Corporate_Groups_Repository::get( $id );
		if ( ! $group ) {
			echo '<div class="wrap"><p>' . esc_html__( 'Group not found.', 'memberistic' ) . '</p></div>';
			return;
		}
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$base = admin_url( 'admin.php?page=' . self::PAGE . '&view=single&id=' . (int) $id );
		$tabs = array(
			'overview' => __( 'Overview', 'memberistic' ),
			'members'  => __( 'Members', 'memberistic' ),
			'payments' => __( 'Payments', 'memberistic' ),
			'waivers'  => __( 'Waivers', 'memberistic' ),
			'settings' => __( 'Settings', 'memberistic' ),
		);
		echo '<div class="wrap memberistic-corp-wrap">';
		echo '<h1>' . esc_html( $group->group_name ?: ( '#' . $id ) ) . '</h1>';
		echo '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ) . '">&larr; ' . esc_html__( 'All groups', 'memberistic' ) . '</a>';
		echo '<h2 class="nav-tab-wrapper" style="margin-top:14px;">';
		foreach ( $tabs as $key => $label ) {
			$cls = 'nav-tab' . ( $tab === $key ? ' nav-tab-active' : '' );
			echo '<a class="' . esc_attr( $cls ) . '" href="' . esc_url( add_query_arg( 'tab', $key, $base ) ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</h2>';

		if ( 'members' === $tab ) {
			self::render_members_tab( $group );
		} elseif ( 'payments' === $tab ) {
			self::render_payments_tab( $group );
		} elseif ( 'waivers' === $tab ) {
			self::render_waivers_tab( $group );
		} elseif ( 'settings' === $tab ) {
			echo '<div style="margin-top:18px;"></div>';
			self::render_form( $group, false );
			self::render_danger_zone( $group );
		} else {
			self::render_overview_tab( $group );
		}
		echo '</div>';
	}

	private static function render_danger_zone( $group ) {
		$delete_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=memberistic_corp_delete&group_id=' . (int) $group->id ),
			'memberistic_corp_delete_' . (int) $group->id
		);
		?>
		<div class="memberistic-corp-card" style="border-color:#e6b4b4;background:#fdf5f5;margin-top:24px;">
			<h3 style="color:#b32d2e;margin-top:0;"><?php esc_html_e( 'Danger zone', 'memberistic' ); ?></h3>
			<p style="color:#646970;"><?php esc_html_e( 'Deleting removes this group and its payment records. Members KEEP their individual accounts, memberships, waivers, and QR codes — they simply stop being part of this group.', 'memberistic' ); ?></p>
			<a href="<?php echo esc_url( $delete_url ); ?>" class="button" style="color:#b32d2e;border-color:#b32d2e;" onclick="return confirm('<?php echo esc_js( __( 'Delete this group? This cannot be undone. Members keep their own accounts and memberships.', 'memberistic' ) ); ?>');"><?php esc_html_e( 'Delete this group', 'memberistic' ); ?></a>
		</div>
		<?php
	}

	private static function render_payments_tab( $group ) {
		$paid     = Corporate_Groups_Repository::paid_total( $group->id );
		$price    = (float) $group->custom_price;
		$balance  = max( 0, $price - $paid );
		$links    = Corporate_Payment_Links_Repository::for_group( $group->id );
		$stripe   = Corporate_Payment_Service::stripe_active();
		$wc       = Corporate_Payment_Service::wc_active();
		$can_charge = $stripe || $wc;
		?>
		<?php if ( isset( $_GET['p_msg'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<div class="notice notice-success is-dismissible"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['p_msg'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?></p></div>
		<?php endif; ?>

		<div class="memberistic-corp-grid" style="margin-top:18px;">
			<div class="memberistic-corp-card"><div class="description" style="text-transform:uppercase;letter-spacing:.08em;font-size:11px;"><?php esc_html_e( 'Amount paid', 'memberistic' ); ?></div><div style="font-size:24px;font-weight:700;margin-top:6px;">$<?php echo esc_html( number_format( $paid, 2 ) ); ?></div></div>
			<div class="memberistic-corp-card"><div class="description" style="text-transform:uppercase;letter-spacing:.08em;font-size:11px;"><?php esc_html_e( 'Group price', 'memberistic' ); ?></div><div style="font-size:24px;font-weight:700;margin-top:6px;">$<?php echo esc_html( number_format( $price, 2 ) ); ?></div></div>
			<div class="memberistic-corp-card"><div class="description" style="text-transform:uppercase;letter-spacing:.08em;font-size:11px;"><?php esc_html_e( 'Balance due', 'memberistic' ); ?></div><div style="font-size:24px;font-weight:700;margin-top:6px;color:<?php echo $balance > 0 ? '#b5611f' : '#5a8a2c'; ?>;">$<?php echo esc_html( number_format( $balance, 2 ) ); ?></div></div>
		</div>

		<h3 style="margin-top:24px;"><?php esc_html_e( 'Payment links', 'memberistic' ); ?></h3>
		<?php if ( $stripe ) : ?>
			<p class="description"><?php esc_html_e( 'Links open an invoice page branded with your business name; "Pay Now" takes the customer to secure Stripe checkout. Paid links auto-record on this group.', 'memberistic' ); ?></p>
		<?php elseif ( $wc ) : ?>
			<p class="description"><?php esc_html_e( 'Stripe is not enabled, so links use the WooCommerce pay page as a fallback after the branded invoice. Enable Stripe in Memberistic → Settings for the cleanest flow.', 'memberistic' ); ?></p>
		<?php else : ?>
			<p class="description"><?php esc_html_e( 'No payment gateway is enabled, so links show a branded invoice with a "call to pay" message. Enable Stripe in Memberistic → Settings to accept online card payments, or record payments manually below.', 'memberistic' ); ?></p>
		<?php endif; ?>
		<table class="wp-list-table widefat fixed striped">
			<thead><tr>
				<th><?php esc_html_e( 'Amount', 'memberistic' ); ?></th>
				<th><?php esc_html_e( 'Description', 'memberistic' ); ?></th>
				<th><?php esc_html_e( 'Recipient', 'memberistic' ); ?></th>
				<th><?php esc_html_e( 'Status', 'memberistic' ); ?></th>
				<th><?php esc_html_e( 'Link', 'memberistic' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'memberistic' ); ?></th>
			</tr></thead>
			<tbody>
				<?php if ( empty( $links ) ) : ?>
					<tr><td colspan="6"><?php esc_html_e( 'No payment links yet.', 'memberistic' ); ?></td></tr>
				<?php else : foreach ( $links as $l ) :
					$url = Corporate_Payment_Service::public_url( $l->token );
					$resend = wp_nonce_url( admin_url( 'admin-post.php?action=memberistic_corp_resend_link&link_id=' . (int) $l->id ), 'memberistic_corp_resend_link_' . (int) $l->id );
					$markpaid = wp_nonce_url( admin_url( 'admin-post.php?action=memberistic_corp_mark_link_paid&link_id=' . (int) $l->id ), 'memberistic_corp_mark_link_paid_' . (int) $l->id );
					$void = wp_nonce_url( admin_url( 'admin-post.php?action=memberistic_corp_void_link&link_id=' . (int) $l->id ), 'memberistic_corp_void_link_' . (int) $l->id );
				?>
					<tr>
						<td><strong>$<?php echo esc_html( number_format( (float) $l->amount, 2 ) ); ?></strong></td>
						<td><?php echo esc_html( $l->description ?: '—' ); ?></td>
						<td><?php echo esc_html( $l->recipient_email ?: '—' ); ?></td>
						<td><span class="memberistic-corp-badge <?php echo 'paid' === $l->status ? 'paid' : ( 'void' === $l->status ? 'cancelled' : 'unpaid' ); ?>"><?php echo esc_html( ucfirst( $l->status ) ); ?></span></td>
						<td><input type="text" readonly onclick="this.select();" value="<?php echo esc_attr( $url ); ?>" style="width:100%;font-size:11px;"></td>
						<td>
							<?php if ( 'paid' !== $l->status && 'void' !== $l->status ) : ?>
								<?php if ( $l->recipient_email ) : ?><a class="button button-small" href="<?php echo esc_url( $resend ); ?>"><?php esc_html_e( 'Email', 'memberistic' ); ?></a><?php endif; ?>
								<a class="button button-small" href="<?php echo esc_url( $markpaid ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Mark this link as paid? This records a payment on the group.', 'memberistic' ) ); ?>');"><?php esc_html_e( 'Mark paid', 'memberistic' ); ?></a>
								<a class="button button-small" href="<?php echo esc_url( $void ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Void this link?', 'memberistic' ) ); ?>');"><?php esc_html_e( 'Void', 'memberistic' ); ?></a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; endif; ?>
			</tbody>
		</table>

		<div class="memberistic-corp-grid" style="margin-top:20px;">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="memberistic-corp-card">
				<input type="hidden" name="action" value="memberistic_corp_create_link">
				<input type="hidden" name="group_id" value="<?php echo (int) $group->id; ?>">
				<?php wp_nonce_field( 'memberistic_corp_create_link_' . (int) $group->id ); ?>
				<h3><?php esc_html_e( 'Generate payment link', 'memberistic' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Enter ANY amount — fully custom per link. Send it to the customer to pay online.', 'memberistic' ); ?></p>
				<p class="memberistic-corp-field"><label><?php esc_html_e( 'Amount ($)', 'memberistic' ); ?></label><input type="number" step="0.01" min="0.01" name="amount" required value="<?php echo esc_attr( $balance > 0 ? number_format( $balance, 2, '.', '' ) : '600.00' ); ?>"></p>
				<?php /* translators: %s: current group description. */ ?>
				<p class="memberistic-corp-field"><label><?php esc_html_e( 'Description', 'memberistic' ); ?></label><input type="text" name="description" value="<?php echo esc_attr( sprintf( __( '%s group membership', 'memberistic' ), $group->group_name ) ); ?>"></p>
				<p class="memberistic-corp-field"><label><?php esc_html_e( 'Send to email (optional)', 'memberistic' ); ?></label><input type="email" name="recipient_email"></p>
				<p class="memberistic-corp-field"><label><?php esc_html_e( 'Expires in (days, 0 = never)', 'memberistic' ); ?></label><input type="number" min="0" name="expiry_days" value="14"></p>
				<p><button class="button button-primary"><?php esc_html_e( 'Create Link', 'memberistic' ); ?></button></p>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="memberistic-corp-card">
				<input type="hidden" name="action" value="memberistic_corp_record_payment">
				<input type="hidden" name="group_id" value="<?php echo (int) $group->id; ?>">
				<?php wp_nonce_field( 'memberistic_corp_record_payment_' . (int) $group->id ); ?>
				<h3><?php esc_html_e( 'Record a payment', 'memberistic' ); ?></h3>
				<p class="description"><?php esc_html_e( 'For cash / POS / card taken in store, or a deposit.', 'memberistic' ); ?></p>
				<p class="memberistic-corp-field"><label><?php esc_html_e( 'Amount ($)', 'memberistic' ); ?></label><input type="number" step="0.01" min="0.01" name="amount" required></p>
				<p class="memberistic-corp-field"><label><?php esc_html_e( 'Method', 'memberistic' ); ?></label>
					<select name="payment_method">
						<?php foreach ( Corporate_Groups_Repository::payment_methods() as $m ) : ?>
							<option value="<?php echo esc_attr( $m ); ?>"><?php echo esc_html( ucwords( str_replace( '_', ' ', $m ) ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<p class="memberistic-corp-field"><label><?php esc_html_e( 'Reference (POS receipt #, etc.)', 'memberistic' ); ?></label><input type="text" name="payment_reference"></p>
				<p class="memberistic-corp-field"><label><?php esc_html_e( 'Notes', 'memberistic' ); ?></label><input type="text" name="notes"></p>
				<p><button class="button button-primary"><?php esc_html_e( 'Record Payment', 'memberistic' ); ?></button></p>
			</form>
		</div>
		<?php
	}

	private static function render_overview_tab( $group ) {
		$used  = Corporate_Members_Repository::active_count( $group->id );
		$paid  = Corporate_Groups_Repository::paid_total( $group->id );
		$payer = $group->primary_user_id ? get_userdata( $group->primary_user_id ) : null;
		$cards = array(
			array( __( 'Seats used', 'memberistic' ), $used . ' / ' . (int) $group->seats_total ),
			array( __( 'Max future seats', 'memberistic' ), (int) $group->max_future_seats ),
			array( __( 'Amount paid', 'memberistic' ), '$' . number_format( $paid, 2 ) ),
			array( __( 'Custom price', 'memberistic' ), '$' . number_format( (float) $group->custom_price, 2 ) ),
			array( __( 'Payment status', 'memberistic' ), ucfirst( $group->payment_status ) ),
			array( __( 'Group status', 'memberistic' ), ucfirst( $group->status ) ),
		);
		echo '<div class="memberistic-corp-grid" style="margin-top:18px;">';
		foreach ( $cards as $c ) {
			echo '<div class="memberistic-corp-card"><div class="description" style="text-transform:uppercase;letter-spacing:.08em;font-size:11px;">' . esc_html( $c[0] ) . '</div><div style="font-size:24px;font-weight:700;margin-top:6px;">' . esc_html( $c[1] ) . '</div></div>';
		}
		echo '</div>';
		echo '<p style="margin-top:12px;">' . esc_html__( 'Primary payer:', 'memberistic' ) . ' <strong>' . ( $payer ? esc_html( $payer->display_name . ' (' . $payer->user_email . ')' ) : '&mdash;' ) . '</strong></p>';
		if ( $group->admin_notes ) {
			echo '<div class="memberistic-corp-card"><strong>' . esc_html__( 'Internal notes', 'memberistic' ) . '</strong><p>' . nl2br( esc_html( $group->admin_notes ) ) . '</p></div>';
		}
	}

	private static function render_waivers_tab( $group ) {
		$members = Corporate_Members_Repository::for_group( $group->id );
		$signed = 0;
		foreach ( $members as $m ) {
			if ( 'signed' === $m->waiver_status ) {
				$signed++;
			}
		}
		$total = count( $members );
		$pct   = $total ? round( ( $signed / $total ) * 100 ) : 0;
		?>
		<?php if ( isset( $_GET['w_msg'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<div class="notice notice-success is-dismissible"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['w_msg'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?></p></div>
		<?php endif; ?>
		<p style="margin-top:18px;"><strong><?php echo esc_html( $signed . ' / ' . $total ); ?></strong> <?php esc_html_e( 'waivers signed', 'memberistic' ); ?> (<?php echo esc_html( $pct ); ?>%).</p>
		<table class="wp-list-table widefat fixed striped" style="margin-top:10px;">
			<thead><tr>
				<th><?php esc_html_e( 'Member', 'memberistic' ); ?></th>
				<th><?php esc_html_e( 'Email', 'memberistic' ); ?></th>
				<th><?php esc_html_e( 'Waiver status', 'memberistic' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'memberistic' ); ?></th>
			</tr></thead>
			<tbody>
				<?php if ( empty( $members ) ) : ?>
					<tr><td colspan="4"><?php esc_html_e( 'No members yet.', 'memberistic' ); ?></td></tr>
				<?php else : foreach ( $members as $m ) :
					$u      = $m->user_id ? get_userdata( $m->user_id ) : null;
					$signed_ok = ( 'signed' === $m->waiver_status );
					$resend = wp_nonce_url( admin_url( 'admin-post.php?action=memberistic_corp_resend_waiver&member_id=' . (int) $m->id ), 'memberistic_corp_resend_waiver_' . (int) $m->id );
					$openurl = $m->user_id ? Corporate_Waiver_Service::waiver_url( (int) $m->user_id ) : '';
					$except = wp_nonce_url( admin_url( 'admin-post.php?action=memberistic_corp_waiver_exception&member_id=' . (int) $m->id ), 'memberistic_corp_waiver_exception_' . (int) $m->id );
				?>
					<tr>
						<td><strong><?php echo $u ? esc_html( $u->display_name ) : '&mdash;'; ?></strong></td>
						<td><?php echo $u ? esc_html( $u->user_email ) : '&mdash;'; ?></td>
						<td><span class="memberistic-corp-badge <?php echo $signed_ok ? 'paid' : 'unpaid'; ?>"><?php echo esc_html( ucfirst( $m->waiver_status ?: 'missing' ) ); ?></span></td>
						<td>
							<?php if ( ! $signed_ok ) : ?>
								<?php if ( $u ) : ?><a class="button button-small" href="<?php echo esc_url( $resend ); ?>"><?php esc_html_e( 'Resend waiver', 'memberistic' ); ?></a><?php endif; ?>
								<?php if ( $openurl ) : ?><a class="button button-small" href="<?php echo esc_url( $openurl ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open signing link', 'memberistic' ); ?></a><?php endif; ?>
								<a class="button button-small" href="<?php echo esc_url( $except ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Mark this waiver complete as a manual staff exception?', 'memberistic' ) ); ?>');"><?php esc_html_e( 'Mark complete (exception)', 'memberistic' ); ?></a>
							<?php else : ?>
								&mdash;
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; endif; ?>
			</tbody>
		</table>
		<p class="description" style="margin-top:10px;"><?php esc_html_e( 'Members receive a waiver link in their welcome email. They can sign from any device with no login required. Signed waivers are valid for one year.', 'memberistic' ); ?></p>
		<?php
	}

	private static function render_members_tab( $group ) {
		$members  = Corporate_Members_Repository::for_group( $group->id );
		$used     = count( $members );
		$seats    = (int) $group->seats_total;
		$full     = $used >= $seats;
		$notice   = isset( $_GET['m_added'] ) ? (int) $_GET['m_added'] : -1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$skipped  = isset( $_GET['m_skipped'] ) ? (int) $_GET['m_skipped'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<?php if ( $notice >= 0 ) : ?>
			<?php /* translators: 1: number of members added. 2: number of members skipped. */ ?>
			<div class="notice notice-success is-dismissible"><p><?php printf( esc_html__( '%1$d member(s) added. %2$d skipped.', 'memberistic' ), (int) $notice, (int) $skipped ); ?></p></div>
		<?php endif; ?>

		<p style="margin-top:16px;"><strong><?php echo esc_html( $used ); ?></strong> / <?php echo esc_html( $seats ); ?> <?php esc_html_e( 'seats used.', 'memberistic' ); ?>
			<?php if ( $full ) : ?><span class="memberistic-corp-badge pending"><?php esc_html_e( 'Seats full — increase seat count in Settings to add more.', 'memberistic' ); ?></span><?php endif; ?>
		</p>

		<table class="wp-list-table widefat fixed striped" style="margin-top:10px;">
			<thead><tr>
				<th><?php esc_html_e( 'Member', 'memberistic' ); ?></th>
				<th><?php esc_html_e( 'Email', 'memberistic' ); ?></th>
				<th><?php esc_html_e( 'Waiver', 'memberistic' ); ?></th>
				<th><?php esc_html_e( 'QR', 'memberistic' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'memberistic' ); ?></th>
			</tr></thead>
			<tbody>
				<?php if ( empty( $members ) ) : ?>
					<tr><td colspan="5"><?php esc_html_e( 'No members yet. Add them below.', 'memberistic' ); ?></td></tr>
				<?php else : foreach ( $members as $m ) :
					$u = $m->user_id ? get_userdata( $m->user_id ) : null;
					$resend_url = wp_nonce_url( admin_url( 'admin-post.php?action=memberistic_corp_resend&member_id=' . (int) $m->id ), 'memberistic_corp_resend_' . (int) $m->id );
					$remove_url = wp_nonce_url( admin_url( 'admin-post.php?action=memberistic_corp_remove_member&member_id=' . (int) $m->id ), 'memberistic_corp_remove_' . (int) $m->id );
				?>
					<tr>
						<td><strong><?php echo $u ? esc_html( $u->display_name ) : '&mdash;'; ?></strong></td>
						<td><?php echo $u ? esc_html( $u->user_email ) : '&mdash;'; ?></td>
						<td><span class="memberistic-corp-badge <?php echo 'signed' === $m->waiver_status ? 'paid' : 'unpaid'; ?>"><?php echo esc_html( ucfirst( $m->waiver_status ?: 'missing' ) ); ?></span></td>
						<td><?php echo $m->qr_token ? '<span class="memberistic-corp-badge active">' . esc_html__( 'Ready', 'memberistic' ) . '</span>' : '&mdash;'; ?></td>
						<td>
							<a href="<?php echo esc_url( $resend_url ); ?>" class="button button-small"><?php esc_html_e( 'Resend email', 'memberistic' ); ?></a>
							<a href="<?php echo esc_url( $remove_url ); ?>" class="button button-small" onclick="return confirm('<?php echo esc_js( __( 'Remove this member from the group?', 'memberistic' ) ); ?>');"><?php esc_html_e( 'Remove', 'memberistic' ); ?></a>
						</td>
					</tr>
				<?php endforeach; endif; ?>
			</tbody>
		</table>

		<div class="memberistic-corp-grid" style="margin-top:20px;">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="memberistic-corp-card">
				<input type="hidden" name="action" value="memberistic_corp_add_member">
				<input type="hidden" name="group_id" value="<?php echo (int) $group->id; ?>">
				<?php wp_nonce_field( 'memberistic_corp_add_member_' . (int) $group->id ); ?>
				<h3><?php esc_html_e( 'Add one member', 'memberistic' ); ?></h3>
				<p class="memberistic-corp-field"><label><?php esc_html_e( 'Full name', 'memberistic' ); ?></label><input type="text" name="member_name" required></p>
				<p class="memberistic-corp-field"><label><?php esc_html_e( 'Email', 'memberistic' ); ?></label><input type="email" name="member_email" required></p>
				<p class="memberistic-corp-field"><label><?php esc_html_e( 'Phone (optional)', 'memberistic' ); ?></label><input type="text" name="member_phone"></p>
				<p><button class="button button-primary" <?php disabled( $full ); ?>><?php esc_html_e( 'Add + Send Welcome Email', 'memberistic' ); ?></button></p>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="memberistic-corp-card">
				<input type="hidden" name="action" value="memberistic_corp_bulk_members">
				<input type="hidden" name="group_id" value="<?php echo (int) $group->id; ?>">
				<?php wp_nonce_field( 'memberistic_corp_bulk_members_' . (int) $group->id ); ?>
				<h3><?php esc_html_e( 'Bulk add', 'memberistic' ); ?></h3>
				<p class="description"><?php esc_html_e( 'One per line: Full Name, email@example.com, phone (phone optional).', 'memberistic' ); ?></p>
				<textarea name="bulk" rows="6" style="width:100%;" placeholder="John Doe, john@example.com, 6025551212&#10;Jane Roe, jane@example.com"></textarea>
				<p><button class="button button-primary" <?php disabled( $full ); ?>><?php esc_html_e( 'Import + Send Welcome Emails', 'memberistic' ); ?></button></p>
			</form>
		</div>
		<?php
	}

	public static function handle_create() {
		if ( ! current_user_can( Corporate_Capabilities::manage_cap() ) ) {
			wp_die( esc_html__( 'Permission denied.', 'memberistic' ) );
		}
		check_admin_referer( 'memberistic_corp_create' );
		$id = Corporate_Groups_Repository::create( wp_unslash( $_POST ) );
		if ( ! $id ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . '&view=new&error=1' ) );
			exit;
		}
		// "Create group from selected members" — attach the existing
		// memberships handed over from the Members screen bulk action.
		if ( ! empty( $_POST['from_members'] ) ) {
			$ids = array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_POST['from_members'] ) ) ) ) );
			if ( $ids ) {
				// send_email = true so members who get a brand-new WP
				// account from this step receive their set-password +
				// QR + waiver welcome (respects the email kill-switch).
				$res = Corporate_Member_Service::attach_many( $id, $ids, true );
				$args = array(
					'page'      => self::PAGE,
					'view'      => 'single',
					'id'        => (int) $id,
					'tab'       => 'members',
					'm_added'   => (int) $res['added'],
					'm_skipped' => (int) $res['skipped'],
				);
				wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
				exit;
			}
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . '&created=1' ) );
		exit;
	}

	public static function handle_update() {
		if ( ! current_user_can( Corporate_Capabilities::manage_cap() ) ) {
			wp_die( esc_html__( 'Permission denied.', 'memberistic' ) );
		}
		$id = isset( $_POST['group_id'] ) ? (int) $_POST['group_id'] : 0;
		check_admin_referer( 'memberistic_corp_update_' . $id );
		Corporate_Groups_Repository::update( $id, wp_unslash( $_POST ) );
		// Recount seats in case seats_total changed.
		Corporate_Member_Service::recount_seats( $id );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . '&view=single&id=' . $id . '&tab=settings&updated=1' ) );
		exit;
	}

	public static function handle_delete() {
		if ( ! current_user_can( Corporate_Capabilities::manage_cap() ) ) {
			wp_die( esc_html__( 'Permission denied.', 'memberistic' ) );
		}
		$id = isset( $_REQUEST['group_id'] ) ? (int) $_REQUEST['group_id'] : 0;
		check_admin_referer( 'memberistic_corp_delete_' . $id );
		if ( $id > 0 ) {
			Corporate_Groups_Repository::delete( $id );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . '&deleted=1' ) );
		exit;
	}

	private static function single_redirect( $group_id, $tab = 'members', $extra = array() ) {
		$args = array_merge( array(
			'page' => self::PAGE,
			'view' => 'single',
			'id'   => (int) $group_id,
			'tab'  => $tab,
		), $extra );
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function handle_add_member() {
		if ( ! current_user_can( Corporate_Capabilities::manage_cap() ) ) {
			wp_die( esc_html__( 'Permission denied.', 'memberistic' ) );
		}
		$gid = isset( $_POST['group_id'] ) ? (int) $_POST['group_id'] : 0;
		check_admin_referer( 'memberistic_corp_add_member_' . $gid );
		$res = Corporate_Member_Service::add_member(
			$gid,
			isset( $_POST['member_name'] ) ? wp_unslash( $_POST['member_name'] ) : '',
			isset( $_POST['member_email'] ) ? wp_unslash( $_POST['member_email'] ) : '',
			isset( $_POST['member_phone'] ) ? wp_unslash( $_POST['member_phone'] ) : ''
		);
		$added = ( 'added' === $res['status'] ) ? 1 : 0;
		self::single_redirect( $gid, 'members', array( 'm_added' => $added, 'm_skipped' => $added ? 0 : 1 ) );
	}

	public static function handle_bulk_members() {
		if ( ! current_user_can( Corporate_Capabilities::manage_cap() ) ) {
			wp_die( esc_html__( 'Permission denied.', 'memberistic' ) );
		}
		$gid = isset( $_POST['group_id'] ) ? (int) $_POST['group_id'] : 0;
		check_admin_referer( 'memberistic_corp_bulk_members_' . $gid );
		$out = Corporate_Member_Service::bulk_add( $gid, isset( $_POST['bulk'] ) ? wp_unslash( $_POST['bulk'] ) : '' );
		self::single_redirect( $gid, 'members', array( 'm_added' => (int) $out['added'], 'm_skipped' => (int) $out['skipped'] ) );
	}

	public static function handle_remove_member() {
		if ( ! current_user_can( Corporate_Capabilities::manage_cap() ) ) {
			wp_die( esc_html__( 'Permission denied.', 'memberistic' ) );
		}
		$mid = isset( $_GET['member_id'] ) ? (int) $_GET['member_id'] : 0;
		check_admin_referer( 'memberistic_corp_remove_' . $mid );
		$member = Corporate_Members_Repository::get( $mid );
		if ( $member ) {
			Corporate_Members_Repository::mark_removed( $mid );
			Corporate_Member_Service::recount_seats( (int) $member->group_id );
			Corporate_Groups_Repository::log_activity( (int) $member->group_id, 'member_removed', __( 'Member removed from group.', 'memberistic' ) );
			self::single_redirect( (int) $member->group_id, 'members' );
		}
		self::single_redirect( 0, 'members' );
	}

	public static function handle_resend() {
		if ( ! current_user_can( Corporate_Capabilities::manage_cap() ) ) {
			wp_die( esc_html__( 'Permission denied.', 'memberistic' ) );
		}
		$mid = isset( $_GET['member_id'] ) ? (int) $_GET['member_id'] : 0;
		check_admin_referer( 'memberistic_corp_resend_' . $mid );
		$member = Corporate_Members_Repository::get( $mid );
		if ( $member && $member->membership_id ) {
			$group = Corporate_Groups_Repository::get( (int) $member->group_id );
			$user  = get_userdata( (int) $member->user_id );
			if ( $group && $user ) {
				// Resend: existing user, so no set-password link (they
				// can use Forgot Password). Pass is_new_user=false.
				Corporate_Member_Service::send_welcome( (int) $member->membership_id, $group, $user, false );
			}
			self::single_redirect( (int) $member->group_id, 'members' );
		}
		self::single_redirect( 0, 'members' );
	}

	/* ---- Phase 3 payment handlers ---- */

	public static function handle_record_payment() {
		if ( ! current_user_can( Corporate_Capabilities::manage_cap() ) ) {
			wp_die( esc_html__( 'Permission denied.', 'memberistic' ) );
		}
		$gid = isset( $_POST['group_id'] ) ? (int) $_POST['group_id'] : 0;
		check_admin_referer( 'memberistic_corp_record_payment_' . $gid );
		$res = Corporate_Payment_Service::record_manual_payment(
			$gid,
			isset( $_POST['amount'] ) ? (float) $_POST['amount'] : 0,
			isset( $_POST['payment_method'] ) ? sanitize_key( wp_unslash( $_POST['payment_method'] ) ) : 'cash',
			isset( $_POST['payment_reference'] ) ? sanitize_text_field( wp_unslash( $_POST['payment_reference'] ) ) : '',
			isset( $_POST['notes'] ) ? sanitize_text_field( wp_unslash( $_POST['notes'] ) ) : ''
		);
		self::single_redirect( $gid, 'payments', array( 'p_msg' => rawurlencode( $res['message'] ) ) );
	}

	public static function handle_create_link() {
		if ( ! current_user_can( Corporate_Capabilities::manage_cap() ) ) {
			wp_die( esc_html__( 'Permission denied.', 'memberistic' ) );
		}
		$gid = isset( $_POST['group_id'] ) ? (int) $_POST['group_id'] : 0;
		check_admin_referer( 'memberistic_corp_create_link_' . $gid );
		$res = Corporate_Payment_Service::create_payment_link(
			$gid,
			isset( $_POST['amount'] ) ? (float) $_POST['amount'] : 0,
			isset( $_POST['description'] ) ? sanitize_text_field( wp_unslash( $_POST['description'] ) ) : '',
			isset( $_POST['recipient_email'] ) ? sanitize_email( wp_unslash( $_POST['recipient_email'] ) ) : '',
			isset( $_POST['expiry_days'] ) ? (int) $_POST['expiry_days'] : 14
		);
		self::single_redirect( $gid, 'payments', array( 'p_msg' => rawurlencode( $res['message'] ) ) );
	}

	public static function handle_resend_link() {
		if ( ! current_user_can( Corporate_Capabilities::manage_cap() ) ) {
			wp_die( esc_html__( 'Permission denied.', 'memberistic' ) );
		}
		$lid = isset( $_GET['link_id'] ) ? (int) $_GET['link_id'] : 0;
		check_admin_referer( 'memberistic_corp_resend_link_' . $lid );
		$link = Corporate_Payment_Links_Repository::get( $lid );
		if ( $link ) {
			Corporate_Payment_Service::send_link_email( $lid );
			self::single_redirect( (int) $link->group_id, 'payments', array( 'p_msg' => rawurlencode( __( 'Payment link emailed.', 'memberistic' ) ) ) );
		}
		self::single_redirect( 0, 'payments' );
	}

	public static function handle_mark_link_paid() {
		if ( ! current_user_can( Corporate_Capabilities::manage_cap() ) ) {
			wp_die( esc_html__( 'Permission denied.', 'memberistic' ) );
		}
		$lid = isset( $_GET['link_id'] ) ? (int) $_GET['link_id'] : 0;
		check_admin_referer( 'memberistic_corp_mark_link_paid_' . $lid );
		$link = Corporate_Payment_Links_Repository::get( $lid );
		if ( $link ) {
			Corporate_Payment_Service::mark_paid( $lid, 'manual_comp', __( 'Marked paid by staff', 'memberistic' ) );
			self::single_redirect( (int) $link->group_id, 'payments', array( 'p_msg' => rawurlencode( __( 'Link marked paid.', 'memberistic' ) ) ) );
		}
		self::single_redirect( 0, 'payments' );
	}

	public static function handle_void_link() {
		if ( ! current_user_can( Corporate_Capabilities::manage_cap() ) ) {
			wp_die( esc_html__( 'Permission denied.', 'memberistic' ) );
		}
		$lid = isset( $_GET['link_id'] ) ? (int) $_GET['link_id'] : 0;
		check_admin_referer( 'memberistic_corp_void_link_' . $lid );
		$link = Corporate_Payment_Links_Repository::get( $lid );
		if ( $link ) {
			Corporate_Payment_Service::void_link( $lid );
			self::single_redirect( (int) $link->group_id, 'payments', array( 'p_msg' => rawurlencode( __( 'Link voided.', 'memberistic' ) ) ) );
		}
		self::single_redirect( 0, 'payments' );
	}

	/* ---- Phase 4 waiver handlers ---- */

	public static function handle_resend_waiver() {
		if ( ! current_user_can( Corporate_Capabilities::manage_cap() ) ) {
			wp_die( esc_html__( 'Permission denied.', 'memberistic' ) );
		}
		$mid = isset( $_GET['member_id'] ) ? (int) $_GET['member_id'] : 0;
		check_admin_referer( 'memberistic_corp_resend_waiver_' . $mid );
		$member = Corporate_Members_Repository::get( $mid );
		if ( $member && $member->user_id && $member->membership_id ) {
			$user = get_userdata( (int) $member->user_id );
			if ( $user ) {
				Corporate_Waiver_Service::send_waiver_email( (int) $member->membership_id, $user );
			}
			self::single_redirect( (int) $member->group_id, 'waivers', array( 'w_msg' => rawurlencode( __( 'Waiver email sent.', 'memberistic' ) ) ) );
		}
		self::single_redirect( 0, 'waivers' );
	}

	public static function handle_waiver_exception() {
		if ( ! current_user_can( Corporate_Capabilities::manage_cap() ) ) {
			wp_die( esc_html__( 'Permission denied.', 'memberistic' ) );
		}
		$mid = isset( $_GET['member_id'] ) ? (int) $_GET['member_id'] : 0;
		check_admin_referer( 'memberistic_corp_waiver_exception_' . $mid );
		$member = Corporate_Members_Repository::get( $mid );
		if ( $member && $member->user_id ) {
			Corporate_Waiver_Service::mark_signed( (int) $member->user_id, __( 'Staff manual exception', 'memberistic' ) );
			self::single_redirect( (int) $member->group_id, 'waivers', array( 'w_msg' => rawurlencode( __( 'Waiver marked complete (staff exception).', 'memberistic' ) ) ) );
		}
		self::single_redirect( 0, 'waivers' );
	}
}

/**
 * Phase 2 — group member data access.
 */
final class Corporate_Members_Repository {

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'memberistic_group_members';
	}

	public static function for_group( $group_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . " WHERE group_id = %d AND status != 'removed' ORDER BY role DESC, id ASC",
			(int) $group_id
		) );
	}

	public static function get( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id ) );
	}

	public static function find( $group_id, $user_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . ' WHERE group_id = %d AND user_id = %d',
			(int) $group_id,
			(int) $user_id
		) );
	}

	public static function active_count( $group_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM ' . self::table() . " WHERE group_id = %d AND status != 'removed'",
			(int) $group_id
		) );
	}

	public static function insert( array $row ) {
		global $wpdb;
		$row['invited_at'] = current_time( 'mysql' );
		$row['joined_at']  = current_time( 'mysql' );
		return $wpdb->insert( self::table(), $row ) ? (int) $wpdb->insert_id : 0;
	}

	public static function mark_removed( $id ) {
		global $wpdb;
		return false !== $wpdb->update(
			self::table(),
			array( 'status' => 'removed', 'removed_at' => current_time( 'mysql' ) ),
			array( 'id' => (int) $id )
		);
	}

	public static function update_waiver( $id, $status ) {
		global $wpdb;
		return false !== $wpdb->update( self::table(), array( 'waiver_status' => sanitize_key( $status ) ), array( 'id' => (int) $id ) );
	}

	/** First active group-member row for a user (any group). */
	public static function first_for_user( $user_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . " WHERE user_id = %d AND status != 'removed' ORDER BY id ASC LIMIT 1",
			(int) $user_id
		) );
	}
}

/**
 * Phase 2 — orchestration: turn a name/email/phone into a real
 * member account under a group, with membership, person record,
 * QR token, and a branded confirmation email.
 *
 * Each group member is a FIRST-CLASS Memberistic membership where
 * they are their own primary person — so they keep their own
 * login, waiver, QR, and confirmation, exactly as the client
 * asked. The group is the container; the membership is the access.
 */
final class Corporate_Member_Service {

	/**
	 * Named-lock scope for add_member() so two near-simultaneous invites to
	 * the SAME group can't both pass the seat-capacity check before either
	 * one's row is inserted (the check-then-insert below spans several
	 * tables — membership, person, group-member — so it can't be wrapped in
	 * a single SQL transaction; a MySQL advisory lock serializes it instead,
	 * scoped per-group so unrelated groups' invites aren't slowed down).
	 */
	private static function acquire_seat_lock( $group_id, $timeout = 5 ) {
		global $wpdb;
		$name = 'memberistic_corp_seats_' . (int) $group_id;
		return 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, max( 0, (int) $timeout ) ) );
	}

	private static function release_seat_lock( $group_id ) {
		global $wpdb;
		$name = 'memberistic_corp_seats_' . (int) $group_id;
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
	}

	/**
	 * Add one member. Returns array{ status:string, message:string,
	 * member_id?:int, user_id?:int }.
	 *
	 * status: added | reactivated | seat_full | invalid | exists | error
	 */
	public static function add_member( $group_id, $name, $email, $phone = '', $send_email = true ) {
		$group = Corporate_Groups_Repository::get( $group_id );
		if ( ! $group ) {
			return array( 'status' => 'error', 'message' => __( 'Group not found.', 'memberistic' ) );
		}

		$name  = sanitize_text_field( (string) $name );
		$email = sanitize_email( (string) $email );
		$phone = sanitize_text_field( (string) $phone );

		if ( ! $email || ! is_email( $email ) || '' === $name ) {
			/* translators: %s: member name, or email when no name was given. */
			return array( 'status' => 'invalid', 'message' => sprintf( __( 'Skipped "%s": a valid name and email are required.', 'memberistic' ), $name ?: $email ) );
		}

		if ( ! self::acquire_seat_lock( $group_id ) ) {
			return array( 'status' => 'error', 'message' => __( 'This group is busy processing another invite — please try again in a moment.', 'memberistic' ) );
		}

		try {
			return self::add_member_locked( $group_id, $group, $name, $email, $phone, $send_email );
		} finally {
			self::release_seat_lock( $group_id );
		}
	}

	/**
	 * The actual add-member body, always called with the per-group seat
	 * lock already held by add_member().
	 */
	private static function add_member_locked( $group_id, $group, $name, $email, $phone, $send_email ) {
		// Seat guard — never exceed seats_total. Re-read seats_total fresh
		// (not from the $group snapshot the caller may have fetched slightly
		// earlier) now that we hold the lock, so a concurrent seats_total
		// change is respected too.
		$group = Corporate_Groups_Repository::get( $group_id ) ?: $group;
		$used  = Corporate_Members_Repository::active_count( $group_id );
		if ( $used >= (int) $group->seats_total ) {
			/* translators: %d: the group's seat limit. */
			return array( 'status' => 'seat_full', 'message' => sprintf( __( 'Seat limit reached (%d). Increase seats to add more.', 'memberistic' ), (int) $group->seats_total ) );
		}

		// Resolve / create the WP user.
		$user = get_user_by( 'email', $email );
		$is_new_user = false;
		if ( ! $user ) {
			$username = self::unique_username( $email );
			$user_id  = wp_insert_user( array(
				'user_login'   => $username,
				'user_email'   => $email,
				'user_pass'    => wp_generate_password( 24, true, true ),
				'display_name' => $name,
				'first_name'   => self::first_name( $name ),
				'last_name'    => self::last_name( $name ),
				'role'         => 'subscriber',
			) );
			if ( is_wp_error( $user_id ) ) {
				/* translators: 1: member email address. 2: reason the user account could not be created. */
				return array( 'status' => 'error', 'message' => sprintf( __( 'Could not create user for %1$s: %2$s', 'memberistic' ), $email, $user_id->get_error_message() ) );
			}
			$user        = get_user_by( 'id', $user_id );
			$is_new_user = true;
		}

		// Already in this group? Reactivate or report.
		$existing = Corporate_Members_Repository::find( $group_id, $user->ID );
		if ( $existing && 'removed' !== $existing->status ) {
			/* translators: %s: member email address. */
			return array( 'status' => 'exists', 'message' => sprintf( __( '%s is already in this group.', 'memberistic' ), $email ) );
		}

		// Resolve the corporate plan for the membership.
		$plan_id = self::resolve_plan_id( $group->plan_key );

		// Create the member's own Memberistic membership.
		$membership_id = \WordPressistic\Memberistic\Database\Memberships_Repository::create( array(
			'membership_uuid' => \WordPressistic\Memberistic\Database\Memberships_Repository::generate_membership_uuid(),
			'primary_user_id' => $user->ID,
			'plan_id'         => $plan_id,
			'billing_cycle'   => 'annual', // corporate groups are yearly; 'corporate' isn't a valid cycle
			'status'          => 'active',
			'start_date'      => current_time( 'mysql' ),
			'payment_source'  => 'corporate_group',
			/* translators: 1: corporate group id. 2: corporate group name. */
			'notes'           => sprintf( __( 'Member of corporate group #%1$d (%2$s).', 'memberistic' ), (int) $group_id, $group->group_name ),
			'created_by'      => get_current_user_id(),
		) );
		if ( ! $membership_id ) {
			/* translators: %s: member email address. */
			return array( 'status' => 'error', 'message' => sprintf( __( 'Could not create membership for %s.', 'memberistic' ), $email ) );
		}

		// Create their primary person record (carries waiver status).
		\WordPressistic\Memberistic\Database\People_Repository::create( array(
			'membership_id' => $membership_id,
			'wp_user_id'    => $user->ID,
			'role'          => 'primary',
			'full_name'     => $name,
			'email'         => $email,
			'phone'         => $phone,
			'waiver_status' => 'missing',
			'status'        => 'active',
		) );

		// Generate their QR verification token (reuses the existing
		// member-card QR + /?memberistic_verify=TOKEN live page).
		$qr_token = '';
		if ( class_exists( '\WordPressistic\Memberistic\Utilities\Verification' ) ) {
			$qr_token = \WordPressistic\Memberistic\Utilities\Verification::get_or_create_token( $user->ID );
		}

		// Link into the group + bump seats_used.
		$member_id = Corporate_Members_Repository::insert( array(
			'group_id'      => (int) $group_id,
			'user_id'       => (int) $user->ID,
			'membership_id' => (int) $membership_id,
			'role'          => 'member',
			'status'        => 'active',
			'waiver_status' => 'missing',
			'qr_token'      => $qr_token,
		) );
		self::recount_seats( $group_id );

		Corporate_Groups_Repository::log_activity( $group_id, 'member_added', sprintf(
			/* translators: 1: member name, 2: email */
			__( 'Member added: %1$s (%2$s).', 'memberistic' ),
			$name,
			$email
		) );

		if ( $send_email ) {
			self::send_welcome( $membership_id, $group, $user, $is_new_user );
		}

		return array(
			'status'    => 'added',
			/* translators: %s: member email address. */
			'message'   => sprintf( __( 'Added %s.', 'memberistic' ), $email ),
			'member_id' => $member_id,
			'user_id'   => $user->ID,
		);
	}

	/**
	 * Bulk add from a textarea: one member per line,
	 * "Full Name, email@x.com, phone" (phone optional).
	 *
	 * @return array{added:int,skipped:int,messages:string[]}
	 */
	public static function bulk_add( $group_id, $raw ) {
		$lines = preg_split( '/\r?\n/', (string) $raw );
		$added = 0; $skipped = 0; $messages = array();
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$parts = array_map( 'trim', explode( ',', $line ) );
			$name  = $parts[0] ?? '';
			$email = $parts[1] ?? '';
			$phone = $parts[2] ?? '';
			$res   = self::add_member( $group_id, $name, $email, $phone, true );
			if ( 'added' === $res['status'] ) {
				$added++;
			} else {
				$skipped++;
				$messages[] = $res['message'];
			}
			if ( 'seat_full' === $res['status'] ) {
				break; // no point continuing once full
			}
		}
		return array( 'added' => $added, 'skipped' => $skipped, 'messages' => $messages );
	}

	/**
	 * Attach an EXISTING membership to a group (the "Create group
	 * from selected members" bulk action). Links the member that
	 * already exists rather than creating a new membership.
	 *
	 * IMPORTANT: core Memberistic members often have NO WordPress
	 * user (the membership/person can exist without one). A group
	 * member needs an account for login + waiver + QR, so when the
	 * membership has no linked WP user we provision one from the
	 * primary person's email (find-or-create) and link it back —
	 * which also fixes "members don't generate WordPress users".
	 *
	 * Seat-guarded, dedup-safe.
	 *
	 * @return string added|seat_full|exists|invalid|no_email
	 */
	public static function attach_membership( $group_id, $membership_id, $send_email = false ) {
		$group = Corporate_Groups_Repository::get( $group_id );
		if ( ! $group ) {
			return 'invalid';
		}
		$membership = \WordPressistic\Memberistic\Database\Memberships_Repository::get( (int) $membership_id );
		if ( ! $membership ) {
			return 'invalid';
		}
		$membership = (array) $membership;
		$mid    = (int) $membership['id'];
		$person = \WordPressistic\Memberistic\Database\People_Repository::get_primary_by_membership( $mid );

		// Resolve (or provision) the WP user for this member.
		$user_id = (int) ( $membership['primary_user_id'] ?? 0 );
		$is_new_user = false;
		if ( $user_id <= 0 ) {
			$email = $person && ! empty( $person['email'] ) ? sanitize_email( $person['email'] ) : '';
			$name  = $person && ! empty( $person['full_name'] ) ? $person['full_name'] : '';
			if ( ! $email || ! is_email( $email ) ) {
				return 'no_email'; // can't make an account without an email
			}
			$user = get_user_by( 'email', $email );
			if ( ! $user ) {
				$username = self::unique_username( $email );
				$new_id   = wp_insert_user( array(
					'user_login'   => $username,
					'user_email'   => $email,
					'user_pass'    => wp_generate_password( 24, true, true ),
					'display_name' => $name ?: $username,
					'first_name'   => self::first_name( $name ),
					'last_name'    => self::last_name( $name ),
					'role'         => 'subscriber',
				) );
				if ( is_wp_error( $new_id ) ) {
					return 'invalid';
				}
				$user_id     = (int) $new_id;
				$is_new_user = true;
			} else {
				$user_id = (int) $user->ID;
			}
			// Link the new/found user back onto the membership + person
			// so the member now has a real account going forward.
			\WordPressistic\Memberistic\Database\Memberships_Repository::update( $mid, array( 'primary_user_id' => $user_id ) );
			if ( $person && ! empty( $person['id'] ) ) {
				\WordPressistic\Memberistic\Database\People_Repository::update( (int) $person['id'], array( 'wp_user_id' => $user_id ) );
			}
		}

		// Same per-group advisory lock add_member() uses — closes the same
		// TOCTOU window (two concurrent attach/invite calls for one group
		// both reading a seat count under the limit before either inserts).
		if ( ! self::acquire_seat_lock( $group_id ) ) {
			return 'error';
		}
		try {
			if ( Corporate_Members_Repository::active_count( $group_id ) >= (int) $group->seats_total ) {
				return 'seat_full';
			}
			$existing = Corporate_Members_Repository::find( $group_id, $user_id );
			if ( $existing && 'removed' !== $existing->status ) {
				return 'exists';
			}

			$waiver = ( $person && ! empty( $person['waiver_status'] ) ) ? $person['waiver_status'] : 'missing';
			$qr_token = '';
			if ( class_exists( '\WordPressistic\Memberistic\Utilities\Verification' ) ) {
				$qr_token = \WordPressistic\Memberistic\Utilities\Verification::get_or_create_token( $user_id );
			}

			Corporate_Members_Repository::insert( array(
				'group_id'      => (int) $group_id,
				'user_id'       => $user_id,
				'membership_id' => $mid,
				'role'          => 'member',
				'status'        => 'active',
				'waiver_status' => $waiver,
				'qr_token'      => $qr_token,
			) );
			self::recount_seats( $group_id );
		} finally {
			self::release_seat_lock( $group_id );
		}

		$u = get_userdata( $user_id );
		Corporate_Groups_Repository::log_activity( $group_id, 'member_attached', sprintf(
			/* translators: %s: member name */
			__( 'Existing member added to group: %s.', 'memberistic' ),
			$u ? $u->display_name : ( '#' . $user_id )
		) );

		// Email: if we just created their account, send the full
		// welcome (with set-password link); otherwise a sign-in welcome.
		if ( $send_email && $u ) {
			self::send_welcome( $mid, $group, $u, $is_new_user );
		}
		return 'added';
	}

	/**
	 * Attach many existing memberships to a group. Returns counts.
	 */
	public static function attach_many( $group_id, array $membership_ids, $send_email = false ) {
		$added = 0; $skipped = 0;
		foreach ( $membership_ids as $mid ) {
			$mid = (int) $mid;
			if ( $mid <= 0 ) { continue; }
			$r = self::attach_membership( $group_id, $mid, $send_email );
			if ( 'added' === $r ) {
				$added++;
			} else {
				$skipped++;
				if ( 'seat_full' === $r ) { break; }
			}
		}
		return array( 'added' => $added, 'skipped' => $skipped );
	}

	/**
	 * Re-sync seats_used on the group from the live member count.
	 */
	public static function recount_seats( $group_id ) {
		global $wpdb;
		$used = Corporate_Members_Repository::active_count( $group_id );
		$wpdb->update( Corporate_Groups_Repository::table(), array( 'seats_used' => $used ), array( 'id' => (int) $group_id ) );
	}

	/**
	 * Send the branded "welcome to the group" email with set-password
	 * + login + account + waiver + QR. Uses the existing Email_Service
	 * pipeline (branded headers, kill-switch, logging) via the
	 * group_member_welcome template registered in Corporate_Emails.
	 */
	public static function send_welcome( $membership_id, $group, $user, $is_new_user ) {
		// Build the set-password link (new users) so they can claim
		// their account in one click. Existing users just sign in.
		$set_password_url = '';
		if ( $is_new_user ) {
			$key = get_password_reset_key( $user );
			if ( ! is_wp_error( $key ) ) {
				$set_password_url = network_site_url(
					'wp-login.php?action=rp&key=' . rawurlencode( $key ) . '&login=' . rawurlencode( $user->user_login ),
					'login'
				);
			}
		}
		$qr_url = class_exists( '\WordPressistic\Memberistic\Utilities\Verification' )
			? \WordPressistic\Memberistic\Utilities\Verification::verify_url( $user->ID )
			: '';
		$waiver_url = Corporate_Waiver_Service::waiver_url( $user->ID );

		\WordPressistic\Memberistic\Emails\Email_Service::send_membership_email(
			$membership_id,
			'group_member_welcome',
			array(
				'corp_group_name'   => $group->group_name,
				'corp_company_name' => $group->company_name,
				'corp_set_password' => $set_password_url,
				'corp_qr_url'       => $qr_url,
				'corp_waiver_url'   => $waiver_url,
				'corp_is_new_user'  => $is_new_user ? '1' : '0',
			)
		);
	}

	/**
	 * Send a group summary email to the primary payer.
	 */
	public static function send_owner_summary( $group_id ) {
		$group = Corporate_Groups_Repository::get( $group_id );
		if ( ! $group || ! $group->primary_user_id ) {
			return false;
		}
		$membership = \WordPressistic\Memberistic\Database\Memberships_Repository::get_by_user_id( (int) $group->primary_user_id );
		if ( ! $membership ) {
			return false; // payer isn't themselves a member; skip gracefully
		}
		return \WordPressistic\Memberistic\Emails\Email_Service::send_membership_email(
			(int) $membership['id'],
			'group_owner_summary',
			array(
				'corp_group_name' => $group->group_name,
				'corp_seats_used' => (string) Corporate_Members_Repository::active_count( $group_id ),
				'corp_seats_total'=> (string) (int) $group->seats_total,
				'corp_paid_total' => number_format( Corporate_Groups_Repository::paid_total( $group_id ), 2 ),
			)
		);
	}

	/* ---- helpers ---- */

	private static function resolve_plan_id( $plan_key ) {
		$plan_key = $plan_key ?: 'corporate';
		$plan = \WordPressistic\Memberistic\Database\Plans_Repository::get_by_slug( $plan_key );
		if ( $plan && ! empty( $plan['id'] ) ) {
			return (int) $plan['id'];
		}
		// Create a hidden corporate plan on first use.
		$id = \WordPressistic\Memberistic\Database\Plans_Repository::create( array(
			'name'            => 'Corporate / Group',
			'slug'            => $plan_key,
			'description'     => 'Corporate / group membership. Custom-priced, hidden from public pricing.',
			'monthly_price'   => 0,
			'annual_price'    => 0,
			'included_people' => 0,
			'is_featured'     => 0,
			'sort_order'      => 99,
			'status'          => 'active',
		) );
		return (int) $id;
	}

	private static function unique_username( $email ) {
		$base = sanitize_user( current( explode( '@', $email ) ), true );
		$base = $base ?: 'member';
		$try  = $base;
		$i    = 1;
		while ( username_exists( $try ) ) {
			$try = $base . $i;
			$i++;
		}
		return $try;
	}

	private static function first_name( $name ) {
		$parts = preg_split( '/\s+/', trim( $name ) );
		return $parts[0] ?? '';
	}
	private static function last_name( $name ) {
		$parts = preg_split( '/\s+/', trim( $name ) );
		array_shift( $parts );
		return implode( ' ', $parts );
	}
}

/**
 * Phase 2 — branded email templates + merge tags for the group
 * member welcome + owner summary. Hooks into Email_Service's
 * filters so we never edit the email core. All URLs are branded
 * (/account/, /login/, /book-a-lane/) via the existing context.
 */
final class Corporate_Emails {

	public static function subject( $subject, $template, $context ) {
		if ( 'group_member_welcome' === $template ) {
			return sprintf(
				/* translators: %s: brand label */
				__( 'Welcome to %s — your range account is ready', 'memberistic' ),
				$context['{brand_label}'] ?? get_bloginfo( 'name' )
			);
		}
		if ( 'group_owner_summary' === $template ) {
			return sprintf(
				/* translators: %s: corporate group name. */
				__( 'Your %s group is set up', 'memberistic' ),
				$context['{brand_label}'] ?? get_bloginfo( 'name' )
			);
		}
		if ( 'corporate_waiver_request' === $template ) {
			return sprintf(
				/* translators: %s: brand */
				__( 'Action needed: sign your %s range waiver', 'memberistic' ),
				$context['{brand_label}'] ?? get_bloginfo( 'name' )
			);
		}
		if ( 'guest_pass_welcome' === $template ) {
			return sprintf(
				/* translators: %s: brand */
				__( 'Your %s range pass + check-in QR', 'memberistic' ),
				$context['{brand_label}'] ?? get_bloginfo( 'name' )
			);
		}
		return $subject;
	}

	public static function body( $body, $template, $context ) {
		if ( 'group_member_welcome' === $template ) {
			$set_pw = $context['{corp_set_password}'] ?? '';
			$claim_line = $set_pw
				? "Set your password and claim your account:\n{corp_set_password}\n\n"
				: "Sign in to your account:\n{login_url}\n\n";
			return "Hi {member_name},\n\n"
				. "You've been added to the {corp_group_name} group membership at {brand_label}. Your individual range account is ready.\n\n"
				. $claim_line
				. "Your account dashboard (digital card, booking, history):\n{account_url}\n\n"
				. "Complete your range waiver before your first visit (takes 30 seconds):\n{corp_waiver_url}\n\n"
				. "Your check-in QR code (show at the range desk or save your digital card):\n{corp_qr_url}\n\n"
				. "Book a lane any time:\n{booking_url}\n\n"
				. "Questions? Call {business_phone}.\n\n"
				. "See you at the range.\n{brand_label}";
		}
		if ( 'corporate_waiver_request' === $template ) {
			return "Hi {member_name},\n\n"
				. "Before your first visit to {brand_label}, please complete your range waiver. It only takes about 30 seconds and means you're ready to shoot the moment you arrive.\n\n"
				. "Sign your waiver here:\n{corp_waiver_url}\n\n"
				. "Questions? Call {business_phone}.\n\n{brand_label}";
		}
		if ( 'group_owner_summary' === $template ) {
			return "Hi {member_name},\n\n"
				. "Your {corp_group_name} group at {brand_label} is set up.\n\n"
				. "Seats used: {corp_seats_used} / {corp_seats_total}\n"
				. "Amount paid: \${corp_paid_total}\n\n"
				. "Each member has received their own welcome email with a set-password link, digital card, waiver, and check-in QR.\n\n"
				. "Manage your group from your account:\n{account_url}\n\n"
				. "Questions? Call {business_phone}.\n\n{brand_label}";
		}
		if ( 'guest_pass_welcome' === $template ) {
			$set_pw = $context['{corp_set_password}'] ?? '';
			$claim  = $set_pw
				? "Set a password to manage your pass (optional):\n{corp_set_password}\n\n"
				: '';
			return "Hi {member_name},\n\n"
				. "Thanks for visiting {brand_label}! Your range guest pass is ready.\n\n"
				. "Your check-in QR code — show this at the range desk:\n{corp_qr_url}\n\n"
				. "Please sign your range waiver before you arrive (takes 30 seconds):\n{corp_waiver_url}\n\n"
				. $claim
				. "See you at the range.\n\n{brand_label}\n{business_phone}";
		}
		return $body;
	}

	public static function merge_tags( $context, $membership, $person, $extra ) {
		foreach ( array( 'corp_group_name', 'corp_company_name', 'corp_set_password', 'corp_qr_url', 'corp_waiver_url', 'corp_seats_used', 'corp_seats_total', 'corp_paid_total', 'corp_is_new_user' ) as $key ) {
			$context[ '{' . $key . '}' ] = isset( $extra[ $key ] ) ? (string) $extra[ $key ] : '';
		}
		return $context;
	}
}

/**
 * Phase 3 — payment-link data access.
 */
final class Corporate_Payment_Links_Repository {

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'memberistic_payment_links';
	}

	public static function for_group( $group_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . ' WHERE group_id = %d ORDER BY created_at DESC',
			(int) $group_id
		) );
	}

	public static function get( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id ) );
	}

	public static function get_by_token( $token ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE token = %s', $token ) );
	}

	public static function get_by_order( $order_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE wc_order_id = %d', (int) $order_id ) );
	}

	public static function insert( array $row ) {
		global $wpdb;
		$row['created_by'] = get_current_user_id();
		$row['created_at'] = current_time( 'mysql' );
		return $wpdb->insert( self::table(), $row ) ? (int) $wpdb->insert_id : 0;
	}

	public static function update( $id, array $row ) {
		global $wpdb;
		return false !== $wpdb->update( self::table(), $row, array( 'id' => (int) $id ) );
	}
}

/**
 * Phase 3 — payments + payment links.
 *
 * Amounts are 100% client-defined per link/payment. Nothing is
 * hard-coded — the $600 figure is only an example default in the
 * admin form and is fully editable.
 *
 * Payment-link mechanics: when WooCommerce is active we create a
 * pending WC order carrying a single fee line of the custom amount
 * (no product needed), tag it with group metadata, and the public
 * link redirects to WC's hosted, gateway-agnostic pay page (Stripe,
 * PayPal, Authnet — whatever the store has). On payment, WC fires
 * the order-paid hook → we mark the link paid + write a group
 * payment row + re-sync the group's payment_status. If WC is not
 * active, the link still records the request and the public page
 * shows a branded "call to complete payment" fallback; staff can
 * always record the payment manually (cash/POS).
 */
final class Corporate_Payment_Service {

	/**
	 * Record a manual / offline payment (cash, POS, card, comp).
	 */
	public static function record_manual_payment( $group_id, $amount, $method, $reference = '', $notes = '' ) {
		$amount = round( (float) $amount, 2 );
		if ( $amount <= 0 ) {
			return array( 'status' => 'invalid', 'message' => __( 'Enter an amount greater than zero.', 'memberistic' ) );
		}
		Corporate_Groups_Repository::add_payment( $group_id, array(
			'amount'            => $amount,
			'payment_method'    => $method,
			'payment_status'    => 'completed',
			'payment_reference' => $reference,
			'notes'             => $notes,
		) );
		self::sync_status( $group_id );
		Corporate_Groups_Repository::log_activity( $group_id, 'payment_recorded', sprintf(
			/* translators: 1: amount, 2: method */
			__( 'Payment recorded: $%1$s via %2$s.', 'memberistic' ),
			number_format( $amount, 2 ),
			ucwords( str_replace( '_', ' ', $method ) )
		) );
		return array( 'status' => 'ok', 'message' => __( 'Payment recorded.', 'memberistic' ) );
	}

	/**
	 * Create a custom-amount payment link.
	 *
	 * @param int    $group_id
	 * @param float  $amount       Client-defined; any positive value.
	 * @param string $description
	 * @param string $recipient    Email to send the link to (optional).
	 * @param int    $expiry_days  0 = no expiry.
	 * @param bool   $send_email
	 */
	public static function create_payment_link( $group_id, $amount, $description, $recipient = '', $expiry_days = 14, $send_email = true ) {
		$amount = round( (float) $amount, 2 );
		if ( $amount <= 0 ) {
			return array( 'status' => 'invalid', 'message' => __( 'Enter an amount greater than zero.', 'memberistic' ) );
		}
		$recipient = sanitize_email( (string) $recipient );
		$token     = self::random_token();
		$expires   = ( (int) $expiry_days > 0 ) ? gmdate( 'Y-m-d H:i:s', time() + ( (int) $expiry_days * DAY_IN_SECONDS ) ) : null;

		// Payment rail priority: Stripe Checkout (branded, preceded by
		// our branded invoice page on the membership URL). A WC order is
		// only pre-created as a LEGACY fallback when Stripe isn't
		// enabled but WooCommerce is — the public flow no longer dumps
		// the customer onto a raw WC page; it always shows this plugin's
		// own branded invoice first.
		$wc_order_id = 0;
		if ( ! self::stripe_active() && self::wc_active() ) {
			$wc_order_id = self::create_wc_order( $group_id, $amount, $description, $recipient );
		}

		$link_id = Corporate_Payment_Links_Repository::insert( array(
			'group_id'        => (int) $group_id,
			'amount'          => $amount,
			'description'     => sanitize_text_field( (string) $description ),
			'recipient_email' => $recipient,
			'token'           => $token,
			'status'          => 'unpaid',
			'wc_order_id'     => (int) $wc_order_id,
			'expires_at'      => $expires,
		) );

		// Link the WC order back to the payment-link id for the
		// reverse lookup on the paid hook, and stamp an order note so
		// staff reading the WC order know this is a membership payment
		// (recorded authoritatively in Memberistic, not a product sale).
		if ( $wc_order_id && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $wc_order_id );
			if ( $order ) {
				$order->update_meta_data( '_memberistic_payment_link_id', $link_id );
				$order->add_order_note( __( 'Membership / group payment link. Recorded in Memberistic; excluded from product-sales reports.', 'memberistic' ) );
				$order->save();
			}
		}

		Corporate_Groups_Repository::log_activity( $group_id, 'payment_link_created', sprintf(
			/* translators: %s: payment amount, already formatted. */
			__( 'Payment link created for $%s.', 'memberistic' ),
			number_format( $amount, 2 )
		) );

		if ( $send_email && $recipient ) {
			self::send_link_email( $link_id );
		}

		return array(
			'status'  => 'ok',
			'message' => __( 'Payment link created.', 'memberistic' ),
			'link_id' => $link_id,
			'url'     => self::public_url( $token ),
		);
	}

	/** Public-facing pay URL for a token. */
	public static function public_url( $token ) {
		return add_query_arg( 'memberistic_pay', $token, home_url( '/' ) );
	}

	/**
	 * Mark a link paid (called from the WC hook OR manual "mark paid"
	 * action). Writes a group payment row once, idempotently.
	 */
	public static function mark_paid( $link_id, $method = 'payment_link', $reference = '' ) {
		$link = Corporate_Payment_Links_Repository::get( $link_id );
		if ( ! $link || 'paid' === $link->status ) {
			return false; // already settled — idempotent
		}
		Corporate_Payment_Links_Repository::update( $link_id, array(
			'status'  => 'paid',
			'paid_at' => current_time( 'mysql' ),
		) );
		Corporate_Groups_Repository::add_payment( (int) $link->group_id, array(
			'amount'            => (float) $link->amount,
			'payment_method'    => $method,
			'payment_status'    => 'completed',
			'payment_reference' => $reference ?: ( $link->wc_order_id ? ( 'WC #' . (int) $link->wc_order_id ) : '' ),
			'wc_order_id'       => (int) $link->wc_order_id,
			'payment_link_id'   => (int) $link_id,
			'notes'             => $link->description,
		) );
		self::sync_status( (int) $link->group_id );
		Corporate_Groups_Repository::log_activity( (int) $link->group_id, 'payment_link_paid', sprintf(
			/* translators: %s: payment amount, already formatted. */
			__( 'Payment link paid: $%s.', 'memberistic' ),
			number_format( (float) $link->amount, 2 )
		) );
		return true;
	}

	public static function void_link( $link_id ) {
		$link = Corporate_Payment_Links_Repository::get( $link_id );
		if ( ! $link || 'paid' === $link->status ) {
			return false;
		}
		Corporate_Payment_Links_Repository::update( $link_id, array( 'status' => 'void' ) );
		return true;
	}

	/**
	 * Re-sync a group's payment_status from its paid total vs the
	 * custom price. paid >= price → paid; >0 → partial; else unpaid.
	 */
	public static function sync_status( $group_id ) {
		global $wpdb;
		$group = Corporate_Groups_Repository::get( $group_id );
		if ( ! $group ) {
			return;
		}
		$paid  = Corporate_Groups_Repository::paid_total( $group_id );
		$price = (float) $group->custom_price;
		if ( $price > 0 && $paid >= $price ) {
			$status = 'paid';
		} elseif ( $paid > 0 ) {
			$status = 'partial';
		} else {
			$status = 'unpaid';
		}
		// Don't override a manual 'comped' flag.
		if ( 'comped' === $group->payment_status ) {
			return;
		}
		$wpdb->update( Corporate_Groups_Repository::table(), array( 'payment_status' => $status ), array( 'id' => (int) $group_id ) );
	}

	/**
	 * Branded payment-link email. Sent via wp_mail with the brand
	 * label, respecting the global kill-switch + staging override,
	 * and logged to memberistic_email_logs.
	 */
	public static function send_link_email( $link_id ) {
		$link = Corporate_Payment_Links_Repository::get( $link_id );
		if ( ! $link || ! $link->recipient_email || ! is_email( $link->recipient_email ) ) {
			return false;
		}
		// Kill-switch.
		if ( ( defined( 'MEMBERISTIC_EMAIL_DISABLED' ) && MEMBERISTIC_EMAIL_DISABLED )
			|| (bool) get_option( 'memberistic_emails_disabled', false ) ) {
			return false;
		}
		$brand = function_exists( '\WordPressistic\Memberistic\memberistic_get_brand_label' )
			? \WordPressistic\Memberistic\memberistic_get_brand_label()
			: get_bloginfo( 'name' );
		$phone = (string) \WordPressistic\Memberistic\memberistic_get_setting( 'business_phone', '' );
		$url   = self::public_url( $link->token );
		$amount = number_format( (float) $link->amount, 2 );

		$subject = sprintf(
			/* translators: %s: brand */
			__( 'Payment request from %s', 'memberistic' ),
			$brand
		);
		$body  = sprintf( __( "Hello,\n\n%1\$s has sent you a secure payment request.\n\n", 'memberistic' ), $brand );
		if ( $link->description ) {
			/* translators: %s: what the payment link is for. */
			$body .= sprintf( __( "For: %s\n", 'memberistic' ), $link->description );
		}
		/* translators: %s: amount due, already formatted. */
		$body .= sprintf( __( "Amount due: \$%s\n\n", 'memberistic' ), $amount );
		/* translators: %s: the payment link URL. */
		$body .= sprintf( __( "Pay securely here:\n%s\n\n", 'memberistic' ), $url );
		if ( $link->expires_at ) {
			/* translators: %s: date the payment link expires. */
			$body .= sprintf( __( "This link expires on %s.\n\n", 'memberistic' ), mysql2date( get_option( 'date_format' ), $link->expires_at ) );
		}
		if ( $phone ) {
			/* translators: %s: the business phone number. */
			$body .= sprintf( __( "Questions? Call %s.\n\n", 'memberistic' ), $phone );
		}
		$body .= $brand;

		// Staging redirect override.
		$recipient = $link->recipient_email;
		$override  = defined( 'MEMBERISTIC_EMAIL_OVERRIDE_RECIPIENT' )
			? MEMBERISTIC_EMAIL_OVERRIDE_RECIPIENT
			: (string) get_option( 'memberistic_email_override_recipient', '' );
		if ( $override && is_email( $override ) ) {
			$subject  = '[REROUTED -> ' . $recipient . '] ' . $subject;
			$recipient = $override;
		}

		$headers = array( 'Content-Type: text/plain; charset=UTF-8', 'From: ' . $brand . ' <' . get_option( 'admin_email' ) . '>' );
		$sent    = wp_mail( $recipient, $subject, $body, $headers );

		if ( class_exists( '\WordPressistic\Memberistic\Database\Email_Logs_Repository' ) ) {
			\WordPressistic\Memberistic\Database\Email_Logs_Repository::log( array(
				'membership_id' => 0,
				'template'      => 'corporate_payment_link',
				'recipient'     => $recipient,
				'subject'       => $subject,
				'status'        => $sent ? 'sent' : 'failed',
			) );
		}
		return (bool) $sent;
	}

	/* ---- Stripe (primary rail) ---- */

	public static function stripe_active() {
		return class_exists( '\WordPressistic\Memberistic\Payments\Stripe_Service' )
			&& \WordPressistic\Memberistic\Payments\Stripe_Service::is_enabled();
	}

	/**
	 * Create a one-time Stripe Checkout Session for a payment link's
	 * custom amount. Returns the hosted Stripe checkout URL (on
	 * checkout.stripe.com — branded with the store's Stripe logo +
	 * business name) or a WP_Error. Metadata carries the link id so
	 * the webhook + the success-return can settle it.
	 */
	public static function create_stripe_session( $link, $email = '' ) {
		if ( ! self::stripe_active() ) {
			return new \WP_Error( 'memberistic_stripe_off', __( 'Stripe is not enabled.', 'memberistic' ) );
		}
		$currency = strtolower( (string) \WordPressistic\Memberistic\memberistic_get_setting( 'currency', 'USD' ) );
		$brand    = function_exists( '\WordPressistic\Memberistic\memberistic_get_brand_label' )
			? \WordPressistic\Memberistic\memberistic_get_brand_label()
			: get_bloginfo( 'name' );
		$return   = self::public_url( $link->token );
		// Stripe requires the literal {CHECKOUT_SESSION_ID} placeholder
		// in success_url — it must NOT be URL-encoded, so we append it
		// as a raw string rather than via add_query_arg.
		$success  = add_query_arg( 'mpay', 'done', $return ) . '&session_id={CHECKOUT_SESSION_ID}';
		$payload  = array(
			'mode'                                          => 'payment',
			'success_url'                                   => $success,
			'cancel_url'                                    => add_query_arg( 'mpay', 'cancel', $return ),
			'line_items[0][quantity]'                       => 1,
			'line_items[0][price_data][currency]'           => $currency,
			'line_items[0][price_data][unit_amount]'        => (int) round( (float) $link->amount * 100 ),
			'line_items[0][price_data][product_data][name]' => $link->description ?: ( $brand . ' — ' . __( 'Group payment', 'memberistic' ) ),
			'metadata[memberistic_payment_link_id]'         => (int) $link->id,
			'metadata[memberistic_group_id]'                => (int) $link->group_id,
			'metadata[memberistic_payment_purpose]'         => 'corporate_group_payment',
			'payment_intent_data[metadata][memberistic_payment_link_id]' => (int) $link->id,
		);
		if ( $email && is_email( $email ) ) {
			$payload['customer_email'] = $email;
		}
		$resp = \WordPressistic\Memberistic\Payments\Stripe_Service::request( 'POST', '/checkout/sessions', $payload );
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		return ! empty( $resp['url'] ) ? $resp['url'] : new \WP_Error( 'memberistic_stripe_nourl', __( 'Stripe did not return a checkout URL.', 'memberistic' ) );
	}

	/**
	 * Verify a returned Stripe session is paid, then settle the link.
	 * Server-side (we re-fetch from Stripe), so it's safe to trust on
	 * the success return without waiting for the webhook.
	 */
	public static function verify_and_settle_session( $link, $session_id ) {
		if ( ! self::stripe_active() || '' === $session_id ) {
			return false;
		}
		$session = \WordPressistic\Memberistic\Payments\Stripe_Service::request( 'GET', '/checkout/sessions/' . rawurlencode( $session_id ) );
		if ( is_wp_error( $session ) ) {
			return false;
		}
		if ( ( $session['payment_status'] ?? '' ) === 'paid' ) {
			self::mark_paid( (int) $link->id, 'payment_link', 'Stripe ' . ( $session['payment_intent'] ?? $session_id ) );
			return true;
		}
		return false;
	}

	/* ---- WooCommerce (legacy fallback rail) ---- */

	public static function wc_active() {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_create_order' );
	}

	/**
	 * Create a pending WC order with a single custom-amount fee line
	 * and group metadata. Returns order id or 0.
	 */
	private static function create_wc_order( $group_id, $amount, $description, $recipient ) {
		try {
			$order = wc_create_order();
			if ( is_wp_error( $order ) ) {
				return 0;
			}
			// Add the custom amount as a fee (no product needed, never
			// shown publicly in the shop).
			$item = new \WC_Order_Item_Fee();
			$item->set_name( $description ?: __( 'Corporate group payment', 'memberistic' ) );
			$item->set_amount( $amount );
			$item->set_total( $amount );
			$order->add_item( $item );

			if ( $recipient ) {
				$order->set_billing_email( $recipient );
			}
			$group = Corporate_Groups_Repository::get( $group_id );
			$order->update_meta_data( '_memberistic_group_id', (int) $group_id );
			$order->update_meta_data( '_memberistic_payment_purpose', 'corporate_group_payment' );
			$order->update_meta_data( '_memberistic_primary_user_id', $group ? (int) $group->primary_user_id : 0 );
			$order->update_meta_data( '_memberistic_created_by', get_current_user_id() );
			$order->calculate_totals();
			$order->set_status( 'pending' );
			$order->save();
			return (int) $order->get_id();
		} catch ( \Throwable $e ) {
			return 0;
		}
	}

	private static function random_token() {
		// 40-char non-guessable token.
		if ( function_exists( 'wp_generate_password' ) ) {
			return wp_generate_password( 40, false, false );
		}
		return bin2hex( random_bytes( 20 ) );
	}
}

/**
 * Phase 3 — public payment endpoint + WC paid hook.
 */
final class Corporate_Pay {

	/**
	 * Handle /?memberistic_pay=TOKEN.
	 *
	 * The customer always lands on this plugin's own branded invoice
	 * page (this membership URL — never a raw WooCommerce page), which
	 * carries the business name from settings, not the plugin's. The
	 * invoice shows the line item + amount and a "Pay Now" button.
	 * Pay Now (a nonce-protected POST back to this same URL) creates
	 * a Stripe Checkout Session and redirects to Stripe's hosted,
	 * branded payment page. On return we verify the session server-
	 * side and settle the link; the Stripe webhook settles it too as
	 * a backstop. Legacy links that already carry a WC order fall
	 * back to the WC pay URL on Pay Now.
	 */
	public static function maybe_handle_public_pay() {
		if ( empty( $_GET['memberistic_pay'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$token = sanitize_text_field( wp_unslash( $_GET['memberistic_pay'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$link  = Corporate_Payment_Links_Repository::get_by_token( $token );

		if ( ! $link ) {
			self::render_invoice_message( __( 'Payment link not found', 'memberistic' ), __( 'This payment link is invalid. Please contact us for a new one.', 'memberistic' ), 404 );
		}

		// Returning from a Stripe checkout — verify + settle.
		$mpay = isset( $_GET['mpay'] ) ? sanitize_key( wp_unslash( $_GET['mpay'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'done' === $mpay ) {
			$sid = isset( $_GET['session_id'] ) ? sanitize_text_field( wp_unslash( $_GET['session_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( 'paid' !== $link->status && $sid ) {
				Corporate_Payment_Service::verify_and_settle_session( $link, $sid );
				$link = Corporate_Payment_Links_Repository::get_by_token( $token ); // refresh
			}
			self::render_invoice_message( __( 'Payment complete', 'memberistic' ), __( 'Thank you — your payment has been received. A receipt is on its way.', 'memberistic' ), 200 );
		}
		if ( 'cancel' === $mpay ) {
			self::render_invoice( $link, $token, __( 'Payment cancelled — you can try again below.', 'memberistic' ) );
		}

		if ( 'paid' === $link->status ) {
			self::render_invoice_message( __( 'Already paid', 'memberistic' ), __( 'This payment has already been completed. Thank you!', 'memberistic' ), 200 );
		}
		if ( 'void' === $link->status ) {
			self::render_invoice_message( __( 'Link cancelled', 'memberistic' ), __( 'This payment link has been cancelled. Please contact us.', 'memberistic' ), 410 );
		}
		if ( $link->expires_at && strtotime( $link->expires_at ) < time() ) {
			self::render_invoice_message( __( 'Link expired', 'memberistic' ), __( 'This payment link has expired. Please contact us for a new one.', 'memberistic' ), 410 );
		}

		// Pay Now POST → start the payment.
		if ( 'POST' === strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
			if ( ! isset( $_POST['memberistic_pay_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['memberistic_pay_nonce'] ) ), 'memberistic_pay_' . $token ) ) {
				self::render_invoice( $link, $token, __( 'Session expired — please try again.', 'memberistic' ) );
			}
			// Primary: Stripe Checkout (branded hosted page).
			if ( Corporate_Payment_Service::stripe_active() ) {
				$url = Corporate_Payment_Service::create_stripe_session( $link, $link->recipient_email );
				if ( ! is_wp_error( $url ) ) {
					wp_redirect( $url ); // checkout.stripe.com — external, so wp_redirect not wp_safe_redirect
					exit;
				}
				self::render_invoice( $link, $token, __( 'We could not start the secure checkout. Please try again or call us.', 'memberistic' ) );
			}
			// Legacy fallback: WC hosted pay page.
			if ( $link->wc_order_id && function_exists( 'wc_get_order' ) ) {
				$order = wc_get_order( (int) $link->wc_order_id );
				if ( $order && $order->needs_payment() ) {
					wp_safe_redirect( $order->get_checkout_payment_url() );
					exit;
				}
			}
			// No gateway available.
			self::render_invoice( $link, $token, __( 'Online payment is not available right now — please call us to complete this payment.', 'memberistic' ) );
		}

		// GET → show the branded invoice.
		self::render_invoice( $link, $token );
	}

	/**
	 * Invoice page with a Pay Now button, branded with the business name
	 * resolved from settings (falling back to the WP site name).
	 */
	private static function render_invoice( $link, $token, $notice = '' ) {
		$brand = function_exists( '\WordPressistic\Memberistic\memberistic_get_brand_label' )
			? \WordPressistic\Memberistic\memberistic_get_brand_label()
			: get_bloginfo( 'name' );
		$logo_id  = (int) get_theme_mod( 'custom_logo' );
		$logo_url = $logo_id ? wp_get_attachment_image_url( $logo_id, 'medium' ) : '';
		$amount   = number_format( (float) $link->amount, 2 );
		$inv_no   = 'INV-' . str_pad( (string) (int) $link->id, 5, '0', STR_PAD_LEFT );
		$phone    = (string) \WordPressistic\Memberistic\memberistic_get_setting( 'business_phone', '' );
		$can_pay  = Corporate_Payment_Service::stripe_active() || ( $link->wc_order_id && function_exists( 'wc_get_order' ) );

		nocache_headers();
		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );
		?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo esc_html( $brand ); ?> — <?php echo esc_html( $inv_no ); ?></title>
<style>
*{box-sizing:border-box}body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#0F1115;color:#fff;margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;}
.inv{max-width:480px;width:100%;background:#1A1F26;border:1px solid #2A323D;border-radius:14px;overflow:hidden;box-shadow:0 24px 70px rgba(0,0,0,.5);}
.inv__head{background:#0F1115;padding:24px;border-bottom:3px solid #C9A84C;text-align:center;}
.inv__head img{max-height:48px;width:auto;max-width:220px;}
.inv__brand{font-family:"Bebas Neue",Impact,sans-serif;letter-spacing:.12em;color:#C9A84C;text-transform:uppercase;font-size:22px;}
.inv__no{font-family:"Space Mono",monospace;font-size:11px;letter-spacing:.2em;color:#8A95A5;text-transform:uppercase;margin-top:8px;}
.inv__body{padding:28px 24px;}
.inv__row{display:flex;justify-content:space-between;align-items:flex-start;padding:14px 0;border-bottom:1px solid #2A323D;}
.inv__row .lbl{color:#8A95A5;font-size:13px;}.inv__row .val{color:#fff;font-size:15px;text-align:right;max-width:60%;}
.inv__total{display:flex;justify-content:space-between;align-items:center;padding:18px 0 4px;}
.inv__total .lbl{font-family:"Space Mono",monospace;letter-spacing:.18em;text-transform:uppercase;color:#8A95A5;font-size:12px;}
.inv__total .amt{font-family:"Bebas Neue",Impact,sans-serif;font-size:38px;color:#C9A84C;line-height:1;}
.inv__btn{display:block;width:100%;margin-top:22px;padding:16px;background:#E8802F;color:#111;border:0;border-radius:6px;font-weight:700;font-size:16px;letter-spacing:.06em;text-transform:uppercase;cursor:pointer;}
.inv__btn:hover{background:#f4922f;}
.inv__secure{text-align:center;color:#5A6371;font-size:11px;letter-spacing:.08em;margin-top:14px;}
.inv__notice{background:rgba(232,128,47,.12);border:1px solid rgba(232,128,47,.4);color:#E8802F;padding:10px 14px;border-radius:6px;font-size:13px;margin-bottom:16px;}
.inv__foot{text-align:center;color:#5A6371;font-size:12px;padding:0 24px 22px;}
</style></head><body>
<div class="inv">
  <div class="inv__head">
    <?php if ( $logo_url ) : ?><img src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo esc_attr( $brand ); ?>"><?php else : ?><div class="inv__brand"><?php echo esc_html( $brand ); ?></div><?php endif; ?>
    <div class="inv__no"><?php echo esc_html( $inv_no ); ?></div>
  </div>
  <div class="inv__body">
    <?php if ( $notice ) : ?><div class="inv__notice"><?php echo esc_html( $notice ); ?></div><?php endif; ?>
    <div class="inv__row"><span class="lbl"><?php esc_html_e( 'Description', 'memberistic' ); ?></span><span class="val"><?php echo esc_html( $link->description ?: __( 'Membership payment', 'memberistic' ) ); ?></span></div>
    <?php if ( $link->expires_at ) : ?>
    <div class="inv__row"><span class="lbl"><?php esc_html_e( 'Pay before', 'memberistic' ); ?></span><span class="val"><?php echo esc_html( mysql2date( get_option( 'date_format' ), $link->expires_at ) ); ?></span></div>
    <?php endif; ?>
    <div class="inv__total"><span class="lbl"><?php esc_html_e( 'Amount due', 'memberistic' ); ?></span><span class="amt">$<?php echo esc_html( $amount ); ?></span></div>
    <?php if ( $can_pay ) : ?>
      <form method="post">
        <?php wp_nonce_field( 'memberistic_pay_' . $token, 'memberistic_pay_nonce' ); ?>
        <button type="submit" class="inv__btn"><?php esc_html_e( 'Pay Now', 'memberistic' ); ?></button>
      </form>
      <div class="inv__secure">🔒 <?php esc_html_e( 'Secure payment via Stripe', 'memberistic' ); ?></div>
    <?php else : ?>
      <?php /* translators: %s: the business phone number. */ ?>
      <p style="color:#8A95A5;text-align:center;margin-top:18px;"><?php echo esc_html( $phone ? sprintf( __( 'To pay, please call us at %s.', 'memberistic' ), $phone ) : __( 'To pay, please contact us.', 'memberistic' ) ); ?></p>
    <?php endif; ?>
  </div>
  <div class="inv__foot"><?php echo esc_html( $brand ); ?><?php echo $phone ? ' · ' . esc_html( $phone ) : ''; ?></div>
</div>
</body></html>
		<?php
		exit;
	}

	/** Simple branded message page (paid / expired / error states). */
	private static function render_invoice_message( $title, $body, $code = 200 ) {
		self::render_message( $title, $body, $code );
	}

	/**
	 * WC order paid → settle the matching payment link (legacy links).
	 */
	public static function on_wc_paid( $order_id ) {
		$order_id = (int) $order_id;
		if ( ! $order_id ) {
			return;
		}
		$link = Corporate_Payment_Links_Repository::get_by_order( $order_id );
		if ( $link && 'paid' !== $link->status ) {
			Corporate_Payment_Service::mark_paid( (int) $link->id, 'payment_link', 'WC #' . $order_id );
		}
	}

	/**
	 * Stripe webhook backstop. Fires for every webhook event via the
	 * memberistic_stripe_webhook_event action. Settles a payment link
	 * when its checkout session completes (idempotent — the success
	 * return may have already settled it).
	 *
	 * @param string $type  Event type.
	 * @param array  $obj   The event data object.
	 */
	public static function on_stripe_webhook( $type, $obj ) {
		if ( 'checkout.session.completed' !== $type || ! is_array( $obj ) ) {
			return;
		}
		$link_id = isset( $obj['metadata']['memberistic_payment_link_id'] ) ? (int) $obj['metadata']['memberistic_payment_link_id'] : 0;
		if ( $link_id <= 0 ) {
			return; // not one of ours (membership-plan checkouts handled elsewhere)
		}
		if ( ( $obj['payment_status'] ?? '' ) === 'paid' ) {
			$pi = isset( $obj['payment_intent'] ) ? (string) $obj['payment_intent'] : '';
			Corporate_Payment_Service::mark_paid( $link_id, 'payment_link', 'Stripe ' . $pi );
		}
	}

	private static function render_message( $title, $body, $code = 200 ) {
		nocache_headers();
		status_header( (int) $code );
		header( 'Content-Type: text/html; charset=utf-8' );
		$brand = function_exists( '\WordPressistic\Memberistic\memberistic_get_brand_label' )
			? \WordPressistic\Memberistic\memberistic_get_brand_label()
			: get_bloginfo( 'name' );
		echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
		echo '<title>' . esc_html( $brand ) . ' — ' . esc_html( $title ) . '</title>';
		echo '<style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#0F1115;color:#fff;margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;}'
			. '.b{max-width:440px;background:#1A1F26;border:1px solid #2A323D;border-radius:12px;padding:32px;text-align:center;}'
			. '.brand{font-family:"Bebas Neue",Impact,sans-serif;letter-spacing:.18em;color:#C9A84C;text-transform:uppercase;font-size:13px;margin-bottom:18px;}'
			. 'h1{font-size:24px;margin:0 0 12px;}p{color:#8A95A5;line-height:1.6;}</style></head><body>';
		echo '<div class="b"><div class="brand">' . esc_html( $brand ) . '</div><h1>' . esc_html( $title ) . '</h1><p>' . esc_html( $body ) . '</p></div></body></html>';
		exit;
	}
}

/**
 * Phase 4 — waiver automation.
 *
 * Reuses the canonical waiver fields on memberistic_people
 * (waiver_status: missing|signed|expired|needs_review|rejected,
 * waiver_signed_at, waiver_expires_at) and mirrors the signed
 * state onto memberistic_group_members for the group view. The
 * signing surface is a tokenized public page; no login required so
 * a member can sign from the link in their welcome email before
 * they've ever set a password.
 */
final class Corporate_Waiver_Service {

	const TOKEN_META = 'memberistic_waiver_token';
	const QUERY      = 'memberistic_waiver';

	/** Validity window in days (filterable). */
	public static function validity_days() {
		return (int) apply_filters( 'memberistic_waiver_validity_days', 365 );
	}

	public static function get_or_create_token( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return '';
		}
		$t = (string) get_user_meta( $user_id, self::TOKEN_META, true );
		if ( '' === $t ) {
			$t = wp_generate_password( 40, false, false );
			update_user_meta( $user_id, self::TOKEN_META, $t );
		}
		return $t;
	}

	public static function waiver_url( $user_id ) {
		$t = self::get_or_create_token( $user_id );
		return $t ? add_query_arg( self::QUERY, $t, home_url( '/' ) ) : home_url( '/account/' );
	}

	public static function user_by_token( $token ) {
		global $wpdb;
		$uid = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
			self::TOKEN_META,
			$token
		) );
		return $uid ?: 0;
	}

	/**
	 * Default waiver body. Filterable so the client can replace it
	 * without code (e.g. via a settings field later). Plain, neutral
	 * liability language — NOT legal advice; the range should have
	 * counsel review the final text.
	 */
	public static function waiver_text() {
		$brand = function_exists( '\WordPressistic\Memberistic\memberistic_get_brand_label' )
			? \WordPressistic\Memberistic\memberistic_get_brand_label()
			: get_bloginfo( 'name' );
		$default = sprintf(
			/* translators: 1: business name where the activity takes place. 2: business name released from liability. */
			__( 'I acknowledge that the use of firearms and the shooting range at %1$s involves inherent risks. I confirm I am legally permitted to handle firearms, will follow all posted range rules and staff instructions at all times, and assume responsibility for my own safety and conduct on the premises. I release %2$s, its owners, staff, and affiliates from liability for injury or loss arising from my voluntary participation, to the extent permitted by law.', 'memberistic' ),
			$brand,
			$brand
		);
		return (string) apply_filters( 'memberistic_waiver_text', $default );
	}

	/**
	 * Record a signature. Updates the person record (canonical) and
	 * the group_members mirror. Returns true on success.
	 */
	public static function mark_signed( $user_id, $typed_name = '' ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return false;
		}
		$now     = current_time( 'mysql' );
		$expires = gmdate( 'Y-m-d H:i:s', time() + ( self::validity_days() * DAY_IN_SECONDS ) );

		// Update every person record tied to this user (a user may be a
		// primary on their own membership). Canonical status = 'signed'.
		$membership = \WordPressistic\Memberistic\Database\Memberships_Repository::get_by_user_id( $user_id );
		if ( $membership ) {
			$person = \WordPressistic\Memberistic\Database\People_Repository::get_primary_by_membership( (int) $membership['id'] );
			if ( $person ) {
				\WordPressistic\Memberistic\Database\People_Repository::update( (int) $person['id'], array(
					'waiver_status'     => 'signed',
					'waiver_signed_at'  => $now,
					'waiver_expires_at' => $expires,
				) );
			}
		}
		// Mirror onto any group_members rows for this user.
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT id, group_id FROM ' . Corporate_Members_Repository::table() . " WHERE user_id = %d AND status != 'removed'",
			$user_id
		) );
		foreach ( (array) $rows as $r ) {
			Corporate_Members_Repository::update_waiver( (int) $r->id, 'signed' );
			Corporate_Groups_Repository::log_activity( (int) $r->group_id, 'waiver_signed', sprintf(
				/* translators: %s: signer name */
				__( 'Waiver signed by %s.', 'memberistic' ),
				$typed_name ?: ( get_userdata( $user_id ) ? get_userdata( $user_id )->display_name : ( '#' . $user_id ) )
			) );
		}
		update_user_meta( $user_id, 'memberistic_waiver_signed_name', sanitize_text_field( $typed_name ) );
		update_user_meta( $user_id, 'memberistic_waiver_signed_at', $now );
		return true;
	}

	public static function status_for_user( $user_id ) {
		$membership = \WordPressistic\Memberistic\Database\Memberships_Repository::get_by_user_id( (int) $user_id );
		if ( $membership ) {
			$person = \WordPressistic\Memberistic\Database\People_Repository::get_primary_by_membership( (int) $membership['id'] );
			if ( $person && ! empty( $person['waiver_status'] ) ) {
				return $person['waiver_status'];
			}
		}
		return 'missing';
	}

	/**
	 * Send the branded waiver email to a group member. Uses the
	 * Email_Service pipeline via the corporate_waiver_request
	 * template registered in Corporate_Emails.
	 */
	public static function send_waiver_email( $membership_id, $user ) {
		$waiver_url = self::waiver_url( $user->ID );
		return \WordPressistic\Memberistic\Emails\Email_Service::send_membership_email(
			(int) $membership_id,
			'corporate_waiver_request',
			array( 'corp_waiver_url' => $waiver_url )
		);
	}
}

/**
 * Phase 4 — public waiver signing page.
 */
final class Corporate_Waiver {

	public static function maybe_handle_public_waiver() {
		if ( empty( $_GET[ Corporate_Waiver_Service::QUERY ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$token   = sanitize_text_field( wp_unslash( $_GET[ Corporate_Waiver_Service::QUERY ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$user_id = Corporate_Waiver_Service::user_by_token( $token );
		if ( ! $user_id ) {
			self::render( __( 'Waiver not found', 'memberistic' ), '<p>' . esc_html__( 'This waiver link is invalid. Please contact us.', 'memberistic' ) . '</p>', 404 );
		}
		$user = get_userdata( $user_id );

		// POST = submit signature.
		if ( 'POST' === strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
			if ( ! isset( $_POST['memberistic_waiver_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['memberistic_waiver_nonce'] ) ), 'memberistic_waiver_' . $token ) ) {
				self::render( __( 'Session expired', 'memberistic' ), '<p>' . esc_html__( 'Please reload the page and try again.', 'memberistic' ) . '</p>', 400 );
			}
			$typed = isset( $_POST['signature_name'] ) ? sanitize_text_field( wp_unslash( $_POST['signature_name'] ) ) : '';
			$agree = ! empty( $_POST['agree'] );
			if ( ! $agree || '' === $typed ) {
				self::render_form( $user, $token, __( 'Please type your full name and check the agreement box.', 'memberistic' ) );
			}
			Corporate_Waiver_Service::mark_signed( $user_id, $typed );
			$account = home_url( '/account/' );
			self::render(
				__( 'Waiver complete', 'memberistic' ),
				'<p>' . esc_html__( 'Thank you — your waiver is on file. You can show your member QR at the range desk on your next visit.', 'memberistic' ) . '</p>'
				. '<p><a class="btn" href="' . esc_url( $account ) . '">' . esc_html__( 'Go to my account', 'memberistic' ) . '</a></p>',
				200
			);
		}

		// Already signed?
		if ( 'signed' === Corporate_Waiver_Service::status_for_user( $user_id ) ) {
			self::render( __( 'Waiver already on file', 'memberistic' ), '<p>' . esc_html__( 'Your waiver is already signed and on file. Thank you!', 'memberistic' ) . '</p>', 200 );
		}

		self::render_form( $user, $token );
	}

	private static function render_form( $user, $token, $error = '' ) {
		$text = Corporate_Waiver_Service::waiver_text();
		ob_start();
		if ( $error ) {
			echo '<p style="color:#E8802F;font-weight:600;">' . esc_html( $error ) . '</p>';
		}
		echo '<div style="text-align:left;background:#11161D;border:1px solid #2A323D;border-radius:8px;padding:18px;max-height:280px;overflow:auto;color:#CBCAD2;line-height:1.6;font-size:14px;">' . esc_html( $text ) . '</div>';
		echo '<form method="post" style="margin-top:18px;text-align:left;">';
		wp_nonce_field( 'memberistic_waiver_' . $token, 'memberistic_waiver_nonce' );
		echo '<label style="display:block;font-size:12px;letter-spacing:.12em;text-transform:uppercase;color:#8A95A5;margin-bottom:6px;">' . esc_html__( 'Type your full legal name', 'memberistic' ) . '</label>';
		echo '<input type="text" name="signature_name" required value="' . esc_attr( $user->display_name ) . '" style="width:100%;padding:12px 14px;background:#0F1115;border:1px solid #2A323D;border-radius:4px;color:#fff;font-size:16px;margin-bottom:14px;">';
		echo '<label style="display:flex;gap:10px;align-items:flex-start;color:#CBCAD2;font-size:14px;cursor:pointer;"><input type="checkbox" name="agree" value="1" required style="width:20px;height:20px;margin-top:2px;"> <span>' . esc_html__( 'I have read and agree to the waiver above, and I am signing electronically.', 'memberistic' ) . '</span></label>';
		echo '<button type="submit" style="margin-top:18px;width:100%;padding:14px;background:#E8802F;color:#111;border:0;border-radius:4px;font-weight:700;font-size:15px;letter-spacing:.06em;text-transform:uppercase;cursor:pointer;">' . esc_html__( 'Sign Waiver', 'memberistic' ) . '</button>';
		echo '</form>';
		self::render( __( 'Range Waiver', 'memberistic' ), ob_get_clean(), 200 );
	}

	private static function render( $title, $html, $code = 200 ) {
		nocache_headers();
		status_header( (int) $code );
		header( 'Content-Type: text/html; charset=utf-8' );
		$brand = function_exists( '\WordPressistic\Memberistic\memberistic_get_brand_label' )
			? \WordPressistic\Memberistic\memberistic_get_brand_label()
			: get_bloginfo( 'name' );
		echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
		echo '<title>' . esc_html( $brand ) . ' — ' . esc_html( $title ) . '</title>';
		echo '<style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#0F1115;color:#fff;margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;}'
			. '.b{max-width:520px;width:100%;background:#1A1F26;border:1px solid #2A323D;border-radius:12px;padding:32px;text-align:center;}'
			. '.brand{font-family:"Bebas Neue",Impact,sans-serif;letter-spacing:.18em;color:#C9A84C;text-transform:uppercase;font-size:13px;margin-bottom:14px;}'
			. 'h1{font-size:26px;margin:0 0 16px;}.btn{display:inline-block;margin-top:8px;padding:12px 22px;background:#C9A84C;color:#111;border-radius:4px;text-decoration:none;font-weight:700;}</style></head><body>';
		echo '<div class="b"><div class="brand">' . esc_html( $brand ) . '</div><h1>' . esc_html( $title ) . '</h1>' . $html . '</div></body></html>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}
}

/**
 * Payment separation — keep WooCommerce reporting to PRODUCT sales
 * only. Membership/group money is authoritatively recorded in
 * Memberistic (memberistic_group_payments / memberistic_payments).
 * The WC order created for a payment link is purely the charging
 * rail; we flag it and exclude it from WC Analytics so it never
 * double-counts as product revenue.
 */
final class Corporate_WC_Separation {

	const META = '_memberistic_payment_purpose';

	/** Is this WC order a membership/group payment (not a product sale)? */
	public static function is_membership_order( $order ) {
		if ( is_numeric( $order ) && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( (int) $order );
		}
		if ( ! $order || ! is_object( $order ) ) {
			return false;
		}
		return (bool) $order->get_meta( self::META );
	}

	/** Show a clear badge on the WC order screen. */
	public static function admin_order_badge( $order ) {
		if ( ! self::is_membership_order( $order ) ) {
			return;
		}
		echo '<p style="background:#1A1F26;color:#C9A84C;border:1px solid #C9A84C;border-radius:4px;padding:8px 12px;display:inline-block;font-weight:600;">'
			. esc_html__( 'Membership payment — recorded in Memberistic. Excluded from product-sales reports.', 'memberistic' )
			. '</p>';
	}

	/**
	 * Exclude membership-payment orders from WooCommerce Analytics so
	 * the store's product-sales numbers stay product-only. WC passes
	 * the stats data array + the order; returning the data unchanged
	 * for product orders, and zeroing the net/gross for membership
	 * orders keeps them out of revenue totals.
	 */
	public static function exclude_from_analytics( $data, $order ) {
		if ( self::is_membership_order( $order ) ) {
			// Zero the monetary contribution so membership money never
			// shows up as product revenue. The authoritative record is
			// in Memberistic.
			foreach ( array( 'net_total', 'gross_total', 'total_sales', 'tax_total', 'shipping_total' ) as $k ) {
				if ( isset( $data[ $k ] ) ) {
					$data[ $k ] = 0;
				}
			}
		}
		return $data;
	}
}

/**
 * Phase 5 — staff QR check-in.
 *
 * The member/guest QR already resolves to the public verification
 * card at /?memberistic_verify=TOKEN. For a logged-in STAFF user
 * (capability memberistic_checkin_members or manage_memberistic_
 * groups) we attach a check-in panel to that same page: member
 * details (name, plan/group, waiver, eligibility, notes) + a
 * "Check In" button. Public viewers see nothing extra — one QR,
 * two audiences.
 */
final class Corporate_Checkin {

	public static function can_checkin() {
		return current_user_can( 'memberistic_checkin_members' )
			|| current_user_can( 'manage_memberistic_groups' )
			|| current_user_can( 'manage_options' );
	}

	/**
	 * Record a check-in when staff submits the panel form on the
	 * verify page. Fires on memberistic_verify_request (before render).
	 */
	public static function maybe_record( $user, $membership, $token ) {
		if ( 'POST' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
			return;
		}
		if ( empty( $_POST['memberistic_checkin_nonce'] ) || ! self::can_checkin() ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['memberistic_checkin_nonce'] ) ), 'memberistic_checkin_' . $token ) ) {
			return;
		}
		if ( ! is_array( $membership ) || empty( $membership['id'] ) ) {
			return;
		}

		// Server-side re-validation — mirrors staff_panel()'s $eligible gate.
		// The rendered panel disables the submit button for an ineligible
		// membership, but that's a client-side cue only; a direct POST (once
		// a valid nonce for this token is in hand) must not be allowed to
		// record range access for an expired/inactive membership.
		$status = (string) ( $membership['status'] ?? '' );
		if ( ! in_array( $status, array( 'active', 'comped', 'trial', 'guest' ), true ) ) {
			return;
		}

		$person = \WordPressistic\Memberistic\Database\People_Repository::get_primary_by_membership( (int) $membership['id'] );
		if ( ! $person ) {
			return;
		}

		// Waiver-missing/expired is shown as a warning (not a hard block) so
		// staff can still check someone in while collecting a waiver in
		// person — but record the waiver status at check-in time so the
		// audit trail reflects reality rather than silently omitting it.
		$waiver = ! empty( $person['waiver_status'] ) ? (string) $person['waiver_status'] : 'missing';
		$notes  = sanitize_text_field( wp_unslash( $_POST['checkin_notes'] ?? '' ) );
		if ( 'signed' !== $waiver ) {
			$notes = trim( sprintf( '[waiver: %s] ', $waiver ) . $notes );
		}

		\WordPressistic\Memberistic\Database\Checkins_Repository::create( array(
			'membership_id' => (int) $membership['id'],
			'person_id'     => (int) $person['id'],
			'checkin_type'  => 'qr_scan',
			'status'        => 'checked_in',
			'notes'         => $notes,
		) );
		\WordPressistic\Memberistic\Database\Activity_Repository::log( array(
			'membership_id'       => (int) $membership['id'],
			'person_id'           => (int) $person['id'],
			'activity_type'       => 'checkin_created',
			'title'               => __( 'QR check-in', 'memberistic' ),
			/* translators: %s: display name of the staff member who scanned the QR code. */
			'description'         => sprintf( __( 'Checked in at the range desk via QR by %s.', 'memberistic' ), wp_get_current_user()->display_name ),
		) );
		// Flag so the panel shows a confirmation banner.
		$GLOBALS['memberistic_checkin_done'] = true;
	}

	/**
	 * Render the staff check-in panel below the verification card.
	 */
	public static function staff_panel( $user, $membership ) {
		if ( ! self::can_checkin() ) {
			return;
		}
		$status = is_array( $membership ) ? (string) ( $membership['status'] ?? '' ) : '';
		$plan   = is_array( $membership ) ? (string) ( $membership['plan_name'] ?? '' ) : '';
		$mid    = is_array( $membership ) ? (int) ( $membership['id'] ?? 0 ) : 0;
		$token  = isset( $_GET['memberistic_verify'] ) ? sanitize_text_field( wp_unslash( $_GET['memberistic_verify'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// Resolve waiver + group context.
		$waiver = 'missing';
		$person = $mid ? \WordPressistic\Memberistic\Database\People_Repository::get_primary_by_membership( $mid ) : null;
		if ( $person && ! empty( $person['waiver_status'] ) ) {
			$waiver = $person['waiver_status'];
		}
		$group_name = '';
		global $wpdb;
		$gm = $wpdb->get_row( $wpdb->prepare(
			'SELECT group_id FROM ' . Corporate_Members_Repository::table() . " WHERE user_id = %d AND status != 'removed' LIMIT 1",
			(int) $user->ID
		) );
		if ( $gm ) {
			$g = Corporate_Groups_Repository::get( (int) $gm->group_id );
			$group_name = $g ? $g->group_name : '';
		}

		$status_ok  = in_array( $status, array( 'active', 'comped', 'trial', 'guest' ), true );
		$waiver_ok  = ( 'signed' === $waiver );
		$eligible   = $status_ok; // membership active; waiver shown as a warning if missing
		$done       = ! empty( $GLOBALS['memberistic_checkin_done'] );

		$last_in = '';
		if ( $mid && class_exists( '\WordPressistic\Memberistic\Database\Checkins_Repository' ) ) {
			$rows = \WordPressistic\Memberistic\Database\Checkins_Repository::get_by_membership( $mid );
			if ( ! empty( $rows ) && is_array( $rows ) ) {
				$last = is_array( $rows[0] ) ? $rows[0] : (array) $rows[0];
				$last_in = $last['checked_in_at'] ?? '';
			}
		}
		?>
		<style>
		.mck{max-width:420px;margin:14px auto 0;background:#11161D;border:1px solid #2A323D;border-radius:12px;padding:22px;text-align:left;color:#fff;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;}
		.mck h2{font-size:13px;letter-spacing:.18em;text-transform:uppercase;color:#C9A84C;margin:0 0 14px;}
		.mck .row{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #2A323D;font-size:14px;}
		.mck .row .l{color:#8A95A5;}.mck .row .v{color:#fff;font-weight:600;}
		.mck .ok{color:#9DE05B;}.mck .warn{color:#E8802F;}
		.mck textarea{width:100%;margin-top:12px;background:#0F1115;border:1px solid #2A323D;border-radius:6px;color:#fff;padding:10px;font-size:14px;}
		.mck button{width:100%;margin-top:12px;padding:15px;border:0;border-radius:6px;font-weight:700;font-size:15px;letter-spacing:.06em;text-transform:uppercase;cursor:pointer;}
		.mck .btn-go{background:#9DE05B;color:#0F1115;}.mck .btn-go.dis{background:#3a4654;color:#8A95A5;cursor:not-allowed;}
		.mck .done{background:rgba(157,224,91,.15);border:1px solid rgba(157,224,91,.5);color:#9DE05B;padding:12px;border-radius:6px;text-align:center;font-weight:700;}
		</style>
		<div class="mck">
			<h2><?php esc_html_e( 'Staff Check-In', 'memberistic' ); ?></h2>
			<?php if ( $done ) : ?>
				<div class="done">✓ <?php esc_html_e( 'Checked in', 'memberistic' ); ?> · <?php echo esc_html( wp_date( 'g:i a' ) ); ?></div>
			<?php else : ?>
				<div class="row"><span class="l"><?php esc_html_e( 'Member', 'memberistic' ); ?></span><span class="v"><?php echo esc_html( $user->display_name ); ?></span></div>
				<div class="row"><span class="l"><?php esc_html_e( 'Membership', 'memberistic' ); ?></span><span class="v <?php echo $status_ok ? 'ok' : 'warn'; ?>"><?php echo esc_html( $status ? ucfirst( $status ) : '—' ); ?></span></div>
				<?php if ( $plan ) : ?><div class="row"><span class="l"><?php esc_html_e( 'Plan', 'memberistic' ); ?></span><span class="v"><?php echo esc_html( $plan ); ?></span></div><?php endif; ?>
				<?php if ( $group_name ) : ?><div class="row"><span class="l"><?php esc_html_e( 'Group', 'memberistic' ); ?></span><span class="v"><?php echo esc_html( $group_name ); ?></span></div><?php endif; ?>
				<div class="row"><span class="l"><?php esc_html_e( 'Waiver', 'memberistic' ); ?></span><span class="v <?php echo $waiver_ok ? 'ok' : 'warn'; ?>"><?php echo $waiver_ok ? esc_html__( 'Signed', 'memberistic' ) : esc_html__( 'NOT on file', 'memberistic' ); ?></span></div>
				<?php if ( $last_in ) : ?><div class="row"><span class="l"><?php esc_html_e( 'Last check-in', 'memberistic' ); ?></span><span class="v"><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' g:i a', $last_in ) ); ?></span></div><?php endif; ?>
				<?php if ( ! $waiver_ok ) : ?><div class="row"><span class="warn" style="font-size:13px;">⚠ <?php esc_html_e( 'Collect a signed waiver before range access.', 'memberistic' ); ?></span></div><?php endif; ?>
				<form method="post">
					<?php wp_nonce_field( 'memberistic_checkin_' . $token, 'memberistic_checkin_nonce' ); ?>
					<textarea name="checkin_notes" rows="2" placeholder="<?php esc_attr_e( 'Notes (optional)', 'memberistic' ); ?>"></textarea>
					<button type="submit" class="btn-go<?php echo $eligible ? '' : ' dis'; ?>"<?php echo $eligible ? '' : ' disabled'; ?>><?php echo $eligible ? esc_html__( 'Check In Now', 'memberistic' ) : esc_html__( 'Not eligible — see status', 'memberistic' ); ?></button>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}
}

/**
 * Phase 5 — non-member "Guest Pass".
 *
 * A non-member who buys online (or registers at the desk) gets a
 * lightweight Guest Pass: their own WP account + a membership on a
 * hidden "guest-pass" plan + QR check-in token + emailed waiver +
 * a digital ID card (the standard account card works for them).
 * Staff scan the same QR to check them in. This reuses every piece
 * of the member infrastructure — the guest IS a (guest-tier)
 * member, so nothing special is needed downstream.
 *
 * Surfaced via the [memberistic_guest_pass] shortcode (a public
 * registration form) and a filterable hook so WooCommerce online
 * buyers can be auto-enrolled later.
 */
final class Corporate_Guest_Service {

	const PLAN_SLUG = 'guest-pass';

	/**
	 * Create (or fetch) a guest pass for an email. Returns the same
	 * status shape as the member service.
	 */
	public static function create_guest( $name, $email, $phone = '', $send_email = true ) {
		$name  = sanitize_text_field( (string) $name );
		$email = sanitize_email( (string) $email );
		$phone = sanitize_text_field( (string) $phone );
		if ( ! $email || ! is_email( $email ) || '' === $name ) {
			return array( 'status' => 'invalid', 'message' => __( 'A valid name and email are required.', 'memberistic' ) );
		}

		$user = get_user_by( 'email', $email );
		$is_new = false;
		if ( ! $user ) {
			$base = sanitize_user( current( explode( '@', $email ) ), true ) ?: 'guest';
			$try = $base; $i = 1;
			while ( username_exists( $try ) ) { $try = $base . $i; $i++; }
			$uid = wp_insert_user( array(
				'user_login'   => $try,
				'user_email'   => $email,
				'user_pass'    => wp_generate_password( 24, true, true ),
				'display_name' => $name,
				'role'         => 'subscriber',
			) );
			if ( is_wp_error( $uid ) ) {
				return array( 'status' => 'error', 'message' => $uid->get_error_message() );
			}
			$user = get_user_by( 'id', $uid );
			$is_new = true;
		}

		// Already has a membership? Just (re)issue QR + waiver.
		$existing = \WordPressistic\Memberistic\Database\Memberships_Repository::get_by_user_id( $user->ID );
		if ( ! $existing ) {
			$plan_id = self::guest_plan_id();
			$mid = \WordPressistic\Memberistic\Database\Memberships_Repository::create( array(
				'membership_uuid' => \WordPressistic\Memberistic\Database\Memberships_Repository::generate_membership_uuid(),
				'primary_user_id' => $user->ID,
				'plan_id'         => $plan_id,
				'billing_cycle'   => 'annual',
				'status'          => 'active',
				'start_date'      => current_time( 'mysql' ),
				'payment_source'  => 'guest_pass',
				'created_by'      => get_current_user_id(),
			) );
			\WordPressistic\Memberistic\Database\People_Repository::create( array(
				'membership_id' => $mid,
				'wp_user_id'    => $user->ID,
				'role'          => 'primary',
				'full_name'     => $name,
				'email'         => $email,
				'phone'         => $phone,
				'waiver_status' => 'missing',
				'status'        => 'active',
			) );
		} else {
			$mid = (int) $existing['id'];
		}

		// QR token (reuses the member verification QR + live card).
		if ( class_exists( '\WordPressistic\Memberistic\Utilities\Verification' ) ) {
			\WordPressistic\Memberistic\Utilities\Verification::get_or_create_token( $user->ID );
		}

		if ( $send_email ) {
			self::send_guest_email( $mid, $user, $is_new );
			Corporate_Waiver_Service::send_waiver_email( $mid, $user );
		}

		return array(
			'status'    => 'ok',
			'message'   => __( "You're all set — check your email for your range pass + waiver.", 'memberistic' ),
			'user_id'   => $user->ID,
			'qr_url'    => class_exists( '\WordPressistic\Memberistic\Utilities\Verification' ) ? \WordPressistic\Memberistic\Utilities\Verification::verify_url( $user->ID ) : '',
		);
	}

	private static function guest_plan_id() {
		$plan = \WordPressistic\Memberistic\Database\Plans_Repository::get_by_slug( self::PLAN_SLUG );
		if ( $plan && ! empty( $plan['id'] ) ) {
			return (int) $plan['id'];
		}
		return (int) \WordPressistic\Memberistic\Database\Plans_Repository::create( array(
			'name'            => 'Guest Pass',
			'slug'            => self::PLAN_SLUG,
			'description'     => 'Non-member range guest pass: QR check-in + waiver on file. Hidden from public pricing.',
			'monthly_price'   => 0,
			'annual_price'    => 0,
			'included_people' => 0,
			'is_featured'     => 0,
			'sort_order'      => 98,
			'status'          => 'active',
		) );
	}

	private static function send_guest_email( $membership_id, $user, $is_new ) {
		$set_password_url = '';
		if ( $is_new ) {
			$key = get_password_reset_key( $user );
			if ( ! is_wp_error( $key ) ) {
				$set_password_url = network_site_url( 'wp-login.php?action=rp&key=' . rawurlencode( $key ) . '&login=' . rawurlencode( $user->user_login ), 'login' );
			}
		}
		$qr_url = class_exists( '\WordPressistic\Memberistic\Utilities\Verification' )
			? \WordPressistic\Memberistic\Utilities\Verification::verify_url( $user->ID ) : '';
		\WordPressistic\Memberistic\Emails\Email_Service::send_membership_email(
			(int) $membership_id,
			'guest_pass_welcome',
			array(
				'corp_set_password' => $set_password_url,
				'corp_qr_url'       => $qr_url,
				'corp_waiver_url'   => Corporate_Waiver_Service::waiver_url( $user->ID ),
			)
		);
	}

	/** Public registration form. */
	public static function shortcode( $atts = array() ) {
		$done = isset( $_GET['guest_pass'] ) ? sanitize_key( wp_unslash( $_GET['guest_pass'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		ob_start();
		if ( 'done' === $done ) {
			echo '<div class="memberistic-frontend"><p>' . esc_html__( "You're all set! Check your email for your range pass QR code and waiver link. Show the QR at the range desk on your next visit.", 'memberistic' ) . '</p></div>';
			return ob_get_clean();
		}
		?>
		<form class="memberistic-frontend memberistic-guest-pass" method="post">
			<?php wp_nonce_field( 'memberistic_guest_pass', 'memberistic_guest_nonce' ); ?>
			<p><label><?php esc_html_e( 'Full name', 'memberistic' ); ?><br><input type="text" name="guest_name" required style="width:100%;padding:10px;"></label></p>
			<p><label><?php esc_html_e( 'Email', 'memberistic' ); ?><br><input type="email" name="guest_email" required style="width:100%;padding:10px;"></label></p>
			<p><label><?php esc_html_e( 'Phone (optional)', 'memberistic' ); ?><br><input type="text" name="guest_phone" style="width:100%;padding:10px;"></label></p>
			<p><button type="submit" class="btn btn-ember"><?php esc_html_e( 'Get My Range Pass', 'memberistic' ); ?></button></p>
		</form>
		<?php
		return ob_get_clean();
	}

	/** Handle the public form submit. */
	public static function maybe_handle_submit() {
		if ( empty( $_POST['memberistic_guest_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['memberistic_guest_nonce'] ) ), 'memberistic_guest_pass' ) ) {
			return;
		}
		$res = self::create_guest(
			wp_unslash( $_POST['guest_name'] ?? '' ),
			wp_unslash( $_POST['guest_email'] ?? '' ),
			wp_unslash( $_POST['guest_phone'] ?? '' )
		);
		$redirect = add_query_arg( 'guest_pass', 'ok' === $res['status'] ? 'done' : 'error', wp_get_referer() ?: home_url( '/' ) );
		wp_safe_redirect( $redirect );
		exit;
	}
}

/**
 * Phase 6 — front-end group portal.
 *
 * Renders inside the member account dashboard (and via the
 * [memberistic_group_portal] shortcode):
 *  - Group OWNER (primary payer) AND the per-group owner_portal
 *    toggle is ON  → self-service management: group summary, seats
 *    used/available, payment status + balance, members list, an
 *    invite-member form (seat-guarded), and a "request more seats"
 *    button.
 *  - Owner with the toggle OFF, or a regular member → a compact
 *    read-only "You're part of {group}" context panel (their card,
 *    waiver, QR already live on the account page).
 *  - Nobody in a group → renders nothing.
 *
 * The admin controls the toggle per group in the Settings tab, so
 * the client decides exactly which owners get self-service.
 */
final class Corporate_Portal {

	/** Hooked to memberistic_account_dashboard_end. */
	public static function render_for_user( $user ) {
		if ( ! $user || empty( $user->ID ) ) {
			return;
		}
		echo self::build( (int) $user->ID ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/** Shortcode entry. */
	public static function shortcode( $atts = array() ) {
		if ( ! is_user_logged_in() ) {
			return '';
		}
		return self::build( get_current_user_id() );
	}

	/**
	 * Build the portal HTML for a user. Returns '' when the user is
	 * not connected to any group.
	 */
	public static function build( $user_id ) {
		$owner_group = Corporate_Groups_Repository::get_by_owner( $user_id );
		$member_row  = Corporate_Members_Repository::first_for_user( $user_id );

		// Owner with management enabled → full self-service view.
		if ( $owner_group && (int) $owner_group->owner_portal === 1 ) {
			return self::render_owner( $owner_group, $user_id );
		}
		// Owner without management, OR a linked member → context panel.
		$ctx_group = $owner_group;
		if ( ! $ctx_group && $member_row ) {
			$ctx_group = Corporate_Groups_Repository::get( (int) $member_row->group_id );
		}
		if ( $ctx_group ) {
			return self::render_member_context( $ctx_group );
		}
		return '';
	}

	private static function styles() {
		// Premium tactical-luxury panel that matches the member
		// account dashboard. Brass accent rule, fade-in entrance,
		// lifting stat cards, branded inputs + buttons. Output once
		// per page (guarded) so multiple shortcodes don't duplicate.
		if ( ! empty( $GLOBALS['memberistic_mgp_css_done'] ) ) {
			return '';
		}
		$GLOBALS['memberistic_mgp_css_done'] = true;
		return '<style>
		.mgp{position:relative;max-width:980px;margin:18px 0;background:linear-gradient(180deg,#1C212B,#161A21);border:1px solid #2A323D;border-radius:14px;padding:26px;color:#F4F4F6;box-shadow:0 20px 50px rgba(0,0,0,.35);overflow:hidden;animation:mgpIn .45s cubic-bezier(.4,0,.2,1) both;}
		.mgp::before{content:"";position:absolute;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg,#C9A84C,#E8802F);}
		@keyframes mgpIn{from{opacity:0;transform:translateY(12px);}to{opacity:1;transform:translateY(0);}}
		.mgp h3{font-family:var(--font-display,"Bebas Neue",Impact,sans-serif);font-size:26px;margin:0 0 4px;letter-spacing:.04em;color:#fff;text-transform:uppercase;}
		.mgp .sub{color:#8A95A5;font-size:13.5px;margin:0 0 18px;line-height:1.5;}
		.mgp-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:12px;margin-bottom:18px;}
		.mgp-card{background:#0F1419;border:1px solid #2A323D;border-radius:10px;padding:16px;transition:transform .2s cubic-bezier(.4,0,.2,1),border-color .2s ease;}
		.mgp-card:hover{transform:translateY(-3px);border-color:#C9A84C;}
		.mgp-card .l{font-size:10px;letter-spacing:.16em;text-transform:uppercase;color:#8A95A5;font-weight:600;}
		.mgp-card .v{font-family:var(--font-display,"Bebas Neue",Impact,sans-serif);font-size:30px;font-weight:700;margin-top:6px;color:#fff;line-height:1;}
		.mgp table{width:100%;border-collapse:separate;border-spacing:0;font-size:14px;background:#0F1419;border:1px solid #2A323D;border-radius:10px;overflow:hidden;}
		.mgp th,.mgp td{text-align:left;padding:12px 14px;border-bottom:1px solid #222a34;}
		.mgp th{color:#8A95A5;font-size:11px;letter-spacing:.12em;text-transform:uppercase;background:#11161D;}
		.mgp tbody tr{transition:background .15s ease;}
		.mgp tbody tr:hover{background:rgba(201,168,76,.05);}
		.mgp tbody tr:last-child td{border-bottom:0;}
		.mgp .badge{display:inline-block;padding:3px 11px;border-radius:999px;font-size:11px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;}
		.mgp .ok{background:rgba(157,224,91,.16);color:#9DE05B;border:1px solid rgba(157,224,91,.4);}
		.mgp .no{background:rgba(232,128,47,.15);color:#E8802F;border:1px solid rgba(232,128,47,.4);}
		.mgp form.inv{margin-top:18px;display:grid;grid-template-columns:1fr 1fr auto;gap:10px;align-items:end;padding:16px;background:#0F1419;border:1px solid #2A323D;border-radius:10px;}
		@media(max-width:640px){.mgp form.inv{grid-template-columns:1fr;}}
		.mgp .inv .l{font-size:10px;letter-spacing:.16em;text-transform:uppercase;color:#8A95A5;font-weight:600;display:block;margin-bottom:6px;}
		.mgp input{width:100%;padding:11px 12px;background:#0B0E13;border:1px solid #2A323D;border-radius:8px;color:#fff;font-size:15px;transition:border-color .15s ease,box-shadow .15s ease;}
		.mgp input:focus{outline:none;border-color:#C9A84C;box-shadow:0 0 0 3px rgba(201,168,76,.2);}
		.mgp .btn-go{background:linear-gradient(180deg,#F0922F,#E8802F);color:#1a1408;border:0;border-radius:8px;padding:13px 22px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;cursor:pointer;transition:transform .15s ease,box-shadow .15s ease,filter .15s ease;white-space:nowrap;}
		.mgp .btn-go:hover{transform:translateY(-1px);box-shadow:0 8px 20px rgba(232,128,47,.35);filter:brightness(1.05);}
		.mgp .note{margin:0 0 14px;padding:11px 14px;font-size:13.5px;color:#9DE05B;background:rgba(157,224,91,.1);border:1px solid rgba(157,224,91,.35);border-radius:8px;}
		.mgp .full{color:#E8802F;display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:16px;}
		@media(prefers-reduced-motion:reduce){.mgp,.mgp *{animation:none!important;transition:none!important;}}
		</style>';
	}

	private static function render_owner( $group, $user_id ) {
		$members = Corporate_Members_Repository::for_group( $group->id );
		$used    = count( $members );
		$seats   = (int) $group->seats_total;
		$avail   = max( 0, $seats - $used );
		$paid    = Corporate_Groups_Repository::paid_total( $group->id );
		$balance = max( 0, (float) $group->custom_price - $paid );
		$notice  = isset( $_GET['mgp_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['mgp_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		ob_start();
		echo self::styles(); // phpcs:ignore
		?>
		<div class="mgp">
			<h3><?php echo esc_html( $group->group_name ); ?></h3>
			<p class="sub"><?php esc_html_e( 'Group membership — you manage the people and seats.', 'memberistic' ); ?></p>
			<?php if ( $notice ) : ?><p class="note"><?php echo esc_html( $notice ); ?></p><?php endif; ?>
			<div class="mgp-cards">
				<div class="mgp-card"><div class="l"><?php esc_html_e( 'Seats used', 'memberistic' ); ?></div><div class="v"><?php echo esc_html( $used . ' / ' . $seats ); ?></div></div>
				<div class="mgp-card"><div class="l"><?php esc_html_e( 'Available', 'memberistic' ); ?></div><div class="v"><?php echo esc_html( $avail ); ?></div></div>
				<div class="mgp-card"><div class="l"><?php esc_html_e( 'Paid', 'memberistic' ); ?></div><div class="v">$<?php echo esc_html( number_format( $paid, 2 ) ); ?></div></div>
				<div class="mgp-card"><div class="l"><?php esc_html_e( 'Balance', 'memberistic' ); ?></div><div class="v"<?php echo $balance > 0 ? ' style="color:#E8802F"' : ''; ?>>$<?php echo esc_html( number_format( $balance, 2 ) ); ?></div></div>
			</div>

			<table>
				<thead><tr><th><?php esc_html_e( 'Member', 'memberistic' ); ?></th><th><?php esc_html_e( 'Email', 'memberistic' ); ?></th><th><?php esc_html_e( 'Waiver', 'memberistic' ); ?></th></tr></thead>
				<tbody>
				<?php if ( empty( $members ) ) : ?>
					<tr><td colspan="3"><?php esc_html_e( 'No members yet — invite your first below.', 'memberistic' ); ?></td></tr>
				<?php else : foreach ( $members as $m ) :
					$u = $m->user_id ? get_userdata( $m->user_id ) : null; ?>
					<tr>
						<td><?php echo $u ? esc_html( $u->display_name ) : '—'; ?></td>
						<td><?php echo $u ? esc_html( $u->user_email ) : '—'; ?></td>
						<td><span class="badge <?php echo 'signed' === $m->waiver_status ? 'ok' : 'no'; ?>"><?php echo esc_html( 'signed' === $m->waiver_status ? __( 'Signed', 'memberistic' ) : __( 'Pending', 'memberistic' ) ); ?></span></td>
					</tr>
				<?php endforeach; endif; ?>
				</tbody>
			</table>

			<?php if ( $avail > 0 ) : ?>
			<form class="inv" method="post" action="">
				<?php wp_nonce_field( 'mgp_invite_' . (int) $group->id, 'mgp_nonce' ); ?>
				<input type="hidden" name="mgp_action" value="invite">
				<input type="hidden" name="mgp_group" value="<?php echo (int) $group->id; ?>">
				<div><label class="l"><?php esc_html_e( 'Name', 'memberistic' ); ?></label><input type="text" name="mgp_name" required></div>
				<div><label class="l"><?php esc_html_e( 'Email', 'memberistic' ); ?></label><input type="email" name="mgp_email" required></div>
				<button type="submit" class="btn-go"><?php esc_html_e( 'Invite', 'memberistic' ); ?></button>
			</form>
			<?php else : ?>
			<p class="full"><?php esc_html_e( 'All seats are used.', 'memberistic' ); ?>
				<?php if ( (int) $group->max_future_seats > $seats ) : ?>
				<form method="post" action="" style="display:inline;">
					<?php wp_nonce_field( 'mgp_request_' . (int) $group->id, 'mgp_nonce' ); ?>
					<input type="hidden" name="mgp_action" value="request_seats">
					<input type="hidden" name="mgp_group" value="<?php echo (int) $group->id; ?>">
					<button type="submit" class="btn-go" style="margin-left:8px;"><?php esc_html_e( 'Request more seats', 'memberistic' ); ?></button>
				</form>
				<?php endif; ?>
			</p>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	private static function render_member_context( $group ) {
		ob_start();
		echo self::styles(); // phpcs:ignore
		?>
		<div class="mgp">
			<h3><?php echo esc_html( $group->group_name ); ?></h3>
			<p class="sub"><?php esc_html_e( 'You are part of this group membership. Your digital card, waiver, and check-in QR are on this page.', 'memberistic' ); ?></p>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Handle owner-initiated invite / request-seats. Owner-only,
	 * nonce-gated, seat-guarded.
	 */
	public static function maybe_handle_owner_action() {
		if ( empty( $_POST['mgp_action'] ) || empty( $_POST['mgp_nonce'] ) || ! is_user_logged_in() ) {
			return;
		}
		$action   = sanitize_key( wp_unslash( $_POST['mgp_action'] ) );
		$group_id = isset( $_POST['mgp_group'] ) ? (int) $_POST['mgp_group'] : 0;
		$group    = Corporate_Groups_Repository::get( $group_id );
		// Must be THIS group's owner AND owner portal enabled.
		if ( ! $group || (int) $group->primary_user_id !== get_current_user_id() || (int) $group->owner_portal !== 1 ) {
			return;
		}

		if ( 'invite' === $action && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mgp_nonce'] ) ), 'mgp_invite_' . $group_id ) ) {
			$res = Corporate_Member_Service::add_member(
				$group_id,
				wp_unslash( $_POST['mgp_name'] ?? '' ),
				wp_unslash( $_POST['mgp_email'] ?? '' ),
				'',
				true
			);
			$msg = 'added' === $res['status']
				? __( 'Member invited — they have an email to set up their account, waiver, and QR.', 'memberistic' )
				: $res['message'];
			self::redirect_back( $msg );
		}

		if ( 'request_seats' === $action && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mgp_nonce'] ) ), 'mgp_request_' . $group_id ) ) {
			// Notify the site admin; staff bump seats_total in the admin.
			$admin = get_option( 'admin_email' );
			$owner = wp_get_current_user();
			if ( $admin && is_email( $admin ) ) {
				$brand = function_exists( '\WordPressistic\Memberistic\memberistic_get_brand_label' )
					? \WordPressistic\Memberistic\memberistic_get_brand_label() : get_bloginfo( 'name' );
				wp_mail(
					$admin,
					/* translators: %s: the site's Memberistic brand label. */
					sprintf( __( '[%s] Group seat request', 'memberistic' ), $brand ),
					sprintf(
						__( "%1\$s (owner of group \"%2\$s\", #%3\$d) has requested more seats.\nCurrent: %4\$d seats / max %5\$d.\nReview in Memberistic → Corporate Groups.", 'memberistic' ),
						$owner->display_name,
						$group->group_name,
						$group_id,
						(int) $group->seats_total,
						(int) $group->max_future_seats
					)
				);
			}
			Corporate_Groups_Repository::log_activity( $group_id, 'seats_requested', __( 'Group owner requested more seats.', 'memberistic' ) );
			self::redirect_back( __( 'Request sent — our team will be in touch about adding seats.', 'memberistic' ) );
		}
	}

	private static function redirect_back( $msg ) {
		$url = add_query_arg( 'mgp_msg', rawurlencode( $msg ), wp_get_referer() ?: home_url( '/account/' ) );
		wp_safe_redirect( $url );
		exit;
	}
}

/**
 * Phase 7 — Corporate reports console.
 *
 * Read-only aggregates across all groups, computed live from the
 * corporate tables (+ memberistic_checkins). Gives staff a single
 * operational dashboard: active groups, seats, revenue, unpaid
 * balances, waiver-pending people, open seats, and recent QR
 * check-ins.
 */
final class Corporate_Reports {

	const PAGE = 'memberistic-corporate-reports';

	public static function menu() {
		add_submenu_page(
			'memberistic-dashboard',
			__( 'Corporate Reports', 'memberistic' ),
			__( 'Corporate Reports', 'memberistic' ),
			Corporate_Capabilities::manage_cap(),
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	public static function render() {
		if ( ! current_user_can( Corporate_Capabilities::manage_cap() ) ) {
			wp_die( esc_html__( 'You do not have permission to view corporate reports.', 'memberistic' ) );
		}
		global $wpdb;
		$g  = Corporate_Groups_Repository::table();
		$gm = Corporate_Members_Repository::table();
		$gp = Corporate_Groups_Repository::payments_table();
		$ck = $wpdb->prefix . 'memberistic_checkins';
		$group_url = function ( $id, $tab = 'overview' ) {
			return admin_url( 'admin.php?page=' . Corporate_Admin::PAGE . '&view=single&id=' . (int) $id . '&tab=' . $tab );
		};

		// --- Headline aggregates ---
		$total_groups   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$g}" );
		$active_groups  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$g} WHERE status = %s", 'active' ) );
		$total_members  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$gm} WHERE status != 'removed'" );
		$total_revenue  = (float) $wpdb->get_var( "SELECT COALESCE(SUM(amount),0) FROM {$gp} WHERE payment_status = 'completed'" );
		$waiver_pending = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$gm} WHERE status != 'removed' AND waiver_status != 'signed'" );

		// --- Per-group rows for the tables ---
		$groups = $wpdb->get_results( "SELECT * FROM {$g} ORDER BY created_at DESC LIMIT 500" );

		$open_seats = array();   // groups with seats available
		$unpaid     = array();   // groups with balance due
		foreach ( (array) $groups as $row ) {
			$used = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$gm} WHERE group_id = %d AND status != 'removed'", $row->id ) );
			$paid = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount),0) FROM {$gp} WHERE group_id = %d AND payment_status = 'completed'", $row->id ) );
			$row->_used = $used;
			$row->_paid = $paid;
			$row->_avail = max( 0, (int) $row->seats_total - $used );
			$row->_balance = max( 0, (float) $row->custom_price - $paid );
			if ( $row->_avail > 0 && 'cancelled' !== $row->status ) {
				$open_seats[] = $row;
			}
			if ( $row->_balance > 0 && 'comped' !== $row->payment_status && 'cancelled' !== $row->status ) {
				$unpaid[] = $row;
			}
		}

		// --- Waiver-pending people ---
		$pending_people = $wpdb->get_results(
			"SELECT m.user_id, m.group_id, m.waiver_status FROM {$gm} m WHERE m.status != 'removed' AND m.waiver_status != 'signed' ORDER BY m.group_id ASC LIMIT 100"
		);

		// --- Recent check-ins for corporate/guest members ---
		$recent = $wpdb->get_results(
			"SELECT c.checked_in_at, c.checkin_type, c.membership_id, c.checked_in_by
			 FROM {$ck} c ORDER BY c.checked_in_at DESC LIMIT 25"
		);
		?>
		<div class="wrap memberistic-corp-wrap">
			<h1><?php esc_html_e( 'Corporate Reports', 'memberistic' ); ?></h1>

			<div class="memberistic-corp-grid" style="margin-top:16px;">
				<div class="memberistic-corp-card"><div class="description" style="text-transform:uppercase;letter-spacing:.08em;font-size:11px;"><?php esc_html_e( 'Active groups', 'memberistic' ); ?></div><div style="font-size:26px;font-weight:700;margin-top:6px;"><?php echo esc_html( $active_groups . ' / ' . $total_groups ); ?></div></div>
				<div class="memberistic-corp-card"><div class="description" style="text-transform:uppercase;letter-spacing:.08em;font-size:11px;"><?php esc_html_e( 'Total members', 'memberistic' ); ?></div><div style="font-size:26px;font-weight:700;margin-top:6px;"><?php echo (int) $total_members; ?></div></div>
				<div class="memberistic-corp-card"><div class="description" style="text-transform:uppercase;letter-spacing:.08em;font-size:11px;"><?php esc_html_e( 'Group revenue', 'memberistic' ); ?></div><div style="font-size:26px;font-weight:700;margin-top:6px;">$<?php echo esc_html( number_format( $total_revenue, 2 ) ); ?></div></div>
				<div class="memberistic-corp-card"><div class="description" style="text-transform:uppercase;letter-spacing:.08em;font-size:11px;"><?php esc_html_e( 'Waivers pending', 'memberistic' ); ?></div><div style="font-size:26px;font-weight:700;margin-top:6px;<?php echo $waiver_pending > 0 ? 'color:#b5611f;' : ''; ?>"><?php echo (int) $waiver_pending; ?></div></div>
			</div>

			<h2 style="margin-top:28px;"><?php esc_html_e( 'Unpaid balances', 'memberistic' ); ?></h2>
			<table class="wp-list-table widefat fixed striped">
				<thead><tr><th><?php esc_html_e( 'Group', 'memberistic' ); ?></th><th><?php esc_html_e( 'Price', 'memberistic' ); ?></th><th><?php esc_html_e( 'Paid', 'memberistic' ); ?></th><th><?php esc_html_e( 'Balance', 'memberistic' ); ?></th></tr></thead>
				<tbody>
				<?php if ( empty( $unpaid ) ) : ?>
					<tr><td colspan="4"><?php esc_html_e( 'All groups are paid in full. 🎉', 'memberistic' ); ?></td></tr>
				<?php else : foreach ( $unpaid as $row ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( $group_url( $row->id, 'payments' ) ); ?>"><?php echo esc_html( $row->group_name ?: ( '#' . $row->id ) ); ?></a></td>
						<td>$<?php echo esc_html( number_format( (float) $row->custom_price, 2 ) ); ?></td>
						<td>$<?php echo esc_html( number_format( $row->_paid, 2 ) ); ?></td>
						<td style="color:#b5611f;font-weight:600;">$<?php echo esc_html( number_format( $row->_balance, 2 ) ); ?></td>
					</tr>
				<?php endforeach; endif; ?>
				</tbody>
			</table>

			<h2 style="margin-top:28px;"><?php esc_html_e( 'Groups with open seats', 'memberistic' ); ?></h2>
			<table class="wp-list-table widefat fixed striped">
				<thead><tr><th><?php esc_html_e( 'Group', 'memberistic' ); ?></th><th><?php esc_html_e( 'Used', 'memberistic' ); ?></th><th><?php esc_html_e( 'Total', 'memberistic' ); ?></th><th><?php esc_html_e( 'Available', 'memberistic' ); ?></th></tr></thead>
				<tbody>
				<?php if ( empty( $open_seats ) ) : ?>
					<tr><td colspan="4"><?php esc_html_e( 'No groups have open seats.', 'memberistic' ); ?></td></tr>
				<?php else : foreach ( $open_seats as $row ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( $group_url( $row->id, 'members' ) ); ?>"><?php echo esc_html( $row->group_name ?: ( '#' . $row->id ) ); ?></a></td>
						<td><?php echo (int) $row->_used; ?></td>
						<td><?php echo (int) $row->seats_total; ?></td>
						<td style="color:#5a8a2c;font-weight:600;"><?php echo (int) $row->_avail; ?></td>
					</tr>
				<?php endforeach; endif; ?>
				</tbody>
			</table>

			<h2 style="margin-top:28px;"><?php esc_html_e( 'Waiver-pending members', 'memberistic' ); ?></h2>
			<table class="wp-list-table widefat fixed striped">
				<thead><tr><th><?php esc_html_e( 'Member', 'memberistic' ); ?></th><th><?php esc_html_e( 'Email', 'memberistic' ); ?></th><th><?php esc_html_e( 'Group', 'memberistic' ); ?></th></tr></thead>
				<tbody>
				<?php if ( empty( $pending_people ) ) : ?>
					<tr><td colspan="3"><?php esc_html_e( 'Every group member has a signed waiver. 🎉', 'memberistic' ); ?></td></tr>
				<?php else : foreach ( $pending_people as $p ) :
					$u  = $p->user_id ? get_userdata( (int) $p->user_id ) : null;
					$gr = Corporate_Groups_Repository::get( (int) $p->group_id );
				?>
					<tr>
						<td><?php echo $u ? esc_html( $u->display_name ) : '—'; ?></td>
						<td><?php echo $u ? esc_html( $u->user_email ) : '—'; ?></td>
						<td><a href="<?php echo esc_url( $group_url( (int) $p->group_id, 'waivers' ) ); ?>"><?php echo $gr ? esc_html( $gr->group_name ) : ( '#' . (int) $p->group_id ); ?></a></td>
					</tr>
				<?php endforeach; endif; ?>
				</tbody>
			</table>

			<h2 style="margin-top:28px;"><?php esc_html_e( 'Recent check-ins', 'memberistic' ); ?></h2>
			<table class="wp-list-table widefat fixed striped">
				<thead><tr><th><?php esc_html_e( 'When', 'memberistic' ); ?></th><th><?php esc_html_e( 'Type', 'memberistic' ); ?></th><th><?php esc_html_e( 'By staff', 'memberistic' ); ?></th></tr></thead>
				<tbody>
				<?php if ( empty( $recent ) ) : ?>
					<tr><td colspan="3"><?php esc_html_e( 'No check-ins recorded yet.', 'memberistic' ); ?></td></tr>
				<?php else : foreach ( $recent as $c ) :
					$staff = $c->checked_in_by ? get_userdata( (int) $c->checked_in_by ) : null;
				?>
					<tr>
						<td><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' g:i a', $c->checked_in_at ) ); ?></td>
						<td><?php echo esc_html( ucwords( str_replace( '_', ' ', (string) $c->checkin_type ) ) ); ?></td>
						<td><?php echo $staff ? esc_html( $staff->display_name ) : '—'; ?></td>
					</tr>
				<?php endforeach; endif; ?>
				</tbody>
			</table>

			<p class="description" style="margin-top:18px;"><?php esc_html_e( 'All membership revenue figures here come from Memberistic (the single source of truth) — not WooCommerce product sales.', 'memberistic' ); ?></p>
		</div>
		<?php
	}
}
