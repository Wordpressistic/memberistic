<?php
/**
 * Admin menu.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic\Admin;

use function WordPressistic\Memberistic\memberistic_current_user_can;
use function WordPressistic\Memberistic\memberistic_get_setting;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Admin_Menu {
	/**
	 * Register plugin admin menu.
	 */
	public static function register() {
		// The admin menu is staff-facing plumbing, so it always reads
		// "Memberistic" — the product name. It deliberately does NOT use
		// memberistic_get_brand_label(), which is the CUSTOMER-facing business
		// brand used in emails and the member portal. Sharing one label between
		// the two meant that shortening the brand to fit the sidebar also
		// renamed the whole admin menu, leaving staff without an obvious
		// "Memberistic" entry to look for.
		$label = __( 'Memberistic', 'memberistic' );

		add_menu_page(
			$label,
			$label,
			'view_memberistic_dashboard',
			'memberistic-dashboard',
			array( Dashboard_Page::class, 'render' ),
			'dashicons-groups',
			56
		);

		add_submenu_page( 'memberistic-dashboard', __( 'Dashboard', 'memberistic' ), __( 'Dashboard', 'memberistic' ), 'view_memberistic_dashboard', 'memberistic-dashboard', array( Dashboard_Page::class, 'render' ) );
		add_submenu_page( 'memberistic-dashboard', __( 'Members', 'memberistic' ), __( 'Members', 'memberistic' ), 'view_memberistic_members', 'memberistic-members', array( Members_Page::class, 'render' ) );
		add_submenu_page( 'memberistic-dashboard', __( 'Plans', 'memberistic' ), __( 'Plans', 'memberistic' ), 'manage_memberistic_plans', 'memberistic-plans', array( Plans_Page::class, 'render' ) );
		add_submenu_page( 'memberistic-dashboard', __( 'Payments', 'memberistic' ), __( 'Payments', 'memberistic' ), 'manage_memberistic_payments', 'memberistic-payments', array( Payments_Page::class, 'render' ) );
		add_submenu_page( 'memberistic-dashboard', __( 'Check-Ins', 'memberistic' ), __( 'Check-Ins', 'memberistic' ), 'memberistic_checkin_members', 'memberistic-checkins', array( Checkins_Page::class, 'render' ) );
		add_submenu_page( 'memberistic-dashboard', __( 'Waivers', 'memberistic' ), __( 'Waivers', 'memberistic' ), 'edit_memberistic_members', 'memberistic-waivers', array( \WordPressistic\Memberistic\Waivers\Waiver_Admin_Page::class, 'render' ) );
		add_submenu_page( 'memberistic-dashboard', __( 'Waivers on File', 'memberistic' ), __( 'Waivers on File', 'memberistic' ), 'view_memberistic_dashboard', 'memberistic-waivers-archive', array( \WordPressistic\Memberistic\Waivers\Waiver_Archive_Admin::class, 'render' ) );
		add_submenu_page( 'memberistic-dashboard', __( 'Activity', 'memberistic' ), __( 'Activity', 'memberistic' ), 'view_memberistic_dashboard', 'memberistic-activity', array( Activity_Page::class, 'render' ) );

		add_submenu_page( 'memberistic-dashboard', __( 'Emails', 'memberistic' ), __( 'Emails', 'memberistic' ), 'view_memberistic_dashboard', 'memberistic-emails', array( self::class, 'render_emails' ) );
		add_submenu_page( 'memberistic-dashboard', __( 'Integrations', 'memberistic' ), __( 'Integrations', 'memberistic' ), 'view_memberistic_dashboard', 'memberistic-integrations', array( self::class, 'render_integrations' ) );
		add_submenu_page( 'memberistic-dashboard', __( 'Shortcodes', 'memberistic' ), __( 'Shortcodes', 'memberistic' ), 'view_memberistic_dashboard', 'memberistic-shortcodes', array( self::class, 'render_shortcodes' ) );

		add_submenu_page( 'memberistic-dashboard', __( 'Import', 'memberistic' ), __( 'Import', 'memberistic' ), 'manage_memberistic_settings', 'memberistic-import', array( Import_Page::class, 'render' ) );
		add_submenu_page( 'memberistic-dashboard', __( 'Settings', 'memberistic' ), __( 'Settings', 'memberistic' ), 'manage_memberistic_settings', 'memberistic-settings', array( Settings_Page::class, 'render' ) );
		add_submenu_page( 'memberistic-dashboard', __( 'Tools', 'memberistic' ), __( 'Tools', 'memberistic' ), 'manage_memberistic_settings', 'memberistic-tools', array( self::class, 'render_tools' ) );
	}

	private static function guard_dashboard() {
		if ( ! memberistic_current_user_can( 'view_memberistic_dashboard' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'memberistic' ) );
		}
	}

	public static function render_emails() {
		self::guard_dashboard();
		// Legacy server-side CSV export — still works for users who bookmark the URL.
		if ( isset( $_GET['memberistic_export'] ) && 'emails' === sanitize_key( wp_unslash( $_GET['memberistic_export'] ) ) && check_admin_referer( 'memberistic_export_emails' ) ) {
			self::download_email_csv( self::email_rows() );
		}
		?>
		<div class="wrap memberistic-wrap">
			<div id="memberistic-emails-app" class="memberistic-react-root">
				<div class="memberistic-react-loading">
					<p><?php esc_html_e( 'Loading email directory…', 'memberistic' ); ?></p>
					<noscript>
						<p><?php esc_html_e( 'The Memberistic email directory requires JavaScript.', 'memberistic' ); ?></p>
					</noscript>
				</div>
			</div>
		</div>
		<?php
	}

	public static function render_integrations() {
		self::guard_dashboard();
		$registry = '\\WordPressistic\\Memberistic\\Integrations\\Integrations_Registry';
		$defs     = $registry::definitions();
		?>
		<div class="wrap memberistic-wrap">
			<h1><?php esc_html_e( 'Memberistic Integrations', 'memberistic' ); ?></h1>
			<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Integrations updated.', 'memberistic' ); ?></p></div>
			<?php endif; ?>
			<p class="description" style="max-width:680px;"><?php esc_html_e( 'Turn each integration on or off. Connectable integrations show a switch; "Coming Soon" ones are listed for reference.', 'memberistic' ); ?></p>

			<style>
				.memberistic-switch{position:relative;display:inline-block;width:44px;height:24px;vertical-align:middle;}
				.memberistic-switch input{opacity:0;width:0;height:0;}
				.memberistic-switch .track{position:absolute;cursor:pointer;inset:0;background:#c3c4c7;border-radius:24px;transition:.2s;}
				.memberistic-switch .track:before{content:"";position:absolute;height:18px;width:18px;left:3px;top:3px;background:#fff;border-radius:50%;transition:.2s;}
				.memberistic-switch input:checked + .track{background:#0a7c3f;}
				.memberistic-switch input:checked + .track:before{transform:translateX(20px);}
				.memberistic-switch input:disabled + .track{opacity:.5;cursor:not-allowed;}
				.memberistic-addon-foot{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:10px;}
				.memberistic-addon-subopts{margin-top:10px;padding-top:10px;border-top:1px solid #e2e4e7;font-size:13px;}
				.memberistic-addon-subopts label{display:block;margin:4px 0;}
			</style>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="memberistic_save_integrations" />
				<?php wp_nonce_field( 'memberistic_integrations', 'memberistic_integrations_nonce' ); ?>
				<div class="memberistic-addon-grid">
					<?php
					foreach ( $defs as $key => $def ) :
						$coming    = ! empty( $def['coming_soon'] );
						$available = $registry::is_available( $key );
						$enabled   = $registry::is_enabled( $key );
						$status    = $coming ? __( 'Coming Soon', 'memberistic' ) : ( ! $available ? __( 'Plugin not detected', 'memberistic' ) : ( $enabled ? __( 'Enabled', 'memberistic' ) : __( 'Disabled', 'memberistic' ) ) );
						?>
						<div class="memberistic-addon-card <?php echo $enabled ? 'is-active' : ''; ?> <?php echo $coming ? 'is-coming-soon' : ''; ?>">
							<span class="memberistic-addon-icon"><?php echo esc_html( $def['icon'] ); ?></span>
							<h2><?php echo esc_html( $def['name'] ); ?></h2>
							<p><?php echo esc_html( $def['desc'] ); ?></p>
							<?php if ( ! $coming && ! $available && ! empty( $def['dep_label'] ) ) : ?>
								<p class="description" style="color:#b32d2e;"><?php echo esc_html( $def['dep_label'] ); ?></p>
							<?php endif; ?>
							<div class="memberistic-addon-foot">
								<strong><?php echo esc_html( $status ); ?></strong>
								<?php if ( ! $coming ) : ?>
									<label class="memberistic-switch" title="<?php esc_attr_e( 'Toggle integration', 'memberistic' ); ?>">
										<input type="checkbox" name="memberistic_integrations[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( $enabled ); ?> <?php disabled( ! $available ); ?> />
										<span class="track"></span>
									</label>
								<?php endif; ?>
							</div>

							<?php if ( 'verifyistic' === $key && $available ) : ?>
								<div class="memberistic-addon-subopts">
									<label>
										<input type="checkbox" name="memberistic_verifyistic_autostamp" value="1" <?php checked( 'no' !== memberistic_get_setting( 'integration_verifyistic_autostamp', 'yes' ) ); ?> />
										<?php esc_html_e( 'Auto-stamp member records with verified age/DOB', 'memberistic' ); ?>
									</label>
									<label>
										<input type="checkbox" name="memberistic_verifyistic_require_signup" value="1" <?php checked( 'no' !== memberistic_get_setting( 'integration_verifyistic_require_signup', 'yes' ) ); ?> />
										<?php esc_html_e( 'Require age verification before membership signup', 'memberistic' ); ?>
									</label>
								</div>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				</div>
				<?php self::render_corestore_panel(); ?>
				<?php self::render_woo_discounts_panel(); ?>

				<p class="submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Save Integrations', 'memberistic' ); ?></button></p>
			</form>

			<?php self::render_corestore_log(); ?>
		</div>
		<?php
	}

	/**
	 * coreSTORE connection + tier-mapping settings (rendered inside the
	 * Integrations form so one Save persists everything).
	 */
	private static function render_corestore_panel() {
		$bridge   = '\\WordPressistic\\Memberistic\\Integrations\\CoreStore_Bridge';
		$settings = $bridge::settings();
		$plans    = \WordPressistic\Memberistic\Database\Plans_Repository::get_all( array( 'status' => 'active' ) );
		?>
		<div class="memberistic-addon-card" style="margin-top:24px;max-width:820px;">
			<h2><?php esc_html_e( 'coreSTORE Settings', 'memberistic' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Paste the store URL and API key from coreSTORE (Store Config → API Keys). Map each plan to the coreSTORE price tier that carries its member discount — tiers are created inside coreSTORE under Customers → Tiers.', 'memberistic' ); ?></p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="mcs-url"><?php esc_html_e( 'Store URL', 'memberistic' ); ?></label></th>
					<td><input type="url" id="mcs-url" name="memberistic_corestore[store_url]" class="regular-text" placeholder="https://yourstore.corestore.online" value="<?php echo esc_attr( $settings['store_url'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="mcs-key"><?php esc_html_e( 'API Key', 'memberistic' ); ?></label></th>
					<td>
						<input type="password" id="mcs-key" name="memberistic_corestore[api_key]" class="regular-text" autocomplete="new-password" placeholder="<?php echo $settings['api_key'] ? esc_attr__( '•••••••• (saved — leave blank to keep)', 'memberistic' ) : ''; ?>" value="">
						<p class="description"><?php esc_html_e( 'Stored server-side only. Leave blank to keep the saved key.', 'memberistic' ); ?></p>
					</td>
				</tr>
				<?php foreach ( (array) $plans as $plan ) : $pid = (int) $plan['id']; ?>
					<tr>
						<th scope="row"><?php echo esc_html( sprintf( /* translators: %s: plan name */ __( '%s → tier ID', 'memberistic' ), $plan['name'] ) ); ?></th>
						<td><input type="text" name="memberistic_corestore[tier_map][<?php echo esc_attr( $pid ); ?>]" class="small-text" value="<?php echo esc_attr( $settings['tier_map'][ (string) $pid ] ?? '' ); ?>"></td>
					</tr>
				<?php endforeach; ?>
				<tr>
					<th scope="row"><label for="mcs-lapsed"><?php esc_html_e( 'Lapsed members → tier ID', 'memberistic' ); ?></label></th>
					<td>
						<input type="text" id="mcs-lapsed" name="memberistic_corestore[lapsed_tier_id]" class="small-text" value="<?php echo esc_attr( $settings['lapsed_tier_id'] ); ?>">
						<p class="description"><?php esc_html_e( 'Leave empty to clear lapsed members back to standard pricing.', 'memberistic' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Customer custom fields (optional)', 'memberistic' ); ?></th>
					<td>
						<input type="text" name="memberistic_corestore[field_plan]" placeholder="<?php esc_attr_e( 'Field name for plan', 'memberistic' ); ?>" value="<?php echo esc_attr( $settings['field_plan'] ); ?>">
						<input type="text" name="memberistic_corestore[field_status]" placeholder="<?php esc_attr_e( 'Field name for status', 'memberistic' ); ?>" value="<?php echo esc_attr( $settings['field_status'] ); ?>">
						<input type="text" name="memberistic_corestore[field_expires]" placeholder="<?php esc_attr_e( 'Field name for expiry', 'memberistic' ); ?>" value="<?php echo esc_attr( $settings['field_expires'] ); ?>">
						<p class="description"><?php esc_html_e( 'Create matching custom fields on coreSTORE customer records first, then enter their exact names here so plan / status / expiry show on the cashier\'s screen.', 'memberistic' ); ?></p>
					</td>
				</tr>
			</table>
			<p>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=memberistic_corestore_test' ), 'memberistic_corestore_action' ) ); ?>"><?php esc_html_e( 'Test Connection', 'memberistic' ); ?></a>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=memberistic_corestore_sync' ), 'memberistic_corestore_action' ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Push all current members to coreSTORE now?', 'memberistic' ) ); ?>');"><?php esc_html_e( 'Sync Now', 'memberistic' ); ?></a>
				<span class="description" style="margin-left:8px;"><?php esc_html_e( 'Save first — both buttons use the saved settings.', 'memberistic' ); ?></span>
			</p>
		</div>
		<?php
	}

	/** Per-plan WooCommerce member-discount rules. */
	private static function render_woo_discounts_panel() {
		if ( ! class_exists( '\\WooCommerce' ) ) {
			return;
		}
		$discounts = '\\WordPressistic\\Memberistic\\Integrations\\WooCommerce_Discounts';
		$rules     = $discounts::rules();
		$plans     = \WordPressistic\Memberistic\Database\Plans_Repository::get_all( array( 'status' => 'active' ) );
		$cats      = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'number' => 200 ) );
		$enabled   = 'yes' === memberistic_get_setting( 'woo_member_discounts_enabled', 'yes' );
		?>
		<div class="memberistic-addon-card" style="margin-top:24px;max-width:820px;">
			<h2><?php esc_html_e( 'WooCommerce Member Discounts', 'memberistic' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Active members automatically get their plan\'s discount on eligible products at cart and checkout, and see their member price on product pages. Membership products are never discounted.', 'memberistic' ); ?></p>
			<label style="display:block;margin:8px 0 4px;">
				<input type="checkbox" name="memberistic_woo_discounts_enabled" value="1" <?php checked( $enabled ); ?> />
				<?php esc_html_e( 'Enable automatic member discounts in WooCommerce', 'memberistic' ); ?>
			</label>
			<table class="widefat striped" style="max-width:820px;margin-top:8px;">
				<thead><tr>
					<th><?php esc_html_e( 'Plan', 'memberistic' ); ?></th>
					<th style="width:90px;"><?php esc_html_e( 'Discount %', 'memberistic' ); ?></th>
					<th style="width:170px;"><?php esc_html_e( 'Applies to', 'memberistic' ); ?></th>
					<th><?php esc_html_e( 'Categories', 'memberistic' ); ?></th>
					<th style="width:110px;"><?php esc_html_e( 'Skip sale items', 'memberistic' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( (array) $plans as $plan ) :
					$pid  = (int) $plan['id'];
					$rule = $rules[ $pid ] ?? array( 'percent' => 0, 'mode' => 'all', 'categories' => array(), 'exclude_sale' => 'yes' );
					?>
					<tr>
						<td><strong><?php echo esc_html( $plan['name'] ); ?></strong></td>
						<td><input type="number" step="0.5" min="0" max="100" name="memberistic_woo_discounts[<?php echo esc_attr( $pid ); ?>][percent]" value="<?php echo esc_attr( $rule['percent'] ); ?>" style="width:70px;"></td>
						<td>
							<select name="memberistic_woo_discounts[<?php echo esc_attr( $pid ); ?>][mode]">
								<option value="all" <?php selected( $rule['mode'], 'all' ); ?>><?php esc_html_e( 'Whole store', 'memberistic' ); ?></option>
								<option value="include" <?php selected( $rule['mode'], 'include' ); ?>><?php esc_html_e( 'Only these categories', 'memberistic' ); ?></option>
								<option value="exclude" <?php selected( $rule['mode'], 'exclude' ); ?>><?php esc_html_e( 'All except these', 'memberistic' ); ?></option>
							</select>
						</td>
						<td>
							<select name="memberistic_woo_discounts[<?php echo esc_attr( $pid ); ?>][categories][]" multiple size="3" style="min-width:180px;">
								<?php foreach ( (array) $cats as $cat ) : if ( ! is_object( $cat ) ) { continue; } ?>
									<option value="<?php echo esc_attr( $cat->term_id ); ?>" <?php selected( in_array( (int) $cat->term_id, $rule['categories'], true ) ); ?>><?php echo esc_html( $cat->name ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
						<td style="text-align:center;"><input type="checkbox" name="memberistic_woo_discounts[<?php echo esc_attr( $pid ); ?>][exclude_sale]" value="1" <?php checked( 'yes', $rule['exclude_sale'] ); ?>></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description"><?php esc_html_e( 'Firearms retail note: keep MAP-protected brands and categories in an exclusion list — the same rules should be mirrored in the coreSTORE tier configuration so in-store and online never disagree.', 'memberistic' ); ?></p>
		</div>
		<?php
	}

	/** Recent coreSTORE sync log (outside the form — read only). */
	private static function render_corestore_log() {
		$bridge  = '\\WordPressistic\\Memberistic\\Integrations\\CoreStore_Bridge';
		$entries = $bridge::get_log( 15 );
		if ( ! $entries ) {
			return;
		}
		?>
		<div class="memberistic-addon-card" style="margin-top:24px;max-width:820px;">
			<h2><?php esc_html_e( 'coreSTORE Sync Log', 'memberistic' ); ?></h2>
			<table class="widefat striped">
				<tbody>
				<?php foreach ( $entries as $e ) : ?>
					<tr>
						<td style="white-space:nowrap;width:150px;"><?php echo esc_html( $e['time'] ); ?></td>
						<td style="width:60px;"><strong style="color:<?php echo 'error' === $e['level'] ? '#b32d2e' : ( 'warn' === $e['level'] ? '#996800' : '#0a7c3f' ); ?>;"><?php echo esc_html( strtoupper( $e['level'] ) ); ?></strong></td>
						<td><?php echo esc_html( $e['message'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Persist Integrations-page toggles (admin-post handler).
	 */
	public static function save_integrations() {
		if ( ! current_user_can( 'manage_memberistic_settings' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage integrations.', 'memberistic' ) );
		}
		check_admin_referer( 'memberistic_integrations', 'memberistic_integrations_nonce' );

		$registry = '\\WordPressistic\\Memberistic\\Integrations\\Integrations_Registry';
		$posted   = isset( $_POST['memberistic_integrations'] ) && is_array( $_POST['memberistic_integrations'] )
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['memberistic_integrations'] ) )
			: array();
		$registry::save( $posted );

		// Verifyistic sub-options.
		$settings = get_option( 'memberistic_settings', array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		$settings['integration_verifyistic_autostamp']      = empty( $_POST['memberistic_verifyistic_autostamp'] ) ? 'no' : 'yes';
		$settings['integration_verifyistic_require_signup']  = empty( $_POST['memberistic_verifyistic_require_signup'] ) ? 'no' : 'yes';
		$settings['woo_member_discounts_enabled']            = empty( $_POST['memberistic_woo_discounts_enabled'] ) ? 'no' : 'yes';
		update_option( 'memberistic_settings', $settings, false );

		// coreSTORE connection settings. A blank API key keeps the saved one.
		if ( isset( $_POST['memberistic_corestore'] ) && is_array( $_POST['memberistic_corestore'] ) ) {
			$bridge = '\\WordPressistic\\Memberistic\\Integrations\\CoreStore_Bridge';
			$in     = wp_unslash( $_POST['memberistic_corestore'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- fields sanitized individually below.
			$prev   = $bridge::settings();
			$key    = isset( $in['api_key'] ) ? trim( (string) $in['api_key'] ) : '';
			$store  = array(
				'store_url'      => isset( $in['store_url'] ) ? esc_url_raw( trim( (string) $in['store_url'] ) ) : '',
				'api_key'        => '' !== $key ? sanitize_text_field( $key ) : $prev['api_key'],
				'tier_map'       => array(),
				'lapsed_tier_id' => isset( $in['lapsed_tier_id'] ) ? sanitize_text_field( (string) $in['lapsed_tier_id'] ) : '',
				'field_plan'     => isset( $in['field_plan'] ) ? sanitize_text_field( (string) $in['field_plan'] ) : '',
				'field_status'   => isset( $in['field_status'] ) ? sanitize_text_field( (string) $in['field_status'] ) : '',
				'field_expires'  => isset( $in['field_expires'] ) ? sanitize_text_field( (string) $in['field_expires'] ) : '',
			);
			foreach ( (array) ( $in['tier_map'] ?? array() ) as $plan_id => $tier ) {
				$tier = sanitize_text_field( (string) $tier );
				if ( '' !== $tier ) {
					$store['tier_map'][ (string) (int) $plan_id ] = $tier;
				}
			}
			update_option( $bridge::OPTION, $store, false );
		}

		// Per-plan WooCommerce discount rules.
		if ( isset( $_POST['memberistic_woo_discounts'] ) && is_array( $_POST['memberistic_woo_discounts'] ) ) {
			$rules = array();
			foreach ( wp_unslash( $_POST['memberistic_woo_discounts'] ) as $plan_id => $rule ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- fields sanitized individually below.
				if ( ! is_array( $rule ) ) {
					continue;
				}
				$rules[ (int) $plan_id ] = array(
					'percent'      => isset( $rule['percent'] ) ? max( 0.0, min( 100.0, (float) $rule['percent'] ) ) : 0.0,
					'mode'         => in_array( $rule['mode'] ?? 'all', array( 'all', 'include', 'exclude' ), true ) ? $rule['mode'] : 'all',
					'categories'   => isset( $rule['categories'] ) && is_array( $rule['categories'] ) ? array_map( 'intval', $rule['categories'] ) : array(),
					'exclude_sale' => empty( $rule['exclude_sale'] ) ? 'no' : 'yes',
				);
			}
			update_option( \WordPressistic\Memberistic\Integrations\WooCommerce_Discounts::OPTION, $rules, false );
		}

		wp_safe_redirect( add_query_arg( array( 'page' => 'memberistic-integrations', 'updated' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/** Admin-post: probe the saved coreSTORE credentials. */
	public static function corestore_test() {
		if ( ! current_user_can( 'manage_memberistic_settings' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'memberistic' ) );
		}
		check_admin_referer( 'memberistic_corestore_action' );
		$bridge = '\\WordPressistic\\Memberistic\\Integrations\\CoreStore_Bridge';
		$res    = $bridge::client()->test_connection();
		$bridge::log(
			$res['ok'] ? 'ok' : 'error',
			$res['ok']
				? __( 'Connection test OK — API key accepted.', 'memberistic' )
				: sprintf( 'Connection test FAILED: HTTP %d %s', (int) $res['code'], (string) $res['error'] )
		);
		wp_safe_redirect( add_query_arg( array( 'page' => 'memberistic-integrations', 'updated' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/** Admin-post: full push of current members to coreSTORE. */
	public static function corestore_sync() {
		if ( ! current_user_can( 'manage_memberistic_settings' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'memberistic' ) );
		}
		check_admin_referer( 'memberistic_corestore_action' );
		$bridge = '\\WordPressistic\\Memberistic\\Integrations\\CoreStore_Bridge';
		$bridge::reconcile();
		wp_safe_redirect( add_query_arg( array( 'page' => 'memberistic-integrations', 'updated' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function render_tools() {
		self::guard_dashboard();
		$checks = array(
			__( 'Database version', 'memberistic' ) => get_option( 'memberistic_db_version', 'n/a' ),
			__( 'Plugin version', 'memberistic' )  => MEMBERISTIC_VERSION,
			__( 'REST namespace', 'memberistic' )  => rest_url( 'memberistic/v1/' ),
			__( 'Stripe webhook', 'memberistic' )  => rest_url( 'memberistic/v1/webhooks/stripe' ),
		);
		?>
		<div class="wrap memberistic-wrap">
			<h1><?php esc_html_e( 'Memberistic Tools', 'memberistic' ); ?></h1>
			<div class="memberistic-two-column">
				<div class="memberistic-card">
					<h2><?php esc_html_e( 'System Health', 'memberistic' ); ?></h2>
					<table class="widefat striped">
						<tbody>
						<?php foreach ( $checks as $label => $value ) : ?>
							<tr><th><?php echo esc_html( $label ); ?></th><td><code><?php echo esc_html( (string) $value ); ?></code></td></tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<div class="memberistic-card">
					<h2><?php esc_html_e( 'Quick Actions', 'memberistic' ); ?></h2>
					<p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=memberistic-settings#memberistic-pages' ) ); ?>"><?php esc_html_e( 'Review Page Mapping', 'memberistic' ); ?></a></p>
					<p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=memberistic-emails' ) ); ?>"><?php esc_html_e( 'Open Email Directory', 'memberistic' ); ?></a></p>
					<p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=memberistic-integrations' ) ); ?>"><?php esc_html_e( 'Check Integrations', 'memberistic' ); ?></a></p>
				</div>
			</div>
		</div>
		<?php
	}

	public static function render_shortcodes() {
		self::guard_dashboard();
		?>
		<div class="wrap memberistic-wrap"><h1><?php esc_html_e( 'Memberistic Shortcodes', 'memberistic' ); ?></h1><div class="memberistic-card"><ul class="memberistic-shortcode-list">
			<li><code>[memberistic_plans]</code></li><li><code>[memberistic_checkout]</code></li><li><code>[memberistic_account]</code></li><li><code>[memberistic_staff_dashboard]</code></li><li><code>[memberistic_login]</code></li><li><code>[memberistic_thank_you]</code></li><li><code>[memberistic_payment_failed]</code></li>
		</ul></div></div>
		<?php
	}

	private static function email_rows() {
		global $wpdb;
		$people      = $wpdb->prefix . 'memberistic_people';
		$memberships = $wpdb->prefix . 'memberistic_memberships';
		$plans       = $wpdb->prefix . 'memberistic_plans';
		return $wpdb->get_results( "SELECT p.full_name, p.email, p.phone, p.role, p.waiver_status, p.waiver_signed_at, p.waiver_expires_at, p.created_at AS person_created_at, m.membership_uuid, m.status AS membership_status, m.billing_cycle, m.renewal_date, pl.name AS plan_name FROM {$people} p LEFT JOIN {$memberships} m ON m.id=p.membership_id LEFT JOIN {$plans} pl ON pl.id=m.plan_id WHERE p.email <> '' ORDER BY p.full_name ASC LIMIT 5000", ARRAY_A ) ?: array();
	}

	private static function download_email_csv( $rows ) {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=memberistic-member-emails-' . gmdate( 'Y-m-d' ) . '.csv' );
		$out = fopen( 'php://output', 'w' );
		fputcsv(
			$out,
			array(
				'Name',
				'Email',
				'Phone',
				'Role',
				'Membership ID',
				'Plan',
				'Membership Status',
				'Billing Cycle',
				'Renewal Date',
				'Waiver Status',
				'Waiver Signed At',
				'Waiver Expires At',
				'Created',
			)
		);
		foreach ( $rows as $row ) {
			fputcsv(
				$out,
				array(
					$row['full_name'],
					$row['email'],
					$row['phone'],
					ucfirst( (string) ( $row['role'] ?? '' ) ),
					$row['membership_uuid'],
					$row['plan_name'],
					ucfirst( str_replace( '_', ' ', (string) $row['membership_status'] ) ),
					ucfirst( (string) ( $row['billing_cycle'] ?? '' ) ),
					$row['renewal_date'],
					ucfirst( str_replace( '_', ' ', (string) ( $row['waiver_status'] ?? '' ) ) ),
					$row['waiver_signed_at'],
					$row['waiver_expires_at'],
					$row['person_created_at'],
				)
			);
		}
		fclose( $out );
		exit;
	}
}
