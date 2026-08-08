<?php
/**
 * Member documents — private attachment store for waivers, signed images,
 * IDs and other legal documents.
 *
 * Files are stored in a dedicated uploads/memberistic-private/ directory,
 * hardened with .htaccess/web.config AND given 40-char random on-disk names
 * that are never exposed (so they stay private even on servers that ignore
 * .htaccess, e.g. Nginx). They are only ever served through a
 * permission-gated download endpoint (?memberistic_doc=ID) that verifies a
 * nonce and that the requester owns the document or is staff, and forces a
 * download (never inline). Uploads are validated against a strict type
 * allowlist and a size cap.
 *
 * Integrates with the waiver system: a document can be linked to a waiver
 * signature (signature_id), to a person, to a membership, and/or to a WP user.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic\Waivers;

use WordPressistic\Memberistic\Database\Memberships_Repository;
use WordPressistic\Memberistic\Database\People_Repository;
use function WordPressistic\Memberistic\memberistic_current_user_can;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Documents {

	const QUERY = 'memberistic_doc';
	const SUBDIR = 'memberistic-private';

	/** Capability that lets staff view any member's documents. */
	const STAFF_CAP = 'edit_memberistic_members';

	public static function register() {
		add_action( 'init', array( __CLASS__, 'maybe_handle_download' ) );
		add_action( 'init', array( __CLASS__, 'maybe_handle_member_upload' ) );
		add_action( 'memberistic_account_dashboard_end', array( __CLASS__, 'render_account_section' ) );
	}

	/**
	 * Member-facing "My Documents" panel, injected at the end of the account
	 * dashboard. Lets a member upload and download their own files.
	 *
	 * @param \WP_User|int|null $user The current account user (passed by the hook).
	 */
	public static function render_account_section( $user = null ) {
		$user_id = $user instanceof \WP_User ? (int) $user->ID : (int) get_current_user_id();
		if ( $user_id <= 0 ) {
			return;
		}
		$docs = self::get_for_user( $user_id );
		$msg  = isset( $_GET['memberistic_doc_msg'] ) ? sanitize_key( wp_unslash( $_GET['memberistic_doc_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="memberistic-acct-block" style="margin-top:18px;">
			<h3><?php esc_html_e( 'My Documents', 'memberistic' ); ?></h3>
			<p class="memberistic-acct-muted"><?php esc_html_e( 'Upload a signed waiver, ID, or other required document (PDF or image, up to 10 MB). Only you and range staff can view these.', 'memberistic' ); ?></p>
			<?php if ( 'ok' === $msg ) : ?>
				<p style="color:#4ade80;"><?php esc_html_e( 'Document uploaded.', 'memberistic' ); ?></p>
			<?php elseif ( 'error' === $msg || 'nofile' === $msg ) : ?>
				<p style="color:#f0a565;"><?php esc_html_e( 'Upload failed. Please choose a PDF or image under 10 MB.', 'memberistic' ); ?></p>
			<?php endif; ?>
			<form method="post" enctype="multipart/form-data" style="margin:12px 0;display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
				<?php wp_nonce_field( 'memberistic_doc_upload', 'memberistic_doc_upload_nonce' ); ?>
				<input type="hidden" name="memberistic_doc_upload" value="1">
				<input type="text" name="doc_label" placeholder="<?php esc_attr_e( 'Label (optional)', 'memberistic' ); ?>" style="padding:8px 10px;border-radius:4px;border:1px solid var(--ma-line,#2A323D);background:transparent;color:inherit;">
				<input type="file" name="memberistic_doc_file" accept=".pdf,.jpg,.jpeg,.png,.webp" required>
				<button type="submit" class="memberistic-acct-cta memberistic-acct-cta--ghost"><?php esc_html_e( 'Upload', 'memberistic' ); ?></button>
			</form>
			<?php if ( $docs ) : ?>
				<ul style="list-style:none;margin:0;padding:0;">
					<?php foreach ( $docs as $d ) : ?>
						<li style="padding:8px 0;border-top:1px solid var(--ma-line,#2A323D);display:flex;justify-content:space-between;gap:12px;">
							<span><?php echo esc_html( $d['label'] ?: $d['file_name'] ); ?> <small class="memberistic-acct-muted">· <?php echo esc_html( date_i18n( 'M j, Y', strtotime( (string) $d['created_at'] ) ) ); ?></small></span>
							<a href="<?php echo esc_url( self::download_url( (int) $d['id'] ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View', 'memberistic' ); ?></a>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p class="memberistic-acct-muted"><?php esc_html_e( 'No documents uploaded yet.', 'memberistic' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'memberistic_documents';
	}

	/**
	 * Allowed upload types: extension => mime. Filterable.
	 */
	public static function allowed_types() {
		return (array) apply_filters(
			'memberistic_document_allowed_types',
			array(
				'pdf'  => 'application/pdf',
				'jpg'  => 'image/jpeg',
				'jpeg' => 'image/jpeg',
				'png'  => 'image/png',
				'webp' => 'image/webp',
			)
		);
	}

	/** Max upload size in bytes (default 10 MB), filterable. */
	public static function max_bytes() {
		return (int) apply_filters( 'memberistic_document_max_bytes', 10 * 1024 * 1024 );
	}

	/**
	 * Absolute path to the private storage dir, created + hardened on first use.
	 *
	 * @return string|\WP_Error
	 */
	public static function private_dir() {
		$up = wp_upload_dir();
		if ( ! empty( $up['error'] ) ) {
			return new \WP_Error( 'memberistic_upload_dir', (string) $up['error'] );
		}
		$dir = trailingslashit( $up['basedir'] ) . self::SUBDIR;
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		// Harden: deny direct web access (Apache 2.4 + 2.2 + IIS) and stop
		// directory listing. On servers that ignore these (Nginx/Caddy), the
		// 40-char random filenames are the real protection — files are never
		// linked by their on-disk name and are served only via the gated
		// download endpoint.
		$ht = trailingslashit( $dir ) . '.htaccess';
		if ( ! file_exists( $ht ) ) {
			@file_put_contents( $ht, "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\nOptions -Indexes\n" ); // phpcs:ignore
		}
		$idx = trailingslashit( $dir ) . 'index.html';
		if ( ! file_exists( $idx ) ) {
			@file_put_contents( $idx, '' ); // phpcs:ignore
		}
		$wc = trailingslashit( $dir ) . 'web.config';
		if ( ! file_exists( $wc ) ) {
			@file_put_contents( $wc, "<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n" ); // phpcs:ignore
		}
		return $dir;
	}

	/**
	 * Validate + store an uploaded file, recording a documents row.
	 *
	 * @param array $file    A single $_FILES entry.
	 * @param array $context user_id, person_id, membership_id, signature_id,
	 *                       label, doc_type, uploaded_by.
	 * @return int|\WP_Error Document id on success.
	 */
	public static function store_upload( $file, $context = array() ) {
		if ( empty( $file ) || ! is_array( $file ) ) {
			return new \WP_Error( 'memberistic_no_file', __( 'No file received.', 'memberistic' ) );
		}
		if ( ! empty( $file['error'] ) && UPLOAD_ERR_OK !== (int) $file['error'] ) {
			return new \WP_Error( 'memberistic_upload_error', __( 'The file could not be uploaded.', 'memberistic' ) );
		}
		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new \WP_Error( 'memberistic_not_uploaded', __( 'Invalid upload.', 'memberistic' ) );
		}
		if ( (int) $file['size'] <= 0 || (int) $file['size'] > self::max_bytes() ) {
			return new \WP_Error( 'memberistic_too_big', sprintf( /* translators: %d MB */ __( 'File is too large (max %d MB).', 'memberistic' ), (int) round( self::max_bytes() / 1048576 ) ) );
		}

		$allowed = self::allowed_types();
		// Validate the real type from file contents, not the client-sent name.
		$check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], $allowed );
		$ext   = $check['ext'] ? strtolower( $check['ext'] ) : '';
		$mime  = $check['type'] ?: '';
		if ( ! $ext || ! isset( $allowed[ $ext ] ) || $mime !== $allowed[ $ext ] ) {
			return new \WP_Error( 'memberistic_bad_type', __( 'That file type is not allowed. Upload a PDF or image (JPG, PNG, WEBP, HEIC).', 'memberistic' ) );
		}

		$dir = self::private_dir();
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		// Fully random, unguessable on-disk name (no member name, no original
		// filename) so the file is effectively private by obscurity even on
		// servers that ignore the .htaccess/web.config (Nginx etc.). The real
		// filename is preserved in the DB row for display/download only.
		$filename = wp_generate_password( 40, false, false ) . '.' . $ext;
		$dest     = trailingslashit( $dir ) . $filename;

		if ( ! @move_uploaded_file( $file['tmp_name'], $dest ) ) { // phpcs:ignore
			return new \WP_Error( 'memberistic_move_failed', __( 'Could not save the file. Please try again.', 'memberistic' ) );
		}
		@chmod( $dest, 0640 ); // phpcs:ignore

		return self::insert_row( $dest, sanitize_file_name( (string) $file['name'] ), $mime, (int) $file['size'], $context );
	}

	/**
	 * Store a server-GENERATED file (e.g. the signed-waiver PDF or the drawn
	 * signature image) into the same hardened private store, recording a
	 * documents row. Bypasses the $_FILES-only checks in store_upload() but
	 * keeps the type allowlist, size cap, and random on-disk naming.
	 *
	 * @param string $contents  Raw file bytes.
	 * @param string $file_name Display filename (extension picks the type).
	 * @param array  $context   Same context keys as store_upload().
	 * @return int|\WP_Error Document id on success.
	 */
	public static function store_generated( $contents, $file_name, $context = array() ) {
		$contents = (string) $contents;
		$size     = strlen( $contents );
		if ( $size <= 0 || $size > self::max_bytes() ) {
			return new \WP_Error( 'memberistic_bad_size', __( 'Generated file is empty or too large.', 'memberistic' ) );
		}
		$allowed = self::allowed_types();
		$ext     = strtolower( (string) pathinfo( (string) $file_name, PATHINFO_EXTENSION ) );
		if ( ! $ext || ! isset( $allowed[ $ext ] ) ) {
			return new \WP_Error( 'memberistic_bad_type', __( 'Generated file type is not allowed.', 'memberistic' ) );
		}

		$dir = self::private_dir();
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}
		$dest = trailingslashit( $dir ) . wp_generate_password( 40, false, false ) . '.' . $ext;
		if ( false === @file_put_contents( $dest, $contents ) ) { // phpcs:ignore
			return new \WP_Error( 'memberistic_write_failed', __( 'Could not save the generated file.', 'memberistic' ) );
		}
		@chmod( $dest, 0640 ); // phpcs:ignore

		return self::insert_row( $dest, sanitize_file_name( (string) $file_name ), $allowed[ $ext ], $size, $context );
	}

	/** Shared documents-row insert for uploaded + generated files. */
	private static function insert_row( $dest, $file_name, $mime, $size, $context ) {
		global $wpdb;
		$up        = wp_upload_dir();
		$rel_path  = ltrim( str_replace( trailingslashit( $up['basedir'] ), '', $dest ), '/' );
		$wpdb->insert(
			self::table(),
			array(
				'user_id'       => ! empty( $context['user_id'] ) ? (int) $context['user_id'] : null,
				'person_id'     => ! empty( $context['person_id'] ) ? (int) $context['person_id'] : null,
				'membership_id' => ! empty( $context['membership_id'] ) ? (int) $context['membership_id'] : null,
				'signature_id'  => ! empty( $context['signature_id'] ) ? (int) $context['signature_id'] : null,
				'file_path'     => $rel_path,
				'file_name'     => $file_name,
				'mime'          => $mime,
				'file_size'     => (int) $size,
				'label'         => isset( $context['label'] ) ? sanitize_text_field( (string) $context['label'] ) : '',
				'doc_type'      => isset( $context['doc_type'] ) ? sanitize_key( (string) $context['doc_type'] ) : 'document',
				'uploaded_by'   => ! empty( $context['uploaded_by'] ) ? (int) $context['uploaded_by'] : get_current_user_id(),
				'created_at'    => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	public static function get( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id ), ARRAY_A ) ?: null;
	}

	public static function get_for_user( $user_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE user_id = %d ORDER BY created_at DESC', (int) $user_id ), ARRAY_A ) ?: array();
	}

	public static function get_for_membership( $membership_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE membership_id = %d ORDER BY created_at DESC', (int) $membership_id ), ARRAY_A ) ?: array();
	}

	public static function get_for_person( $person_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE person_id = %d ORDER BY created_at DESC', (int) $person_id ), ARRAY_A ) ?: array();
	}

	public static function download_url( $id ) {
		return wp_nonce_url( add_query_arg( self::QUERY, (int) $id, home_url( '/' ) ), self::QUERY . '_' . (int) $id, 'memberistic_doc_nonce' );
	}

	/**
	 * Whether the current request may access the given document row.
	 */
	private static function can_access( $doc ) {
		if ( memberistic_current_user_can( self::STAFF_CAP ) ) {
			return true;
		}
		$uid = get_current_user_id();
		return $uid && (int) $doc['user_id'] === (int) $uid;
	}

	/**
	 * Gated download endpoint. Streams the private file after verifying the
	 * nonce and that the requester owns it or is staff.
	 */
	public static function maybe_handle_download() {
		if ( empty( $_GET[ self::QUERY ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$id  = absint( $_GET[ self::QUERY ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$doc = $id ? self::get( $id ) : null;
		if ( ! $doc ) {
			wp_die( esc_html__( 'Document not found.', 'memberistic' ), '', array( 'response' => 404 ) );
		}
		if ( empty( $_GET['memberistic_doc_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['memberistic_doc_nonce'] ) ), self::QUERY . '_' . $id ) ) {
			wp_die( esc_html__( 'This download link could not be verified.', 'memberistic' ), '', array( 'response' => 403 ) );
		}
		if ( ! is_user_logged_in() ) {
			auth_redirect();
			exit;
		}
		if ( ! self::can_access( $doc ) ) {
			wp_die( esc_html__( 'You do not have permission to view this document.', 'memberistic' ), '', array( 'response' => 403 ) );
		}

		$up   = wp_upload_dir();
		$path = trailingslashit( $up['basedir'] ) . ltrim( (string) $doc['file_path'], '/' );
		$path = realpath( $path );
		$base = realpath( trailingslashit( $up['basedir'] ) . self::SUBDIR );
		// Path-traversal guard: the resolved file must live inside the private dir.
		if ( ! $path || ! $base || 0 !== strpos( $path, $base ) || ! is_file( $path ) ) {
			wp_die( esc_html__( 'The document file is missing.', 'memberistic' ), '', array( 'response' => 404 ) );
		}

		nocache_headers();
		header( 'Content-Type: ' . ( $doc['mime'] ?: 'application/octet-stream' ) );
		// Force download rather than inline render so a disguised file (e.g.
		// HTML/JS renamed .pdf) can never execute in the site origin.
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( (string) ( $doc['file_name'] ?: basename( $path ) ) ) . '"' );
		header( 'Content-Length: ' . (string) filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	/**
	 * Handle a member uploading a document from their account page.
	 */
	public static function maybe_handle_member_upload() {
		if ( empty( $_POST['memberistic_doc_upload'] ) ) {
			return;
		}
		if ( ! is_user_logged_in() ) {
			return;
		}
		if ( ! isset( $_POST['memberistic_doc_upload_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['memberistic_doc_upload_nonce'] ) ), 'memberistic_doc_upload' ) ) {
			return;
		}
		$user_id    = get_current_user_id();
		$membership = Memberships_Repository::get_by_user_id( $user_id );
		$person     = $membership ? People_Repository::get_primary_by_membership( (int) $membership['id'] ) : null;
		$label      = isset( $_POST['doc_label'] ) ? sanitize_text_field( wp_unslash( $_POST['doc_label'] ) ) : '';

		$redirect = wp_get_referer() ?: home_url( '/account/' );
		if ( empty( $_FILES['memberistic_doc_file'] ) ) {
			wp_safe_redirect( add_query_arg( 'memberistic_doc_msg', 'nofile', $redirect ) );
			exit;
		}
		$res = self::store_upload(
			$_FILES['memberistic_doc_file'], // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			array(
				'user_id'       => $user_id,
				'person_id'     => $person ? (int) $person['id'] : 0,
				'membership_id' => $membership ? (int) $membership['id'] : 0,
				'label'         => $label,
				'doc_type'      => 'member_upload',
				'uploaded_by'   => $user_id,
			)
		);
		$msg = is_wp_error( $res ) ? 'error' : 'ok';
		wp_safe_redirect( add_query_arg( 'memberistic_doc_msg', $msg, $redirect ) );
		exit;
	}
}
