<?php
/**
 * Guards the shipped defaults and the de-branding of the public release.
 *
 * 2.0.0's core promise is that activating this plugin creates no commercial
 * content and touches nothing outside the site: no plans appear for sale, no
 * plan is quietly entitled to free bookings, and no partner branding ships.
 *
 * Those are exactly the properties that erode silently — someone restores a
 * "helpful" default, or pastes a snippet back in, and a year later every new
 * install ships with priced plans nobody chose. These tests fail if that
 * happens.
 *
 * Two styles here. Entitlement behaviour is tested against the real
 * `Entitlement_Service`. Plan seeding and branding are tested by inspecting
 * the source, because the repository layer is stubbed in this suite (see
 * tests/bootstrap.php) — asserting against a stub would prove nothing about
 * what actually ships. Source guards are the same approach PmproRemovalTest
 * already uses.
 *
 * @package Memberistic\Tests
 */

use PHPUnit\Framework\TestCase;
use WordPressistic\Memberistic\Integrations\Entitlement_Service;

final class FreshInstallDefaultsTest extends TestCase {

	protected function setUp(): void {
		memberistic_tests_reset_state();
	}

	private static function root(): string {
		return str_replace( '\\', '/', dirname( __DIR__, 2 ) );
	}

	/**
	 * @return string[] Shipped PHP/JS/CSS files, excluding tests and tooling.
	 */
	private static function shipped_files(): array {
		$root     = self::root();
		$skip_dir = array( '/tests/', '/vendor/', '/node_modules/', '/.git/', '/languages/', '/build/' );
		$out      = array();

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			/** @var SplFileInfo $file */
			if ( ! $file->isFile() ) {
				continue;
			}

			$path = str_replace( '\\', '/', $file->getPathname() );

			foreach ( $skip_dir as $needle ) {
				if ( false !== strpos( $path, $needle ) ) {
					continue 2;
				}
			}

			if ( ! in_array( strtolower( $file->getExtension() ), array( 'php', 'js', 'css' ), true ) ) {
				continue;
			}

			$out[] = $path;
		}

		return $out;
	}

	/* ── Entitlements: real service, real behaviour ── */

	public function test_no_plan_includes_bookings_by_default(): void {
		$this->assertSame(
			array(),
			Entitlement_Service::included_plan_slugs(),
			'A fresh install must not entitle any plan to bookings at no charge.'
		);
	}

	public function test_an_empty_allowlist_is_respected_rather_than_replaced(): void {
		// An empty allowlist is a real answer, not a missing one. If a
		// fallback ever creeps back in, a site that deliberately included
		// nothing would start giving paid inventory away.
		update_option( 'memberistic_lane_included_plan_slugs', array() );

		$this->assertSame( array(), Entitlement_Service::included_plan_slugs() );
	}

	public function test_guest_pass_can_never_be_included(): void {
		update_option( 'memberistic_lane_included_plan_slugs', array( 'guest-pass', 'individual' ) );

		$slugs = Entitlement_Service::included_plan_slugs();

		$this->assertNotContains( 'guest-pass', $slugs );
		$this->assertContains( 'individual', $slugs );
	}

	public function test_a_filter_cannot_smuggle_guest_pass_back_in(): void {
		update_option( 'memberistic_lane_included_plan_slugs', array( 'individual' ) );

		add_filter(
			'memberistic_lane_included_plan_slugs',
			static function ( $slugs ) {
				$slugs[] = 'guest-pass';
				return $slugs;
			}
		);

		$this->assertNotContains( 'guest-pass', Entitlement_Service::included_plan_slugs() );
	}

	/* ── Source guards ── */

	public function test_the_plan_seeder_ships_no_built_in_plans(): void {
		$source = (string) file_get_contents( self::root() . '/includes/database/class-plans-repository.php' );

		$start = strpos( $source, 'function seed_default_plans' );
		$this->assertNotFalse( $start, 'seed_default_plans() should still exist as the extension point.' );

		$body = substr( $source, $start );

		$this->assertStringContainsString(
			"apply_filters( 'memberistic_default_plans', array() )",
			$body,
			'seed_default_plans() must default to an empty array, not a built-in catalogue.'
		);

		$this->assertDoesNotMatchRegularExpression(
			'/[\'"]monthly_price[\'"]\s*=>/',
			$body,
			'seed_default_plans() must not contain hard-coded plan pricing.'
		);
	}

	public function test_no_partner_branding_ships(): void {
		$offenders = array();

		foreach ( self::shipped_files() as $path ) {
			$contents = (string) file_get_contents( $path );

			if ( preg_match( '/guns\s?2\s?ammo|guns2ammo|joint venture|launch partner|battle-tested/i', $contents ) ) {
				$offenders[] = str_replace( self::root() . '/', '', $path );
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			"No shipped file may carry partner branding. Offending files:\n" . implode( "\n", $offenders )
		);
	}

	public function test_legacy_plan_names_do_not_appear_as_plan_slugs(): void {
		$offenders = array();

		foreach ( self::shipped_files() as $path ) {
			$contents = (string) file_get_contents( $path );

			// Deliberately narrow: matches the legacy names only where they
			// are used as a plan slug or name. "parent/guardian" in the
			// waiver copy is correct English and must not be flagged.
			if ( preg_match( '/[\'"](defender|patriot|guardian)[\'"]/i', $contents ) ) {
				$offenders[] = str_replace( self::root() . '/', '', $path );
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			"Legacy default plan names must not ship as slugs or plan names. Offending files:\n" . implode( "\n", $offenders )
		);
	}

	public function test_no_asset_points_at_an_external_partner_domain(): void {
		$offenders = array();

		foreach ( self::shipped_files() as $path ) {
			$contents = (string) file_get_contents( $path );

			if ( preg_match( '#https?://[^\s"\')]*guns2ammo#i', $contents ) ) {
				$offenders[] = str_replace( self::root() . '/', '', $path );
			}
		}

		$this->assertSame( array(), $offenders, 'No shipped asset may resolve to a partner domain.' );
	}
}
