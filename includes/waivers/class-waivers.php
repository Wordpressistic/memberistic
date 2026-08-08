<?php
/**
 * Waivers — fully dynamic, all-member electronic waiver system.
 *
 * Generalises the corporate waiver engine to EVERY member (not just group
 * members). One tokenised public page lets anyone sign from any device with
 * no login; signatures are stored on the canonical memberistic_people rows
 * (waiver_status / waiver_signed_at / waiver_expires_at), mirrored onto
 * corporate group_members when the corporate module is present, and expire
 * automatically after a configurable validity window.
 *
 * Surfaces:
 *  - Public sign page:        GET /?memberistic_waiver=TOKEN
 *  - Member account CTA:      Waiver_Service::waiver_url( $user_id )
 *  - Email merge tag:         {waiver_url}
 *  - Admin console:           Memberistic → Waivers (bulk mark, email, settings)
 *  - Daily auto-expiry:       Waiver_Service::expire_due() (via Scheduler)
 *
 * Settings (options):
 *  - memberistic_waiver_text          Editable waiver body.
 *  - memberistic_waiver_validity_days Validity window in days (default 365).
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic\Waivers;

use WordPressistic\Memberistic\Database\Activity_Repository;
use WordPressistic\Memberistic\Database\Memberships_Repository;
use WordPressistic\Memberistic\Database\People_Repository;
use WordPressistic\Memberistic\Emails\Email_Service;
use function WordPressistic\Memberistic\memberistic_current_user_can;
use function WordPressistic\Memberistic\memberistic_get_brand_label;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Canonical waiver engine. Static, stateless.
 */
final class Waiver_Service {

	/** Per-user token meta key. Shared with the legacy corporate flow so
	 *  any waiver links already emailed keep resolving. */
	const TOKEN_META = 'memberistic_waiver_token';

	/**
	 * When the current token was minted (unix timestamp, as user meta).
	 * Used to bound how long a self-serve signing link stays valid — see
	 * get_or_create_token()/user_by_token().
	 */
	const TOKEN_ISSUED_META = 'memberistic_waiver_token_issued_at';

	/**
	 * How many days a minted signing token/link stays valid before it is
	 * silently rotated on next use. The token used to be permanent (minted
	 * once per user, never expiring) — anyone who obtained a copy of an
	 * old emailed/SMS'd link (forwarded mail, shared device, browser
	 * history) could sign as that member indefinitely. Filterable.
	 */
	const DEFAULT_TOKEN_VALIDITY_DAYS = 60;

	/** Public query var. */
	const QUERY = 'memberistic_waiver';

	/** Default validity window (days). */
	const DEFAULT_VALIDITY = 365;

	/**
	 * Validity window in days. Admin-configurable, filterable.
	 */
	public static function validity_days() {
		$days = (int) get_option( 'memberistic_waiver_validity_days', self::DEFAULT_VALIDITY );
		if ( $days <= 0 ) {
			$days = self::DEFAULT_VALIDITY;
		}
		return (int) apply_filters( 'memberistic_waiver_validity_days', $days );
	}

	/**
	 * The waiver body. Admin-editable via the Waivers settings, filterable.
	 * NOT legal advice — the range should have counsel review the final text.
	 */
	public static function waiver_text() {
		// The versions table is the source of truth; the legacy option is a
		// synced fallback (and the seed for version 1 on migration).
		if ( class_exists( '\WordPressistic\Memberistic\Waivers\Waiver_Versions' ) ) {
			$ver = Waiver_Versions::current();
			if ( $ver && '' !== trim( (string) $ver['body'] ) ) {
				return (string) apply_filters( 'memberistic_waiver_text', (string) $ver['body'] );
			}
		}
		$saved = (string) get_option( 'memberistic_waiver_text', '' );
		if ( '' === trim( $saved ) ) {
			$brand = memberistic_get_brand_label();
			$saved = sprintf(
				/* translators: 1,2: business/brand name */
				__( 'I acknowledge that the use of firearms and the shooting range at %1$s involves inherent risks. I confirm I am legally permitted to handle firearms, will follow all posted range rules and staff instructions at all times, and assume responsibility for my own safety and conduct on the premises. I release %2$s, its owners, staff, and affiliates from liability for injury or loss arising from my voluntary participation, to the extent permitted by law.', 'memberistic' ),
				$brand,
				$brand
			);
		}
		return (string) apply_filters( 'memberistic_waiver_text', $saved );
	}

	/**
	 * Days a minted token/link stays valid before rotating automatically.
	 */
	public static function token_validity_days() {
		return (int) apply_filters( 'memberistic_waiver_token_validity_days', self::DEFAULT_TOKEN_VALIDITY_DAYS );
	}

	public static function get_or_create_token( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return '';
		}
		$t         = (string) get_user_meta( $user_id, self::TOKEN_META, true );
		$issued_at = (int) get_user_meta( $user_id, self::TOKEN_ISSUED_META, true );
		$max_age   = self::token_validity_days() * DAY_IN_SECONDS;
		$expired   = $max_age > 0 && $issued_at > 0 && ( time() - $issued_at ) > $max_age;

		if ( '' === $t || 0 === $issued_at || $expired ) {
			$t = wp_generate_password( 40, false, false );
			update_user_meta( $user_id, self::TOKEN_META, $t );
			update_user_meta( $user_id, self::TOKEN_ISSUED_META, time() );
		}
		return $t;
	}

	/**
	 * Force-rotate a member's signing token, invalidating any previously
	 * issued link immediately. Used when staff explicitly resend a link
	 * (so a stale/possibly-shared old link stops working) and after a
	 * waiver is completed (the link that just signed it should not remain
	 * a standing "sign as this member" credential).
	 */
	public static function rotate_token( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return '';
		}
		$t = wp_generate_password( 40, false, false );
		update_user_meta( $user_id, self::TOKEN_META, $t );
		update_user_meta( $user_id, self::TOKEN_ISSUED_META, time() );
		return $t;
	}

	public static function waiver_url( $user_id ) {
		$t = self::get_or_create_token( $user_id );
		return $t ? add_query_arg( self::QUERY, $t, home_url( '/' ) ) : home_url( '/account/' );
	}

	public static function user_by_token( $token ) {
		global $wpdb;
		$token = sanitize_text_field( (string) $token );
		if ( '' === $token ) {
			return 0;
		}
		$uid = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
				self::TOKEN_META,
				$token
			)
		);
		if ( ! $uid ) {
			return 0;
		}

		// Reject an expired token exactly like an unrecognized one — the
		// caller already renders "invalid or has expired" either way, so a
		// leaked old link stops working silently rather than remaining a
		// standing bearer credential for this member.
		//
		// Tokens minted before this validity window existed have no
		// issued-at timestamp. Rejecting those outright would instantly
		// invalidate every link already emailed/texted to members on
		// deploy day, so a missing timestamp is backfilled to "now" —
		// granting one full fresh window — instead of being treated as
		// already expired. Every token gets a real timestamp going
		// forward, and this path only ever fires once per legacy token.
		$max_age = self::token_validity_days() * DAY_IN_SECONDS;
		if ( $max_age > 0 ) {
			$issued_at = (int) get_user_meta( $uid, self::TOKEN_ISSUED_META, true );
			if ( 0 === $issued_at ) {
				update_user_meta( $uid, self::TOKEN_ISSUED_META, time() );
			} elseif ( ( time() - $issued_at ) > $max_age ) {
				return 0;
			}
		}

		return $uid;
	}

	/**
	 * Whether a person row's waiver is signed AND not past its expiry.
	 *
	 * @param array<string,mixed> $person People row.
	 */
	public static function is_current( $person ) {
		if ( empty( $person['waiver_status'] ) || 'signed' !== $person['waiver_status'] ) {
			return false;
		}
		// A re-consent version invalidates any signature made before it took
		// effect (person rows are also flipped to needs_review at publish
		// time; this covers rows written between then and now, e.g. imports).
		$boundary = Waiver_Versions::reconsent_boundary();
		if ( '' !== $boundary ) {
			$signed = ! empty( $person['waiver_signed_at'] ) ? strtotime( (string) $person['waiver_signed_at'] ) : 0;
			if ( $signed < strtotime( $boundary ) ) {
				return false;
			}
		}
		if ( empty( $person['waiver_expires_at'] ) ) {
			return true;
		}
		return strtotime( (string) $person['waiver_expires_at'] ) > current_time( 'timestamp' );
	}

	/**
	 * Current waiver status for a WP user (resolved via their primary person row).
	 */
	public static function status_for_user( $user_id ) {
		$membership = Memberships_Repository::get_by_user_id( (int) $user_id );
		if ( $membership ) {
			$person = People_Repository::get_primary_by_membership( (int) $membership['id'] );
			if ( $person && ! empty( $person['waiver_status'] ) ) {
				return (string) $person['waiver_status'];
			}
		}
		return 'missing';
	}

	/**
	 * Record a signature for a WP user. Updates every person row tied to that
	 * user, stamps signed/expiry, logs activity, and mirrors onto corporate
	 * group_members when the corporate module is present.
	 *
	 * @param int    $user_id    WP user id.
	 * @param string $typed_name Typed signature.
	 * @param array  $meta       Optional context (ip, source).
	 * @return bool
	 */
	public static function mark_signed( $user_id, $typed_name = '', $meta = array() ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return false;
		}
		$now     = current_time( 'mysql' );
		$expires = wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) + ( self::validity_days() * DAY_IN_SECONDS ) );

		// Update every person record tied to this user across all memberships.
		global $wpdb;
		$people_table = People_Repository::table();
		$person_rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT id, membership_id FROM {$people_table} WHERE wp_user_id = %d", $user_id ),
			ARRAY_A
		);
		foreach ( (array) $person_rows as $pr ) {
			People_Repository::update(
				(int) $pr['id'],
				array(
					'waiver_status'     => 'signed',
					'waiver_signed_at'  => $now,
					'waiver_expires_at' => $expires,
				)
			);
		}

		// Mirror onto corporate group_members + log, if corporate is present.
		if ( class_exists( '\WordPressistic\Memberistic\Corporate\Corporate_Members_Repository' ) ) {
			$corp_table = \WordPressistic\Memberistic\Corporate\Corporate_Members_Repository::table();
			$corp_rows  = $wpdb->get_results(
				$wpdb->prepare( "SELECT id, group_id FROM {$corp_table} WHERE user_id = %d AND status != 'removed'", $user_id ),
				ARRAY_A
			);
			foreach ( (array) $corp_rows as $cr ) {
				\WordPressistic\Memberistic\Corporate\Corporate_Members_Repository::update_waiver( (int) $cr['id'], 'signed' );
			}
		}

		$signer = $typed_name ?: ( get_userdata( $user_id ) ? get_userdata( $user_id )->display_name : ( '#' . $user_id ) );
		update_user_meta( $user_id, 'memberistic_waiver_signed_name', sanitize_text_field( $typed_name ) );
		update_user_meta( $user_id, 'memberistic_waiver_signed_at', $now );
		update_user_meta( $user_id, 'memberistic_waiver_signed_ip', isset( $meta['ip'] ) ? sanitize_text_field( (string) $meta['ip'] ) : '' );

		$membership = Memberships_Repository::get_by_user_id( $user_id );
		$person     = $membership ? People_Repository::get_primary_by_membership( (int) $membership['id'] ) : null;
		if ( $membership ) {
			Activity_Repository::log(
				array(
					'membership_id' => (int) $membership['id'],
					'activity_type' => 'waiver_signed',
					'title'         => sprintf( /* translators: %s: signer */ __( 'Waiver signed by %s', 'memberistic' ), $signer ),
				)
			);
		}

		// Immutable, versioned signature record for the archive/export.
		return (int) self::record_signature(
			array(
				'user_id'       => $user_id,
				'person_id'     => $person ? (int) $person['id'] : 0,
				'membership_id' => $membership ? (int) $membership['id'] : 0,
				'signer_name'   => $signer,
				'signer_email'  => $person ? (string) ( $person['email'] ?? '' ) : ( get_userdata( $user_id ) ? get_userdata( $user_id )->user_email : '' ),
				'source'          => isset( $meta['source'] ) ? (string) $meta['source'] : 'self_serve',
				'signed_at'       => $now,
				'expires_at'      => $expires,
				'ip'              => isset( $meta['ip'] ) ? (string) $meta['ip'] : '',
				'user_agent'      => isset( $meta['user_agent'] ) ? (string) $meta['user_agent'] : ( isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '' ),
				'dob'             => isset( $meta['dob'] ) ? (string) $meta['dob'] : '',
				'phone'           => isset( $meta['phone'] ) ? (string) $meta['phone'] : '',
				'emergency_name'  => isset( $meta['emergency_name'] ) ? (string) $meta['emergency_name'] : '',
				'emergency_phone' => isset( $meta['emergency_phone'] ) ? (string) $meta['emergency_phone'] : '',
				'minors'          => isset( $meta['minors'] ) ? (array) $meta['minors'] : array(),
			)
		);
	}

	/**
	 * Insert an immutable waiver-signature record (snapshots the exact waiver
	 * text + a hash of it, so an export proves what each person agreed to).
	 *
	 * @param array $args Signature fields.
	 * @return int Signature id.
	 */
	public static function record_signature( $args ) {
		global $wpdb;
		$text = self::waiver_text();
		$now  = current_time( 'mysql' );

		// Minors the adult signer is covering as parent/guardian:
		// array of { name, dob } rows, stored as JSON.
		$minors = array();
		foreach ( (array) ( $args['minors'] ?? array() ) as $m ) {
			$m_name = sanitize_text_field( (string) ( $m['name'] ?? '' ) );
			if ( '' === $m_name ) {
				continue;
			}
			$minors[] = array(
				'name' => $m_name,
				'dob'  => sanitize_text_field( (string) ( $m['dob'] ?? '' ) ),
			);
		}

		$wpdb->insert(
			self::signatures_table(),
			array(
				'user_id'           => ! empty( $args['user_id'] ) ? (int) $args['user_id'] : null,
				'person_id'         => ! empty( $args['person_id'] ) ? (int) $args['person_id'] : null,
				'membership_id'     => ! empty( $args['membership_id'] ) ? (int) $args['membership_id'] : null,
				'signer_name'       => isset( $args['signer_name'] ) ? sanitize_text_field( (string) $args['signer_name'] ) : '',
				'signer_email'      => isset( $args['signer_email'] ) ? sanitize_email( (string) $args['signer_email'] ) : '',
				'source'            => isset( $args['source'] ) ? sanitize_key( (string) $args['source'] ) : 'self_serve',
				'signed_at'         => ! empty( $args['signed_at'] ) ? (string) $args['signed_at'] : $now,
				'expires_at'        => ! empty( $args['expires_at'] ) ? (string) $args['expires_at'] : null,
				'ip'                => isset( $args['ip'] ) ? substr( sanitize_text_field( (string) $args['ip'] ), 0, 64 ) : '',
				'user_agent'        => isset( $args['user_agent'] ) ? substr( sanitize_text_field( (string) $args['user_agent'] ), 0, 255 ) : '',
				'waiver_text'       => $text,
				'text_hash'         => hash( 'sha256', $text ),
				'attachment_id'     => ! empty( $args['attachment_id'] ) ? (int) $args['attachment_id'] : null,
				'dob'               => ! empty( $args['dob'] ) ? sanitize_text_field( (string) $args['dob'] ) : null,
				'phone'             => ! empty( $args['phone'] ) ? substr( sanitize_text_field( (string) $args['phone'] ), 0, 60 ) : null,
				'emergency_name'    => ! empty( $args['emergency_name'] ) ? sanitize_text_field( (string) $args['emergency_name'] ) : null,
				'emergency_phone'   => ! empty( $args['emergency_phone'] ) ? substr( sanitize_text_field( (string) $args['emergency_phone'] ), 0, 60 ) : null,
				'minors_json'       => $minors ? (string) wp_json_encode( $minors ) : null,
				'waiver_version_id' => ! empty( $args['waiver_version_id'] ) ? (int) $args['waiver_version_id'] : ( Waiver_Versions::current_id() ?: null ),
				'station'           => ! empty( $args['station'] ) ? substr( sanitize_title( (string) $args['station'] ), 0, 100 ) : null,
				'created_at'        => $now,
			),
			array( '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);
		$sig_id = (int) $wpdb->insert_id;

		// Mirror into the waivers archive — the store the booking/check-in
		// "waiver on file?" lookup reads — so people who sign the new e-waiver
		// (guest or member) are found at their next visit, exactly like the
		// imported Ottertext signers.
		if ( $sig_id > 0 ) {
			$row = self::get_signature( $sig_id );
			Waivers_Archive::upsert_from_signature( $row );
			// Completion signal for live surfaces + automations (front-desk
			// check-in waiting on a waiver, Messageistic SMS bridges, etc.).
			do_action( 'memberistic_waiver_signed', $sig_id, is_array( $row ) ? $row : array() );
		}
		return $sig_id;
	}

	public static function signatures_table() {
		global $wpdb;
		return $wpdb->prefix . 'memberistic_waiver_signatures';
	}

	public static function get_signature( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::signatures_table() . ' WHERE id = %d', (int) $id ), ARRAY_A ) ?: null;
	}

	/**
	 * List signature records for the admin table / export.
	 *
	 * @param int $limit
	 * @param int $offset
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_signatures( $limit = 100, $offset = 0 ) {
		global $wpdb;
		$limit  = max( 1, min( 5000, (int) $limit ) );
		$offset = max( 0, (int) $offset );
		return $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . self::signatures_table() . ' ORDER BY signed_at DESC LIMIT %d OFFSET %d', $limit, $offset ),
			ARRAY_A
		) ?: array();
	}

	public static function count_signatures() {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::signatures_table() );
	}

	/**
	 * Mark a single person row signed (used for linked members / staff action
	 * on members who have no WP user). Mirrors to the user if one is linked.
	 *
	 * @param int    $person_id  People row id.
	 * @param string $typed_name Optional signer name.
	 * @return bool
	 */
	public static function mark_person_signed( $person_id, $typed_name = '' ) {
		$person = People_Repository::get( (int) $person_id );
		if ( ! $person ) {
			return false;
		}
		if ( ! empty( $person['wp_user_id'] ) ) {
			return self::mark_signed( (int) $person['wp_user_id'], $typed_name, array( 'source' => 'staff' ) );
		}
		$now     = current_time( 'mysql' );
		$expires = wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) + ( self::validity_days() * DAY_IN_SECONDS ) );
		People_Repository::update(
			(int) $person_id,
			array(
				'waiver_status'     => 'signed',
				'waiver_signed_at'  => $now,
				'waiver_expires_at' => $expires,
			)
		);
		if ( ! empty( $person['membership_id'] ) ) {
			Activity_Repository::log(
				array(
					'membership_id' => (int) $person['membership_id'],
					'person_id'     => (int) $person_id,
					'activity_type' => 'waiver_signed',
					'title'         => __( 'Waiver marked signed by staff', 'memberistic' ),
				)
			);
		}
		return (int) self::record_signature(
			array(
				'person_id'     => (int) $person_id,
				'membership_id' => ! empty( $person['membership_id'] ) ? (int) $person['membership_id'] : 0,
				'signer_name'   => $typed_name ?: (string) ( $person['full_name'] ?? '' ),
				'signer_email'  => (string) ( $person['email'] ?? '' ),
				'source'        => 'staff',
				'signed_at'     => $now,
				'expires_at'    => $expires,
			)
		);
	}

	/**
	 * Bulk-mark active members' waivers as signed (the "everyone signed in
	 * store" backlog). Only touches active people without a current signed
	 * waiver. Returns the number updated.
	 *
	 * @param string $source Audit source label.
	 */
	public static function bulk_mark_active_signed( $source = 'staff_bulk' ) {
		global $wpdb;
		$table   = People_Repository::table();
		$now     = current_time( 'mysql' );
		$expires = wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) + ( self::validity_days() * DAY_IN_SECONDS ) );

		// Pull the rows we're about to sign so each gets a signature record
		// (labelled staff_bulk = recorded from an in-store paper waiver),
		// then flip the flag. Bounded batch keeps a single request safe.
		$rows = $wpdb->get_results(
			"SELECT id, membership_id, full_name, email FROM {$table} WHERE status = 'active' AND waiver_status IN ( 'missing', 'expired', 'needs_review' ) LIMIT 2000",
			ARRAY_A
		) ?: array();

		$updated = 0;
		foreach ( $rows as $row ) {
			People_Repository::update(
				(int) $row['id'],
				array(
					'waiver_status'     => 'signed',
					'waiver_signed_at'  => $now,
					'waiver_expires_at' => $expires,
				)
			);
			self::record_signature(
				array(
					'person_id'     => (int) $row['id'],
					'membership_id' => (int) $row['membership_id'],
					'signer_name'   => (string) $row['full_name'],
					'signer_email'  => (string) $row['email'],
					'source'        => $source,
					'signed_at'     => $now,
					'expires_at'    => $expires,
				)
			);
			$updated++;
		}

		// Mirror to corporate members — correlated to the people rows we just
		// signed (via the shared wp user), so we never flip a group member
		// whose canonical person row wasn't actually backlogged.
		if ( $updated > 0 && class_exists( '\WordPressistic\Memberistic\Corporate\Corporate_Members_Repository' ) ) {
			$corp = \WordPressistic\Memberistic\Corporate\Corporate_Members_Repository::table();
			$wpdb->query(
				"UPDATE {$corp} c
				 INNER JOIN {$table} p ON p.wp_user_id = c.user_id AND p.waiver_status = 'signed'
				 SET c.waiver_status = 'signed'
				 WHERE c.status = 'active' AND c.waiver_status IN ( 'missing', 'expired', 'needs_review' )"
			);
		}
		return $updated;
	}

	/**
	 * Daily expiry sweep: flip signed waivers past their expiry to 'expired'.
	 * Returns the number expired. Called from the Scheduler.
	 */
	public static function expire_due() {
		global $wpdb;
		$table = People_Repository::table();
		$now   = current_time( 'mysql' );
		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET waiver_status = 'expired' WHERE waiver_status = 'signed' AND waiver_expires_at IS NOT NULL AND waiver_expires_at <> '0000-00-00 00:00:00' AND waiver_expires_at < %s",
				$now
			)
		);
	}

	/**
	 * Days before expiry to send the renewal reminder. 0 disables.
	 */
	public static function renewal_reminder_days() {
		$days = (int) get_option( 'memberistic_waiver_renewal_reminder_days', 30 );
		$days = max( 0, min( 365, $days ) );
		return (int) apply_filters( 'memberistic_waiver_renewal_reminder_days', $days );
	}

	/**
	 * Renewal reminders: email members whose signed waiver expires within the
	 * reminder window, once per signing cycle (re-signing resets the gate,
	 * because waiver_signed_at moves past waiver_renewal_reminded_at).
	 * Called daily from the Scheduler. Returns the number of emails sent.
	 */
	public static function send_renewal_reminders() {
		$days = self::renewal_reminder_days();
		if ( $days <= 0 ) {
			return 0;
		}
		global $wpdb;
		$table = People_Repository::table();

		// Tolerate a not-yet-migrated schema (column ships in DB 1.8.0).
		$columns = (array) $wpdb->get_col( "SHOW COLUMNS FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! in_array( 'waiver_renewal_reminded_at', $columns, true ) ) {
			return 0;
		}

		$now   = current_time( 'mysql' );
		$until = wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) + ( $days * DAY_IN_SECONDS ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, membership_id, wp_user_id, full_name, email, phone, waiver_expires_at FROM {$table}
				 WHERE status = 'active' AND waiver_status = 'signed'
				   AND membership_id IS NOT NULL AND membership_id > 0
				   AND waiver_expires_at IS NOT NULL AND waiver_expires_at BETWEEN %s AND %s
				   AND ( waiver_renewal_reminded_at IS NULL OR waiver_renewal_reminded_at <= COALESCE(waiver_signed_at, '1970-01-01') )
				 LIMIT 200",
				$now,
				$until
			),
			ARRAY_A
		);

		$sent = 0;
		foreach ( (array) $rows as $row ) {
			$expires = date_i18n( get_option( 'date_format' ), strtotime( (string) $row['waiver_expires_at'] ) );
			$ok      = Email_Service::send_membership_email(
				(int) $row['membership_id'],
				'waiver_renewal',
				array( 'waiver_expires' => $expires )
			);
			// Stamp even on send failure? No — leave unset so tomorrow's run
			// retries; the LIMIT keeps a persistent failure from flooding.
			if ( $ok ) {
				$wpdb->update( $table, array( 'waiver_renewal_reminded_at' => $now ), array( 'id' => (int) $row['id'] ) );
				$sent++;

				// Companion signal for other channels (Messageistic sends the
				// SMS version). Fired only when the cycle is stamped, so both
				// channels share the once-per-cycle guarantee.
				$link = ! empty( $row['wp_user_id'] )
					? self::waiver_url( (int) $row['wp_user_id'] )
					: ( class_exists( '\WordPressistic\Memberistic\Waivers\Waiver_Public' ) ? Waiver_Public::guest_url() : home_url( '/' ) );
				$name_parts = preg_split( '/\s+/', trim( (string) ( $row['full_name'] ?? '' ) ) );
				do_action(
					'memberistic_waiver_renewal_due',
					array(
						'person_id'      => (int) $row['id'],
						'first_name'     => (string) ( $name_parts[0] ?? '' ),
						'last_name'      => count( $name_parts ) > 1 ? implode( ' ', array_slice( $name_parts, 1 ) ) : '',
						'email'          => (string) ( $row['email'] ?? '' ),
						'phone'          => (string) ( $row['phone'] ?? '' ),
						'waiver_link'    => (string) $link,
						'waiver_expires' => (string) $expires,
					)
				);
			}
		}
		return $sent;
	}

	/**
	 * Counts of active people by waiver bucket.
	 *
	 * @return array{signed:int,missing:int,expired:int,needs_review:int,total:int}
	 */
	public static function counts() {
		global $wpdb;
		$table = People_Repository::table();
		$rows  = $wpdb->get_results( "SELECT waiver_status AS s, COUNT(*) AS c FROM {$table} WHERE status = 'active' GROUP BY waiver_status", ARRAY_A ) ?: array();
		$out   = array( 'signed' => 0, 'missing' => 0, 'expired' => 0, 'needs_review' => 0, 'total' => 0 );
		foreach ( $rows as $r ) {
			$s = (string) $r['s'];
			$out[ $s ] = ( $out[ $s ] ?? 0 ) + (int) $r['c'];
			$out['total'] += (int) $r['c'];
		}
		return $out;
	}

	/**
	 * List active people for the admin table, optionally filtered by waiver
	 * status. Joins the primary membership for context.
	 *
	 * @param string $status One of signed|missing|expired|needs_review|'' (all).
	 * @param int    $limit
	 * @param int    $offset
	 * @return array<int,array<string,mixed>>
	 */
	public static function list_people( $status = '', $limit = 100, $offset = 0 ) {
		global $wpdb;
		$people = People_Repository::table();
		$mem    = Memberships_Repository::table();
		$limit  = max( 1, min( 500, (int) $limit ) );
		$offset = max( 0, (int) $offset );

		$where      = array( "p.status = 'active'" );
		$where_args = array();
		if ( in_array( $status, array( 'signed', 'missing', 'expired', 'needs_review' ), true ) ) {
			$where[]      = 'p.waiver_status = %s';
			$where_args[] = $status;
		}
		$where_sql = 'WHERE ' . implode( ' AND ', $where );

		$sql = "SELECT p.id, p.full_name, p.email, p.role, p.wp_user_id, p.waiver_status, p.waiver_signed_at, p.waiver_expires_at, m.status AS membership_status, m.membership_uuid
			FROM {$people} p LEFT JOIN {$mem} m ON m.id = p.membership_id
			{$where_sql} ORDER BY p.full_name ASC LIMIT %d OFFSET %d";
		$args = array_merge( $where_args, array( $limit, $offset ) );

		return $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Email the waiver link to every active member missing a current waiver.
	 * Returns the number of emails dispatched.
	 */
	public static function email_all_missing() {
		$rows = People_Repository::get_active_missing_waiver();
		$sent = 0;
		foreach ( (array) $rows as $row ) {
			if ( Email_Service::send_membership_email( (int) $row['membership_id'], 'waiver_missing' ) ) {
				$sent++;
			}
		}
		return $sent;
	}
}

/**
 * Public, tokenised waiver signing page. Supersedes the corporate-only
 * handler (which is no longer registered) and serves every member.
 */
final class Waiver_Public {

	public static function register() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_handle' ), 1 );
		add_shortcode( 'memberistic_guest_waiver', array( __CLASS__, 'guest_button_shortcode' ) );
		add_shortcode( 'memberistic_kiosk', array( __CLASS__, 'kiosk_shortcode' ) );
	}

	/** Public URL of the kiosk check-in hub (no setup / no login needed). */
	public static function kiosk_url() {
		return add_query_arg( 'memberistic_kiosk', '1', home_url( '/' ) );
	}

	/**
	 * Friendliest kiosk URL: the auto-created /check-in/ page permalink if it
	 * exists, otherwise the zero-setup query URL.
	 */
	public static function best_kiosk_url() {
		$page_id = (int) get_option( 'memberistic_checkin_page_id', 0 );
		if ( $page_id && 'publish' === get_post_status( $page_id ) ) {
			$url = get_permalink( $page_id );
			if ( $url ) {
				return $url;
			}
		}
		$page = get_page_by_path( 'check-in' );
		if ( $page && 'publish' === get_post_status( $page ) ) {
			return get_permalink( $page );
		}
		return self::kiosk_url();
	}

	/**
	 * Standalone, touch-friendly check-in hub. Two paths: guests sign the
	 * waiver; members sign in to show their pass. Self-contained branded page
	 * — works as a kiosk URL with no page setup.
	 */
	public static function render_kiosk() {
		// Station mode: a valid HMAC station token unlocks the full-screen
		// attract UI (and carries into the guest form, where it lifts the
		// public throttle + stamps the station on the signature). An expired
		// token just falls through to the public hub below.
		$token   = Waiver_Kiosk::request_token();
		$station = Waiver_Kiosk::verify_token( $token );
		if ( '' !== $station ) {
			self::render_kiosk_attract( $token, $station );
			return;
		}
		$guest   = self::guest_url();
		$account = home_url( '/account/' );
		// Brand colors flow through CSS custom properties so the kiosk + the
		// guest-waiver pages stay in sync with whatever the site has
		// configured for primary / accent. Was hardcoded to the launch
		// partner's dark palette.
		$primary = (string) get_option( 'memberistic_primary_brand_color', '#0F2044' );
		$accent  = (string) get_option( 'memberistic_accent_brand_color', '#E8802F' );
		$big     = 'display:block;width:100%;box-sizing:border-box;margin:0 0 14px;padding:20px;border-radius:8px;font-weight:700;font-size:17px;letter-spacing:.04em;text-decoration:none;';
		$style   = '<style>:root{--memberistic-brand-primary:' . esc_attr( $primary ) . ';--memberistic-brand-accent:' . esc_attr( $accent ) . ';}</style>';
		$html    = $style;
		$html   .= '<p style="color:#CBCAD2;margin:0 0 24px;font-size:15px;">' . esc_html__( 'Welcome to the range. Tap an option to check in.', 'memberistic' ) . '</p>';
		$html   .= '<a href="' . esc_url( $guest ) . '" style="' . esc_attr( $big ) . 'background:var(--memberistic-brand-accent);color:#111;">' . esc_html__( 'Guest / First Visit — Sign Waiver', 'memberistic' ) . '</a>';
		$html   .= '<a href="' . esc_url( $account ) . '" style="' . esc_attr( $big ) . 'background:var(--memberistic-brand-primary);color:#fff;border:1px solid rgba(255,255,255,.1);">' . esc_html__( 'Member — Sign In to Show Your Pass', 'memberistic' ) . '</a>';
		$html   .= '<p style="color:#5A6371;font-size:12px;margin-top:20px;">' . esc_html__( 'Already have your member QR on your phone? Just show it at the desk.', 'memberistic' ) . '</p>';
		self::render( __( 'Range Check-In', 'memberistic' ), $html, 200 );
	}

	/**
	 * Full-screen attract screen for a verified kiosk station: one giant
	 * "sign the waiver" button, a member path, and (when a staff PIN is
	 * configured) a discreet server-verified exit button. The station token
	 * threads through every link so the whole loop stays in kiosk mode.
	 */
	private static function render_kiosk_attract( $token, $station ) {
		// Server-verified staff exit (device-level guided access is the real
		// lock; this is the polite path off the attract loop).
		$pin = Waiver_Kiosk::staff_pin();
		if ( '' !== $pin && isset( $_POST['kiosk_exit_pin'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- kiosk surface; PIN is the credential.
			$given = preg_replace( '/\D+/', '', (string) wp_unslash( $_POST['kiosk_exit_pin'] ) );
			if ( hash_equals( $pin, $given ) ) {
				wp_safe_redirect( home_url( '/' ) );
				exit;
			}
		}
		$guest   = Waiver_Kiosk::guest_url( $token );
		$account = home_url( '/account/' );
		$primary = (string) get_option( 'memberistic_primary_brand_color', '#0F2044' );
		$accent  = (string) get_option( 'memberistic_accent_brand_color', '#E8802F' );
		$brand   = memberistic_get_brand_label();

		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">';
		echo '<title>' . esc_html( $brand ) . ' — ' . esc_html__( 'Range Check-In', 'memberistic' ) . '</title>';
		echo '<style>'
			. ':root{--p:' . esc_attr( $primary ) . ';--a:' . esc_attr( $accent ) . ';}'
			. '*{-webkit-tap-highlight-color:transparent;}'
			. 'body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:var(--p);color:#fff;margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;box-sizing:border-box;overscroll-behavior:none;}'
			. '.wrap{max-width:640px;width:100%;text-align:center;}'
			. '.brand{font-family:"Bebas Neue",Impact,sans-serif;letter-spacing:.2em;color:#C9A84C;text-transform:uppercase;font-size:20px;margin-bottom:8px;}'
			. 'h1{font-size:34px;margin:0 0 28px;}'
			. 'a.big{display:block;padding:30px;margin:0 0 18px;border-radius:12px;font-weight:800;font-size:24px;letter-spacing:.04em;text-decoration:none;}'
			. 'a.guest{background:var(--a);color:#111;}'
			. 'a.member{background:rgba(255,255,255,.08);color:#fff;border:1px solid rgba(255,255,255,.18);}'
			. '.station{color:rgba(255,255,255,.35);font-size:12px;margin-top:26px;letter-spacing:.08em;text-transform:uppercase;}'
			. '.exit{position:fixed;bottom:10px;right:12px;}'
			. '.exit button{background:none;border:0;color:rgba(255,255,255,.25);font-size:12px;cursor:pointer;padding:8px;}'
			. '</style></head><body><div class="wrap">';
		echo '<div class="brand">' . esc_html( $brand ) . '</div>';
		echo '<h1>' . esc_html__( 'Welcome to the range', 'memberistic' ) . '</h1>';
		echo '<a class="big guest" href="' . esc_url( $guest ) . '">' . esc_html__( 'Guest / First Visit — Sign the Waiver', 'memberistic' ) . '</a>';
		echo '<a class="big member" href="' . esc_url( $account ) . '">' . esc_html__( 'Member — Show Your Pass', 'memberistic' ) . '</a>';
		echo '<div class="station">' . esc_html( sprintf( /* translators: %s: station name */ __( 'Station: %s', 'memberistic' ), $station ) ) . '</div>';
		if ( '' !== $pin ) {
			echo '<form class="exit" method="post"><button type="button" onclick="var p=prompt(' . wp_json_encode( __( 'Staff PIN', 'memberistic' ) ) . ');if(p!==null){this.form.kiosk_exit_pin.value=p;this.form.submit();}">' . esc_html__( 'Staff exit', 'memberistic' ) . '</button><input type="hidden" name="kiosk_exit_pin" value=""></form>';
		}
		echo '</div></body></html>';
		exit;
	}

	/**
	 * [memberistic_kiosk] — embeddable check-in hub (guest waiver + member
	 * sign-in) for placing on a /check-in/ page.
	 */
	public static function kiosk_shortcode( $atts = array() ) {
		$guest   = self::guest_url();
		$account = home_url( '/account/' );
		$primary = (string) get_option( 'memberistic_primary_brand_color', '#0F2044' );
		$accent  = (string) get_option( 'memberistic_accent_brand_color', '#E8802F' );
		ob_start();
		echo '<style>.memberistic-kiosk{--memberistic-brand-primary:' . esc_attr( $primary ) . ';--memberistic-brand-accent:' . esc_attr( $accent ) . ';}</style>';
		echo '<div class="memberistic-frontend memberistic-kiosk" style="max-width:520px;margin:0 auto;text-align:center;">';
		echo '<a class="memberistic-plan-button" style="display:block;margin-bottom:14px;background:var(--memberistic-brand-accent);" href="' . esc_url( $guest ) . '">' . esc_html__( 'Guest / First Visit — Sign Waiver', 'memberistic' ) . '</a>';
		echo '<a class="memberistic-secondary-button" style="display:block;background:var(--memberistic-brand-primary);color:#fff;" href="' . esc_url( $account ) . '">' . esc_html__( 'Member — Sign In to Show Your Pass', 'memberistic' ) . '</a>';
		echo '</div>';
		return ob_get_clean();
	}

	/** Public URL of the guest (non-member) waiver page. */
	public static function guest_url() {
		return add_query_arg( Waiver_Service::QUERY, 'guest', home_url( '/' ) );
	}

	/**
	 * [memberistic_guest_waiver] — a button linking to the guest waiver page,
	 * for embedding on a public /waiver/ page or a desk kiosk.
	 */
	public static function guest_button_shortcode( $atts = array() ) {
		$atts  = shortcode_atts( array( 'label' => __( 'Sign the range waiver', 'memberistic' ) ), $atts, 'memberistic_guest_waiver' );
		return '<a class="memberistic-plan-button" href="' . esc_url( self::guest_url() ) . '">' . esc_html( $atts['label'] ) . '</a>';
	}

	/**
	 * Guest / non-member waiver: collects a name + email, records an
	 * immutable signature with no membership attached. No file upload here —
	 * the surface is fully unauthenticated, so we don't accept anonymous
	 * uploads.
	 */
	public static function handle_guest() {
		// Kiosk station mode: a valid HMAC token lifts the public per-IP
		// throttle (one desk device signs many guests), stamps the station
		// on the signature, and turns the success page into an auto-reset
		// loop back to the attract screen for the next guest.
		$station_token = Waiver_Kiosk::request_token();
		$station       = Waiver_Kiosk::verify_token( $station_token );

		if ( 'POST' === strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
			if ( ! isset( $_POST['memberistic_waiver_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['memberistic_waiver_nonce'] ) ), 'memberistic_waiver_guest' ) ) {
				self::render( __( 'Session expired', 'memberistic' ), '<p>' . esc_html__( 'Please reload the page and try again.', 'memberistic' ) . '</p>', 400 );
			}
			$name  = isset( $_POST['signature_name'] ) ? sanitize_text_field( wp_unslash( $_POST['signature_name'] ) ) : '';
			$email = isset( $_POST['signature_email'] ) ? sanitize_email( wp_unslash( $_POST['signature_email'] ) ) : '';
			$agree = ! empty( $_POST['agree'] );
			if ( ! $agree || '' === $name || ! is_email( $email ) ) {
				self::render_guest_form( __( 'Please enter your full name, a valid email, and check the agreement box.', 'memberistic' ) );
			}
			$extra = self::collect_extra_fields();
			if ( ! empty( $extra['error'] ) ) {
				self::render_guest_form( (string) $extra['error'] );
			}
			$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

			// Throttle the fully-unauthenticated guest surface to curb scripted
			// row spam: cap per-IP submissions per hour (filterable). A
			// verified kiosk station is exempt — the front desk signs a
			// steady stream of guests from one IP.
			if ( '' === $station ) {
				$limit = (int) apply_filters( 'memberistic_guest_waiver_hourly_limit', 8 );
				$rk    = 'memberistic_guest_wv_' . md5( $ip );
				$count = (int) get_transient( $rk );
				if ( $count >= $limit ) {
					self::render( __( 'Please try again later', 'memberistic' ), '<p>' . esc_html__( 'Too many waiver submissions from this connection. Please ask range staff for help.', 'memberistic' ) . '</p>', 429 );
				}
				set_transient( $rk, $count + 1, HOUR_IN_SECONDS );
			}

			$sig_id = (int) Waiver_Service::record_signature(
				array_merge(
					array(
						'signer_name'  => $name,
						'signer_email' => $email,
						'source'       => '' !== $station ? 'kiosk' : 'guest',
						'station'      => $station,
						'signed_at'    => current_time( 'mysql' ),
						'expires_at'   => wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) + ( Waiver_Service::validity_days() * DAY_IN_SECONDS ) ),
						'ip'           => $ip,
					),
					$extra['fields']
				)
			);
			if ( $sig_id ) {
				self::store_signature_artifacts( $sig_id, (string) $extra['jpeg'] );
			}
			if ( '' !== $station ) {
				// Auto-reset kiosk loop: bounce back to the attract screen
				// after a short thank-you so the device is ready for the
				// next guest even if nobody touches it.
				$attract = Waiver_Kiosk::kiosk_url( $station_token );
				self::render(
					__( 'Waiver complete', 'memberistic' ),
					'<p>' . esc_html__( 'Thank you — your waiver is on file. Enjoy your visit.', 'memberistic' ) . '</p>'
					. '<p><a class="btn" href="' . esc_url( $attract ) . '">' . esc_html__( 'Next guest — tap here', 'memberistic' ) . '</a></p>'
					. '<p style="color:#5A6371;font-size:12px;" id="mw-reset-note"></p>'
					. '<script>(function(){var s=12,n=document.getElementById("mw-reset-note");function t(){n.textContent="' . esc_js( __( 'Resetting for the next guest in', 'memberistic' ) ) . ' "+s+"s";if(s--<=0){window.location.href=' . wp_json_encode( $attract ) . ';return;}setTimeout(t,1000);}t();})();</script>',
					200
				);
			}
			self::render(
				__( 'Waiver complete', 'memberistic' ),
				'<p>' . esc_html__( 'Thank you — your waiver is on file. Enjoy your visit.', 'memberistic' ) . '</p>'
				. '<p><a class="btn" href="' . esc_url( self::kiosk_url() ) . '">' . esc_html__( 'Next guest — back to check-in', 'memberistic' ) . '</a></p>',
				200
			);
		}
		self::render_guest_form();
	}

	private static function render_guest_form( $error = '' ) {
		$text = Waiver_Service::waiver_text();
		ob_start();
		if ( $error ) {
			echo '<p style="color:#E8802F;font-weight:600;">' . esc_html( $error ) . '</p>';
		}
		echo '<div style="text-align:left;background:#11161D;border:1px solid #2A323D;border-radius:8px;padding:18px;max-height:280px;overflow:auto;color:#CBCAD2;line-height:1.6;font-size:14px;white-space:pre-wrap;">' . esc_html( $text ) . '</div>';
		echo '<form method="post" style="margin-top:18px;text-align:left;">';
		wp_nonce_field( 'memberistic_waiver_guest', 'memberistic_waiver_nonce' );
		// Thread a kiosk station token (if any) through the POST so the
		// submit keeps its throttle exemption + station attribution.
		$station_token = Waiver_Kiosk::request_token();
		if ( '' !== $station_token && '' !== Waiver_Kiosk::verify_token( $station_token ) ) {
			echo '<input type="hidden" name="' . esc_attr( Waiver_Kiosk::PARAM ) . '" value="' . esc_attr( $station_token ) . '">';
		}
		echo '<label style="display:block;font-size:12px;letter-spacing:.12em;text-transform:uppercase;color:#8A95A5;margin-bottom:6px;">' . esc_html__( 'Full legal name', 'memberistic' ) . '</label>';
		echo '<input type="text" name="signature_name" required style="width:100%;padding:12px 14px;background:#0F1115;border:1px solid #2A323D;border-radius:4px;color:#fff;font-size:16px;margin-bottom:14px;box-sizing:border-box;">';
		echo '<label style="display:block;font-size:12px;letter-spacing:.12em;text-transform:uppercase;color:#8A95A5;margin-bottom:6px;">' . esc_html__( 'Email address', 'memberistic' ) . '</label>';
		echo '<input type="email" name="signature_email" required style="width:100%;padding:12px 14px;background:#0F1115;border:1px solid #2A323D;border-radius:4px;color:#fff;font-size:16px;margin-bottom:14px;box-sizing:border-box;">';
		self::render_extra_fields();
		echo '<label style="display:flex;gap:10px;align-items:flex-start;color:#CBCAD2;font-size:14px;cursor:pointer;"><input type="checkbox" name="agree" value="1" required style="width:20px;height:20px;margin-top:2px;"> <span>' . esc_html__( 'I have read and agree to the waiver above, and I am signing electronically.', 'memberistic' ) . '</span></label>';
		echo '<button type="submit" style="margin-top:18px;width:100%;padding:14px;background:#E8802F;color:#111;border:0;border-radius:4px;font-weight:700;font-size:15px;letter-spacing:.06em;text-transform:uppercase;cursor:pointer;">' . esc_html__( 'Sign Waiver', 'memberistic' ) . '</button>';
		echo '</form>';
		self::render( __( 'Range Waiver', 'memberistic' ), ob_get_clean(), 200 );
	}

	/* ------------------------------------------------------------------ */
	/* Shared signing fields (guest + member forms)                        */
	/* ------------------------------------------------------------------ */

	/**
	 * Echo the signing-parity fields both forms share: phone, DOB, emergency
	 * contact, minors (guardian signing), and the draw-to-sign signature pad.
	 */
	private static function render_extra_fields() {
		$in = 'width:100%;padding:12px 14px;background:#0F1115;border:1px solid #2A323D;border-radius:4px;color:#fff;font-size:16px;margin-bottom:14px;box-sizing:border-box;';
		$lb = 'display:block;font-size:12px;letter-spacing:.12em;text-transform:uppercase;color:#8A95A5;margin-bottom:6px;';

		echo '<div style="display:flex;gap:12px;">';
		echo '<div style="flex:1;"><label style="' . esc_attr( $lb ) . '">' . esc_html__( 'Phone number', 'memberistic' ) . '</label>';
		echo '<input type="tel" name="signature_phone" required autocomplete="tel" style="' . esc_attr( $in ) . '"></div>';
		echo '<div style="flex:1;"><label style="' . esc_attr( $lb ) . '">' . esc_html__( 'Date of birth', 'memberistic' ) . '</label>';
		echo '<input type="date" name="signature_dob" required max="' . esc_attr( wp_date( 'Y-m-d' ) ) . '" style="' . esc_attr( $in ) . '"></div>';
		echo '</div>';

		echo '<div style="display:flex;gap:12px;">';
		echo '<div style="flex:1;"><label style="' . esc_attr( $lb ) . '">' . esc_html__( 'Emergency contact name', 'memberistic' ) . '</label>';
		echo '<input type="text" name="emergency_name" style="' . esc_attr( $in ) . '"></div>';
		echo '<div style="flex:1;"><label style="' . esc_attr( $lb ) . '">' . esc_html__( 'Emergency contact phone', 'memberistic' ) . '</label>';
		echo '<input type="tel" name="emergency_phone" style="' . esc_attr( $in ) . '"></div>';
		echo '</div>';

		// Minors — the adult signs as parent/guardian for up to 4 children.
		echo '<label style="' . esc_attr( $lb ) . '">' . esc_html__( 'Minors you are signing for as parent/guardian (optional)', 'memberistic' ) . '</label>';
		echo '<div id="mw-minors">';
		echo '<div class="mw-minor-row" style="display:flex;gap:12px;">';
		echo '<input type="text" name="minor_name[]" placeholder="' . esc_attr__( "Minor's full name", 'memberistic' ) . '" style="' . esc_attr( $in ) . 'flex:2;">';
		echo '<input type="date" name="minor_dob[]" max="' . esc_attr( wp_date( 'Y-m-d' ) ) . '" style="' . esc_attr( $in ) . 'flex:1;">';
		echo '</div>';
		echo '</div>';
		echo '<button type="button" id="mw-addminor" style="margin:0 0 14px;padding:8px 14px;background:transparent;border:1px dashed #2A323D;border-radius:4px;color:#8A95A5;font-size:13px;cursor:pointer;">+ ' . esc_html__( 'Add another minor', 'memberistic' ) . '</button>';

		// Draw-to-sign pad. Exported as JPEG (white background) so the PDF
		// generator can embed it via DCTDecode with no image libraries.
		echo '<label style="' . esc_attr( $lb ) . '">' . esc_html__( 'Signature — please sign in the box below', 'memberistic' ) . '</label>';
		echo '<div style="background:#fff;border:1px solid #2A323D;border-radius:6px;">';
		echo '<canvas id="mw-sigpad" width="640" height="200" style="width:100%;height:auto;display:block;touch-action:none;border-radius:6px;"></canvas>';
		echo '</div>';
		echo '<div style="text-align:right;margin:6px 0 14px;"><button type="button" id="mw-sigclear" style="padding:6px 12px;background:transparent;border:1px solid #2A323D;border-radius:4px;color:#8A95A5;font-size:12px;cursor:pointer;">' . esc_html__( 'Clear signature', 'memberistic' ) . '</button></div>';
		echo '<input type="hidden" name="signature_data" id="mw-sigdata">';
		echo '<noscript><p style="color:#E8802F;">' . esc_html__( 'JavaScript is required to sign electronically. Please ask range staff for a paper waiver.', 'memberistic' ) . '</p></noscript>';
		echo '<script>(function(){'
			. 'var c=document.getElementById("mw-sigpad");if(!c||!c.getContext)return;'
			. 'var x=c.getContext("2d"),drawn=false,down=false;'
			. 'function blank(){x.fillStyle="#fff";x.fillRect(0,0,c.width,c.height);x.strokeStyle="#111";x.lineWidth=2.5;x.lineJoin=x.lineCap="round";}'
			. 'blank();'
			. 'function pos(e){var r=c.getBoundingClientRect();return{x:(e.clientX-r.left)*(c.width/r.width),y:(e.clientY-r.top)*(c.height/r.height)};}'
			. 'c.addEventListener("pointerdown",function(e){down=true;drawn=true;var p=pos(e);x.beginPath();x.moveTo(p.x,p.y);x.lineTo(p.x+.1,p.y+.1);x.stroke();e.preventDefault();});'
			. 'c.addEventListener("pointermove",function(e){if(!down)return;var p=pos(e);x.lineTo(p.x,p.y);x.stroke();e.preventDefault();});'
			. '["pointerup","pointerleave","pointercancel"].forEach(function(ev){c.addEventListener(ev,function(){down=false;});});'
			. 'document.getElementById("mw-sigclear").addEventListener("click",function(){blank();drawn=false;document.getElementById("mw-sigdata").value="";});'
			. 'var add=document.getElementById("mw-addminor");'
			. 'if(add){add.addEventListener("click",function(){'
			. 'var w=document.getElementById("mw-minors"),rows=w.querySelectorAll(".mw-minor-row");'
			. 'if(rows.length>=4)return;'
			. 'var t=rows[0].cloneNode(true);t.querySelectorAll("input").forEach(function(i){i.value="";});w.appendChild(t);});}'
			. 'var f=c.closest("form");'
			. 'if(f){f.addEventListener("submit",function(e){'
			. 'if(!drawn){e.preventDefault();alert(' . wp_json_encode( __( 'Please sign in the signature box.', 'memberistic' ) ) . ');return;}'
			. 'document.getElementById("mw-sigdata").value=c.toDataURL("image/jpeg",0.85);});}'
			. '})();</script>';
	}

	/**
	 * Read + validate the shared signing fields from $_POST (nonce already
	 * verified by the caller).
	 *
	 * @return array { fields: array, jpeg: string } or { error: string }.
	 */
	private static function collect_extra_fields() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- caller verified.
		$dob    = isset( $_POST['signature_dob'] ) ? sanitize_text_field( wp_unslash( $_POST['signature_dob'] ) ) : '';
		$phone  = isset( $_POST['signature_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['signature_phone'] ) ) : '';
		$em_n   = isset( $_POST['emergency_name'] ) ? sanitize_text_field( wp_unslash( $_POST['emergency_name'] ) ) : '';
		$em_p   = isset( $_POST['emergency_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['emergency_phone'] ) ) : '';
		$m_name = isset( $_POST['minor_name'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['minor_name'] ) ) : array();
		$m_dob  = isset( $_POST['minor_dob'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['minor_dob'] ) ) : array();
		$data   = isset( $_POST['signature_data'] ) ? (string) wp_unslash( $_POST['signature_data'] ) : '';
		// phpcs:enable

		$ts = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $dob ) ? strtotime( $dob . ' 00:00:00' ) : false;
		if ( false === $ts || $ts > current_time( 'timestamp' ) ) {
			return array( 'error' => __( 'Please enter your date of birth.', 'memberistic' ) );
		}
		$age = (int) floor( ( current_time( 'timestamp' ) - $ts ) / ( 365.2425 * DAY_IN_SECONDS ) );
		if ( $age < 18 ) {
			return array( 'error' => __( 'You must be 18 or older to sign this waiver. A parent or guardian signs for minors — add the minor below their own signature.', 'memberistic' ) );
		}
		if ( strlen( preg_replace( '/\D+/', '', $phone ) ) < 7 ) {
			return array( 'error' => __( 'Please enter a valid phone number.', 'memberistic' ) );
		}

		$minors = array();
		foreach ( $m_name as $i => $n ) {
			$n = trim( (string) $n );
			if ( '' === $n ) {
				continue;
			}
			$md = isset( $m_dob[ $i ] ) ? trim( (string) $m_dob[ $i ] ) : '';
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $md ) ) {
				return array( 'error' => __( 'Please enter a date of birth for each minor listed.', 'memberistic' ) );
			}
			$minors[] = array(
				'name' => $n,
				'dob'  => $md,
			);
			if ( count( $minors ) >= 4 ) {
				break;
			}
		}

		if ( 0 !== strpos( $data, 'data:image/jpeg;base64,' ) ) {
			return array( 'error' => __( 'Please sign in the signature box.', 'memberistic' ) );
		}
		$jpeg = base64_decode( substr( $data, strlen( 'data:image/jpeg;base64,' ) ), true );
		$max  = (int) apply_filters( 'memberistic_signature_image_max_bytes', 262144 );
		if ( false === $jpeg || strlen( $jpeg ) < 100 || strlen( $jpeg ) > $max || null === Waiver_PDF::jpeg_dims( $jpeg ) ) {
			return array( 'error' => __( 'Your signature could not be read. Please clear the box and sign again.', 'memberistic' ) );
		}

		return array(
			'fields' => array(
				'dob'             => $dob,
				'phone'           => $phone,
				'emergency_name'  => $em_n,
				'emergency_phone' => $em_p,
				'minors'          => $minors,
			),
			'jpeg'   => $jpeg,
		);
	}

	/**
	 * File the permanent artifacts for a fresh signature: the drawn-signature
	 * JPEG and the generated signed-waiver PDF, both in the hardened private
	 * document store. The PDF becomes the signature's canonical attachment.
	 *
	 * @param int    $sig_id  Signature id.
	 * @param string $jpeg    Raw JPEG bytes of the drawn signature.
	 * @param array  $context Optional user_id / person_id / membership_id.
	 */
	private static function store_signature_artifacts( $sig_id, $jpeg, $context = array() ) {
		$sig = Waiver_Service::get_signature( (int) $sig_id );
		if ( ! $sig ) {
			return;
		}
		$ctx = array_merge(
			$context,
			array(
				'signature_id' => (int) $sig_id,
				'uploaded_by'  => ! empty( $context['user_id'] ) ? (int) $context['user_id'] : 0,
			)
		);
		if ( '' !== $jpeg ) {
			Documents::store_generated(
				$jpeg,
				'signature-' . (int) $sig_id . '.jpg',
				array_merge( $ctx, array(
					'doc_type' => 'waiver_signature_image',
					'label'    => __( 'Drawn signature', 'memberistic' ),
				) )
			);
		}
		$pdf    = Waiver_PDF::build( $sig, (string) $jpeg );
		$doc_id = Documents::store_generated(
			$pdf,
			'waiver-' . (int) $sig_id . '.pdf',
			array_merge( $ctx, array(
				'doc_type' => 'waiver_pdf',
				'label'    => __( 'Signed waiver (PDF)', 'memberistic' ),
			) )
		);
		if ( ! is_wp_error( $doc_id ) && $doc_id ) {
			global $wpdb;
			$wpdb->update( Waiver_Service::signatures_table(), array( 'attachment_id' => (int) $doc_id ), array( 'id' => (int) $sig_id ) );
		}
	}

	public static function maybe_handle() {
		// Kiosk check-in hub (its own query var).
		if ( ! empty( $_GET['memberistic_kiosk'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			self::render_kiosk();
			return;
		}
		if ( empty( $_GET[ Waiver_Service::QUERY ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$token = sanitize_text_field( wp_unslash( $_GET[ Waiver_Service::QUERY ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// Guest / non-member signing surface.
		if ( 'guest' === strtolower( $token ) ) {
			self::handle_guest();
			return;
		}

		$user_id = Waiver_Service::user_by_token( $token );
		if ( ! $user_id ) {
			self::render( __( 'Waiver not found', 'memberistic' ), '<p>' . esc_html__( 'This waiver link is invalid or has expired. Please contact the range.', 'memberistic' ) . '</p>', 404 );
		}
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			self::render( __( 'Waiver not found', 'memberistic' ), '<p>' . esc_html__( 'This waiver link is invalid. Please contact the range.', 'memberistic' ) . '</p>', 404 );
		}

		if ( 'POST' === strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
			if ( ! isset( $_POST['memberistic_waiver_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['memberistic_waiver_nonce'] ) ), 'memberistic_waiver_' . $token ) ) {
				self::render( __( 'Session expired', 'memberistic' ), '<p>' . esc_html__( 'Please reload the page and try again.', 'memberistic' ) . '</p>', 400 );
			}
			$typed = isset( $_POST['signature_name'] ) ? sanitize_text_field( wp_unslash( $_POST['signature_name'] ) ) : '';
			$agree = ! empty( $_POST['agree'] );
			if ( ! $agree || '' === $typed ) {
				self::render_form( $user, $token, __( 'Please type your full name and check the agreement box.', 'memberistic' ) );
			}
			$extra = self::collect_extra_fields();
			if ( ! empty( $extra['error'] ) ) {
				self::render_form( $user, $token, (string) $extra['error'] );
			}
			$ip     = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
			$sig_id = (int) Waiver_Service::mark_signed( $user_id, $typed, array_merge( array( 'ip' => $ip, 'source' => 'self_serve' ), $extra['fields'] ) );

			// The link that just signed the waiver should not remain a
			// standing "sign as this member" credential — rotate now so a
			// copy of this exact URL (email history, shared device,
			// forwarded message) can't be replayed later.
			Waiver_Service::rotate_token( $user_id );

			$membership = Memberships_Repository::get_by_user_id( $user_id );
			$person     = $membership ? People_Repository::get_primary_by_membership( (int) $membership['id'] ) : null;
			$doc_ctx    = array(
				'user_id'       => $user_id,
				'person_id'     => $person ? (int) $person['id'] : 0,
				'membership_id' => $membership ? (int) $membership['id'] : 0,
			);

			// Optional supporting document (e.g. photo of ID / paper waiver).
			// The token authenticates this specific member; store privately.
			// Kept as its own documents row — the generated PDF below stays
			// the signature's canonical attachment.
			if ( $sig_id && ! empty( $_FILES['waiver_doc']['name'] ) ) {
				Documents::store_upload(
					$_FILES['waiver_doc'], // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
					array_merge( $doc_ctx, array(
						'signature_id' => $sig_id,
						'doc_type'     => 'waiver_attachment',
						'uploaded_by'  => $user_id,
					) )
				);
			}

			// File the canonical artifacts: drawn-signature JPEG + generated
			// signed-waiver PDF (becomes the signature's attachment).
			if ( $sig_id ) {
				self::store_signature_artifacts( $sig_id, (string) $extra['jpeg'], $doc_ctx );
			}
			$account = home_url( '/account/' );
			self::render(
				__( 'Waiver complete', 'memberistic' ),
				'<p>' . esc_html__( 'Thank you — your waiver is on file. Show your member QR at the range desk on your next visit.', 'memberistic' ) . '</p>'
				. '<p><a class="btn" href="' . esc_url( $account ) . '">' . esc_html__( 'Go to my account', 'memberistic' ) . '</a></p>',
				200
			);
		}

		// Already current?
		$membership = Memberships_Repository::get_by_user_id( $user_id );
		$person     = $membership ? People_Repository::get_primary_by_membership( (int) $membership['id'] ) : null;
		if ( $person && Waiver_Service::is_current( $person ) ) {
			$exp = ! empty( $person['waiver_expires_at'] ) ? date_i18n( get_option( 'date_format' ), strtotime( (string) $person['waiver_expires_at'] ) ) : '';
			$msg = $exp
				? sprintf( /* translators: %s: date */ esc_html__( 'Your waiver is signed and on file (valid through %s). Thank you!', 'memberistic' ), esc_html( $exp ) )
				: esc_html__( 'Your waiver is signed and on file. Thank you!', 'memberistic' );
			self::render( __( 'Waiver already on file', 'memberistic' ), '<p>' . $msg . '</p>', 200 );
		}

		self::render_form( $user, $token );
	}

	private static function render_form( $user, $token, $error = '' ) {
		$text = Waiver_Service::waiver_text();
		ob_start();
		if ( $error ) {
			echo '<p style="color:#E8802F;font-weight:600;">' . esc_html( $error ) . '</p>';
		}
		echo '<div style="text-align:left;background:#11161D;border:1px solid #2A323D;border-radius:8px;padding:18px;max-height:280px;overflow:auto;color:#CBCAD2;line-height:1.6;font-size:14px;white-space:pre-wrap;">' . esc_html( $text ) . '</div>';
		echo '<form method="post" enctype="multipart/form-data" style="margin-top:18px;text-align:left;">';
		wp_nonce_field( 'memberistic_waiver_' . $token, 'memberistic_waiver_nonce' );
		echo '<label style="display:block;font-size:12px;letter-spacing:.12em;text-transform:uppercase;color:#8A95A5;margin-bottom:6px;">' . esc_html__( 'Type your full legal name', 'memberistic' ) . '</label>';
		echo '<input type="text" name="signature_name" required value="' . esc_attr( $user->display_name ) . '" style="width:100%;padding:12px 14px;background:#0F1115;border:1px solid #2A323D;border-radius:4px;color:#fff;font-size:16px;margin-bottom:14px;box-sizing:border-box;">';
		self::render_extra_fields();
		echo '<label style="display:flex;gap:10px;align-items:flex-start;color:#CBCAD2;font-size:14px;cursor:pointer;"><input type="checkbox" name="agree" value="1" required style="width:20px;height:20px;margin-top:2px;"> <span>' . esc_html__( 'I have read and agree to the waiver above, and I am signing electronically.', 'memberistic' ) . '</span></label>';
		echo '<label style="display:block;font-size:12px;letter-spacing:.12em;text-transform:uppercase;color:#8A95A5;margin:16px 0 6px;">' . esc_html__( 'Optional: attach a photo of your ID or a signed paper waiver (PDF/JPG/PNG)', 'memberistic' ) . '</label>';
		echo '<input type="file" name="waiver_doc" accept=".pdf,.jpg,.jpeg,.png,.webp" style="width:100%;color:#CBCAD2;font-size:14px;">';
		echo '<button type="submit" style="margin-top:18px;width:100%;padding:14px;background:#E8802F;color:#111;border:0;border-radius:4px;font-weight:700;font-size:15px;letter-spacing:.06em;text-transform:uppercase;cursor:pointer;">' . esc_html__( 'Sign Waiver', 'memberistic' ) . '</button>';
		echo '</form>';
		self::render( __( 'Range Waiver', 'memberistic' ), ob_get_clean(), 200 );
	}

	private static function render( $title, $html, $code = 200 ) {
		nocache_headers();
		status_header( (int) $code );
		header( 'Content-Type: text/html; charset=utf-8' );
		$brand = memberistic_get_brand_label();
		// Brand palette is configurable per-site. Was hardcoded to the launch
		// partner's dark + gold scheme; CSS variables let the kiosk + the
		// guest waiver pages stay in sync with whatever the site admin sets.
		$primary = (string) get_option( 'memberistic_primary_brand_color', '#0F2044' );
		$accent  = (string) get_option( 'memberistic_accent_brand_color', '#E8802F' );
		echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
		echo '<title>' . esc_html( $brand ) . ' — ' . esc_html( $title ) . '</title>';
		echo '<style>:root{--memberistic-brand-primary:' . esc_attr( $primary ) . ';--memberistic-brand-accent:' . esc_attr( $accent ) . ';}'
			. 'body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:var(--memberistic-brand-primary);color:#fff;margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;}'
			. '.b{max-width:520px;width:100%;background:#1A1F26;border:1px solid #2A323D;border-radius:12px;padding:32px;text-align:center;box-sizing:border-box;}'
			. '.brand{font-family:"Bebas Neue",Impact,sans-serif;letter-spacing:.18em;color:#C9A84C;text-transform:uppercase;font-size:13px;margin-bottom:14px;}'
			. 'h1{font-size:22px;margin:0 0 16px;}a.btn{display:inline-block;margin-top:8px;padding:12px 22px;background:var(--memberistic-brand-accent);color:#111;text-decoration:none;border-radius:4px;font-weight:700;}</style>';
		echo '</head><body><div class="b"><div class="brand">' . esc_html( $brand ) . '</div><h1>' . esc_html( $title ) . '</h1>' . $html . '</div></body></html>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}
}

/**
 * Admin console: Memberistic → Waivers.
 */
final class Waiver_Admin_Page {

	const CAP = 'edit_memberistic_members';

	public static function handle_actions() {
		if ( empty( $_POST['memberistic_waiver_action'] ) ) {
			return;
		}
		if ( ! memberistic_current_user_can( self::CAP ) ) {
			return;
		}
		$action = sanitize_key( wp_unslash( $_POST['memberistic_waiver_action'] ) );

		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'memberistic_waivers' ) ) {
			return;
		}

		$notice = '';
		switch ( $action ) {
			case 'save_settings':
				$days     = isset( $_POST['waiver_validity_days'] ) ? absint( $_POST['waiver_validity_days'] ) : Waiver_Service::DEFAULT_VALIDITY;
				$reminder = isset( $_POST['waiver_renewal_reminder_days'] ) ? absint( $_POST['waiver_renewal_reminder_days'] ) : 30;
				update_option( 'memberistic_waiver_validity_days', $days > 0 ? $days : Waiver_Service::DEFAULT_VALIDITY );
				update_option( 'memberistic_waiver_renewal_reminder_days', min( 365, $reminder ) );
				$notice = __( 'Waiver settings saved.', 'memberistic' );
				break;
			case 'publish_version':
				$text      = isset( $_POST['waiver_text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['waiver_text'] ) ) : '';
				$title     = isset( $_POST['waiver_version_title'] ) ? sanitize_text_field( wp_unslash( $_POST['waiver_version_title'] ) ) : '';
				$reconsent = ! empty( $_POST['waiver_requires_reconsent'] );
				$current   = Waiver_Versions::current();
				if ( '' === trim( $text ) ) {
					$notice = __( 'The waiver text cannot be empty.', 'memberistic' );
					break;
				}
				if ( $current && hash( 'sha256', trim( $text ) ) === (string) $current['text_hash'] && ! $reconsent ) {
					$notice = __( 'No changes — the text matches the current version.', 'memberistic' );
					break;
				}
				$vid = Waiver_Versions::publish( $text, $title, $reconsent, get_current_user_id() );
				if ( $vid ) {
					$notice = $reconsent
						? sprintf( /* translators: %d: version id */ __( 'Version %d published. All previously signed waivers now require re-consent.', 'memberistic' ), $vid )
						: sprintf( /* translators: %d: version id */ __( 'Version %d published. Existing signatures remain valid until their normal expiry.', 'memberistic' ), $vid );
				} else {
					$notice = __( 'The new version could not be published.', 'memberistic' );
				}
				break;
			case 'kiosk_link':
				$station = isset( $_POST['kiosk_station'] ) ? sanitize_title( wp_unslash( $_POST['kiosk_station'] ) ) : '';
				$token   = Waiver_Kiosk::mint_token( $station ?: 'front-desk' );
				set_transient(
					'memberistic_kiosk_link_' . get_current_user_id(),
					array(
						'url'     => Waiver_Kiosk::kiosk_url( $token ),
						'station' => $station ?: 'front-desk',
						'expires' => time() + Waiver_Kiosk::ttl(),
					),
					5 * MINUTE_IN_SECONDS
				);
				$notice = __( 'Kiosk link generated — see the Kiosk stations card below. Open it on the kiosk device.', 'memberistic' );
				break;
			case 'save_kiosk_pin':
				$pin = isset( $_POST['kiosk_staff_pin'] ) ? preg_replace( '/\D+/', '', (string) wp_unslash( $_POST['kiosk_staff_pin'] ) ) : '';
				update_option( Waiver_Kiosk::PIN_OPTION, substr( $pin, 0, 8 ), false );
				$notice = '' === $pin
					? __( 'Kiosk staff PIN removed.', 'memberistic' )
					: __( 'Kiosk staff PIN saved.', 'memberistic' );
				break;
			case 'bulk_sign':
				$n      = Waiver_Service::bulk_mark_active_signed();
				$notice = sprintf( /* translators: %d: count */ __( 'Marked %d member(s) as signed.', 'memberistic' ), $n );
				break;
			case 'email_missing':
				$n      = Waiver_Service::email_all_missing();
				$notice = sprintf( /* translators: %d: count */ __( 'Sent the waiver link to %d member(s).', 'memberistic' ), $n );
				break;
			case 'mark_one':
				$pid = isset( $_POST['person_id'] ) ? absint( $_POST['person_id'] ) : 0;
				if ( $pid && Waiver_Service::mark_person_signed( $pid ) ) {
					$notice = __( 'Waiver marked signed.', 'memberistic' );
				}
				break;
			case 'staff_upload':
				$pid = isset( $_POST['person_id'] ) ? absint( $_POST['person_id'] ) : 0;
				$row = $pid ? People_Repository::get( $pid ) : null;
				if ( $row && ! empty( $_FILES['memberistic_doc_file']['name'] ) ) {
					$res = Documents::store_upload(
						$_FILES['memberistic_doc_file'], // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
						array(
							'user_id'       => (int) ( $row['wp_user_id'] ?? 0 ),
							'person_id'     => $pid,
							'membership_id' => (int) ( $row['membership_id'] ?? 0 ),
							'label'         => isset( $_POST['doc_label'] ) ? sanitize_text_field( wp_unslash( $_POST['doc_label'] ) ) : '',
							'doc_type'      => 'staff_upload',
							'uploaded_by'   => get_current_user_id(),
						)
					);
					$notice = is_wp_error( $res ) ? $res->get_error_message() : __( 'Document uploaded.', 'memberistic' );
				} else {
					$notice = __( 'No file selected.', 'memberistic' );
				}
				wp_safe_redirect(
					add_query_arg(
						array(
							'page'               => 'memberistic-waivers',
							'member'             => $pid,
							'memberistic_notice' => rawurlencode( $notice ),
						),
						admin_url( 'admin.php' )
					)
				);
				exit;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                     => 'memberistic-waivers',
					'memberistic_notice'       => rawurlencode( $notice ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Per-member detail panel: waiver status + signature history + documents,
	 * with a staff upload form. Rendered when ?member=PERSON_ID is present.
	 */
	private static function render_member_detail( $person_id ) {
		$person = People_Repository::get( $person_id );
		if ( ! $person ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Member not found.', 'memberistic' ) . '</p></div>';
			return;
		}
		$docs = Documents::get_for_person( $person_id );
		$back = admin_url( 'admin.php?page=memberistic-waivers' );
		?>
		<p><a href="<?php echo esc_url( $back ); ?>">&larr; <?php esc_html_e( 'Back to all members', 'memberistic' ); ?></a></p>
		<div class="memberistic-card">
			<h2><?php echo esc_html( $person['full_name'] ?: ( $person['email'] ?: __( 'Member', 'memberistic' ) ) ); ?></h2>
			<p>
				<strong><?php esc_html_e( 'Waiver', 'memberistic' ); ?>:</strong>
				<?php echo esc_html( ucfirst( str_replace( '_', ' ', (string) $person['waiver_status'] ) ) ); ?>
				<?php if ( ! empty( $person['waiver_expires_at'] ) ) : ?>
					· <?php esc_html_e( 'valid through', 'memberistic' ); ?> <?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( (string) $person['waiver_expires_at'] ) ) ); ?>
				<?php endif; ?>
			</p>
			<?php if ( 'signed' !== (string) $person['waiver_status'] ) : ?>
				<form method="post" style="display:inline-block;margin-right:8px;">
					<?php wp_nonce_field( 'memberistic_waivers' ); ?>
					<input type="hidden" name="memberistic_waiver_action" value="mark_one">
					<input type="hidden" name="person_id" value="<?php echo (int) $person_id; ?>">
					<button type="submit" class="button"><?php esc_html_e( 'Mark waiver signed', 'memberistic' ); ?></button>
				</form>
			<?php endif; ?>
		</div>
		<div class="memberistic-card">
			<h2><?php esc_html_e( 'Documents', 'memberistic' ); ?></h2>
			<form method="post" enctype="multipart/form-data" style="margin-bottom:14px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
				<?php wp_nonce_field( 'memberistic_waivers' ); ?>
				<input type="hidden" name="memberistic_waiver_action" value="staff_upload">
				<input type="hidden" name="person_id" value="<?php echo (int) $person_id; ?>">
				<input type="text" name="doc_label" placeholder="<?php esc_attr_e( 'Label (e.g. Signed paper waiver)', 'memberistic' ); ?>" class="regular-text">
				<input type="file" name="memberistic_doc_file" accept=".pdf,.jpg,.jpeg,.png,.webp" required>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Upload document', 'memberistic' ); ?></button>
			</form>
			<?php if ( $docs ) : ?>
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'Document', 'memberistic' ); ?></th><th><?php esc_html_e( 'Type', 'memberistic' ); ?></th><th><?php esc_html_e( 'Uploaded', 'memberistic' ); ?></th><th></th></tr></thead>
					<tbody>
					<?php foreach ( $docs as $d ) : ?>
						<tr>
							<td><?php echo esc_html( $d['label'] ?: $d['file_name'] ); ?></td>
							<td><?php echo esc_html( ucfirst( str_replace( '_', ' ', (string) $d['doc_type'] ) ) ); ?></td>
							<td><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( (string) $d['created_at'] ) ) ); ?></td>
							<td><a class="button button-small" href="<?php echo esc_url( Documents::download_url( (int) $d['id'] ) ); ?>"><?php esc_html_e( 'Download', 'memberistic' ); ?></a></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p><?php esc_html_e( 'No documents on file for this member yet.', 'memberistic' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Neutralise spreadsheet formula injection: a leading =, +, -, @, or
	 * control char makes Excel/Sheets treat a cell as a formula. Guest signer
	 * names/emails are attacker-controllable, so prefix risky cells with a
	 * single quote before they reach the CSV.
	 *
	 * @param mixed $value Cell value.
	 * @return string
	 */
	public static function csv_safe( $value ) {
		$value = (string) $value;
		if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			return "'" . $value;
		}
		return $value;
	}

	/** Nonced URL that downloads the full signed-waiver archive as CSV. */
	public static function export_url() {
		return wp_nonce_url( add_query_arg( 'memberistic_waiver_export', 'csv', home_url( '/' ) ), 'memberistic_waiver_export' );
	}

	/** Nonced URL that opens a single signature as a printable page. */
	public static function print_url( $signature_id ) {
		return wp_nonce_url( add_query_arg( 'memberistic_waiver_print', (int) $signature_id, home_url( '/' ) ), 'memberistic_waiver_export' );
	}

	/** Nonced URL that opens the printable check-in QR poster. */
	public static function poster_url() {
		return wp_nonce_url( add_query_arg( 'memberistic_waiver_poster', '1', home_url( '/' ) ), 'memberistic_waiver_export' );
	}

	/**
	 * Handle the CSV export + printable single-signature view. Runs on init
	 * (before output) and is gated by capability + nonce.
	 */
	public static function maybe_handle_export() {
		$want_csv    = ! empty( $_GET['memberistic_waiver_export'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$want_print  = ! empty( $_GET['memberistic_waiver_print'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$want_poster = ! empty( $_GET['memberistic_waiver_poster'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $want_csv && ! $want_print && ! $want_poster ) {
			return;
		}
		if ( ! memberistic_current_user_can( self::CAP ) ) {
			return;
		}
		if ( empty( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'memberistic_waiver_export' ) ) {
			wp_die( esc_html__( 'This export link could not be verified.', 'memberistic' ), '', array( 'response' => 403 ) );
		}

		if ( $want_print ) {
			self::render_print( absint( $_GET['memberistic_waiver_print'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			exit;
		}

		if ( $want_poster ) {
			self::render_poster();
			exit;
		}

		// Stream CSV of every signature, including the snapshotted waiver text.
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="memberistic-waivers-' . gmdate( 'Y-m-d' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'id', 'signed_at', 'expires_at', 'source', 'station', 'signer_name', 'signer_email', 'dob', 'phone', 'emergency_name', 'emergency_phone', 'minors', 'membership_id', 'ip', 'waiver_version', 'text_hash', 'has_attachment', 'waiver_text' ) );
		$page = 0;
		do {
			$rows = Waiver_Service::get_signatures( 1000, $page * 1000 );
			foreach ( $rows as $r ) {
				fputcsv(
					$out,
					array_map(
						array( __CLASS__, 'csv_safe' ),
						array(
							$r['id'],
							$r['signed_at'],
							$r['expires_at'],
							$r['source'],
							$r['station'] ?? '',
							$r['signer_name'],
							$r['signer_email'],
							$r['dob'] ?? '',
							$r['phone'] ?? '',
							$r['emergency_name'] ?? '',
							$r['emergency_phone'] ?? '',
							$r['minors_json'] ?? '',
							$r['membership_id'],
							$r['ip'],
							$r['waiver_version_id'] ?? '',
							$r['text_hash'],
							! empty( $r['attachment_id'] ) ? 'yes' : 'no',
							$r['waiver_text'],
						)
					)
				);
			}
			$page++;
		} while ( count( $rows ) === 1000 && $page < 100 );
		fclose( $out );
		exit;
	}

	/**
	 * Printable single-signature page (browser → Save as PDF for the archive).
	 */
	private static function render_print( $signature_id ) {
		$sig = Waiver_Service::get_signature( $signature_id );
		if ( ! $sig ) {
			wp_die( esc_html__( 'Signature not found.', 'memberistic' ), '', array( 'response' => 404 ) );
		}
		$brand = memberistic_get_brand_label();
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		$signed  = $sig['signed_at'] ? date_i18n( 'F j, Y g:i a', strtotime( (string) $sig['signed_at'] ) ) : '';
		$expires = $sig['expires_at'] ? date_i18n( 'F j, Y', strtotime( (string) $sig['expires_at'] ) ) : '';
		echo '<!doctype html><html><head><meta charset="utf-8"><title>' . esc_html( $brand ) . ' — ' . esc_html__( 'Signed Waiver', 'memberistic' ) . '</title>';
		echo '<style>body{font-family:Georgia,serif;max-width:760px;margin:40px auto;padding:0 24px;color:#111;line-height:1.6;}h1{font-size:20px;}.meta{margin:18px 0;padding:14px 18px;background:#f5f5f5;border:1px solid #ddd;border-radius:6px;font-family:monospace;font-size:13px;}.meta div{margin:3px 0;}.txt{white-space:pre-wrap;border:1px solid #ddd;padding:18px;border-radius:6px;}@media print{button{display:none;}}</style></head><body>';
		echo '<button onclick="window.print()" style="float:right;padding:8px 16px;">' . esc_html__( 'Print / Save PDF', 'memberistic' ) . '</button>';
		echo '<h1>' . esc_html( $brand ) . ' — ' . esc_html__( 'Liability Waiver (signed copy)', 'memberistic' ) . '</h1>';
		echo '<div class="meta">';
		echo '<div><strong>' . esc_html__( 'Signer', 'memberistic' ) . ':</strong> ' . esc_html( (string) $sig['signer_name'] ) . ' &lt;' . esc_html( (string) $sig['signer_email'] ) . '&gt;</div>';
		if ( ! empty( $sig['dob'] ) ) {
			echo '<div><strong>' . esc_html__( 'Date of birth', 'memberistic' ) . ':</strong> ' . esc_html( (string) $sig['dob'] ) . '</div>';
		}
		if ( ! empty( $sig['phone'] ) ) {
			echo '<div><strong>' . esc_html__( 'Phone', 'memberistic' ) . ':</strong> ' . esc_html( (string) $sig['phone'] ) . '</div>';
		}
		if ( ! empty( $sig['emergency_name'] ) || ! empty( $sig['emergency_phone'] ) ) {
			echo '<div><strong>' . esc_html__( 'Emergency contact', 'memberistic' ) . ':</strong> ' . esc_html( trim( (string) ( $sig['emergency_name'] ?? '' ) . ' ' . (string) ( $sig['emergency_phone'] ?? '' ) ) ) . '</div>';
		}
		if ( ! empty( $sig['minors_json'] ) ) {
			$minors = (array) json_decode( (string) $sig['minors_json'], true );
			$names  = array();
			foreach ( $minors as $m ) {
				if ( ! empty( $m['name'] ) ) {
					$names[] = (string) $m['name'] . ( ! empty( $m['dob'] ) ? ' (DOB ' . (string) $m['dob'] . ')' : '' );
				}
			}
			if ( $names ) {
				echo '<div><strong>' . esc_html__( 'Minors covered', 'memberistic' ) . ':</strong> ' . esc_html( implode( '; ', $names ) ) . '</div>';
			}
		}
		echo '<div><strong>' . esc_html__( 'Signed', 'memberistic' ) . ':</strong> ' . esc_html( $signed ) . '</div>';
		echo '<div><strong>' . esc_html__( 'Valid through', 'memberistic' ) . ':</strong> ' . esc_html( $expires ) . '</div>';
		echo '<div><strong>' . esc_html__( 'Method', 'memberistic' ) . ':</strong> ' . esc_html( (string) $sig['source'] . ( ! empty( $sig['station'] ) ? ' @ ' . (string) $sig['station'] : '' ) ) . '</div>';
		echo '<div><strong>IP:</strong> ' . esc_html( (string) $sig['ip'] ) . '</div>';
		echo '<div><strong>' . esc_html__( 'Document hash (SHA-256)', 'memberistic' ) . ':</strong> ' . esc_html( (string) $sig['text_hash'] ) . '</div>';
		echo '</div>';
		echo '<div class="txt">' . esc_html( (string) $sig['waiver_text'] ) . '</div>';
		echo '</body></html>';
		exit;
	}

	/**
	 * Printable check-in poster: a large QR pointing at the kiosk/check-in
	 * page, for the desk to print and post. Browser → Print/Save PDF.
	 */
	private static function render_poster() {
		$brand = memberistic_get_brand_label();
		$url   = Waiver_Public::best_kiosk_url();
		$qr    = class_exists( '\WordPressistic\Memberistic\Utilities\QR' )
			? \WordPressistic\Memberistic\Utilities\QR::svg( $url, 320, '#ffffff', '#0F1115' )
			: '';
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc_html( $brand ) . ' — ' . esc_html__( 'Check-In Poster', 'memberistic' ) . '</title>';
		echo '<style>@page{size:portrait;margin:0.5in;}body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;text-align:center;color:#111;margin:0;padding:48px 24px;}'
			. '.brand{font-size:22px;letter-spacing:.18em;text-transform:uppercase;color:#A8862F;font-weight:700;margin-bottom:8px;}'
			. 'h1{font-size:40px;margin:8px 0 4px;}h2{font-size:20px;font-weight:400;color:#444;margin:0 0 28px;}'
			. '.qr{display:inline-block;padding:20px;border:2px solid #111;border-radius:14px;}'
			. '.qr svg{width:320px;height:320px;display:block;}'
			. '.url{margin-top:22px;font-family:monospace;font-size:16px;word-break:break-all;}'
			. '.steps{max-width:520px;margin:28px auto 0;text-align:left;font-size:17px;line-height:1.7;}'
			. 'button{margin-top:28px;padding:10px 20px;font-size:15px;}@media print{button{display:none;}}</style></head><body>';
		echo '<button onclick="window.print()">' . esc_html__( 'Print / Save PDF', 'memberistic' ) . '</button>';
		echo '<div class="brand">' . esc_html( $brand ) . '</div>';
		echo '<h1>' . esc_html__( 'Check In Here', 'memberistic' ) . '</h1>';
		echo '<h2>' . esc_html__( 'Scan with your phone camera to sign your waiver & check in', 'memberistic' ) . '</h2>';
		echo '<div class="qr">' . $qr . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trusted SVG generator.
		echo '<div class="url">' . esc_html( $url ) . '</div>';
		echo '<div class="steps">1. ' . esc_html__( 'Point your phone camera at the code above.', 'memberistic' ) . '<br>2. ' . esc_html__( 'Tap the link, read and sign the waiver.', 'memberistic' ) . '<br>3. ' . esc_html__( 'Show your confirmation (or member QR) at the desk.', 'memberistic' ) . '</div>';
		echo '</body></html>';
		exit;
	}

	public static function render() {
		if ( ! memberistic_current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'memberistic' ) );
		}

		$counts = Waiver_Service::counts();
		$filter = isset( $_GET['waiver_status'] ) ? sanitize_key( wp_unslash( $_GET['waiver_status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$rows   = Waiver_Service::list_people( $filter, 200, 0 );
		$notice = isset( $_GET['memberistic_notice'] ) ? sanitize_text_field( wp_unslash( $_GET['memberistic_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap memberistic-wrap">
			<h1><?php esc_html_e( 'Waivers', 'memberistic' ); ?></h1>
			<?php if ( $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
			<?php endif; ?>

			<?php
			$member_id = isset( $_GET['member'] ) ? absint( $_GET['member'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( $member_id ) {
				self::render_member_detail( $member_id );
				echo '</div>';
				return;
			}
			?>

			<div class="memberistic-card">
				<h2><?php esc_html_e( 'Waiver status (active members)', 'memberistic' ); ?></h2>
				<p style="font-size:15px;">
					<strong style="color:#16794a;"><?php echo (int) $counts['signed']; ?></strong> <?php esc_html_e( 'signed', 'memberistic' ); ?>
					&nbsp;·&nbsp; <strong style="color:#b45309;"><?php echo (int) $counts['missing']; ?></strong> <?php esc_html_e( 'missing', 'memberistic' ); ?>
					&nbsp;·&nbsp; <strong style="color:#b45309;"><?php echo (int) $counts['expired']; ?></strong> <?php esc_html_e( 'expired', 'memberistic' ); ?>
					&nbsp;·&nbsp; <strong><?php echo (int) $counts['total']; ?></strong> <?php esc_html_e( 'total', 'memberistic' ); ?>
				</p>
				<form method="post" style="display:inline-block;margin-right:10px;" onsubmit="return confirm('<?php echo esc_js( __( 'Mark every active member with a missing or expired waiver as signed? Use this only for members who signed on paper in store.', 'memberistic' ) ); ?>');">
					<?php wp_nonce_field( 'memberistic_waivers' ); ?>
					<input type="hidden" name="memberistic_waiver_action" value="bulk_sign">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Mark all missing/expired as signed (in-store backlog)', 'memberistic' ); ?></button>
				</form>
				<form method="post" style="display:inline-block;">
					<?php wp_nonce_field( 'memberistic_waivers' ); ?>
					<input type="hidden" name="memberistic_waiver_action" value="email_missing">
					<button type="submit" class="button button-secondary"><?php esc_html_e( 'Email waiver link to all missing', 'memberistic' ); ?></button>
				</form>
			</div>

			<div class="memberistic-card">
				<h2><?php esc_html_e( 'Signed waiver archive', 'memberistic' ); ?></h2>
				<p>
					<?php
					/* translators: %d: number of recorded signatures */
					printf( esc_html__( '%d signature record(s) on file. Each export includes the signer, date, expiry, IP, the exact waiver text they agreed to, and a SHA-256 hash of that text.', 'memberistic' ), (int) Waiver_Service::count_signatures() );
					?>
				</p>
				<p>
					<a class="button button-primary" href="<?php echo esc_url( self::export_url() ); ?>"><?php esc_html_e( 'Download all signed waivers (CSV)', 'memberistic' ); ?></a>
					<a class="button" target="_blank" rel="noopener" href="<?php echo esc_url( Waiver_Public::best_kiosk_url() ); ?>"><?php esc_html_e( 'Open check-in kiosk', 'memberistic' ); ?></a>
					<a class="button" target="_blank" rel="noopener" href="<?php echo esc_url( self::poster_url() ); ?>"><?php esc_html_e( 'Print check-in QR poster', 'memberistic' ); ?></a>
				</p>
				<p class="description"><?php esc_html_e( 'Guest/non-member signing page (no login): share the link above or embed the [memberistic_guest_waiver] shortcode on a public page or desk kiosk.', 'memberistic' ); ?></p>
				<?php
				$sigs = Waiver_Service::get_signatures( 50, 0 );
				if ( $sigs ) :
					?>
					<table class="widefat striped" style="margin-top:12px;">
						<thead><tr>
							<th><?php esc_html_e( 'Signed', 'memberistic' ); ?></th>
							<th><?php esc_html_e( 'Signer', 'memberistic' ); ?></th>
							<th><?php esc_html_e( 'Method', 'memberistic' ); ?></th>
							<th><?php esc_html_e( 'Expires', 'memberistic' ); ?></th>
							<th><?php esc_html_e( 'Attachment', 'memberistic' ); ?></th>
							<th><?php esc_html_e( 'Copy', 'memberistic' ); ?></th>
						</tr></thead>
						<tbody>
						<?php foreach ( $sigs as $s ) : ?>
							<tr>
								<td><?php echo esc_html( $s['signed_at'] ? date_i18n( 'M j, Y', strtotime( (string) $s['signed_at'] ) ) : '—' ); ?></td>
								<td><?php echo esc_html( ( $s['signer_name'] ?: '—' ) . ( $s['signer_email'] ? ' (' . $s['signer_email'] . ')' : '' ) ); ?></td>
								<td><?php echo esc_html( ucfirst( str_replace( '_', ' ', (string) $s['source'] ) ) ); ?></td>
								<td><?php echo esc_html( $s['expires_at'] ? date_i18n( 'M j, Y', strtotime( (string) $s['expires_at'] ) ) : '—' ); ?></td>
								<td><?php if ( ! empty( $s['attachment_id'] ) ) : ?><a href="<?php echo esc_url( Documents::download_url( (int) $s['attachment_id'] ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View', 'memberistic' ); ?></a><?php else : ?>—<?php endif; ?></td>
								<td><a class="button button-small" target="_blank" rel="noopener" href="<?php echo esc_url( self::print_url( (int) $s['id'] ) ); ?>"><?php esc_html_e( 'Print', 'memberistic' ); ?></a></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<p class="description"><?php esc_html_e( 'Showing the 50 most recent. Use the CSV download for the full archive.', 'memberistic' ); ?></p>
				<?php endif; ?>
			</div>

			<div class="memberistic-card">
				<h2><?php esc_html_e( 'Waiver document (versioned)', 'memberistic' ); ?></h2>
				<?php $cur_ver = Waiver_Versions::current(); ?>
				<?php if ( $cur_ver ) : ?>
					<p class="description">
						<?php
						printf(
							/* translators: 1: version id, 2: title, 3: date */
							esc_html__( 'Currently in effect: version %1$d%2$s, published %3$s.', 'memberistic' ),
							(int) $cur_ver['id'],
							$cur_ver['title'] ? ' (' . esc_html( (string) $cur_ver['title'] ) . ')' : '',
							esc_html( date_i18n( get_option( 'date_format' ), strtotime( (string) $cur_ver['effective_from'] ) ) )
						);
						?>
					</p>
				<?php endif; ?>
				<form method="post">
					<?php wp_nonce_field( 'memberistic_waivers' ); ?>
					<input type="hidden" name="memberistic_waiver_action" value="publish_version">
					<p>
						<label for="waiver_text" style="font-weight:600;display:block;margin-bottom:6px;"><?php esc_html_e( 'Waiver text shown when signing (saving publishes a NEW version — past signatures keep their original text snapshot)', 'memberistic' ); ?></label>
						<textarea id="waiver_text" name="waiver_text" rows="8" class="large-text" style="width:100%;"><?php echo esc_textarea( Waiver_Service::waiver_text() ); ?></textarea>
					</p>
					<p>
						<label for="waiver_version_title" style="font-weight:600;"><?php esc_html_e( 'Version label (optional, e.g. "2026 insurance update")', 'memberistic' ); ?></label><br>
						<input type="text" id="waiver_version_title" name="waiver_version_title" class="regular-text">
					</p>
					<p>
						<label>
							<input type="checkbox" name="waiver_requires_reconsent" value="1">
							<strong><?php esc_html_e( 'Require re-consent', 'memberistic' ); ?></strong> —
							<?php esc_html_e( 'everyone signed before this version must sign again (their status flips to "needs review" and waivers on file stop satisfying booking/check-in). Leave unchecked for wording tweaks: existing signatures then stay valid until their normal expiry.', 'memberistic' ); ?>
						</label>
					</p>
					<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Publish new version', 'memberistic' ); ?></button></p>
				</form>
				<?php $versions = Waiver_Versions::all( 10 ); ?>
				<?php if ( $versions ) : ?>
					<h3 style="margin-top:18px;"><?php esc_html_e( 'Version history', 'memberistic' ); ?></h3>
					<table class="widefat striped">
						<thead><tr>
							<th><?php esc_html_e( 'Version', 'memberistic' ); ?></th>
							<th><?php esc_html_e( 'Label', 'memberistic' ); ?></th>
							<th><?php esc_html_e( 'Effective from', 'memberistic' ); ?></th>
							<th><?php esc_html_e( 'Re-consent', 'memberistic' ); ?></th>
							<th><?php esc_html_e( 'Published by', 'memberistic' ); ?></th>
						</tr></thead>
						<tbody>
						<?php foreach ( $versions as $v ) : ?>
							<tr>
								<td>#<?php echo (int) $v['id']; ?><?php if ( $cur_ver && (int) $cur_ver['id'] === (int) $v['id'] ) : ?> <strong>(<?php esc_html_e( 'current', 'memberistic' ); ?>)</strong><?php endif; ?></td>
								<td><?php echo esc_html( $v['title'] ?: '—' ); ?></td>
								<td><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' H:i', strtotime( (string) $v['effective_from'] ) ) ); ?></td>
								<td><?php echo $v['requires_reconsent'] ? '<span style="color:#b32d2e;font-weight:600;">' . esc_html__( 'Yes', 'memberistic' ) . '</span>' : esc_html__( 'No', 'memberistic' ); ?></td>
								<td><?php $u = $v['created_by'] ? get_userdata( (int) $v['created_by'] ) : null; echo esc_html( $u ? $u->display_name : '—' ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>

			<div class="memberistic-card">
				<h2><?php esc_html_e( 'Waiver schedule settings', 'memberistic' ); ?></h2>
				<form method="post">
					<?php wp_nonce_field( 'memberistic_waivers' ); ?>
					<input type="hidden" name="memberistic_waiver_action" value="save_settings">
					<p>
						<label for="waiver_validity_days" style="font-weight:600;"><?php esc_html_e( 'Validity (days before a signed waiver expires)', 'memberistic' ); ?></label><br>
						<input type="number" min="1" id="waiver_validity_days" name="waiver_validity_days" value="<?php echo esc_attr( (string) Waiver_Service::validity_days() ); ?>" class="small-text">
					</p>
					<p>
						<label for="waiver_renewal_reminder_days" style="font-weight:600;"><?php esc_html_e( 'Renewal reminder (days before expiry to email the member; 0 disables)', 'memberistic' ); ?></label><br>
						<input type="number" min="0" max="365" id="waiver_renewal_reminder_days" name="waiver_renewal_reminder_days" value="<?php echo esc_attr( (string) Waiver_Service::renewal_reminder_days() ); ?>" class="small-text">
					</p>
					<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save waiver settings', 'memberistic' ); ?></button></p>
				</form>
			</div>

			<div class="memberistic-card">
				<h2><?php esc_html_e( 'Kiosk stations', 'memberistic' ); ?></h2>
				<p class="description" style="max-width:680px;">
					<?php
					printf(
						/* translators: %d: hours */
						esc_html__( 'Generate a signed kiosk link for a front-desk device. A kiosk link unlocks the full-screen signing mode, skips the public per-IP throttle, and stamps the station name on every signature. Links are valid for %d hours — mint a fresh one each shift, like the check-in stations.', 'memberistic' ),
						(int) round( Waiver_Kiosk::ttl() / HOUR_IN_SECONDS )
					);
					?>
				</p>
				<?php
				$kiosk_link = get_transient( 'memberistic_kiosk_link_' . get_current_user_id() );
				if ( is_array( $kiosk_link ) && ! empty( $kiosk_link['url'] ) ) :
					delete_transient( 'memberistic_kiosk_link_' . get_current_user_id() );
					?>
					<div class="notice notice-success inline" style="padding:12px;">
						<p style="margin:0 0 6px;"><strong><?php echo esc_html( (string) $kiosk_link['station'] ); ?></strong> — <?php esc_html_e( 'open this on the kiosk device (valid until', 'memberistic' ); ?> <?php echo esc_html( date_i18n( get_option( 'time_format' ), (int) $kiosk_link['expires'] + (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) ) ); ?>):</p>
						<p style="margin:0;"><input type="text" readonly value="<?php echo esc_attr( (string) $kiosk_link['url'] ); ?>" class="large-text" onclick="this.select();"> <a class="button" href="<?php echo esc_url( (string) $kiosk_link['url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open', 'memberistic' ); ?></a></p>
					</div>
				<?php endif; ?>
				<form method="post" style="margin-bottom:14px;">
					<?php wp_nonce_field( 'memberistic_waivers' ); ?>
					<input type="hidden" name="memberistic_waiver_action" value="kiosk_link">
					<input type="text" name="kiosk_station" placeholder="<?php esc_attr_e( 'Station name (e.g. front-desk-ipad)', 'memberistic' ); ?>" class="regular-text">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Generate kiosk link', 'memberistic' ); ?></button>
				</form>
				<form method="post">
					<?php wp_nonce_field( 'memberistic_waivers' ); ?>
					<input type="hidden" name="memberistic_waiver_action" value="save_kiosk_pin">
					<label for="kiosk_staff_pin" style="font-weight:600;"><?php esc_html_e( 'Staff exit PIN (digits, optional — shows a PIN-gated exit button on the kiosk; empty disables)', 'memberistic' ); ?></label><br>
					<input type="password" inputmode="numeric" pattern="[0-9]*" maxlength="8" id="kiosk_staff_pin" name="kiosk_staff_pin" value="<?php echo esc_attr( Waiver_Kiosk::staff_pin() ); ?>" class="small-text" autocomplete="off">
					<button type="submit" class="button"><?php esc_html_e( 'Save PIN', 'memberistic' ); ?></button>
				</form>
			</div>

			<div class="memberistic-card">
				<h2><?php esc_html_e( 'Members', 'memberistic' ); ?></h2>
				<p>
					<?php
					$base = admin_url( 'admin.php?page=memberistic-waivers' );
					foreach ( array( '' => __( 'All', 'memberistic' ), 'missing' => __( 'Missing', 'memberistic' ), 'expired' => __( 'Expired', 'memberistic' ), 'signed' => __( 'Signed', 'memberistic' ) ) as $k => $label ) {
						$url = $k ? add_query_arg( 'waiver_status', $k, $base ) : $base;
						printf( '<a href="%s" class="button%s">%s</a> ', esc_url( $url ), ( $filter === $k ? ' button-primary' : '' ), esc_html( $label ) );
					}
					?>
				</p>
				<table class="widefat striped">
					<thead><tr>
						<th><?php esc_html_e( 'Member', 'memberistic' ); ?></th>
						<th><?php esc_html_e( 'Email', 'memberistic' ); ?></th>
						<th><?php esc_html_e( 'Role', 'memberistic' ); ?></th>
						<th><?php esc_html_e( 'Waiver', 'memberistic' ); ?></th>
						<th><?php esc_html_e( 'Signed', 'memberistic' ); ?></th>
						<th><?php esc_html_e( 'Expires', 'memberistic' ); ?></th>
						<th><?php esc_html_e( 'Action', 'memberistic' ); ?></th>
					</tr></thead>
					<tbody>
						<?php if ( empty( $rows ) ) : ?>
							<tr><td colspan="7"><?php esc_html_e( 'No members match this filter.', 'memberistic' ); ?></td></tr>
						<?php else : foreach ( $rows as $r ) :
							$ws = (string) $r['waiver_status'];
							?>
							<tr>
								<td><?php echo esc_html( $r['full_name'] ?: '—' ); ?></td>
								<td><?php echo esc_html( $r['email'] ?: '—' ); ?></td>
								<td><?php echo esc_html( ucfirst( (string) $r['role'] ) ); ?></td>
								<td><span style="font-weight:600;color:<?php echo 'signed' === $ws ? '#16794a' : '#b45309'; ?>;"><?php echo esc_html( ucfirst( str_replace( '_', ' ', $ws ) ) ); ?></span></td>
								<td><?php echo esc_html( $r['waiver_signed_at'] ? date_i18n( get_option( 'date_format' ), strtotime( (string) $r['waiver_signed_at'] ) ) : '—' ); ?></td>
								<td><?php echo esc_html( $r['waiver_expires_at'] ? date_i18n( get_option( 'date_format' ), strtotime( (string) $r['waiver_expires_at'] ) ) : '—' ); ?></td>
								<td>
									<?php if ( 'signed' !== $ws ) : ?>
									<form method="post" style="display:inline;">
										<?php wp_nonce_field( 'memberistic_waivers' ); ?>
										<input type="hidden" name="memberistic_waiver_action" value="mark_one">
										<input type="hidden" name="person_id" value="<?php echo (int) $r['id']; ?>">
										<button type="submit" class="button button-small"><?php esc_html_e( 'Mark signed', 'memberistic' ); ?></button>
									</form>
									<?php endif; ?>
									<?php if ( ! empty( $r['wp_user_id'] ) ) : ?>
										<a class="button button-small" target="_blank" rel="noopener" href="<?php echo esc_url( Waiver_Service::waiver_url( (int) $r['wp_user_id'] ) ); ?>"><?php esc_html_e( 'Sign link', 'memberistic' ); ?></a>
									<?php endif; ?>
									<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=memberistic-waivers&member=' . (int) $r['id'] ) ); ?>"><?php esc_html_e( 'Docs', 'memberistic' ); ?></a>
								</td>
							</tr>
						<?php endforeach; endif; ?>
					</tbody>
				</table>
				<p class="description"><?php esc_html_e( 'Showing up to 200 rows. Use the filters above to narrow the list.', 'memberistic' ); ?></p>
			</div>
		</div>
		<?php
	}
}
