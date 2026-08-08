<?php
/**
 * Guard: every class file shipped under includes/ is in the require list.
 *
 * There is no autoloader. `Plugin::load_dependencies()` is a hand-ordered
 * require_once array, and a class file that is not in it is invisible at
 * runtime — the file ships, `php -l` passes, the unit suite passes, and the
 * class simply does not exist when something calls it.
 *
 * That is not hypothetical. `class-booking-adapter.php` shipped in 2.0.0
 * without a require entry, and `Waiver_Booking_Bridge::register()` calls
 * `Booking_Adapter::hook()` as its first statement on `init` priority 4,
 * gated only by the Waiver Manager toggle — one of the two integrations that
 * default to *on*. The result was a fatal on `init` on a fresh install, on
 * every WordPress and PHP version, which no lint and no unit test could see.
 *
 * This test is deliberately a source scan rather than a behavioural test, in
 * the same spirit as FreshInstallDefaultsTest and PmproRemovalTest: it encodes
 * a promise about the shipped package, and it runs in milliseconds without
 * booting WordPress.
 * Guard: every class file under includes/ is in the manual require list.
 *
 * This plugin has no autoloader. `Plugin::load_dependencies()` is a
 * hand-ordered array of require_once paths, which means a new class file is
 * invisible until someone remembers to add it there — and the failure mode is
 * not a missing feature but a fatal "Class not found" the first time anything
 * touches it.
 *
 * That is not hypothetical. `includes/integrations/class-booking-adapter.php`
 * shipped in the 2.0.0 baseline without ever being added to the list, while
 * Waiver_Booking_Bridge::register() called Booking_Adapter::hook()
 * unconditionally on `init`. Because the Waiver Manager integration defaults to
 * ON, that fatalled on activation for every install, and neither `php -l` nor
 * the unit suite could see it: each file parses perfectly on its own.
 *
 * So this test compares the two lists directly. Like FreshInstallDefaultsTest
 * and PmproRemovalTest it asserts against the source files themselves rather
 * than behaviour, because the whole point is to catch the omission before a
 * real WordPress ever loads the plugin.
 *
 * @package Memberistic
 */

use PHPUnit\Framework\TestCase;

/**
 * @group guard
 */
class DependencyManifestTest extends TestCase {

	private function plugin_root(): string {
final class DependencyManifestTest extends TestCase {

	/**
	 * Files that are deliberately absent from the require list.
	 *
	 * Extend this with the reason, rather than loosening the comparison.
	 */
	private const ALLOWED_ABSENCES = array(
		// The coordinator itself; the bootstrap requires it directly, and it is
		// what defines load_dependencies() in the first place.
		'includes/class-plugin.php',
	);

	private static function root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * Paths that are legitimately absent from the require list.
	 *
	 * Extend this *with the reason*, the way the PMPro allow-list works. Do
	 * not loosen the matcher.
	 *
	 * @return array<string, string> path => reason
	 */
	private function allowed_absences(): array {
		return array(
			// The file that owns the require list cannot require itself; the
			// main plugin bootstrap requires it directly.
			'includes/class-plugin.php' => 'Contains load_dependencies() and is required by the plugin bootstrap.',
		);
	}

	/**
	 * @return array<int, string> Paths relative to the plugin root.
	 */
	private function shipped_php_files(): array {
		$root  = $this->plugin_root();
	 * The paths listed in Plugin::load_dependencies().
	 *
	 * Read out of the source rather than by invoking the method: the method is
	 * private, and calling it would require_once ~70 files that expect a live
	 * WordPress. The array is a flat list of string literals, so matching them
	 * is both sufficient and immune to the load order the real method depends on.
	 *
	 * @return string[]
	 */
	private static function listed_files(): array {
		$source = file_get_contents( self::root() . '/includes/class-plugin.php' );

		self::assertIsString( $source, 'includes/class-plugin.php is unreadable' );

		$start = strpos( $source, 'function load_dependencies' );
		self::assertNotFalse( $start, 'Plugin::load_dependencies() no longer exists — this guard needs updating' );

		$end = strpos( $source, ');', $start );
		self::assertNotFalse( $end, 'Could not find the end of the $files array in load_dependencies()' );

		preg_match_all(
			"#'(includes/[A-Za-z0-9/_-]+\.php)'#",
			substr( $source, $start, $end - $start ),
			$matches
		);

		return $matches[1];
	}

	/**
	 * Every .php file under includes/, relative to the plugin root.
	 *
	 * @return string[]
	 */
	private static function files_on_disk(): array {
		$root  = self::root();
		$found = array();

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $root . '/includes', FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( 'php' !== strtolower( $file->getExtension() ) ) {
				continue;
			}

			$found[] = ltrim( str_replace( $root, '', $file->getPathname() ), '/' );
			$found[] = str_replace( $root . '/', '', $file->getPathname() );
		}

		sort( $found );

		return $found;
	}

	/**
	 * @return array<int, string> Paths as written in load_dependencies().
	 */
	private function required_paths(): array {
		$source = file_get_contents( $this->plugin_root() . '/includes/class-plugin.php' );

		$this->assertNotFalse( $source, 'Could not read includes/class-plugin.php' );

		preg_match_all( "/'(includes\/[^']+\.php)'/", $source, $matches );

		return array_values( array_unique( $matches[1] ) );
	}

	public function test_require_list_is_not_empty(): void {
		$required = $this->required_paths();

		$this->assertGreaterThan(
			50,
			count( $required ),
			'load_dependencies() returned suspiciously few paths — has the list moved or changed shape?'
		);
	}

	public function test_every_shipped_class_file_is_required(): void {
		$required = $this->required_paths();
		$allowed  = $this->allowed_absences();

		$missing = array();

		foreach ( $this->shipped_php_files() as $path ) {
			if ( in_array( $path, $required, true ) || isset( $allowed[ $path ] ) ) {
				continue;
			}

			$missing[] = $path;
		}

		$this->assertSame(
			array(),
			$missing,
			"These files ship under includes/ but are never required by Plugin::load_dependencies(),\n"
			. "so the classes they define do not exist at runtime:\n  - "
			. implode( "\n  - ", $missing )
			. "\n\nAdd each to the require list next to its siblings, or allow-list it with a reason."
		);
	}

	/**
	 * The reverse direction: a require entry pointing at a file that no longer
	 * exists is a fatal on load, which is worse than an unused entry.
	 */
	public function test_every_required_path_exists(): void {
		$root    = $this->plugin_root();
		$missing = array();

		foreach ( $this->required_paths() as $path ) {
			if ( ! file_exists( $root . '/' . $path ) ) {
				$missing[] = $path;
			}
		}

		$this->assertSame(
			array(),
			$missing,
			"load_dependencies() requires files that do not exist:\n  - " . implode( "\n  - ", $missing )
		);
	}

	/**
	 * Booking_Adapter specifically, because its absence was a live fatal and
	 * its three consumers are not gated by the booking toggle.
	 */
	public function test_booking_adapter_is_required(): void {
		$this->assertContains(
			'includes/integrations/class-booking-adapter.php',
			$this->required_paths(),
			'Booking_Adapter must load unconditionally: Waiver_Booking_Bridge, Staff_Dashboard '
			. 'and POS_Bridge all reference it outside the booking integration toggle.'
		);
	public function test_every_php_file_under_includes_is_required(): void {
		$missing = array_diff( self::files_on_disk(), self::listed_files(), self::ALLOWED_ABSENCES );

		$this->assertSame(
			array(),
			array_values( $missing ),
			"These files exist under includes/ but are not in Plugin::load_dependencies().\n" .
			"Nothing autoloads, so any class they declare fatals the moment it is touched.\n" .
			'Add them next to their siblings in the require list, or to ALLOWED_ABSENCES with a reason.'
		);
	}

	public function test_every_required_file_exists_on_disk(): void {
		$stale = array_diff( self::listed_files(), self::files_on_disk() );

		$this->assertSame(
			array(),
			array_values( $stale ),
			"Plugin::load_dependencies() requires files that do not exist.\n" .
			'require_once on a missing path is a fatal error at plugin load.'
		);
	}

	/**
	 * The specific regression that motivated this guard.
	 *
	 * Kept as its own case so a failure names the bug rather than making
	 * someone re-derive it from a diff of two lists.
	 */
	public function test_booking_adapter_is_required_before_its_consumers(): void {
		$listed = self::listed_files();

		$adapter = array_search( 'includes/integrations/class-booking-adapter.php', $listed, true );

		$this->assertNotFalse(
			$adapter,
			'Booking_Adapter is not in the require list. Waiver_Booking_Bridge::register() ' .
			'calls it unconditionally on init, and the Waiver Manager integration is on by ' .
			'default, so this fatals on activation for every install.'
		);

		$engine = array_search( 'includes/integrations/class-booking-engine.php', $listed, true );

		if ( false !== $engine ) {
			$this->assertLessThan(
				$engine,
				$adapter,
				'Booking_Adapter must be required before Booking_Engine, which consults it.'
			);
		}
	}
}
