<?php
/**
 * Front-end authentication surface.
 *
 * Themes that hide wp-login.php filter `login_url` / `lostpassword_url`
 * so that every auth link — the login form,
 * the "Forgot password?" link, and the "set your password" links inside member
 * welcome emails (wp-login.php?action=rp&key=…) — points at the branded
 * `/login/` page instead of wp-login.php.
 *
 * That page renders `[memberistic_login]`, which historically only ever drew
 * the *login* form. So `/login/?action=lostpassword` and `/login/?action=rp`
 * dead-ended: the customer saw the login form again and nothing happened. With
 * no working reset, members provisioned with a random password could never set
 * one — they could not log in, and could not reach their digital card.
 *
 * This class turns `/login/` into a complete, theme-independent auth surface:
 *   • login         — authenticate via wp_signon (works even if wp-login.php is
 *                     blocked/redirected by the theme)
 *   • lostpassword  — collect an email and send the WP reset link
 *   • rp / resetpass — validate the reset key and set a new password
 *
 * Form submissions are processed on `template_redirect` (before any output) so
 * cookies and PRG redirects work. The shortcode then renders whichever form the
 * current request calls for.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic\Frontend;

use WordPressistic\Memberistic\Database\Plans_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Auth {

	/** Query var carrying the result of a processed submission (PRG). */
	const NOTICE_PARAM = 'memberistic_auth';

	/**
	 * Hook the early form processor + the email link rewriters.
	 */
	public static function register() {
		// Keep the login surface out of every page cache. Without this, a
		// caching plugin / CDN serves the cached *login* HTML for
		// /login/?action=lostpassword and /login/?action=rp too, so the
		// forgot-password and reset views never appear — the classic
		// "works in dev, dead on the live site" symptom.
		add_action( 'template_redirect', array( self::class, 'prevent_login_cache' ), 0 );

		// The theme filters logout_url to /login/?action=logout too, so the
		// branded page has to actually sign the member out — otherwise the link
		// just lands back on the login page still logged in.
		add_action( 'template_redirect', array( self::class, 'maybe_logout' ), 1 );

		add_action( 'template_redirect', array( self::class, 'maybe_process' ), 9 );

		// WP builds password-reset and new-user "set your password" links with
		// network_site_url( 'wp-login.php?action=rp…' ), which side-steps the
		// theme's login_url filter. Rewrite those links to the branded /login/
		// page so every password link lands on the handler above — no matter
		// what the theme does with wp-login.php.
		add_filter( 'retrieve_password_message', array( self::class, 'filter_reset_message' ), 20, 4 );
		add_filter( 'wp_new_user_notification_email', array( self::class, 'filter_new_user_email' ), 20, 3 );
	}

	/** Rewrite the reset link inside the lost-password email. */
	public static function filter_reset_message( $message, $key = '', $user_login = '', $user_data = null ) {
		return self::rewrite_rp_links( (string) $message );
	}

	/** Rewrite the "set your password" link inside the new-user email. */
	public static function filter_new_user_email( $email, $user = null, $blogname = '' ) {
		if ( is_array( $email ) && isset( $email['message'] ) ) {
			$email['message'] = self::rewrite_rp_links( (string) $email['message'] );
		}
		return $email;
	}

	/**
	 * Swap any wp-login.php?action=rp&key=…&login=… URL for the branded
	 * /login/?action=rp equivalent, preserving the (already-encoded) key and
	 * login exactly.
	 */
	private static function rewrite_rp_links( $text ) {
		$base = self::login_page_url();
		return preg_replace_callback(
			'~https?://[^\s<>"\']*/wp-login\.php\?action=rp&key=([^&\s<>"\']+)&login=([^\s<>"\'&]+)~',
			static function ( $m ) use ( $base ) {
				$sep = ( false !== strpos( $base, '?' ) ) ? '&' : '?';
				return $base . $sep . 'action=rp&key=' . $m[1] . '&login=' . $m[2];
			},
			$text
		);
	}

	/**
	 * Tell page caches never to store the login surface, and send no-cache
	 * headers so a CDN that honours origin headers won't either.
	 */
	public static function prevent_login_cache() {
		if ( is_admin() || ! self::is_login_surface() ) {
			return;
		}
		nocache_headers();
		foreach ( array( 'DONOTCACHEPAGE', 'DONOTCACHEOBJECT', 'DONOTCACHEDB', 'DONOTMINIFY' ) as $flag ) {
			if ( ! defined( $flag ) ) {
				define( $flag, true );
			}
		}
	}

	/**
	 * Is this request a login / forgot / reset surface that must stay dynamic?
	 * True when an auth action or notice is present, or when we're on the
	 * configured login / account page, or a page carrying the login/account
	 * shortcode.
	 */
	private static function is_login_surface() {
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		if ( in_array( $action, array( 'lostpassword', 'rp', 'resetpass' ), true ) ) {
			return true;
		}
		if ( isset( $_GET[ self::NOTICE_PARAM ] ) ) {
			return true;
		}
		$settings   = get_option( 'memberistic_settings', array() );
		$login_id   = is_array( $settings ) && ! empty( $settings['login_page_id'] ) ? (int) $settings['login_page_id'] : 0;
		$account_id = is_array( $settings ) && ! empty( $settings['account_page_id'] ) ? (int) $settings['account_page_id'] : 0;
		if ( ( $login_id && is_page( $login_id ) ) || ( $account_id && is_page( $account_id ) ) ) {
			return true;
		}
		if ( is_singular() ) {
			$post = get_post();
			if ( $post && ( has_shortcode( (string) $post->post_content, 'memberistic_login' ) || has_shortcode( (string) $post->post_content, 'memberistic_account' ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Sign the member out when the theme's logout link lands on /login/.
	 * Mirrors wp-login.php's logout: verify the standard `log-out` nonce, clear
	 * the session, then honour redirect_to (default home). On a bad/stale nonce
	 * we don't force-logout (CSRF guard) — the shortcode renders a confirm view.
	 */
	public static function maybe_logout() {
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		if ( 'logout' !== $action || ! is_user_logged_in() ) {
			return;
		}
		$nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'log-out' ) ) {
			// Stale link — fall through; render_logout_confirm() offers a fresh one.
			return;
		}

		$user      = wp_get_current_user();
		wp_logout();

		$requested = isset( $_REQUEST['redirect_to'] ) ? (string) wp_unslash( $_REQUEST['redirect_to'] ) : '';
		$redirect  = $requested ? wp_validate_redirect( $requested, home_url( '/' ) ) : home_url( '/' );
		/** This filter is documented in wp-login.php */
		$redirect  = apply_filters( 'logout_redirect', $redirect, $requested, $user );
		self::redirect( $redirect ?: home_url( '/' ) );
	}

	/* ───────────────────────────── processing ─────────────────────────── */

	/**
	 * Handle a posted auth form before any page output. Keyed off our own
	 * hidden field so it never collides with other forms on the site.
	 */
	public static function maybe_process() {
		if ( empty( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) ) {
			return;
		}
		$action = isset( $_POST['memberistic_auth_action'] ) ? sanitize_key( wp_unslash( $_POST['memberistic_auth_action'] ) ) : '';
		if ( '' === $action ) {
			return;
		}

		switch ( $action ) {
			case 'login':
				self::process_login();
				break;
			case 'lostpassword':
				self::process_lostpassword();
				break;
			case 'resetpass':
				self::process_resetpass();
				break;
		}
	}

	/**
	 * Authenticate and start a session, then redirect. Handling login here
	 * (rather than POSTing to wp-login.php) means it keeps working even on
	 * sites that fully block or redirect wp-login.php.
	 */
	private static function process_login() {
		// Already signed in? Bounce to the account.
		if ( is_user_logged_in() ) {
			self::redirect( self::redirect_target() );
		}

		$creds = array(
			'user_login'    => isset( $_POST['log'] ) ? trim( (string) wp_unslash( $_POST['log'] ) ) : '',
			'user_password' => isset( $_POST['pwd'] ) ? (string) $_POST['pwd'] : '',
			'remember'      => ! empty( $_POST['rememberme'] ),
		);

		if ( '' === $creds['user_login'] || '' === $creds['user_password'] ) {
			self::redirect( self::login_page_url( array( self::NOTICE_PARAM => 'empty' ) ) );
		}

		$user = wp_signon( $creds, is_ssl() );

		if ( is_wp_error( $user ) ) {
			self::redirect( self::login_page_url( array( self::NOTICE_PARAM => 'failed' ) ) );
		}

		wp_set_current_user( $user->ID );

		// Land on the account page with a unique query arg. A CDN/page cache
		// that still holds a LOGGED-OUT copy of /account/ would otherwise
		// serve the login form right back to the freshly signed-in member —
		// the classic "the Sign In button doesn't work" report. The unique
		// URL forces a cache miss, so the first post-login view is always
		// rendered fresh with the member's cookies.
		self::redirect( add_query_arg( 'mlogin', (string) time(), self::redirect_target() ) );
	}

	/**
	 * Email a password-reset link. Always reports success to the visitor so
	 * the form can't be used to enumerate which emails have accounts.
	 */
	private static function process_lostpassword() {
		if ( ! self::verify_nonce( 'memberistic_lostpassword' ) ) {
			self::redirect( self::login_page_url( array( 'action' => 'lostpassword', self::NOTICE_PARAM => 'expired' ) ) );
		}

		$login = isset( $_POST['user_login'] ) ? trim( (string) wp_unslash( $_POST['user_login'] ) ) : '';

		// retrieve_password() (WP 5.7+) looks the user up, generates the key and
		// sends the branded reset email, honouring all the core filters.
		if ( '' !== $login && function_exists( 'retrieve_password' ) ) {
			retrieve_password( $login );
		} elseif ( '' !== $login ) {
			self::fallback_retrieve_password( $login );
		}

		// Confirm regardless of the outcome (no account enumeration).
		self::redirect( self::login_page_url( array( 'action' => 'lostpassword', self::NOTICE_PARAM => 'checkemail' ) ) );
	}

	/**
	 * Validate the reset key and set the new password.
	 */
	private static function process_resetpass() {
		if ( ! self::verify_nonce( 'memberistic_resetpass' ) ) {
			self::redirect( self::login_page_url( array( self::NOTICE_PARAM => 'expired' ) ) );
		}

		$key   = isset( $_POST['rp_key'] ) ? (string) wp_unslash( $_POST['rp_key'] ) : '';
		$login = isset( $_POST['rp_login'] ) ? (string) wp_unslash( $_POST['rp_login'] ) : '';
		$user  = check_password_reset_key( $key, $login );

		if ( is_wp_error( $user ) ) {
			// Key expired or already used — send them back to request a fresh one.
			self::redirect( self::login_page_url( array( 'action' => 'lostpassword', self::NOTICE_PARAM => 'expiredkey' ) ) );
		}

		$pass1 = isset( $_POST['pass1'] ) ? (string) $_POST['pass1'] : '';
		$pass2 = isset( $_POST['pass2'] ) ? (string) $_POST['pass2'] : '';

		$args = array(
			'action'   => 'rp',
			'key'      => rawurlencode( $key ),
			'login'    => rawurlencode( $login ),
		);

		if ( '' === $pass1 || '' === $pass2 ) {
			$args[ self::NOTICE_PARAM ] = 'passempty';
			self::redirect( self::login_page_url( $args ) );
		}
		if ( $pass1 !== $pass2 ) {
			$args[ self::NOTICE_PARAM ] = 'passmismatch';
			self::redirect( self::login_page_url( $args ) );
		}
		if ( strlen( $pass1 ) < 8 ) {
			$args[ self::NOTICE_PARAM ] = 'passweak';
			self::redirect( self::login_page_url( $args ) );
		}

		reset_password( $user, $pass1 );

		self::redirect( self::login_page_url( array( self::NOTICE_PARAM => 'passchanged' ) ) );
	}

	/* ───────────────────────────── rendering ──────────────────────────── */

	/**
	 * Entry point used by the [memberistic_login] shortcode. Branches on the
	 * current request to render the right form.
	 */
	public static function render() {
		$action = self::current_action();

		// A logout link reached the page but wasn't processed (stale nonce) and
		// the member is still signed in → show a one-tap confirm with a fresh
		// logout link. (Valid logouts are handled in maybe_logout() and never
		// reach here.)
		if ( 'logout' === $action && is_user_logged_in() ) {
			return self::render_logout_confirm();
		}
		if ( in_array( $action, array( 'rp', 'resetpass' ), true ) ) {
			return self::render_reset_form();
		}
		if ( 'lostpassword' === $action ) {
			return self::render_lostpassword_form();
		}
		return self::render_login_form();
	}

	private static function render_logout_confirm() {
		$logout_url = wp_logout_url( home_url( '/' ) );
		$account    = self::redirect_target();
		ob_start();
		?>
		<div class="memberistic-frontend memberistic-auth-shell">
			<div class="memberistic-auth-card">
				<div class="memberistic-auth-logo"><span class="memberistic-auth-mark"></span><?php esc_html_e( 'MEMBERS HUB', 'memberistic' ); ?></div>
				<h2 class="memberistic-auth-title"><?php esc_html_e( 'SIGN OUT', 'memberistic' ); ?></h2>
				<p class="memberistic-auth-sub"><?php esc_html_e( 'Ready to sign out of your range account?', 'memberistic' ); ?></p>
				<a class="memberistic-auth-btn memberistic-auth-btn--primary" href="<?php echo esc_url( $logout_url ); ?>"><?php esc_html_e( 'Yes, Sign Me Out', 'memberistic' ); ?></a>
				<a class="memberistic-auth-back" href="<?php echo esc_url( $account ); ?>">← <?php esc_html_e( 'Back to my account', 'memberistic' ); ?></a>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	private static function render_login_form() {
		$plans_url = \WordPressistic\Memberistic\memberistic_get_page_url( 'plans_page_id', 'memberistic-plans', home_url( '/' ) );
		$lost_url  = self::login_page_url( array( 'action' => 'lostpassword' ) );
		$post_url  = self::login_page_url();
		$redirect  = self::redirect_target();
		$notice    = self::notice_html();

		ob_start();
		?>
		<div class="memberistic-frontend memberistic-auth-shell">
			<div class="memberistic-auth-card">
				<div class="memberistic-auth-logo"><span class="memberistic-auth-mark"></span><?php esc_html_e( 'MEMBERS HUB', 'memberistic' ); ?></div>
				<h2 class="memberistic-auth-title"><?php esc_html_e( 'MEMBER LOGIN', 'memberistic' ); ?></h2>
				<p class="memberistic-auth-sub"><?php esc_html_e( 'Welcome back. Access your range account.', 'memberistic' ); ?></p>

				<?php echo $notice; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

				<form class="memberistic-auth-form" method="post" action="<?php echo esc_url( $post_url ); ?>">
					<input type="hidden" name="memberistic_auth_action" value="login">
					<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect ); ?>">

					<label class="memberistic-auth-label" for="memberistic_user_login"><?php esc_html_e( 'Email Address', 'memberistic' ); ?></label>
					<input class="memberistic-auth-field" id="memberistic_user_login" type="text" name="log" autocomplete="username" required>

					<label class="memberistic-auth-label" for="memberistic_user_pass"><?php esc_html_e( 'Password', 'memberistic' ); ?></label>
					<input class="memberistic-auth-field" id="memberistic_user_pass" type="password" name="pwd" autocomplete="current-password" required>

					<div class="memberistic-auth-row">
						<label class="memberistic-auth-check"><input type="checkbox" name="rememberme" value="forever"> <?php esc_html_e( 'Remember me', 'memberistic' ); ?></label>
						<a class="memberistic-auth-forgot" href="<?php echo esc_url( $lost_url ); ?>"><?php esc_html_e( 'Forgot password?', 'memberistic' ); ?></a>
					</div>

					<button class="memberistic-auth-btn memberistic-auth-btn--primary" type="submit"><?php esc_html_e( 'Sign In', 'memberistic' ); ?></button>

					<div class="memberistic-auth-divider"><?php esc_html_e( 'OR', 'memberistic' ); ?></div>
					<a class="memberistic-auth-btn memberistic-auth-btn--secondary" href="<?php echo esc_url( $plans_url ); ?>"><?php esc_html_e( 'Join As A Member', 'memberistic' ); ?></a>
					<?php echo self::min_price_line(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</form>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	private static function render_lostpassword_form() {
		$post_url  = self::login_page_url();
		$login_url = self::login_page_url();
		$notice    = self::notice_html();
		$confirmed = ( 'checkemail' === self::current_notice() );

		ob_start();
		?>
		<div class="memberistic-frontend memberistic-auth-shell">
			<div class="memberistic-auth-card">
				<div class="memberistic-auth-logo"><span class="memberistic-auth-mark"></span><?php esc_html_e( 'MEMBERS HUB', 'memberistic' ); ?></div>
				<h2 class="memberistic-auth-title"><?php esc_html_e( 'RESET PASSWORD', 'memberistic' ); ?></h2>
				<p class="memberistic-auth-sub"><?php esc_html_e( 'Enter the email on your membership and we will send a reset link.', 'memberistic' ); ?></p>

				<?php echo $notice; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

				<?php if ( ! $confirmed ) : ?>
					<form class="memberistic-auth-form" method="post" action="<?php echo esc_url( $post_url ); ?>">
						<input type="hidden" name="memberistic_auth_action" value="lostpassword">
						<?php wp_nonce_field( 'memberistic_lostpassword', 'memberistic_auth_nonce' ); ?>

						<label class="memberistic-auth-label" for="memberistic_lost_login"><?php esc_html_e( 'Email Address', 'memberistic' ); ?></label>
						<input class="memberistic-auth-field" id="memberistic_lost_login" type="text" name="user_login" autocomplete="username" required>

						<button class="memberistic-auth-btn memberistic-auth-btn--primary" type="submit"><?php esc_html_e( 'Send Reset Link', 'memberistic' ); ?></button>
						<a class="memberistic-auth-back" href="<?php echo esc_url( $login_url ); ?>">← <?php esc_html_e( 'Back to sign in', 'memberistic' ); ?></a>
					</form>
				<?php else : ?>
					<a class="memberistic-auth-btn memberistic-auth-btn--secondary" href="<?php echo esc_url( $login_url ); ?>"><?php esc_html_e( 'Back to sign in', 'memberistic' ); ?></a>
				<?php endif; ?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	private static function render_reset_form() {
		$key   = isset( $_GET['key'] ) ? (string) wp_unslash( $_GET['key'] ) : '';
		$login = isset( $_GET['login'] ) ? (string) wp_unslash( $_GET['login'] ) : '';
		$user  = ( '' !== $key && '' !== $login ) ? check_password_reset_key( $key, $login ) : new \WP_Error( 'missing', 'missing' );

		$login_url = self::login_page_url();
		$lost_url  = self::login_page_url( array( 'action' => 'lostpassword' ) );

		ob_start();
		?>
		<div class="memberistic-frontend memberistic-auth-shell">
			<div class="memberistic-auth-card">
				<div class="memberistic-auth-logo"><span class="memberistic-auth-mark"></span><?php esc_html_e( 'MEMBERS HUB', 'memberistic' ); ?></div>
				<h2 class="memberistic-auth-title"><?php esc_html_e( 'SET PASSWORD', 'memberistic' ); ?></h2>

				<?php if ( is_wp_error( $user ) ) : ?>
					<p class="memberistic-auth-sub"><?php esc_html_e( 'This password link is invalid or has expired.', 'memberistic' ); ?></p>
					<div class="memberistic-auth-notice memberistic-auth-notice--error"><?php esc_html_e( 'Request a new link below — the old one only works once and expires after 24 hours.', 'memberistic' ); ?></div>
					<a class="memberistic-auth-btn memberistic-auth-btn--primary" href="<?php echo esc_url( $lost_url ); ?>"><?php esc_html_e( 'Request a New Link', 'memberistic' ); ?></a>
				<?php else : ?>
					<p class="memberistic-auth-sub"><?php echo esc_html( sprintf( /* translators: %s: member display name */ __( 'Choose a new password for %s.', 'memberistic' ), $user->display_name ?: $user->user_login ) ); ?></p>

					<?php echo self::notice_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

					<form class="memberistic-auth-form" method="post" action="<?php echo esc_url( $login_url ); ?>" autocomplete="off">
						<input type="hidden" name="memberistic_auth_action" value="resetpass">
						<input type="hidden" name="rp_key" value="<?php echo esc_attr( $key ); ?>">
						<input type="hidden" name="rp_login" value="<?php echo esc_attr( $login ); ?>">
						<?php wp_nonce_field( 'memberistic_resetpass', 'memberistic_auth_nonce' ); ?>

						<label class="memberistic-auth-label" for="memberistic_pass1"><?php esc_html_e( 'New Password', 'memberistic' ); ?></label>
						<input class="memberistic-auth-field" id="memberistic_pass1" type="password" name="pass1" autocomplete="new-password" minlength="8" required>

						<label class="memberistic-auth-label" for="memberistic_pass2"><?php esc_html_e( 'Confirm Password', 'memberistic' ); ?></label>
						<input class="memberistic-auth-field" id="memberistic_pass2" type="password" name="pass2" autocomplete="new-password" minlength="8" required>

						<button class="memberistic-auth-btn memberistic-auth-btn--primary" type="submit"><?php esc_html_e( 'Save Password & Continue', 'memberistic' ); ?></button>
					</form>
				<?php endif; ?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/* ───────────────────────────── helpers ────────────────────────────── */

	/** Current auth action from the query (normalised). */
	private static function current_action() {
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		if ( 'retrievepassword' === $action ) {
			$action = 'lostpassword'; // legacy alias
		}
		if ( in_array( $action, array( 'rp', 'resetpass', 'lostpassword', 'login', 'logout' ), true ) ) {
			return $action;
		}
		return 'login';
	}

	/** The notice code carried back after a PRG redirect. */
	private static function current_notice() {
		return isset( $_GET[ self::NOTICE_PARAM ] ) ? sanitize_key( wp_unslash( $_GET[ self::NOTICE_PARAM ] ) ) : '';
	}

	/** Render the notice banner for the current request, if any. */
	private static function notice_html() {
		$code = self::current_notice();
		if ( '' === $code ) {
			return '';
		}
		$map = array(
			'checkemail'   => array( 'success', __( 'If an account exists for that email, a reset link is on its way. Check your inbox (and spam folder).', 'memberistic' ) ),
			'passchanged'  => array( 'success', __( 'Your password has been set. You can sign in now.', 'memberistic' ) ),
			'failed'       => array( 'error', __( 'That email and password did not match. Please try again or reset your password.', 'memberistic' ) ),
			'empty'        => array( 'error', __( 'Please enter both your email and password.', 'memberistic' ) ),
			'expired'      => array( 'error', __( 'Your session expired. Please try again.', 'memberistic' ) ),
			'expiredkey'   => array( 'error', __( 'That reset link has expired. Request a new one below.', 'memberistic' ) ),
			'passmismatch' => array( 'error', __( 'The two passwords did not match. Please try again.', 'memberistic' ) ),
			'passempty'    => array( 'error', __( 'Please enter and confirm your new password.', 'memberistic' ) ),
			'passweak'     => array( 'error', __( 'Use at least 8 characters for your password.', 'memberistic' ) ),
		);
		if ( ! isset( $map[ $code ] ) ) {
			return '';
		}
		return sprintf(
			'<div class="memberistic-auth-notice memberistic-auth-notice--%1$s">%2$s</div>',
			esc_attr( $map[ $code ][0] ),
			esc_html( $map[ $code ][1] )
		);
	}

	/** Canonical URL of the /login/ page, with optional query args. */
	private static function login_page_url( $args = array() ) {
		$base = \WordPressistic\Memberistic\memberistic_get_page_url( 'login_page_id', 'login', home_url( '/login/' ) );
		if ( ! empty( $args ) ) {
			$base = add_query_arg( $args, $base );
		}
		return $base;
	}

	/** Safe post-login redirect target (account by default). */
	private static function redirect_target() {
		$account = \WordPressistic\Memberistic\memberistic_get_page_url( 'account_page_id', 'account', home_url( '/account/' ) );
		$requested = '';
		if ( isset( $_REQUEST['redirect_to'] ) ) {
			$requested = (string) wp_unslash( $_REQUEST['redirect_to'] );
		}
		$target = $requested ? wp_validate_redirect( $requested, $account ) : $account;
		return $target ?: $account;
	}

	private static function redirect( $url ) {
		wp_safe_redirect( $url );
		exit;
	}

	private static function verify_nonce( $action ) {
		$nonce = isset( $_POST['memberistic_auth_nonce'] ) ? (string) wp_unslash( $_POST['memberistic_auth_nonce'] ) : '';
		return (bool) wp_verify_nonce( $nonce, $action );
	}

	/**
	 * Minimal stand-in for retrieve_password() on very old WP. Generates a
	 * reset key and emails a branded /login/?action=rp link.
	 */
	private static function fallback_retrieve_password( $login ) {
		$user = strpos( $login, '@' ) ? get_user_by( 'email', $login ) : get_user_by( 'login', $login );
		if ( ! $user ) {
			return;
		}
		$key = get_password_reset_key( $user );
		if ( is_wp_error( $key ) ) {
			return;
		}
		$reset_url = self::login_page_url( array(
			'action' => 'rp',
			'key'    => rawurlencode( $key ),
			'login'  => rawurlencode( $user->user_login ),
		) );
		$message = sprintf(
			/* translators: 1: site name, 2: reset URL */
			__( "Someone requested a password reset for your %1\$s account.\n\nIf this was you, set a new password here:\n%2\$s\n\nIf not, you can ignore this email.", 'memberistic' ),
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			$reset_url
		);
		wp_mail( $user->user_email, __( 'Password reset', 'memberistic' ), $message );
	}

	/** "From $X/mo" line under the Join button (mirrors the storefront). */
	private static function min_price_line() {
		$active_plans = Plans_Repository::get_all( array( 'status' => 'active' ) );
		$min_monthly  = null;
		foreach ( (array) $active_plans as $ap ) {
			$price = isset( $ap['monthly_price'] ) ? (float) $ap['monthly_price'] : 0.0;
			if ( $price > 0 && ( null === $min_monthly || $price < $min_monthly ) ) {
				$min_monthly = $price;
			}
		}
		if ( null === $min_monthly ) {
			return '';
		}
		$currency = (string) \WordPressistic\Memberistic\memberistic_get_setting( 'currency', 'USD' );
		$symbol   = 'USD' === $currency ? '$' : '';
		$line     = sprintf(
			/* translators: %s: formatted plan price (e.g. $29.99) */
			__( 'From %s/mo - Cancel Anytime', 'memberistic' ),
			$symbol . number_format_i18n( $min_monthly, 2 )
		);
		return '<div class="memberistic-auth-alt">' . esc_html( $line ) . '</div>';
	}
}
