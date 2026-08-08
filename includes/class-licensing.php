<?php
/**
 * Licensing and update-channel seam.
 *
 * Memberistic 2.0.0 ships NO licence enforcement and NO update client.
 * Nothing in this file makes a network request, reads a licence key, or
 * contacts any server. It exists so that a licensing add-on (Licenseistic
 * or otherwise) can be dropped in later without patching the plugin core,
 * and so the policy that add-on must follow is written down and testable
 * before anyone implements it.
 *
 * WHY A SEAM RATHER THAN THE FEATURE
 *
 * 2.0.0 is a de-brand, harden, and package release. A licence client is a
 * new subsystem with its own failure modes — it phones home, it caches, it
 * decides whether the plugin works — and bolting one on in the same release
 * that changes defaults and branding makes both harder to verify. The
 * contract lands now; the implementation lands in 2.1.0.
 *
 * THE POLICY A LICENCE CLIENT MUST FOLLOW
 *
 * 1. Fail OPEN on updates, CLOSED on premium features. An expired licence
 *    must never take a site down, break an existing member's access, block
 *    a check-in, or stop a renewal charge. It disables premium modules and
 *    shows a renewal notice. People's memberships are not the leverage.
 * 2. Never block a page load on a remote call. Licence state is read from
 *    a cached transient; refreshes happen on cron or on an explicit admin
 *    action, never inline in a request the visitor is waiting on.
 * 3. No phone-home before consent. Nothing contacts a licence server until
 *    the site owner has entered a key. A fresh activation is silent.
 * 4. Degrade to "licensed" when the server is unreachable. A network
 *    outage at the vendor must not read as "this site is unlicensed".
 *
 * @package Memberistic
 * @since   2.0.0
 */

namespace WordPressistic\Memberistic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Licensing {

	/**
	 * Licence states.
	 *
	 * UNLICENSED is the shipped default and is NOT a failure state — it is
	 * simply "no licensing add-on is installed", which is the normal
	 * condition for a GPL build distributed without a licence server.
	 */
	const STATUS_UNLICENSED = 'unlicensed';
	const STATUS_VALID      = 'valid';
	const STATUS_EXPIRED    = 'expired';
	const STATUS_INVALID    = 'invalid';

	/**
	 * Register the seam.
	 *
	 * @return void
	 */
	public static function register() {
		// Intentionally empty. Registering nothing is the point: with no
		// licensing add-on present, this class adds zero hooks, zero
		// queries, and zero requests. The methods below are the API that
		// an add-on filters into.
	}

	/**
	 * Current licence status.
	 *
	 * Returns STATUS_UNLICENSED unless a licensing add-on answers the
	 * filter. Callers must treat UNLICENSED as fully functional for every
	 * core feature — see the fail-open policy in the class docblock.
	 *
	 * @since 2.0.0
	 *
	 * @return string One of the STATUS_* constants.
	 */
	public static function status() {
		$valid = array(
			self::STATUS_UNLICENSED,
			self::STATUS_VALID,
			self::STATUS_EXPIRED,
			self::STATUS_INVALID,
		);

		/**
		 * Filters the current licence status.
		 *
		 * A licensing add-on answers this from its own cached transient.
		 * It must NOT make a remote request inside this filter — it is
		 * called during page rendering.
		 *
		 * @since 2.0.0
		 *
		 * @param string $status One of the Licensing::STATUS_* constants.
		 */
		$status = (string) apply_filters( 'memberistic_licence_status', self::STATUS_UNLICENSED );

		return in_array( $status, $valid, true ) ? $status : self::STATUS_UNLICENSED;
	}

	/**
	 * Is a premium (licence-gated) feature available?
	 *
	 * Nothing in 2.0.0 calls this — every feature in this release is part
	 * of the base product. It exists so that when a premium module is
	 * added, the gate is already defined and already fails open.
	 *
	 * @since 2.0.0
	 *
	 * @param string $feature Feature slug, e.g. 'multi_location'.
	 * @return bool True when the feature may run.
	 */
	public static function can_use( $feature ) {
		$feature = sanitize_key( (string) $feature );
		$status  = self::status();

		// UNLICENSED means no licensing layer is installed at all, so
		// nothing is gated. Only an add-on that reports EXPIRED or INVALID
		// actually closes a gate.
		$allowed = in_array( $status, array( self::STATUS_UNLICENSED, self::STATUS_VALID ), true );

		/**
		 * Filters whether a licence-gated feature may run.
		 *
		 * @since 2.0.0
		 *
		 * @param bool   $allowed Whether the feature is available.
		 * @param string $feature Feature slug.
		 * @param string $status  Current licence status.
		 */
		return (bool) apply_filters( 'memberistic_licence_can_use', $allowed, $feature, $status );
	}

	/**
	 * Metadata an update client needs to identify this build.
	 *
	 * Provided so an add-on does not have to re-derive the slug, basename,
	 * and version — mismatches there are the usual cause of update clients
	 * that silently never offer an update.
	 *
	 * @since 2.0.0
	 *
	 * @return array{slug:string,basename:string,version:string,php:string,wp:string}
	 */
	public static function build_info() {
		return array(
			'slug'     => 'memberistic-membership-solutions',
			'basename' => defined( 'MEMBERISTIC_BASENAME' ) ? MEMBERISTIC_BASENAME : '',
			'version'  => defined( 'MEMBERISTIC_VERSION' ) ? MEMBERISTIC_VERSION : '',
			'php'      => defined( 'MEMBERISTIC_MIN_PHP' ) ? MEMBERISTIC_MIN_PHP : '',
			'wp'       => defined( 'MEMBERISTIC_MIN_WP' ) ? MEMBERISTIC_MIN_WP : '',
		);
	}
}
