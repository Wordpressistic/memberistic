<?php
/**
 * Plans repository.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic\Database;

use function WordPressistic\Memberistic\memberistic_db_formats;
use function WordPressistic\Memberistic\memberistic_sanitize_price;
use function WordPressistic\Memberistic\memberistic_sanitize_text;
use function WordPressistic\Memberistic\memberistic_sanitize_textarea;
use function WordPressistic\Memberistic\memberistic_validate_status;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plans_Repository {
	/**
	 * Get table name.
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'memberistic_plans';
	}

	/**
	 * Get all plans.
	 *
	 * @param array<string, mixed> $args Query args.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_all( $args = array() ) {
		global $wpdb;

		$table      = self::table();
		$where      = array();
		$where_args = array();

		if ( ! empty( $args['status'] ) ) {
			$where[]      = 'status = %s';
			$where_args[] = sanitize_key( (string) $args['status'] );
		}

		$where_sql = $where ? ' WHERE ' . implode( ' AND ', $where ) : '';
		$sql       = "SELECT * FROM {$table}{$where_sql} ORDER BY sort_order ASC, id ASC";

		if ( $where_args ) {
			$sql = $wpdb->prepare( $sql, $where_args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		return $wpdb->get_results( $sql, ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Get a plan by ID.
	 */
	public static function get( $id ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d LIMIT 1', $id ),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Get a plan by slug.
	 */
	public static function get_by_slug( $slug ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE slug = %s LIMIT 1', sanitize_title( $slug ) ),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Create a plan.
	 *
	 * @param array<string, mixed> $data Plan data.
	 */
	public static function create( $data ) {
		global $wpdb;

		$data              = self::sanitize_data( $data );
		$data['created_at'] = current_time( 'mysql' );

		$inserted = $wpdb->insert( self::table(), $data, memberistic_db_formats( $data ) );

		if ( false === $inserted ) {
			return false;
		}

		$plan_id = (int) $wpdb->insert_id;
		do_action( 'memberistic_plan_created', $plan_id );

		return $plan_id;
	}

	/**
	 * Update a plan.
	 *
	 * @param int                  $id   Plan ID.
	 * @param array<string, mixed> $data Plan data.
	 */
	public static function update( $id, $data ) {
		global $wpdb;

		$data               = self::sanitize_data( $data );
		$data['updated_at'] = current_time( 'mysql' );

		$updated = $wpdb->update( self::table(), $data, array( 'id' => $id ), memberistic_db_formats( $data ), array( '%d' ) );

		if ( false !== $updated ) {
			do_action( 'memberistic_plan_updated', $id );
			return true;
		}

		return false;
	}

	/**
	 * Delete a plan if safe.
	 */
	public static function delete( $id ) {
		global $wpdb;

		if ( self::has_active_memberships( $id ) ) {
			return false;
		}

		return false !== $wpdb->delete( self::table(), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Check slug existence.
	 */
	public static function exists_by_slug( $slug ) {
		return null !== self::get_by_slug( $slug );
	}

	/**
	 * Seed membership plans on first install.
	 *
	 * Ships NO default plans. A membership plan is a commercial decision —
	 * its name, price, and what it includes are specific to one business —
	 * so inventing three and writing them to the database would leave every
	 * new site with priced, publicly listed plans nobody chose. The Plans
	 * screen therefore starts empty, with a Getting Started state that
	 * walks the operator through creating the first plan or importing a
	 * starting point from the bundled templates in `templates/plans/`.
	 *
	 * This method exists as the extension point for that import and for
	 * provisioning tools: return a non-empty array from the
	 * `memberistic_default_plans` filter and those plans are created on
	 * first install. Existing slugs are never overwritten, so it stays
	 * safe to re-run.
	 *
	 * @since 2.0.0 No longer seeds built-in plans; filter-driven only.
	 *
	 * @return int Number of plans created.
	 */
	public static function seed_default_plans() {
		/**
		 * Filters the plans created on first install.
		 *
		 * Empty by default. Each entry accepts the same shape as
		 * {@see Plans_Repository::create()} and must carry a unique `slug`.
		 *
		 * @since 1.0.0
		 *
		 * @param array $plans Plans to create. Empty array by default.
		 */
		$plans = apply_filters( 'memberistic_default_plans', array() );

		if ( ! is_array( $plans ) || empty( $plans ) ) {
			return 0;
		}

		$created = 0;

		foreach ( $plans as $plan ) {
			if ( ! is_array( $plan ) || empty( $plan['slug'] ) || self::exists_by_slug( (string) $plan['slug'] ) ) {
				continue;
			}

			if ( self::create( $plan ) ) {
				++$created;
			}
		}

		return $created;
	}

	/**
	 * Check active memberships for a plan.
	 */
	public static function has_active_memberships( $plan_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'memberistic_memberships';
		$count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE plan_id = %d AND status IN ('active','past_due','paused','comped','trial')", $plan_id )
		);

		return $count > 0;
	}

	/**
	 * Sanitize plan data.
	 *
	 * @param array<string, mixed> $data Raw data.
	 * @return array<string, mixed>
	 */
	private static function sanitize_data( $data ) {
		$clean = array();

		if ( array_key_exists( 'name', $data ) ) {
			$clean['name'] = memberistic_sanitize_text( $data['name'] );
		}
		if ( array_key_exists( 'slug', $data ) ) {
			$clean['slug'] = sanitize_title( wp_unslash( (string) $data['slug'] ) );
		}
		if ( array_key_exists( 'description', $data ) ) {
			$clean['description'] = memberistic_sanitize_textarea( $data['description'] );
		}
		if ( array_key_exists( 'monthly_price', $data ) ) {
			$clean['monthly_price'] = memberistic_sanitize_price( $data['monthly_price'] );
		}
		if ( array_key_exists( 'annual_price', $data ) ) {
			$clean['annual_price'] = memberistic_sanitize_price( $data['annual_price'] );
		}
		if ( array_key_exists( 'included_people', $data ) ) {
			$clean['included_people'] = max( 1, absint( $data['included_people'] ) );
		}
		if ( array_key_exists( 'benefits', $data ) ) {
			$clean['benefits'] = wp_json_encode( json_decode( (string) $data['benefits'], true ) ?: array() );
		}
		if ( array_key_exists( 'settings', $data ) ) {
			$clean['settings'] = wp_json_encode( json_decode( (string) $data['settings'], true ) ?: array() );
		}
		if ( array_key_exists( 'is_featured', $data ) ) {
			$clean['is_featured'] = absint( $data['is_featured'] ) ? 1 : 0;
		}
		if ( array_key_exists( 'sort_order', $data ) ) {
			$clean['sort_order'] = absint( $data['sort_order'] );
		}
		if ( array_key_exists( 'status', $data ) ) {
			$clean['status'] = memberistic_validate_status( $data['status'], 'active' );
		}

		return $clean;
	}
}
