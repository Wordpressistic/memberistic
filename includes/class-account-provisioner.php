<?php
/**
 * Member account provisioning.
 *
 * A membership is only useful to the customer if it is attached to a WP user
 * account they can sign into. Historically only the Stripe checkout and
 * corporate paths created that account — members added by staff in the admin,
 * or brought in via CSV import, ended up with a membership row but no
 * `primary_user_id`. With no account there was nothing to log into and no way
 * to set a password, so those members were locked out of their digital card.
 *
 * This class is the single, idempotent place that turns a membership into a
 * usable login: it finds (or creates) the WP user for the primary person,
 * links it back to the membership + person rows, and emails a "set your
 * password" link (rewritten by the Auth handler to the branded /login/ page).
 *
 * It runs automatically when a membership is activated and when staff create a
 * member with an email, and can be run in bulk from Settings to repair the
 * existing backlog. The set-password email is guarded so a member is never
 * emailed twice.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic;

use WordPressistic\Memberistic\Database\Memberships_Repository;
use WordPressistic\Memberistic\Database\People_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Account_Provisioner {

	/** User-meta guard so the set-password email is sent at most once. */
	const EMAILED_META = '_memberistic_setpw_emailed';

	public static function register() {
		// Provision whenever a membership becomes active. Priority 20 so the
		// Stripe checkout path (which links its user during activation) has
		// already run — making this a harmless no-op there and never sending a
		// duplicate welcome email.
		add_action( 'memberistic_membership_activated', array( self::class, 'on_activated' ), 20, 1 );

		// Nudge staff to repair the existing backlog of accountless members.
		add_action( 'admin_notices', array( self::class, 'maybe_show_repair_notice' ) );
	}

	/**
	 * Show a one-click "set up member logins" prompt on Memberistic admin
	 * screens whenever members exist without a WP account.
	 */
	public static function maybe_show_repair_notice() {
		if ( ! memberistic_current_user_can( 'manage_memberistic_settings' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || false === strpos( (string) $screen->id, 'memberistic' ) ) {
			return;
		}
		$missing = self::count_missing_logins();
		if ( $missing < 1 ) {
			return;
		}
		$url = wp_nonce_url(
			memberistic_admin_url( 'memberistic-settings', array( 'memberistic_action' => 'repair_logins' ) ),
			'memberistic_repair_logins'
		);
		printf(
			'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s</p><p><a class="button button-primary" href="%3$s">%4$s</a></p></div>',
			esc_html( sprintf(
				/* translators: %d: number of members without a login */
				_n( '%d member does not have a login yet.', '%d members do not have a login yet.', $missing, 'memberistic' ),
				$missing
			) ),
			esc_html__( 'They cannot sign in or reach their digital card until an account is created. Set up logins and email each member a set-password link.', 'memberistic' ),
			esc_url( $url ),
			esc_html__( 'Set up member logins now', 'memberistic' )
		);
	}

	public static function on_activated( $membership_id ) {
		self::ensure_user_for_membership( (int) $membership_id, true );
	}

	/**
	 * Ensure the membership's primary person has a WP user account, creating
	 * one (and emailing a set-password link) when missing. Idempotent.
	 *
	 * @param int  $membership_id Membership id.
	 * @param bool $send_email    Email a set-password link when a new account is made.
	 * @return array{user_id:int,status:string,emailed:bool}
	 */
	public static function ensure_user_for_membership( $membership_id, $send_email = true ) {
		$membership_id = (int) $membership_id;
		$result        = array( 'user_id' => 0, 'status' => 'skipped', 'emailed' => false );

		$membership = Memberships_Repository::get( $membership_id );
		if ( ! $membership ) {
			$result['status'] = 'failed';
			return $result;
		}

		// Already linked — just make sure the person row agrees, then stop.
		if ( ! empty( $membership['primary_user_id'] ) ) {
			$result['user_id'] = (int) $membership['primary_user_id'];
			$result['status']  = 'existing';
			self::link_person_row( $membership_id, $result['user_id'] );
			return $result;
		}

		$person = self::resolve_person_with_email( $membership_id );
		if ( ! $person ) {
			// No usable email on any person — nothing we can provision against.
			$result['status'] = 'no_email';
			return $result;
		}

		$email     = (string) $person['email'];
		$full_name = isset( $person['full_name'] ) ? (string) $person['full_name'] : '';

		$existing = email_exists( $email );
		if ( $existing ) {
			// A WP account already exists for this email (e.g. they registered
			// separately). Link it; don't email — they already have a password.
			$user_id          = (int) $existing;
			$result['status'] = 'linked';
		} else {
			$user_id = self::create_user( $email, $full_name );
			if ( ! $user_id ) {
				$result['status'] = 'failed';
				return $result;
			}
			$result['status'] = 'created';
		}

		Memberships_Repository::update( $membership_id, array( 'primary_user_id' => $user_id ) );
		if ( ! empty( $person['id'] ) ) {
			People_Repository::update( (int) $person['id'], array( 'wp_user_id' => $user_id ) );
		}
		$result['user_id'] = $user_id;

		// Only email a set-password link when we just created the account, and
		// only once ever for that user.
		if ( $send_email && 'created' === $result['status'] && ! get_user_meta( $user_id, self::EMAILED_META, true ) ) {
			$result['emailed'] = self::send_set_password_email( $user_id );
		}

		return $result;
	}

	/**
	 * Bulk-repair: provision logins for every membership that has none. Safe to
	 * run repeatedly — already-linked members are skipped and nobody is emailed
	 * twice.
	 *
	 * @param bool $send_email Email set-password links to newly created accounts.
	 * @return array{created:int,linked:int,skipped:int,failed:int,emailed:int,total:int}
	 */
	public static function repair_all( $send_email = true ) {
		global $wpdb;
		$table = Memberships_Repository::table();
		$ids   = $wpdb->get_col( "SELECT id FROM {$table} WHERE primary_user_id IS NULL OR primary_user_id = 0" );

		$tally = array( 'created' => 0, 'linked' => 0, 'skipped' => 0, 'failed' => 0, 'emailed' => 0, 'total' => 0 );
		foreach ( (array) $ids as $id ) {
			$tally['total']++;
			$r = self::ensure_user_for_membership( (int) $id, $send_email );
			switch ( $r['status'] ) {
				case 'created':
					$tally['created']++;
					break;
				case 'linked':
					$tally['linked']++;
					break;
				case 'failed':
					$tally['failed']++;
					break;
				default:
					$tally['skipped']++;
			}
			if ( ! empty( $r['emailed'] ) ) {
				$tally['emailed']++;
			}
		}
		return $tally;
	}

	/**
	 * Count memberships that can actually be given a login — i.e. they have no
	 * linked WP account yet AND have at least one person with a real email to
	 * provision against. Memberships with no email aren't counted, otherwise the
	 * "set up logins" notice could never clear (the repair tool can't create a
	 * login without an email).
	 */
	public static function count_missing_logins() {
		global $wpdb;
		$m = Memberships_Repository::table();
		$p = People_Repository::table();
		return (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT m.id)
			 FROM {$m} m
			 INNER JOIN {$p} p ON p.membership_id = m.id
			 WHERE ( m.primary_user_id IS NULL OR m.primary_user_id = 0 )
			   AND p.email <> '' AND p.email LIKE '%@%'"
		);
	}

	/* ───────────────────────────── internals ──────────────────────────── */

	/**
	 * Find the person on a membership we should provision against: the primary
	 * member if they have a valid email, otherwise the first linked person who
	 * does. Imported data often has no row flagged "primary", so the fallback
	 * keeps those members reachable.
	 */
	private static function resolve_person_with_email( $membership_id ) {
		$primary = People_Repository::get_primary_by_membership( (int) $membership_id );
		if ( $primary && ! empty( $primary['email'] ) && is_email( $primary['email'] ) ) {
			return $primary;
		}
		foreach ( People_Repository::get_by_membership( (int) $membership_id ) as $p ) {
			if ( ! empty( $p['email'] ) && is_email( $p['email'] ) ) {
				return $p;
			}
		}
		return null;
	}

	private static function create_user( $email, $full_name ) {
		$email_parts   = explode( '@', $email );
		$username_base = sanitize_user( $email_parts[0], true );
		$username      = $username_base ?: 'member';
		$suffix        = 1;
		while ( username_exists( $username ) ) {
			$username = $username_base . '-' . $suffix;
			$suffix++;
		}
		$user_id = wp_insert_user(
			array(
				'user_login'   => $username,
				'user_email'   => $email,
				'display_name' => $full_name,
				'user_pass'    => wp_generate_password( 24, true, true ),
				'role'         => 'subscriber',
			)
		);
		return is_wp_error( $user_id ) ? 0 : (int) $user_id;
	}

	private static function send_set_password_email( $user_id ) {
		if ( ! function_exists( 'wp_new_user_notification' ) ) {
			return false;
		}
		// 'user' → sends the member the set-your-password email. The Auth
		// handler rewrites its wp-login.php?action=rp link to /login/.
		wp_new_user_notification( (int) $user_id, null, 'user' );
		update_user_meta( (int) $user_id, self::EMAILED_META, time() );
		return true;
	}

	private static function link_person_row( $membership_id, $user_id ) {
		$person = People_Repository::get_primary_by_membership( (int) $membership_id );
		if ( ! empty( $person['id'] ) && empty( $person['wp_user_id'] ) ) {
			People_Repository::update( (int) $person['id'], array( 'wp_user_id' => (int) $user_id ) );
		}
	}
}
