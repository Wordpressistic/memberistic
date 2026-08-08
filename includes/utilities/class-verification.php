<?php
/**
 * Member verification token + public verify page.
 *
 * The member dashboard's QR code used to encode a PII payload
 * (name + email + plan + member id) that anyone who scanned it
 * would see in plaintext. Now the QR encodes a SHORT verification
 * URL whose token resolves on the server to the member's CURRENT
 * status — staff at the range scan the QR and see live data, not
 * a stale snapshot, and the QR itself contains no PII.
 *
 * Endpoint: GET /?memberistic_verify=TOKEN  → branded card.
 * Token   : 32-char random hex per user, lazily generated and
 *           stored in wp_usermeta key 'memberistic_verification_token'.
 * Profile : wp_usermeta key 'memberistic_profile_image_id'
 *           (attachment id); rendered on the dashboard card AND
 *           on the public verify page so the gate clerk can
 *           compare faces.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic\Utilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Verification {

	const TOKEN_META   = 'memberistic_verification_token';
	const PHOTO_META   = 'memberistic_profile_image_id';
	const QUERY_PARAM  = 'memberistic_verify';

	public static function register() {
		add_action( 'init', array( __CLASS__, 'maybe_render_verify_page' ), 5 );
	}

	/**
	 * Return the member's verification token, generating one if it
	 * doesn't yet exist. Stable for the life of the user account —
	 * regenerated only if explicitly revoked via revoke_token().
	 */
	public static function get_or_create_token( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return '';
		}
		$token = (string) get_user_meta( $user_id, self::TOKEN_META, true );
		if ( '' === $token ) {
			$token = wp_generate_password( 32, false, false );
			update_user_meta( $user_id, self::TOKEN_META, $token );
		}
		return $token;
	}

	public static function revoke_token( $user_id ) {
		delete_user_meta( (int) $user_id, self::TOKEN_META );
	}

	public static function verify_url( $user_id ) {
		$token = self::get_or_create_token( (int) $user_id );
		if ( '' === $token ) {
			return '';
		}
		return add_query_arg( self::QUERY_PARAM, $token, home_url( '/' ) );
	}

	public static function get_profile_image_id( $user_id ) {
		return (int) get_user_meta( (int) $user_id, self::PHOTO_META, true );
	}

	public static function set_profile_image_id( $user_id, $attachment_id ) {
		$user_id       = (int) $user_id;
		$attachment_id = (int) $attachment_id;
		if ( $user_id <= 0 ) {
			return false;
		}
		if ( $attachment_id > 0 ) {
			$post = get_post( $attachment_id );
			if ( ! $post || 'attachment' !== $post->post_type ) {
				return false;
			}
			update_user_meta( $user_id, self::PHOTO_META, $attachment_id );
		} else {
			delete_user_meta( $user_id, self::PHOTO_META );
		}
		return true;
	}

	/**
	 * If the request carries ?memberistic_verify=TOKEN, look up the
	 * matching user and render a public verification card. No login
	 * required — that's the point, range staff scan and see status.
	 * The card never reveals the email or anything sensitive beyond
	 * what the member would willingly show at check-in.
	 */
	public static function maybe_render_verify_page() {
		if ( empty( $_GET[ self::QUERY_PARAM ] ) ) {
			return;
		}
		$token = sanitize_text_field( wp_unslash( $_GET[ self::QUERY_PARAM ] ) );
		if ( '' === $token || strlen( $token ) > 64 ) {
			self::render_error( __( 'Invalid verification token.', 'memberistic' ) );
		}

		// Find the user by token. wp_usermeta is indexed on meta_key
		// so this is O(log n) on standard WP installs.
		global $wpdb;
		$user_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
			self::TOKEN_META,
			$token
		) );
		if ( ! $user_id ) {
			self::render_error( __( 'No member matches this code. Ask the customer to refresh their dashboard.', 'memberistic' ) );
		}

		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			self::render_error( __( 'Member not found.', 'memberistic' ) );
		}

		$membership = \WordPressistic\Memberistic\Database\Memberships_Repository::get_by_user_id( $user_id );

		// Let staff tools (e.g. the corporate check-in module) handle a
		// POSTed check-in action before the card renders.
		do_action( 'memberistic_verify_request', $user, $membership, $token );

		self::render_card( $user, $membership );
	}

	private static function render_card( $user, $membership ) {
		$status  = is_array( $membership ) ? (string) ( $membership['status'] ?? '' ) : '';
		$plan    = is_array( $membership ) ? (string) ( $membership['plan_name'] ?? '' ) : '';
		$renew   = is_array( $membership ) && ! empty( $membership['renewal_date'] )
			? date_i18n( get_option( 'date_format' ), strtotime( $membership['renewal_date'] ) )
			: '—';
		$img_id  = self::get_profile_image_id( $user->ID );
		$img_url = $img_id ? wp_get_attachment_image_url( $img_id, 'medium' ) : '';
		$status_ok = in_array( $status, array( 'active', 'comped', 'trial' ), true );

		// Resolve the member's waiver state so the desk clerk sees it at a
		// glance when they scan the QR.
		$waiver_state = 'missing';
		$waiver_exp   = '';
		if ( is_array( $membership ) && class_exists( '\WordPressistic\Memberistic\Waivers\Waiver_Service' ) ) {
			$person = \WordPressistic\Memberistic\Database\People_Repository::get_primary_by_membership( (int) $membership['id'] );
			if ( $person ) {
				$waiver_ok    = \WordPressistic\Memberistic\Waivers\Waiver_Service::is_current( $person );
				$waiver_state = $waiver_ok ? 'signed' : (string) ( $person['waiver_status'] ?: 'missing' );
				$waiver_exp   = ! empty( $person['waiver_expires_at'] ) ? date_i18n( get_option( 'date_format' ), strtotime( (string) $person['waiver_expires_at'] ) ) : '';
			}
		}
		$waiver_ok_badge = ( 'signed' === $waiver_state );

		$brand   = function_exists( '\WordPressistic\Memberistic\memberistic_get_brand_label' )
			? \WordPressistic\Memberistic\memberistic_get_brand_label()
			: get_bloginfo( 'name' );

		nocache_headers();
		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );

		?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo esc_html( $brand ); ?> — Member Verification</title>
<style>
body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background:#0F1115; color:#fff; margin:0; min-height:100vh; display:grid; place-items:center; padding:24px; }
.card { max-width:420px; width:100%; background:#1A1F26; border:1px solid #2A323D; border-radius:12px; padding:32px; text-align:center; box-shadow:0 24px 70px rgba(0,0,0,.5); }
.brand { font-family:"Rajdhani","Oswald",Impact,sans-serif; font-size:14px; letter-spacing:.22em; color:#C9A84C; text-transform:uppercase; margin-bottom:24px; }
.photo { width:140px; height:140px; border-radius:50%; margin:0 auto 20px; background:linear-gradient(135deg,#C9A84C,#A8862F); display:grid; place-items:center; overflow:hidden; font-family:"Bebas Neue",sans-serif; font-size:56px; color:#0F1115; }
.photo img { width:100%; height:100%; object-fit:cover; }
.name { font-size:24px; font-weight:700; margin:0 0 4px; }
.plan { color:#8A95A5; font-size:14px; margin:0 0 24px; }
.status { display:inline-block; padding:8px 18px; border-radius:999px; font-size:13px; font-weight:700; letter-spacing:.08em; text-transform:uppercase; }
.status--ok { background:rgba(157,224,91,.15); color:#9DE05B; border:1px solid rgba(157,224,91,.4); }
.status--no { background:rgba(232,128,47,.15); color:#E8802F; border:1px solid rgba(232,128,47,.4); }
.meta { margin:24px 0 0; padding:18px 0 0; border-top:1px solid #2A323D; font-size:13px; color:#8A95A5; }
.meta strong { color:#fff; font-weight:600; }
.footer { margin-top:24px; font-size:11px; color:#5A6371; letter-spacing:.08em; text-transform:uppercase; }
</style>
</head>
<body>
<div class="card">
	<div class="brand"><?php echo esc_html( $brand ); ?> · Member Verification</div>
	<div class="photo">
		<?php if ( $img_url ) : ?>
			<img src="<?php echo esc_url( $img_url ); ?>" alt="<?php echo esc_attr( $user->display_name ); ?>">
		<?php else : ?>
			<?php
			$initials = '';
			foreach ( preg_split( '/\s+/', trim( $user->display_name ?: $user->user_login ) ) as $p ) {
				if ( '' !== $p ) $initials .= strtoupper( substr( $p, 0, 1 ) );
				if ( strlen( $initials ) >= 2 ) break;
			}
			echo esc_html( $initials ?: 'M' );
			?>
		<?php endif; ?>
	</div>
	<h1 class="name"><?php echo esc_html( $user->display_name ); ?></h1>
	<p class="plan"><?php echo esc_html( $plan ?: __( 'Member', 'memberistic' ) ); ?></p>
	<?php if ( $status_ok ) : ?>
		<span class="status status--ok">✓ <?php esc_html_e( 'Active Member', 'memberistic' ); ?></span>
	<?php else : ?>
		<span class="status status--no"><?php echo esc_html( $status ? strtoupper( $status ) : 'INACTIVE' ); ?></span>
	<?php endif; ?>
	<div style="margin-top:12px;">
		<span class="status <?php echo $waiver_ok_badge ? 'status--ok' : 'status--no'; ?>">
			<?php
			echo $waiver_ok_badge
				? esc_html__( '✓ Waiver on file', 'memberistic' )
				: esc_html( 'expired' === $waiver_state ? __( '⚠ Waiver expired', 'memberistic' ) : __( '⚠ Waiver not signed', 'memberistic' ) );
			?>
		</span>
	</div>
	<div class="meta">
		<?php if ( $plan ) : ?><div><strong><?php esc_html_e( 'Plan', 'memberistic' ); ?>:</strong> <?php echo esc_html( $plan ); ?></div><?php endif; ?>
		<?php if ( '—' !== $renew ) : ?><div><strong><?php esc_html_e( 'Renews', 'memberistic' ); ?>:</strong> <?php echo esc_html( $renew ); ?></div><?php endif; ?>
		<?php if ( $waiver_ok_badge && $waiver_exp ) : ?><div><strong><?php esc_html_e( 'Waiver valid through', 'memberistic' ); ?>:</strong> <?php echo esc_html( $waiver_exp ); ?></div><?php endif; ?>
	</div>
	<div class="footer"><?php echo esc_html( wp_date( 'Y-m-d H:i' ) ); ?> · <?php esc_html_e( 'Live verification', 'memberistic' ); ?></div>
</div>
<?php
	// Staff-only check-in panel attaches here (corporate module).
	// Public/no-PII viewers see nothing extra; logged-in staff with
	// the check-in capability get member details + a Check In button.
	do_action( 'memberistic_verify_card_after', $user, $membership );
	?>
</body>
</html>
<?php
		exit;
	}

	private static function render_error( $message ) {
		nocache_headers();
		status_header( 404 );
		header( 'Content-Type: text/html; charset=utf-8' );
		echo '<!doctype html><html><head><meta charset="utf-8"><title>Verification</title>';
		echo '<style>body{font-family:-apple-system,BlinkMacSystemFont,sans-serif;background:#0F1115;color:#fff;margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;text-align:center;} .b{max-width:400px;background:#1A1F26;border:1px solid #2A323D;border-radius:12px;padding:32px;}</style>';
		echo '</head><body><div class="b"><h1 style="margin-top:0;">' . esc_html__( 'Verification failed', 'memberistic' ) . '</h1><p>' . esc_html( $message ) . '</p></div></body></html>';
		exit;
	}
}
