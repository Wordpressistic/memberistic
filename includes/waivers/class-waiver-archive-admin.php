<?php
/**
 * Admin UI for the waivers archive: import CSV, see stats, look up a waiver by
 * email or name, and stream the mirrored PDF (staff-only, protected).
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic\Waivers;

use function WordPressistic\Memberistic\memberistic_current_user_can;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Waiver_Archive_Admin {

	public static function register() {
		add_action( 'admin_post_memberistic_import_waivers', array( __CLASS__, 'handle_import' ) );
		add_action( 'admin_post_memberistic_waiver_pdf', array( __CLASS__, 'stream_pdf' ) );
	}

	/** Protected PDF view URL for an archive row (nonce'd, staff-only). */
	public static function pdf_view_url( $archive_id ) {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=memberistic_waiver_pdf&id=' . (int) $archive_id ),
			'memberistic_waiver_pdf_' . (int) $archive_id
		);
	}

	/* ------------------------------------------------------------------ */
	/* Page                                                               */
	/* ------------------------------------------------------------------ */

	public static function render() {
		if ( ! memberistic_current_user_can( 'view_memberistic_dashboard' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'memberistic' ) );
		}
		$stats   = Waivers_Archive::stats();
		$can_imp = current_user_can( 'manage_memberistic_settings' );

		// Lookup.
		$lookup = null;
		$q_email = isset( $_GET['wq_email'] ) ? sanitize_email( wp_unslash( $_GET['wq_email'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$q_name  = isset( $_GET['wq_name'] ) ? sanitize_text_field( wp_unslash( $_GET['wq_name'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $q_email || $q_name ) {
			$lookup = Waivers_Archive::find_on_file( $q_email, $q_name );
		}
		?>
		<div class="wrap memberistic-wrap">
			<h1><?php esc_html_e( 'Waivers on File', 'memberistic' ); ?></h1>

			<?php if ( isset( $_GET['imported'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['imported'] ) ) ); // phpcs:ignore ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['import_error'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-error is-dismissible"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['import_error'] ) ) ); // phpcs:ignore ?></p></div>
			<?php endif; ?>

			<p>
				<strong><?php echo esc_html( number_format_i18n( $stats['total'] ) ); ?></strong> <?php esc_html_e( 'waiver records', 'memberistic' ); ?> ·
				<strong><?php echo esc_html( number_format_i18n( $stats['current'] ) ); ?></strong> <?php esc_html_e( 'current', 'memberistic' ); ?> ·
				<strong><?php echo esc_html( number_format_i18n( $stats['with_pdf'] ) ); ?></strong> <?php esc_html_e( 'with mirrored PDF', 'memberistic' ); ?> ·
				<strong><?php echo esc_html( number_format_i18n( $stats['matched'] ) ); ?></strong> <?php esc_html_e( 'matched to members', 'memberistic' ); ?>
			</p>

			<h2><?php esc_html_e( 'Look up a waiver', 'memberistic' ); ?></h2>
			<form method="get" style="margin-bottom:8px;">
				<input type="hidden" name="page" value="memberistic-waivers-archive" />
				<input type="email" name="wq_email" placeholder="<?php esc_attr_e( 'Email', 'memberistic' ); ?>" value="<?php echo esc_attr( $q_email ); ?>" class="regular-text" />
				<input type="text" name="wq_name" placeholder="<?php esc_attr_e( 'or First Last', 'memberistic' ); ?>" value="<?php echo esc_attr( $q_name ); ?>" class="regular-text" />
				<button class="button button-primary"><?php esc_html_e( 'Search', 'memberistic' ); ?></button>
			</form>
			<?php if ( $q_email || $q_name ) : ?>
				<?php if ( $lookup ) : ?>
					<div class="notice notice-success inline" style="padding:12px;">
						<p style="margin:0 0 6px;font-size:14px;">
							✅ <strong><?php echo esc_html( trim( $lookup['first_name'] . ' ' . $lookup['last_name'] ) ); ?></strong>
							— <?php esc_html_e( 'waiver on file', 'memberistic' ); ?>
							<?php if ( ! empty( $lookup['signed_at'] ) ) : ?>· <?php echo esc_html( mysql2date( get_option( 'date_format' ), $lookup['signed_at'] ) ); ?><?php endif; ?>
						</p>
						<p style="margin:0;font-size:13px;color:#555;">
							<?php echo esc_html( $lookup['email'] ); ?> · <?php echo esc_html( $lookup['phone'] ); ?>
							<?php if ( ! empty( $lookup['dob'] ) ) : ?> · DOB <?php echo esc_html( $lookup['dob'] ); ?><?php endif; ?>
						</p>
						<p style="margin:8px 0 0;">
							<?php $pdf = Waivers_Archive::pdf_url( $lookup ); // Always the gated streaming endpoint. ?>
							<?php if ( $pdf ) : ?><a class="button" href="<?php echo esc_url( $pdf ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View signed PDF', 'memberistic' ); ?></a><?php endif; ?>
						</p>
					</div>
				<?php else : ?>
					<div class="notice notice-warning inline" style="padding:12px;"><p style="margin:0;">❌ <?php esc_html_e( 'No waiver on file for that email/name. They will need to sign.', 'memberistic' ); ?></p></div>
				<?php endif; ?>
			<?php endif; ?>

			<?php if ( $can_imp ) : ?>
				<hr style="margin:24px 0;">
				<h2><?php esc_html_e( 'Import from Ottertext CSV', 'memberistic' ); ?></h2>
				<p class="description" style="max-width:680px;">
					<?php esc_html_e( 'Upload the OtterWaiver export. PDFs are mirrored into protected storage so they survive Ottertext cancellation, repeat signers collapse to one current waiver, and members are auto-matched by email. For very large files, the WP-CLI command is more reliable:', 'memberistic' ); ?>
					<code>wp memberistic import-waivers /path/file.csv</code>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
					<input type="hidden" name="action" value="memberistic_import_waivers" />
					<?php wp_nonce_field( 'memberistic_import_waivers', 'memberistic_import_nonce' ); ?>
					<p><input type="file" name="waiver_csv" accept=".csv" required /></p>
					<p>
						<label><input type="checkbox" name="download_pdfs" value="1" checked /> <?php esc_html_e( 'Mirror PDFs locally (recommended)', 'memberistic' ); ?></label><br>
						<label><input type="checkbox" name="match_members" value="1" checked /> <?php esc_html_e( 'Match & stamp existing members', 'memberistic' ); ?></label><br>
						<label><input type="checkbox" name="fresh" value="1" /> <?php esc_html_e( 'Fresh import (clear the archive first)', 'memberistic' ); ?></label>
					</p>
					<p><button class="button button-primary"><?php esc_html_e( 'Import Waivers', 'memberistic' ); ?></button></p>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Handlers                                                           */
	/* ------------------------------------------------------------------ */

	public static function handle_import() {
		if ( ! current_user_can( 'manage_memberistic_settings' ) ) {
			wp_die( esc_html__( 'You are not allowed to import waivers.', 'memberistic' ) );
		}
		check_admin_referer( 'memberistic_import_waivers', 'memberistic_import_nonce' );

		$back = admin_url( 'admin.php?page=memberistic-waivers-archive' );
		if ( empty( $_FILES['waiver_csv']['tmp_name'] ) || ! is_uploaded_file( $_FILES['waiver_csv']['tmp_name'] ) ) {
			wp_safe_redirect( add_query_arg( 'import_error', rawurlencode( __( 'No file uploaded.', 'memberistic' ) ), $back ) );
			exit;
		}
		$res = Waiver_Import::import_file(
			sanitize_text_field( $_FILES['waiver_csv']['tmp_name'] ),
			array(
				'download_pdfs' => ! empty( $_POST['download_pdfs'] ),
				// Web upload: never download PDFs inline (it times out on ~1,900
				// files). They mirror in the background via cron instead.
				'defer_pdfs'    => true,
				'match_members' => ! empty( $_POST['match_members'] ),
				'fresh'         => ! empty( $_POST['fresh'] ),
			)
		);
		if ( is_wp_error( $res ) ) {
			wp_safe_redirect( add_query_arg( 'import_error', rawurlencode( $res->get_error_message() ), $back ) );
			exit;
		}
		$queued = isset( $res['pdfs_queued'] ) ? (int) $res['pdfs_queued'] : 0;
		$msg = sprintf(
			/* translators: import stats */
			__( 'Imported %1$d rows for %2$d people. Members matched: %3$d. %4$d PDFs are mirroring in the background — reload this page in a few minutes to watch the count rise.', 'memberistic' ),
			$res['rows'], $res['people'], $res['members_matched'], $queued
		);
		wp_safe_redirect( add_query_arg( 'imported', rawurlencode( $msg ), $back ) );
		exit;
	}

	/** Stream a mirrored PDF (staff-only, nonce-checked). */
	public static function stream_pdf() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		if ( ! memberistic_current_user_can( 'view_memberistic_dashboard' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'memberistic' ) );
		}
		check_admin_referer( 'memberistic_waiver_pdf_' . $id );

		global $wpdb;
		$table = Waivers_Archive::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT local_path, external_url, attachment_id FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		if ( ! $row ) {
			wp_die( esc_html__( 'Waiver not found.', 'memberistic' ) );
		}
		if ( empty( $row['local_path'] ) ) {
			// No mirror — try a linked attachment (streamed, not its public
			// URL), then fall back to the external source URL.
			if ( ! empty( $row['attachment_id'] ) ) {
				$att = get_attached_file( (int) $row['attachment_id'] );
				$att = $att ? realpath( $att ) : false;
				$ubase = realpath( wp_upload_dir()['basedir'] );
				if ( $att && $ubase && 0 === strpos( $att, $ubase ) && is_readable( $att ) ) {
					$type = wp_check_filetype( $att );
					nocache_headers();
					header( 'Content-Type: ' . ( ! empty( $type['type'] ) ? $type['type'] : 'application/pdf' ) );
					header( 'Content-Disposition: inline; filename="waiver.' . ( ! empty( $type['ext'] ) ? $type['ext'] : 'pdf' ) . '"' );
					header( 'Content-Length: ' . filesize( $att ) );
					readfile( $att ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_readfile
					exit;
				}
			}
			if ( ! empty( $row['external_url'] ) ) {
				wp_redirect( esc_url_raw( $row['external_url'] ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
				exit;
			}
			wp_die( esc_html__( 'No document available.', 'memberistic' ) );
		}
		$file = trailingslashit( wp_upload_dir()['basedir'] ) . ltrim( (string) $row['local_path'], '/\\' );
		$real = realpath( $file );
		$base = realpath( trailingslashit( wp_upload_dir()['basedir'] ) . Waiver_Import::SUBDIR );
		if ( ! $real || ! $base || 0 !== strpos( $real, $base ) || ! is_readable( $real ) ) {
			wp_die( esc_html__( 'Document unavailable.', 'memberistic' ) );
		}
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="waiver.pdf"' );
		header( 'Content-Length: ' . filesize( $real ) );
		readfile( $real ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_readfile
		exit;
	}
}
