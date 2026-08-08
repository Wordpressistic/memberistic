<?php
/**
 * Membership content restrictions and role sync.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic;

use WordPressistic\Memberistic\Database\Memberships_Repository;
use WordPressistic\Memberistic\Database\Plans_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Content_Restrictions {
	private static $locked_post = null;

	public static function register() {
		add_action( 'add_meta_boxes', array( self::class, 'add_meta_boxes' ) );
		add_action( 'save_post', array( self::class, 'save_post' ) );
		add_action( 'wp', array( self::class, 'detect_locked_content' ) );
		add_filter( 'body_class', array( self::class, 'body_class' ) );
		add_action( 'wp_footer', array( self::class, 'render_overlay' ) );
		// Server-side gate. Run as early as possible — `the_posts` lets
		// us redact $post->post_content and $post->post_excerpt IN PLACE
		// before any theme code (SEO meta, OG/Twitter cards, Schema.org
		// JSON-LD, RSS feeds, REST responses) reads them. The_content +
		// the_excerpt filters below are a belt-and-braces backup in
		// case any code path bypasses the post object cache.
		add_filter( 'the_posts', array( self::class, 'redact_locked_posts' ), 5, 2 );
		add_filter( 'the_content', array( self::class, 'filter_locked_content' ), 999 );
		add_filter( 'the_excerpt', array( self::class, 'filter_locked_content' ), 999 );
		// REST: hide content + excerpt on locked posts for non-eligible callers.
		add_filter( 'rest_prepare_post', array( self::class, 'filter_rest_response' ), 10, 2 );
		add_filter( 'rest_prepare_page', array( self::class, 'filter_rest_response' ), 10, 2 );
		add_action( 'memberistic_membership_created', array( self::class, 'sync_roles_for_membership' ) );
		add_action( 'memberistic_membership_activated', array( self::class, 'sync_roles_for_membership' ), 30 );
		// On expiry, strip the member roles + active-plan meta so an expired member
		// no longer reads as active to content restriction or the booking engine.
		add_action( 'memberistic_membership_expired', array( self::class, 'clear_roles_for_membership' ) );
	}

	/**
	 * Redact post_content + post_excerpt on locked posts BEFORE any
	 * downstream code can read them off the post object. Targets only
	 * the main query and skips admin/feed/REST contexts (REST is
	 * handled by filter_rest_response so the response shape stays
	 * consistent with WP's REST schema).
	 */
	public static function redact_locked_posts( $posts, $query ) {
		if ( is_admin() || ! is_array( $posts ) || empty( $posts ) ) {
			return $posts;
		}
		if ( $query && ( $query->is_admin || $query->is_feed || defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return $posts;
		}
		if ( $query && ! $query->is_main_query() ) {
			return $posts;
		}
		foreach ( $posts as $i => $post ) {
			if ( ! ( $post instanceof \WP_Post ) ) {
				continue;
			}
			if ( ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
				continue;
			}
			if ( self::is_memberistic_page( $post->ID ) || self::is_booking_page( $post->ID ) ) {
				continue;
			}
			$plans = array_filter( array_map( 'absint', (array) get_post_meta( $post->ID, '_memberistic_required_plans', true ) ) );
			if ( ! $plans || self::current_user_has_any_plan( $plans ) ) {
				continue;
			}
			$names  = self::plan_names( $plans );
			$teaser = $names
				? sprintf(
					/* translators: %s: comma-separated list of membership plan names. */
					__( 'Members-only content. Available for: %s.', 'memberistic' ),
					implode( ', ', $names )
				)
				: __( 'Members-only content. Available for active members only.', 'memberistic' );
			$posts[ $i ]->post_content = $teaser;
			$posts[ $i ]->post_excerpt = $teaser;
			self::$locked_post = $post->ID;
		}
		return $posts;
	}

	public static function add_meta_boxes() {
		foreach ( array( 'post', 'page' ) as $type ) {
			add_meta_box( 'memberistic-content-access', __( 'Memberistic Access', 'memberistic' ), array( self::class, 'render_meta_box' ), $type, 'side', 'default' );
		}
	}

	public static function render_meta_box( $post ) {
		$selected = array_filter( array_map( 'absint', (array) get_post_meta( $post->ID, '_memberistic_required_plans', true ) ) );
		$plans    = Plans_Repository::get_all( array( 'status' => 'active' ) );
		wp_nonce_field( 'memberistic_save_content_access', 'memberistic_content_access_nonce' );
		echo '<p>' . esc_html__( 'Restrict this content to selected membership plans.', 'memberistic' ) . '</p>';
		if ( empty( $plans ) ) {
			echo '<p><em>' . esc_html__( 'No active plans found.', 'memberistic' ) . '</em></p>';
			return;
		}
		foreach ( $plans as $plan ) {
			printf(
				'<label style="display:block;margin:8px 0;"><input type="checkbox" name="memberistic_required_plans[]" value="%1$d" %2$s> %3$s</label>',
				(int) $plan['id'],
				checked( in_array( (int) $plan['id'], $selected, true ), true, false ),
				esc_html( $plan['name'] )
			);
		}
	}

	public static function save_post( $post_id ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! isset( $_POST['memberistic_content_access_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['memberistic_content_access_nonce'] ) ), 'memberistic_save_content_access' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$plans = isset( $_POST['memberistic_required_plans'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['memberistic_required_plans'] ) ) : array();
		$plans = array_values( array_filter( array_unique( $plans ) ) );
		if ( $plans ) {
			update_post_meta( $post_id, '_memberistic_required_plans', $plans );
		} else {
			delete_post_meta( $post_id, '_memberistic_required_plans' );
		}
	}

	public static function detect_locked_content() {
		if ( is_admin() || ! is_singular( array( 'post', 'page' ) ) ) {
			return;
		}
		$post_id = get_queried_object_id();

		// Never restrict Memberistic's own functional pages — or a page hosting the
		// booking form, since locking it would hide the lane-booking UI from guests.
		if ( self::is_memberistic_page( $post_id ) || self::is_booking_page( $post_id ) ) {
			return;
		}

		$plans = array_filter( array_map( 'absint', (array) get_post_meta( $post_id, '_memberistic_required_plans', true ) ) );
		if ( ! $plans || self::current_user_has_any_plan( $plans ) ) {
			return;
		}
		self::$locked_post = $post_id;
	}

	public static function body_class( $classes ) {
		if ( self::$locked_post ) {
			$classes[] = 'memberistic-content-locked';
		}
		return $classes;
	}

	public static function render_overlay() {
		if ( ! self::$locked_post ) {
			return;
		}
		$plans = self::plan_names( array_filter( array_map( 'absint', (array) get_post_meta( self::$locked_post, '_memberistic_required_plans', true ) ) ) );
		$url   = memberistic_get_page_url( 'plans_page_id', 'memberships', home_url( '/memberships/' ) );
		?>
		<style>
			body.memberistic-content-locked > *:not(.memberistic-restriction-overlay){filter:blur(4px);pointer-events:none;user-select:none;}
			.memberistic-restriction-overlay{position:fixed;inset:0;z-index:99999;background:rgba(15,32,68,.48);backdrop-filter:blur(10px);display:flex;align-items:center;justify-content:center;padding:24px;}
			.memberistic-restriction-modal{background:#fff;border:1px solid rgba(15,32,68,.18);border-radius:8px;box-shadow:0 24px 70px rgba(15,32,68,.3);max-width:520px;padding:30px;text-align:left;}
			.memberistic-restriction-modal h2{color:#0f2044;font-size:28px;line-height:1.2;margin:0 0 10px;}
			.memberistic-restriction-modal p{color:#4b5563;font-size:16px;margin:0 0 18px;}
			.memberistic-restriction-modal a{background:#0f2044;border-radius:6px;color:#fff;display:inline-block;font-weight:800;padding:13px 18px;text-decoration:none;}
		</style>
		<div class="memberistic-restriction-overlay" role="dialog" aria-modal="true">
			<div class="memberistic-restriction-modal">
				<h2><?php esc_html_e( 'Membership Required', 'memberistic' ); ?></h2>
				<p>
					<?php
					echo esc_html(
						$plans
							? sprintf(
								/* translators: %s: comma-separated list of membership plan names. */
								__( 'This content is available for: %s.', 'memberistic' ),
								implode( ', ', $plans )
							)
							: __( 'This content is available for active members only.', 'memberistic' )
					);
					?>
				</p>
				<a href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'View Membership Plans', 'memberistic' ); ?></a>
			</div>
		</div>
		<?php
	}

	/**
	 * Replace the body of locked posts with a short teaser before output.
	 * Runs late (priority 999) so blocks/shortcodes have already been
	 * processed but the cached overlay-only behavior no longer leaks the
	 * full HTML to the browser. Operates only on the actual locked post
	 * inside the main query — does not affect sidebars or related posts.
	 */
	public static function filter_locked_content( $content ) {
		if ( is_admin() || is_feed() ) {
			return $content;
		}
		if ( ! in_the_loop() || ! is_main_query() || ! is_singular( array( 'post', 'page' ) ) ) {
			return $content;
		}
		$post_id = get_the_ID();
		if ( ! $post_id || self::is_memberistic_page( $post_id ) || self::is_booking_page( $post_id ) ) {
			return $content;
		}
		$plans = array_filter( array_map( 'absint', (array) get_post_meta( $post_id, '_memberistic_required_plans', true ) ) );
		if ( ! $plans || self::current_user_has_any_plan( $plans ) ) {
			return $content;
		}
		// Caller is not eligible. Mark for the cosmetic overlay (still
		// useful as a visual hint), but replace the body so the source
		// view does not contain the gated text.
		self::$locked_post = $post_id;
		$url = memberistic_get_page_url( 'plans_page_id', 'memberships', home_url( '/memberships/' ) );
		$names = self::plan_names( $plans );
		$detail = $names
			? sprintf(
				/* translators: %s: comma-separated list of membership plan names. */
				__( 'This content is available for: %s.', 'memberistic' ),
				implode( ', ', $names )
			)
			: __( 'This content is available for active members only.', 'memberistic' );
		ob_start();
		?>
		<div class="memberistic-content-teaser" style="padding:24px;border:1px solid #e5e7eb;border-radius:8px;background:#f9fafb;">
			<h3 style="margin:0 0 8px;"><?php esc_html_e( 'Members-only content', 'memberistic' ); ?></h3>
			<p style="margin:0 0 14px;color:#4b5563;"><?php echo esc_html( $detail ); ?></p>
			<a href="<?php echo esc_url( $url ); ?>" style="display:inline-block;padding:10px 16px;background:#0f2044;color:#fff;border-radius:6px;text-decoration:none;font-weight:700;"><?php esc_html_e( 'View Membership Plans', 'memberistic' ); ?></a>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * REST counterpart: when a locked post is requested via the REST API
	 * by a caller without an eligible membership, blank out content,
	 * excerpt and rendered content so the body is not exposed.
	 */
	public static function filter_rest_response( $response, $post ) {
		if ( ! $post || ! ( $post instanceof \WP_Post ) ) {
			return $response;
		}
		if ( self::is_memberistic_page( $post->ID ) || self::is_booking_page( $post->ID ) ) {
			return $response;
		}
		$plans = array_filter( array_map( 'absint', (array) get_post_meta( $post->ID, '_memberistic_required_plans', true ) ) );
		if ( ! $plans || self::current_user_has_any_plan( $plans ) ) {
			return $response;
		}
		$data = $response->get_data();
		foreach ( array( 'content', 'excerpt' ) as $field ) {
			if ( isset( $data[ $field ] ) && is_array( $data[ $field ] ) ) {
				$data[ $field ]['rendered']  = '';
				$data[ $field ]['raw']       = '';
				$data[ $field ]['protected'] = true;
			}
		}
		$data['memberistic_restricted'] = true;
		$response->set_data( $data );
		return $response;
	}

	public static function sync_roles_for_membership( $membership_id ) {
		$membership = Memberships_Repository::get( absint( $membership_id ) );
		if ( ! is_array( $membership ) || empty( $membership['primary_user_id'] ) ) {
			return;
		}
		if ( ! in_array( $membership['status'], array( 'active', 'comped', 'trial' ), true ) ) {
			return;
		}
		$user = get_user_by( 'id', (int) $membership['primary_user_id'] );
		if ( ! $user ) {
			return;
		}
		if ( ! get_role( 'memberistic_member' ) ) {
			add_role( 'memberistic_member', __( 'Member', 'memberistic' ), array( 'read' => true ) );
		}
		$user->add_role( 'memberistic_member' );
		$plan = Plans_Repository::get( (int) $membership['plan_id'] );
		if ( $plan ) {
			$role = 'memberistic_plan_' . sanitize_key( $plan['slug'] );
			if ( ! get_role( $role ) ) {
				/* translators: %s: the membership plan's name. */
				add_role( $role, sprintf( __( 'Member - %s', 'memberistic' ), $plan['name'] ), array( 'read' => true ) );
			}
			$user->add_role( $role );
			update_user_meta( $user->ID, 'memberistic_active_plan_id', (int) $plan['id'] );
			update_user_meta( $user->ID, 'memberistic_active_plan_name', $plan['name'] );
		}
		// Once the customer becomes a member, remove any lower-tier
		// walk-in role so the WP user list shows them under their member
		// classification instead of both. Which roles count as walk-in is
		// site-specific, so it is filterable rather than hard-coded.
		/**
		 * Filters the roles removed from a user when they become a member.
		 *
		 * @since 2.0.0
		 *
		 * @param string[] $roles   Role slugs to strip.
		 * @param \WP_User $user    The user being promoted to member.
		 */
		$walkin_roles = (array) apply_filters( 'memberistic_walkin_roles', array(), $user );

		foreach ( $walkin_roles as $walkin_role ) {
			$walkin_role = (string) $walkin_role;
			if ( '' !== $walkin_role && get_role( $walkin_role ) && in_array( $walkin_role, (array) $user->roles, true ) ) {
				$user->remove_role( $walkin_role );
			}
		}
		// Also strip the default "Subscriber" role if WP added it — the
		// member role already grants 'read'.
		if ( in_array( 'subscriber', (array) $user->roles, true ) ) {
			$user->remove_role( 'subscriber' );
		}
	}

	private static function current_user_has_any_plan( $plan_ids ) {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return false;
		}
		// Resolve via the same path the booking integration uses (primary_user_id
		// OR email-linked person) so a legitimately active email-linked member is
		// not wrongly treated as a non-member here.
		// Booking_Engine's resolver is the authoritative bookable check (status +
		// renewal + email-link). It's always loaded, so trust it exclusively — do
		// NOT fall through to a status-only test that would re-admit an active-status
		// but past-renewal (lazily-not-yet-expired) member.
		if ( class_exists( '\\WordPressistic\\Memberistic\\Integrations\\Booking_Engine' ) ) {
			$membership = \WordPressistic\Memberistic\Integrations\Booking_Engine::get_active_membership_for_user( $user_id );
			return is_array( $membership ) && in_array( (int) $membership['plan_id'], $plan_ids, true );
		}
		// Fallback only when the integration class isn't available at all.
		$membership = Memberships_Repository::get_by_user_id( $user_id );
		if ( ! is_array( $membership ) || ! in_array( $membership['status'], array( 'active', 'comped', 'trial' ), true ) ) {
			return false;
		}
		return in_array( (int) $membership['plan_id'], $plan_ids, true );
	}

	/**
	 * Strip the member roles + active-plan user-meta when a membership expires,
	 * UNLESS the user still holds another active membership. Mirrors the
	 * activation logic in sync_roles_for_membership() so an expired member stops
	 * reading as active to both content restriction and the booking engine.
	 *
	 * @param int $membership_id Expired membership row id.
	 */
	public static function clear_roles_for_membership( $membership_id ) {
		$membership = Memberships_Repository::get( absint( $membership_id ) );
		if ( ! is_array( $membership ) || empty( $membership['primary_user_id'] ) ) {
			return;
		}
		$user_id = (int) $membership['primary_user_id'];
		// If the user still holds ANOTHER active membership (other than this expired
		// one), keep their roles/meta. We can't use get_by_user_id() here: the
		// scheduler sets this row's status to 'expired' BEFORE firing the hook, and
		// that method returns only the newest row — which may be this expired one.
		global $wpdb;
		$survivor = $wpdb->get_var( $wpdb->prepare(
			'SELECT 1 FROM ' . Memberships_Repository::table() . " WHERE primary_user_id = %d AND id <> %d AND status IN ( 'active', 'comped', 'trial' ) LIMIT 1",
			$user_id,
			(int) $membership['id']
		) );
		if ( $survivor ) {
			return;
		}
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return;
		}
		if ( in_array( 'memberistic_member', (array) $user->roles, true ) ) {
			$user->remove_role( 'memberistic_member' );
		}
		$plan = ! empty( $membership['plan_id'] ) ? Plans_Repository::get( (int) $membership['plan_id'] ) : null;
		if ( is_array( $plan ) && ! empty( $plan['slug'] ) ) {
			$role = 'memberistic_plan_' . sanitize_key( $plan['slug'] );
			if ( in_array( $role, (array) $user->roles, true ) ) {
				$user->remove_role( $role );
			}
		}
		delete_user_meta( $user->ID, 'memberistic_active_plan_id' );
		delete_user_meta( $user->ID, 'memberistic_active_plan_name' );
	}

	/**
	 * Never restrict a page that hosts the booking-engine form — locking it would
	 * hide the lane-booking UI from guests with no booking-engine-side evidence.
	 * Filterable so operators can exempt additional pages.
	 *
	 * @param int $post_id Post ID.
	 */
	/**
	 * Is this a booking page that must never be content-restricted?
	 *
	 * A mis-set "required plan" must not be able to hide the booking form
	 * from the guests it exists to serve, so any page carrying the mapped
	 * booking engine's shortcode is exempt from all four restriction gates.
	 *
	 * @param int $post_id Post to test.
	 * @return bool
	 */
	private static function is_booking_page( $post_id ) {
		$is_booking = false;
		$post       = get_post( $post_id );

		if ( $post instanceof \WP_Post ) {
			$content = (string) $post->post_content;

			foreach ( \WordPressistic\Memberistic\Integrations\Booking_Adapter::shortcodes() as $tag ) {
				if ( has_shortcode( $content, $tag ) ) {
					$is_booking = true;
					break;
				}
			}
		}

		return (bool) apply_filters( 'memberistic_restriction_exempt_post', $is_booking, $post_id );
	}

	private static function plan_names( $ids ) {
		$names = array();
		foreach ( $ids as $id ) {
			$plan = Plans_Repository::get( $id );
			if ( $plan ) {
				$names[] = $plan['name'];
			}
		}
		return $names;
	}

	/**
	 * Check if a page is one of Memberistic's own functional pages.
	 * These pages must never be subject to content restriction overlays.
	 *
	 * @param int $post_id Post ID.
	 */
	private static function is_memberistic_page( $post_id ) {
		$page_keys = array(
			'plans_page_id',
			'checkout_page_id',
			'account_page_id',
			'renewal_page_id',
			'login_page_id',
			'thank_you_page_id',
			'failed_payment_page_id',
			'staff_dashboard_page_id',
		);

		foreach ( $page_keys as $key ) {
			if ( absint( memberistic_get_setting( $key, 0 ) ) === $post_id ) {
				return true;
			}
		}

		// Also match by known Memberistic page slugs as a fallback.
		$post = get_post( $post_id );
		if ( $post ) {
			$memberistic_slugs = array(
				'memberistic-memberships',
				'memberistic-checkout',
				'memberistic-account',
				'memberistic-renewal',
				'memberistic-login',
				'memberistic-thank-you',
				'memberistic-payment-failed',
				'memberistic-staff-dashboard',
			);
			if ( in_array( $post->post_name, $memberistic_slugs, true ) ) {
				return true;
			}
		}

		return false;
	}
}
