<?php
/**
 * Booking engine adapter.
 *
 * Memberistic does not ship a booking engine. It integrates with whichever
 * booking plugin a site already runs, and this class is the single place
 * that knows that plugin's names: its hook prefix, its table prefix, and
 * the shortcode that renders its booking form.
 *
 * Why an adapter instead of hard-coded names: those identifiers belong to
 * a third-party plugin, so Memberistic cannot rename them — querying
 * `{prefix}renamed_bookings` would simply find nothing. What it can do is
 * stop scattering them through the codebase. Every foreign identifier the
 * booking integration touches is declared in one preset here, which makes
 * the coupling auditable, lets any other booking plugin be mapped in with
 * a single filter, and keeps the rest of the plugin brand-neutral.
 *
 * Resolution order:
 *   1. The `memberistic_booking_adapter` filter, if it returns a config.
 *   2. A bundled preset whose detection signature matches an active plugin.
 *   3. No adapter — the Booking Engine integration reports itself as
 *      unavailable and every code path stays inert.
 *
 * Mapping a different booking plugin:
 *
 *     add_filter( 'memberistic_booking_adapter', function () {
 *         return array(
 *             'label'        => 'Acme Bookings',
 *             'hook_prefix'  => 'acme',            // acme_booking_created, ...
 *             'table_prefix' => 'acme_',           // {$wpdb->prefix}acme_bookings
 *             'shortcodes'   => array( 'acme_booking_form' ),
 *         );
 *     } );
 *
 * @package Memberistic
 * @since   2.0.0
 */

namespace WordPressistic\Memberistic\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Booking_Adapter {

	/**
	 * Bundled presets, keyed by id.
	 *
	 * `detect` lists constants and classes that indicate the plugin is
	 * active. A preset is only auto-selected when one of them is present,
	 * so an unmapped site never queries a table that does not exist.
	 *
	 * @var array<string,array>
	 */
	private const PRESETS = array(
		// Compatibility preset for the booking plugin Memberistic 1.x was
		// originally built against. Retained so existing installs keep
		// working across the 2.0.0 upgrade without reconfiguration.
		'legacy_lane_booking' => array(
			'label'        => 'Lane Booking Engine (legacy)',
			'hook_prefix'  => 'g2ab',
			'table_prefix' => 'g2ab_',
			'css_prefix'   => 'g2ab',
			'shortcodes'   => array( 'g2a_lane_booking', 'g2a_booking_form' ),
			'detect'       => array(
				'constants' => array( 'G2AB_VERSION' ),
				'classes'   => array( '\\G2AB_Plugin' ),
			),
		),
	);

	/**
	 * Cached resolved config for this request.
	 *
	 * @var array|null|false False when not yet resolved, null when none.
	 */
	private static $resolved = false;

	/**
	 * Resolve the active booking adapter config.
	 *
	 * @return array|null Config array, or null when no booking plugin is mapped.
	 */
	public static function config() {
		if ( false !== self::$resolved ) {
			return self::$resolved;
		}

		$config = null;

		foreach ( self::PRESETS as $preset ) {
			if ( self::detect( $preset['detect'] ) ) {
				$config = $preset;
				break;
			}
		}

		/**
		 * Filters the booking engine adapter configuration.
		 *
		 * Return an array with `hook_prefix`, `table_prefix`, `shortcodes`
		 * and `label` to map a booking plugin, or null to disable the
		 * integration entirely.
		 *
		 * @since 2.0.0
		 *
		 * @param array|null $config Auto-detected config, or null if none matched.
		 */
		$config = apply_filters( 'memberistic_booking_adapter', $config );

		self::$resolved = self::normalize( $config );

		return self::$resolved;
	}

	/**
	 * Is a booking plugin mapped and detectable?
	 *
	 * @return bool
	 */
	public static function is_available() {
		return null !== self::config();
	}

	/**
	 * Human-readable name of the mapped booking plugin.
	 *
	 * @return string
	 */
	public static function label() {
		$config = self::config();
		return null === $config ? '' : $config['label'];
	}

	/**
	 * Build a fully-qualified hook name for the mapped plugin.
	 *
	 * @param string $suffix Hook suffix, e.g. 'booking_created'.
	 * @return string Prefixed hook name, or '' when no adapter is mapped.
	 */
	public static function hook( $suffix ) {
		$config = self::config();
		if ( null === $config || '' === $config['hook_prefix'] ) {
			return '';
		}
		return $config['hook_prefix'] . '_' . ltrim( (string) $suffix, '_' );
	}

	/**
	 * Build a fully-qualified table name for the mapped plugin.
	 *
	 * Returns '' when no adapter is mapped so callers can bail rather than
	 * querying a table that does not exist. The result is composed only
	 * from a hard-coded suffix and an adapter-declared prefix — never from
	 * request input — so it is safe to interpolate into SQL.
	 *
	 * @param string $suffix Table suffix, e.g. 'bookings'.
	 * @return string Full table name, or '' when no adapter is mapped.
	 */
	public static function table( $suffix ) {
		global $wpdb;

		$config = self::config();
		if ( null === $config || '' === $config['table_prefix'] ) {
			return '';
		}

		$suffix = preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $suffix ) );
		if ( '' === $suffix ) {
			return '';
		}

		return $wpdb->prefix . $config['table_prefix'] . $suffix;
	}

	/**
	 * Shortcodes that render the mapped plugin's booking form.
	 *
	 * @return string[]
	 */
	public static function shortcodes() {
		$config = self::config();
		return null === $config ? array() : $config['shortcodes'];
	}

	/**
	 * CSS class prefix used by the mapped plugin's booking form markup.
	 *
	 * Memberistic embeds that form inside its own account modal, which
	 * needs a handful of layout overrides against the form's own classes.
	 * Those overrides are only emitted when a prefix is known, so a site
	 * running a different booking plugin never ships dead CSS for markup
	 * that is not on the page.
	 *
	 * @return string Prefix without a trailing dash, or '' when unknown.
	 */
	public static function css_prefix() {
		$config = self::config();
		return null === $config ? '' : $config['css_prefix'];
	}

	/**
	 * The first mapped booking shortcode that is actually registered.
	 *
	 * @return string Shortcode tag, or '' when none is registered.
	 */
	public static function active_shortcode() {
		foreach ( self::shortcodes() as $tag ) {
			if ( shortcode_exists( $tag ) ) {
				return $tag;
			}
		}
		return '';
	}

	/**
	 * Reset the per-request cache. Test seam.
	 *
	 * @return void
	 */
	public static function reset() {
		self::$resolved = false;
	}

	/**
	 * Does a preset's detection signature match anything active?
	 *
	 * @param array $detect Detection signature.
	 * @return bool
	 */
	private static function detect( array $detect ) {
		foreach ( (array) ( $detect['constants'] ?? array() ) as $constant ) {
			if ( defined( $constant ) ) {
				return true;
			}
		}
		foreach ( (array) ( $detect['classes'] ?? array() ) as $class ) {
			if ( class_exists( $class ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Validate and normalize a config array.
	 *
	 * A config missing both a hook prefix and a table prefix cannot do
	 * anything useful, so it is treated as "no adapter" rather than
	 * half-wiring the integration.
	 *
	 * @param mixed $config Raw config from preset or filter.
	 * @return array|null
	 */
	private static function normalize( $config ) {
		if ( ! is_array( $config ) ) {
			return null;
		}

		$hook_prefix  = preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) ( $config['hook_prefix'] ?? '' ) ) );
		$table_prefix = preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) ( $config['table_prefix'] ?? '' ) ) );

		if ( '' === $hook_prefix && '' === $table_prefix ) {
			return null;
		}

		$shortcodes = array();
		foreach ( (array) ( $config['shortcodes'] ?? array() ) as $tag ) {
			$tag = preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $tag ) );
			if ( '' !== $tag ) {
				$shortcodes[] = $tag;
			}
		}

		$label      = (string) ( $config['label'] ?? '' );
		$css_prefix = preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) ( $config['css_prefix'] ?? '' ) ) );

		return array(
			'label'        => '' !== $label ? $label : __( 'Booking engine', 'memberistic' ),
			'hook_prefix'  => $hook_prefix,
			'table_prefix' => $table_prefix,
			'css_prefix'   => $css_prefix,
			'shortcodes'   => $shortcodes,
		);
	}
}
