<?php
/**
 * License settings screen + admin notices. Every incoming value is
 * sanitized, every output escaped, every action nonce-protected and
 * capability-gated.
 *
 * @package WPistic\Sdk
 */

namespace WPistic\Sdk\Admin;

use WPistic\Sdk\WpisticClient;

/**
 * Renders the license settings screen and handles its form submissions.
 */
class SettingsPage {

	/**
	 * The SDK client this settings page manages.
	 *
	 * @var WpisticClient
	 */
	private $client;

	/**
	 * Human-readable labels for each license status value.
	 *
	 * @var array<string,string>
	 */
	private const STATUS_LABELS = array(
		'active'               => 'Active',
		'suspended'            => 'Suspended',
		'expired'              => 'Expired',
		'revoked'              => 'Revoked',
		'cancelled'            => 'Cancelled',
		'grace_period'         => 'Grace period',
		'activation_suspended' => 'Activation suspended',
		'not_connected'        => 'Not connected',
	);

	/**
	 * Constructor.
	 *
	 * @param WpisticClient $client The SDK client this settings page manages.
	 */
	public function __construct( WpisticClient $client ) {
		$this->client = $client;
	}

	/**
	 * Wire up WordPress hooks for the settings page and its form handlers.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_' . $this->action_name( 'activate' ), array( $this, 'handle_activate' ) );
		add_action( 'admin_post_' . $this->action_name( 'deactivate' ), array( $this, 'handle_deactivate' ) );
		add_action( 'admin_post_' . $this->action_name( 'set_channel' ), array( $this, 'handle_set_channel' ) );
		add_action( 'admin_notices', array( $this, 'grace_notice' ) );
	}

	/**
	 * The consuming product's slug.
	 *
	 * @return string
	 */
	private function slug(): string {
		return $this->client->product_slug();
	}

	/**
	 * The settings page's slug, used in the admin menu and its URL.
	 *
	 * @return string
	 */
	public function page_slug(): string {
		return 'wpistic-' . $this->slug() . '-license';
	}

	/**
	 * The admin-post action name for a given verb (e.g. "activate").
	 *
	 * @param string $verb The action verb.
	 * @return string
	 */
	private function action_name( string $verb ): string {
		return 'wpistic_' . $this->slug() . '_' . $verb;
	}

	/**
	 * The settings page's own admin URL.
	 *
	 * @return string
	 */
	private function page_url(): string {
		return admin_url( 'options-general.php?page=' . $this->page_slug() );
	}

	/**
	 * Register the settings page under Settings in the admin menu.
	 *
	 * @return void
	 */
	public function add_menu(): void {
		add_options_page(
			sprintf( /* translators: %s: product name */ __( '%s License', 'wpistic-sdk' ), ucfirst( $this->slug() ) ),
			sprintf( __( '%s License', 'wpistic-sdk' ), ucfirst( $this->slug() ) ),
			'manage_options',
			$this->page_slug(),
			array( $this, 'render_page' )
		);
	}

	/**
	 * Handle the "activate license" form submission.
	 *
	 * @return void
	 */
	public function handle_activate(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage licenses.', 'wpistic-sdk' ) );
		}
		check_admin_referer( $this->action_name( 'activate' ) );

		$key    = isset( $_POST['wpistic_license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['wpistic_license_key'] ) ) : '';
		$result = $this->client->activate( $key );

		$args = is_wp_error( $result )
			? array( 'wpistic_error' => rawurlencode( $result->get_error_message() ) )
			: array( 'wpistic_activated' => '1' );
		wp_safe_redirect( add_query_arg( $args, $this->page_url() ) );
		exit;
	}

	/**
	 * Handle the "deactivate license" form submission.
	 *
	 * @return void
	 */
	public function handle_deactivate(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage licenses.', 'wpistic-sdk' ) );
		}
		check_admin_referer( $this->action_name( 'deactivate' ) );

		$this->client->deactivate();
		wp_safe_redirect( add_query_arg( 'wpistic_deactivated', '1', $this->page_url() ) );
		exit;
	}

	/**
	 * Handle the "save update channel" form submission.
	 *
	 * @return void
	 */
	public function handle_set_channel(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage licenses.', 'wpistic-sdk' ) );
		}
		check_admin_referer( $this->action_name( 'set_channel' ) );

		$channel = isset( $_POST['wpistic_update_channel'] ) ? sanitize_text_field( wp_unslash( $_POST['wpistic_update_channel'] ) ) : 'stable';
		$this->client->update_client()->set_channel( $channel );

		wp_safe_redirect( add_query_arg( 'wpistic_channel_saved', '1', $this->page_url() ) );
		exit;
	}

	/**
	 * Print an admin notice while premium features are in a grace period.
	 *
	 * @return void
	 */
	public function grace_notice(): void {
		$remaining = $this->client->grace_seconds_remaining();
		if ( null === $remaining ) {
			return;
		}
		$days = max( 1, (int) ceil( $remaining / DAY_IN_SECONDS ) );
		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: 1: product name, 2: number of days */
					__( '%1$s cannot reach the WPistic licensing service. Premium features stay active for %2$d more day(s) — check your connection or license from the settings page.', 'wpistic-sdk' ),
					ucfirst( $this->slug() ),
					$days
				)
			)
		);
	}

	/**
	 * Render the settings page markup.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$status = $this->client->status();
		?>
		<div class="wrap">
			<h1>
				<?php
				// translators: %s is the product name (e.g. "Seoistic").
				echo esc_html( sprintf( __( '%s License', 'wpistic-sdk' ), ucfirst( $this->slug() ) ) );
				?>
			</h1>

			<?php if ( isset( $_GET['wpistic_activated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success"><p><?php esc_html_e( 'License activated. Premium features are enabled.', 'wpistic-sdk' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['wpistic_deactivated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-info"><p><?php esc_html_e( 'License deactivated on this site.', 'wpistic-sdk' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['wpistic_channel_saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success"><p><?php esc_html_e( 'Update channel saved.', 'wpistic-sdk' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['wpistic_error'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-error"><p><?php echo esc_html( rawurldecode( sanitize_text_field( wp_unslash( $_GET['wpistic_error'] ) ) ) ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['wpistic_connect_error'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-error"><p><?php echo esc_html( rawurldecode( sanitize_text_field( wp_unslash( $_GET['wpistic_connect_error'] ) ) ) ); ?></p></div>
			<?php endif; ?>

			<?php if ( $status['connected'] ) : ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'License', 'wpistic-sdk' ); ?></th>
						<td><code><?php echo esc_html( $status['key_mask'] ); ?></code></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Status', 'wpistic-sdk' ); ?></th>
						<td>
							<?php if ( $status['active'] ) : ?>
								<span style="color:#00a32a;font-weight:600;"><?php esc_html_e( 'Active', 'wpistic-sdk' ); ?></span>
							<?php else : ?>
								<span style="color:#d63638;font-weight:600;"><?php echo esc_html( $this->status_label( $status['status'] ) ); ?></span>
							<?php endif; ?>
							<?php if ( '' !== $status['plan'] ) : ?>
								— <?php echo esc_html( ucfirst( $status['plan'] ) ); ?> <?php esc_html_e( 'plan', 'wpistic-sdk' ); ?>
							<?php endif; ?>
						</td>
					</tr>
					<?php if ( '' !== $status['expires_at'] ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Renews', 'wpistic-sdk' ); ?></th>
							<td><?php echo esc_html( gmdate( 'F j, Y', strtotime( $status['expires_at'] ) ) ); ?></td>
						</tr>
					<?php endif; ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Connected website', 'wpistic-sdk' ); ?></th>
						<td>
							<?php echo '' !== $status['domain'] ? esc_html( $status['domain'] ) : esc_html__( 'Not available', 'wpistic-sdk' ); ?>
							<?php if ( '' !== $status['environment'] ) : ?>
								<span class="description"> (<?php echo esc_html( $status['environment'] ); ?>)</span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Organization', 'wpistic-sdk' ); ?></th>
						<td>
							<?php
							// The license validate/activate response does not currently carry an
							// organization name field — see the WPistic SDK Phase 4 report for
							// the follow-up. We never fabricate a value here.
							echo '' !== $status['organization']
								? esc_html( $status['organization'] )
								: '<span class="description">' . esc_html__( 'Not available from the licensing API — see your WPistic dashboard.', 'wpistic-sdk' ) . '</span>';
							?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Activations', 'wpistic-sdk' ); ?></th>
						<td>
							<?php
							if ( null !== $status['max_activations'] && null !== $status['activation_count'] ) {
								printf(
									/* translators: 1: activations used, 2: activation limit */
									esc_html__( '%1$d of %2$d', 'wpistic-sdk' ),
									(int) $status['activation_count'],
									(int) $status['max_activations']
								);
							} else {
								echo '<span class="description">' . esc_html__( 'Not reported by the licensing API — see your WPistic dashboard.', 'wpistic-sdk' ) . '</span>';
							}
							?>
						</td>
					</tr>
				</table>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:1.5em;">
					<input type="hidden" name="action" value="<?php echo esc_attr( $this->action_name( 'set_channel' ) ); ?>" />
					<?php wp_nonce_field( $this->action_name( 'set_channel' ) ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">
								<label for="wpistic_update_channel"><?php esc_html_e( 'Update channel', 'wpistic-sdk' ); ?></label>
							</th>
							<td>
								<select id="wpistic_update_channel" name="wpistic_update_channel">
									<?php foreach ( array( 'stable', 'beta', 'early_access' ) as $channel ) : ?>
										<option value="<?php echo esc_attr( $channel ); ?>" <?php selected( $this->client->update_client()->channel(), $channel ); ?>>
											<?php echo esc_html( ucfirst( str_replace( '_', ' ', $channel ) ) ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<?php submit_button( __( 'Save channel', 'wpistic-sdk' ), 'secondary', 'submit', false ); ?>
							</td>
						</tr>
					</table>
				</form>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( $this->action_name( 'deactivate' ) ); ?>" />
					<?php wp_nonce_field( $this->action_name( 'deactivate' ) ); ?>
					<?php submit_button( __( 'Deactivate on this site', 'wpistic-sdk' ), 'secondary' ); ?>
				</form>
			<?php else : ?>
				<p><?php esc_html_e( 'Connect your WPistic account, or enter your license key directly, to unlock premium features and updates.', 'wpistic-sdk' ); ?></p>
				<p>
					<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . $this->onboarding_connect_action() ), $this->onboarding_connect_action() ) ); ?>">
						<?php esc_html_e( 'Connect to WPistic', 'wpistic-sdk' ); ?>
					</a>
				</p>
			<?php endif; ?>

			<h2 style="margin-top:2em;"><?php esc_html_e( 'Enter a license key manually', 'wpistic-sdk' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Always available as a fallback, even if you connected via your WPistic account above.', 'wpistic-sdk' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( $this->action_name( 'activate' ) ); ?>" />
				<?php wp_nonce_field( $this->action_name( 'activate' ) ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="wpistic_license_key"><?php esc_html_e( 'License key', 'wpistic-sdk' ); ?></label>
						</th>
						<td>
							<input type="password" class="regular-text" id="wpistic_license_key" name="wpistic_license_key"
								placeholder="<?php echo esc_attr( $this->slug() . '_…' ); ?>" autocomplete="off" required />
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Activate license', 'wpistic-sdk' ) ); ?>
			</form>

			<p>
				<a href="https://docs.wpistic.com/<?php echo esc_attr( rawurlencode( $this->slug() ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Documentation', 'wpistic-sdk' ); ?></a>
				&nbsp;|&nbsp;
				<a href="https://wpistic.com/support" target="_blank" rel="noopener"><?php esc_html_e( 'Support', 'wpistic-sdk' ); ?></a>
				&nbsp;|&nbsp;
				<a href="https://app.wpistic.com/licenses" target="_blank" rel="noopener"><?php esc_html_e( 'Manage licenses in your WPistic dashboard', 'wpistic-sdk' ); ?></a>
				&nbsp;|&nbsp;
				<a href="https://wpistic.com/pricing" target="_blank" rel="noopener"><?php esc_html_e( 'Upgrade plan', 'wpistic-sdk' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * A human-readable label for a raw license status value.
	 *
	 * @param string $status The raw status value.
	 * @return string
	 */
	private function status_label( string $status ): string {
		return isset( self::STATUS_LABELS[ $status ] ) ? self::STATUS_LABELS[ $status ] : ucfirst( str_replace( '_', ' ', $status ) );
	}

	/**
	 * The admin-post action name for starting the onboarding connect flow.
	 *
	 * @return string
	 */
	private function onboarding_connect_action(): string {
		return 'wpistic_' . $this->slug() . '_connect';
	}
}
