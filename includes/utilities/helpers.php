<?php
/**
 * General helpers.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get a plugin setting.
 *
 * @param string $key     Setting key.
 * @param mixed  $default Default value.
 */
function memberistic_get_setting( $key, $default = null ) {
	// Constant overrides for sensitive secrets. Defining any of these in
	// wp-config.php (recommended for live keys) takes precedence over the
	// stored option, and refuse-overwrite logic in the settings save +
	// REST update paths refuses to clobber the option while a constant
	// is set. Pattern: define( 'MEMBERISTIC_STRIPE_LIVE_SECRET_KEY', '...' );
	$constant_map = array(
		'stripe_test_secret_key'  => 'MEMBERISTIC_STRIPE_TEST_SECRET_KEY',
		'stripe_live_secret_key'  => 'MEMBERISTIC_STRIPE_LIVE_SECRET_KEY',
		'stripe_webhook_secret'   => 'MEMBERISTIC_STRIPE_WEBHOOK_SECRET',
	);
	if ( isset( $constant_map[ $key ] ) && defined( $constant_map[ $key ] ) ) {
		$const_val = constant( $constant_map[ $key ] );
		if ( is_string( $const_val ) && '' !== $const_val ) {
			return $const_val;
		}
	}

	$settings = get_option( 'memberistic_settings', array() );

	if ( ! is_array( $settings ) ) {
		return $default;
	}

	return array_key_exists( $key, $settings ) ? $settings[ $key ] : $default;
}

/**
 * The list of secret-setting keys that should never be returned in
 * plain text via the REST settings endpoint, and whose option value
 * should be refused on save if a wp-config.php constant is in force.
 *
 * Filter via memberistic_secret_setting_keys.
 */
function memberistic_secret_setting_keys() {
	return apply_filters(
		'memberistic_secret_setting_keys',
		array(
			'stripe_test_secret_key',
			'stripe_live_secret_key',
			'stripe_webhook_secret',
		)
	);
}

/**
 * Whether a given setting key is currently locked by a wp-config.php constant.
 */
function memberistic_setting_is_locked_by_constant( $key ) {
	$constant_map = array(
		'stripe_test_secret_key' => 'MEMBERISTIC_STRIPE_TEST_SECRET_KEY',
		'stripe_live_secret_key' => 'MEMBERISTIC_STRIPE_LIVE_SECRET_KEY',
		'stripe_webhook_secret'  => 'MEMBERISTIC_STRIPE_WEBHOOK_SECRET',
	);
	if ( ! isset( $constant_map[ $key ] ) ) {
		return false;
	}
	$name = $constant_map[ $key ];
	if ( ! defined( $name ) ) {
		return false;
	}
	$val = constant( $name );
	return is_string( $val ) && '' !== $val;
}

/**
 * Mask a secret for display ("sk_live_***1234").
 */
function memberistic_mask_secret( $value ) {
	if ( ! is_string( $value ) || '' === $value ) {
		return '';
	}
	$len = strlen( $value );
	if ( $len <= 8 ) {
		return str_repeat( '*', $len );
	}
	$prefix_len = $len > 12 ? 7 : 3;
	return substr( $value, 0, $prefix_len ) . str_repeat( '*', 4 ) . substr( $value, -4 );
}

/**
 * Customer-facing business brand — used in emails, waivers, PDFs and the member
 * portal. This is NOT the admin menu label; the admin menu always reads
 * "Memberistic" (see Admin\Admin_Menu::register).
 *
 * Resolution order, most specific first:
 *   1. `business_name` — the trading name staff enter for customer documents.
 *      Preferred, because this is what a customer should see on a receipt or
 *      a waiver.
 *   2. `brand_label` — a legacy/shorthand override.
 *   3. The WP site name, so a fresh install never exposes the plugin author's
 *      own brand.
 *
 * Previously this read `brand_label` first. Because the admin menu used the
 * same value, sites shortened it to fit the sidebar and that abbreviation then
 * went out on every membership email and waiver. Splitting the two lets the
 * menu stay "Memberistic" while customers see the full business name.
 */
function memberistic_get_brand_label() {
	$business = trim( (string) memberistic_get_setting( 'business_name', '' ) );
	$legacy   = trim( (string) memberistic_get_setting( 'brand_label', '' ) );

	$label = $business !== '' ? $business : ( $legacy !== '' ? $legacy : (string) get_bloginfo( 'name' ) );

	return apply_filters( 'memberistic_brand_label', $label );
}

/**
 * Resolve a frontend template, letting the theme override it.
 *
 * Lookup order, first hit wins:
 *
 *   1. child-theme/memberistic/<template>
 *   2. parent-theme/memberistic/<template>
 *   3. the plugin's own templates/<template>
 *
 * A theme copy is used as-is, so an override is a full replacement rather
 * than a patch — which is what makes it safe to keep across plugin updates.
 *
 * The resolved path is validated to sit inside one of those three roots
 * before it is returned. The filter below is a developer API, but a filter
 * that could be made to return an arbitrary path would turn a template call
 * into an arbitrary-file include, so the result is checked rather than
 * trusted.
 *
 * @since 2.0.0
 *
 * @param string $template Template filename, e.g. 'account.php'.
 * @return string Absolute path to the template, or '' when none exists.
 */
function memberistic_locate_template( $template ) {
	$template = ltrim( (string) $template, '/\\' );

	// Reject traversal outright rather than trying to normalize it away.
	if ( '' === $template || false !== strpos( $template, '..' ) || ! preg_match( '/^[a-z0-9_\-\/]+\.php$/i', $template ) ) {
		return '';
	}

	$roots = array(
		trailingslashit( get_stylesheet_directory() ) . 'memberistic/',
		trailingslashit( get_template_directory() ) . 'memberistic/',
		MEMBERISTIC_PATH . 'templates/',
	);

	$found = '';

	foreach ( $roots as $root ) {
		$candidate = $root . $template;

		if ( is_readable( $candidate ) ) {
			$found = $candidate;
			break;
		}
	}

	/**
	 * Filters the resolved path of a Memberistic frontend template.
	 *
	 * The returned path must resolve inside the child theme's, parent
	 * theme's, or plugin's template directory; anything else is discarded
	 * and the unfiltered path is used instead.
	 *
	 * @since 2.0.0
	 *
	 * @param string $found    Resolved absolute path, or '' when not found.
	 * @param string $template Requested template filename.
	 */
	$filtered = (string) apply_filters( 'memberistic_locate_template', $found, $template );

	if ( $filtered === $found ) {
		return $found;
	}

	$real = realpath( $filtered );

	if ( false === $real ) {
		return $found;
	}

	$real = wp_normalize_path( $real );

	foreach ( $roots as $root ) {
		$root_real = realpath( $root );

		if ( false !== $root_real && 0 === strpos( $real, wp_normalize_path( trailingslashit( $root_real ) ) ) ) {
			return $filtered;
		}
	}

	return $found;
}

/**
 * Render a frontend template and return its output.
 *
 * @since 2.0.0
 *
 * @param string $template Template filename, e.g. 'account.php'.
 * @param array  $vars     Variables extracted into the template's scope.
 * @return string Rendered output, or '' when the template is missing.
 */
function memberistic_get_template( $template, array $vars = array() ) {
	$path = memberistic_locate_template( $template );

	if ( '' === $path ) {
		return '';
	}

	// phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- template scope, keys are controlled by the caller.
	extract( $vars, EXTR_SKIP );

	ob_start();
	require $path;

	return (string) ob_get_clean();
}

/**
 * Human-readable member ID shown on the digital member card.
 *
 * Format: `<PREFIX>-<YEAR>-<PADDED ID>` — e.g. MEM-2026-0042. The year is
 * the membership start year, so the id stays stable for the life of the
 * membership, and the numeric part is the membership row id zero-padded to
 * at least four digits.
 *
 * This is a display label only. It is never stored, never used as a lookup
 * key, and never assumed unique across sites — check-in and verification
 * resolve on the membership UUID, not this string. Changing the prefix is
 * therefore safe at any time and does not invalidate existing records.
 *
 * @since 2.0.0
 *
 * @param array $membership Membership row (needs `id`, optionally `start_date`).
 * @return string Formatted member ID, or '' when the row has no id.
 */
function memberistic_member_display_id( $membership ) {
	$id = isset( $membership['id'] ) ? absint( $membership['id'] ) : 0;

	if ( ! $id ) {
		return '';
	}

	/**
	 * Filters the prefix used on the printed/digital member card ID.
	 *
	 * @since 2.0.0
	 *
	 * @param string $prefix     Default 'MEM'.
	 * @param array  $membership Membership row.
	 */
	$prefix = (string) apply_filters( 'memberistic_member_id_prefix', 'MEM', $membership );
	$prefix = preg_replace( '/[^A-Za-z0-9]/', '', $prefix );
	$prefix = '' !== $prefix ? strtoupper( $prefix ) : 'MEM';

	$start = ! empty( $membership['start_date'] ) ? strtotime( (string) $membership['start_date'] ) : false;
	$year  = $start ? gmdate( 'Y', $start ) : gmdate( 'Y' );

	$formatted = $prefix . '-' . $year . '-' . str_pad( (string) $id, 4, '0', STR_PAD_LEFT );

	/**
	 * Filters the fully formatted member card ID.
	 *
	 * @since 2.0.0
	 *
	 * @param string $formatted  Formatted member ID.
	 * @param array  $membership Membership row.
	 */
	return (string) apply_filters( 'memberistic_member_display_id', $formatted, $membership );
}

/**
 * Resolve a mapped frontend page URL with an optional branded slug fallback.
 *
 * @param string $setting_key Page ID setting key.
 * @param string $fallback_slug Optional page slug to find if the setting is empty.
 * @param string $fallback_url Final fallback URL.
 */
function memberistic_get_page_url( $setting_key, $fallback_slug = '', $fallback_url = '' ) {
	$clean   = memberistic_clean_page_slug( $setting_key );
	$page_id = absint( memberistic_get_setting( $setting_key, 0 ) );

	// 1) Honor an explicitly configured page — but ONLY if it's published, has
	//    a pretty permalink, and isn't a legacy/branded slug. A page id that
	//    resolves to ?page_id=… (unpublished or a stale page) is rejected so we
	//    fall through to the clean slug instead of emitting an ugly URL.
	if ( $page_id && 'publish' === get_post_status( $page_id ) ) {
		$page    = get_post( $page_id );
		$url     = (string) get_permalink( $page_id );
		$legacy  = $page && in_array( $page->post_name, memberistic_legacy_page_slugs(), true );
		$ugly    = ( false !== strpos( $url, 'page_id=' ) || false !== strpos( $url, '?p=' ) );
		if ( '' !== $url && ! $legacy && ! $ugly ) {
			return $url;
		}
	}

	// 2) Resolve by page slug. Prefer short, unbranded slugs (/checkout/,
	//    /memberships/, /renewal/, /thank-you/, /payment-failed/, /account/),
	//    so try the clean slug for this setting FIRST, then the caller's slug,
	//    then a prefix-stripped variant. Only published pages with a pretty
	//    permalink qualify.
	$candidates = array();
	if ( $clean ) {
		$candidates[] = $clean;
	}
	if ( $fallback_slug ) {
		$candidates[] = sanitize_title( $fallback_slug );
		$stripped     = preg_replace( '/^(memberistic|membership)-/', '', sanitize_title( $fallback_slug ) );
		if ( $stripped && $stripped !== sanitize_title( $fallback_slug ) ) {
			$candidates[] = $stripped;
		}
	}
	foreach ( array_unique( array_filter( $candidates ) ) as $cand ) {
		$page = get_page_by_path( $cand );
		if ( $page && 'publish' === get_post_status( $page ) ) {
			$url = (string) get_permalink( $page );
			if ( '' !== $url && false === strpos( $url, 'page_id=' ) && false === strpos( $url, '?p=' ) ) {
				return $url;
			}
		}
	}

	// 3) Nothing resolved to a real published page. Respect the caller's
	//    fallback URL (e.g. Stripe success/cancel pass home_url('/')). We do
	//    NOT synthesize home_url('/<clean>/') here: step 2 already verified the
	//    clean page exists, so if we got this far that path would 404.
	return $fallback_url;
}

/**
 * Map a page-ID setting key to its clean (unbranded) page slug.
 * Filterable so other sites can override the slug scheme.
 *
 * @param string $setting_key e.g. checkout_page_id
 * @return string Clean slug, or '' when unmapped.
 */
function memberistic_clean_page_slug( $setting_key ) {
	$map = array(
		'plans_page_id'          => 'memberships',
		'checkout_page_id'       => 'checkout',
		'renewal_page_id'        => 'renewal',
		'thank_you_page_id'      => 'thank-you',
		'failed_payment_page_id' => 'payment-failed',
		'account_page_id'        => 'account',
		'login_page_id'          => 'login',
		'booking_page_id'        => 'book',
	);
	$map = apply_filters( 'memberistic_clean_page_slugs', $map );
	return isset( $map[ $setting_key ] ) ? $map[ $setting_key ] : '';
}

/**
 * Common old membership slugs that should not win over branded Memberistic pages.
 *
 * @return string[]
 */
function memberistic_legacy_page_slugs() {
	return array(
		// Pre-Memberistic plugin pages
		'memberships',
		'memberships-2',
		'membership-checkout',
		'membership-checkout-2',
		'my-membership',
		'my-membership-2',
		'membership-renewal',
		'membership-renewal-2',
		'membership-login',
		'membership-login-2',
		'membership-thank-you',
		'membership-thank-you-2',
		'membership-payment-failed',
		'membership-payment-failed-2',
		// Memberistic-namespaced auto-created pages from older installs.
		// Treat as legacy so the page-URL helper prefers the modern
		// branded slugs (account, book-a-lane, etc.) and customers
		// never get redirected to URLs containing "memberistic-".
		'memberistic-account',
		'memberistic-account-2',
		'memberistic-memberships',
		'memberistic-memberships-2',
		'memberistic-checkout',
		'memberistic-checkout-2',
		'memberistic-renewal',
		'memberistic-renewal-2',
		'memberistic-login',
		'memberistic-login-2',
		'memberistic-thank-you',
		'memberistic-thank-you-2',
		'memberistic-payment-failed',
		'memberistic-payment-failed-2',
		'memberistic-staff-dashboard',
		'memberistic-staff-dashboard-2',
	);
}

/**
 * Build an admin URL for plugin screens.
 *
 * @param string               $page Page slug.
 * @param array<string, mixed> $args Query args.
 */
function memberistic_admin_url( $page, $args = array() ) {
	$args = array_merge( array( 'page' => $page ), $args );
	return add_query_arg( $args, admin_url( 'admin.php' ) );
}

/**
 * One-time upgrade notice: the included-plans allowlist needs setting.
 *
 * 2.0.0 stopped shipping a built-in list of plans whose membership includes
 * bookings at no charge. On a site upgrading from 1.x that never set the
 * option explicitly, that means included bookings stop being free until an
 * operator says which plans qualify.
 *
 * The alternative — guessing the list during upgrade — risks giving away
 * paid inventory on a site whose plans differ, so the upgrade fails closed
 * and says so here instead of failing open quietly. See
 * Installer::preserve_pre_2_0_defaults().
 *
 * Clears itself as soon as the option is set, and can be dismissed.
 *
 * @since 2.0.0
 *
 * @return void
 */
function memberistic_entitlement_review_notice() {
	if ( 'yes' !== get_option( 'memberistic_entitlement_needs_review', '' ) ) {
		return;
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// Resolved the moment the option exists — no dismissal needed.
	if ( false !== get_option( 'memberistic_lane_included_plan_slugs', false ) ) {
		delete_option( 'memberistic_entitlement_needs_review' );
		return;
	}

	if ( ! empty( $_GET['memberistic_dismiss_entitlement_review'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified below.
		&& isset( $_GET['_wpnonce'] )
		&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'memberistic_dismiss_entitlement_review' ) ) {
		delete_option( 'memberistic_entitlement_needs_review' );
		return;
	}

	$dismiss_url = wp_nonce_url(
		add_query_arg( 'memberistic_dismiss_entitlement_review', '1' ),
		'memberistic_dismiss_entitlement_review'
	);

	printf(
		'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s</p><p><a class="button" href="%3$s">%4$s</a> <a href="%5$s">%6$s</a></p></div>',
		esc_html__( 'Memberistic 2.0.0:', 'memberistic' ),
		esc_html__( 'Plans no longer include bookings at no charge by default. Until you choose which plans qualify, members will be charged the standard booking price. This does not affect memberships, payments, or waivers.', 'memberistic' ),
		esc_url( admin_url( 'admin.php?page=memberistic-plans' ) ),
		esc_html__( 'Review plans', 'memberistic' ),
		esc_url( $dismiss_url ),
		esc_html__( 'Dismiss', 'memberistic' )
	);
}

/**
 * Render mapped admin notice messages, plus any standing upgrade notices.
 */
function memberistic_admin_notices() {
	memberistic_entitlement_review_notice();

	if ( empty( $_GET['memberistic_notice'] ) ) {
		return;
	}

	$notice = sanitize_key( wp_unslash( $_GET['memberistic_notice'] ) );
	$type   = isset( $_GET['memberistic_notice_type'] ) ? sanitize_key( wp_unslash( $_GET['memberistic_notice_type'] ) ) : 'success';
	$type   = in_array( $type, array( 'success', 'error', 'warning', 'info' ), true ) ? $type : 'success';

	$messages = array(
		'plan_saved'          => __( 'Plan saved successfully.', 'memberistic' ),
		'plan_deleted'        => __( 'Plan deleted successfully.', 'memberistic' ),
		'plan_deactivated'    => __( 'Plan deactivated successfully.', 'memberistic' ),
		'plan_delete_blocked' => __( 'This plan has active memberships and cannot be deleted.', 'memberistic' ),
		'plan_not_found'      => __( 'The requested plan was not found.', 'memberistic' ),
		'invalid_request'     => __( 'The request could not be verified.', 'memberistic' ),
		'settings_saved'      => __( 'Settings saved successfully.', 'memberistic' ),
		'member_saved'        => __( 'Membership saved successfully.', 'memberistic' ),
		'member_not_found'    => __( 'The requested membership was not found.', 'memberistic' ),
		'member_save_failed'  => __( 'Membership could not be saved.', 'memberistic' ),
		'stripe_cancel_failed'=> __( 'The membership was NOT cancelled: Stripe could not stop the subscription, so billing would have continued. A retry is queued and the cancellation will complete automatically once Stripe confirms — see the notice on this screen for details.', 'memberistic' ),
		'person_added'        => __( 'Linked member added successfully.', 'memberistic' ),
		'person_limit_reached'=> __( 'This membership plan has reached its included people limit.', 'memberistic' ),
		'payment_added'       => __( 'Payment record added successfully.', 'memberistic' ),
		'checkin_added'       => __( 'Check-in recorded successfully.', 'memberistic' ),
		'note_added'          => __( 'Staff note added successfully.', 'memberistic' ),
		'pages_created'       => __( 'Required Memberistic pages were created or mapped successfully.', 'memberistic' ),
		'pages_remapped'      => __( 'Branded Memberistic pages were created and mapped successfully.', 'memberistic' ),
	);

	if ( 'logins_repaired' === $notice ) {
		$created = isset( $_GET['memberistic_created'] ) ? absint( wp_unslash( $_GET['memberistic_created'] ) ) : 0;
		$linked  = isset( $_GET['memberistic_linked'] ) ? absint( wp_unslash( $_GET['memberistic_linked'] ) ) : 0;
		$emailed = isset( $_GET['memberistic_emailed'] ) ? absint( wp_unslash( $_GET['memberistic_emailed'] ) ) : 0;
		if ( $created || $linked ) {
			$message = sprintf(
				/* translators: 1: accounts created, 2: set-password emails sent, 3: accounts linked */
				__( 'Member logins repaired: %1$d new account(s) created, %2$d set-password email(s) sent, %3$d existing account(s) linked.', 'memberistic' ),
				$created,
				$emailed,
				$linked
			);
		} else {
			$message = __( 'All members already have a login — nothing to repair.', 'memberistic' );
		}
	} else {
		$message = $messages[ $notice ] ?? __( 'Memberistic notice.', 'memberistic' );
	}

	printf(
		'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
		esc_attr( $type ),
		esc_html( $message )
	);
}

/**
 * Convert newline benefits to JSON.
 *
 * @param string $raw Raw textarea content.
 */
function memberistic_benefits_to_json( $raw ) {
	$lines = array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $raw ) ?: array() ) );
	return wp_json_encode( array_values( $lines ) ) ?: '[]';
}

/**
 * Decode benefits JSON to lines.
 *
 * @param mixed $raw Raw JSON.
 */
function memberistic_json_to_lines( $raw ) {
	$decoded = json_decode( (string) $raw, true );

	if ( ! is_array( $decoded ) ) {
		return '';
	}

	return implode( "\n", array_map( 'strval', $decoded ) );
}

/**
 * Return allowed membership statuses.
 */
function memberistic_membership_statuses() {
	return array( 'pending', 'active', 'past_due', 'expired', 'cancelled', 'paused', 'comped', 'trial', 'suspended', 'needs_review' );
}

/**
 * Return allowed waiver statuses.
 */
function memberistic_waiver_statuses() {
	return array( 'missing', 'signed', 'expired', 'needs_review', 'rejected' );
}
