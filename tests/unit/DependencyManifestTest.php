<?php
/**
 * Guards the manual require list in `Plugin::load_dependencies()`.
 *
 * The plugin has no autoloader: every class file is listed by hand in
 * `load_dependencies()`, and a file left off that list is a fatal error the
 * moment something reaches for the class. That failure is invisible to the
 * checks we already run — `php -l` proves each file parses, and it does parse,
 * perfectly, on its own; the unit suite never boots the plugin, so it never
 * walks the list at all.
 *
 * That is not hypothetical. 2.0.0 shipped with
 * `includes/integrations/class-booking-adapter.php` absent from the list.
 * `Waiver_Booking_Bridge::register()` calls `Booking_Adapter::hook()` as its
 * first statement and runs on `init` priority 4 whenever the Waiver Manager
 * integration is enabled — and its default is `'yes'`. Every stock install
 * fatalled on `init` with `Class "…\Integrations\Booking_Adapter" not found`,
 * and CI was green throughout.
 *
 * So this test does not assert that one file is present. It asserts the
 * property that was violated: the list and the directory agree. A future file
 * added under `includes/` and forgotten fails here, on the pull request that
 * added it.
 *
 * Source inspection rather than execution, deliberately — the same approach
 * `FreshInstallDefaultsTest` and `PmproRemovalTest` take. Requiring the real
 * files would need a live WordPress, and this suite does not have one; the
 * question here is what the manifest *says*, which is exactly what the fatal
 * depended on.
 *
 * @package Memberistic\Tests
 */

use PHPUnit\Framework\TestCase;

final class DependencyManifestTest extends TestCase {

	private static function root(): string {
		return str_replace( '\\', '/', dirname( __DIR__, 2 ) );
	}

	/**
	 * The file paths listed inside `Plugin::load_dependencies()`.
	 *
	 * @return string[] Repository-relative paths, in listed order.
	 */
	private static function manifest(): array {
		$source = file_get_contents( self::root() . '/includes/class-plugin.php' );
		self::assertNotFalse( $source, 'includes/class-plugin.php is unreadable' );

		$ok = preg_match(
			'/function\s+load_dependencies\s*\(\s*\).*?\$files\s*=\s*array\s*\((.*?)\n\t\t\);/s',
			$source,
			$m
		);
		self::assertSame(
			1,
			$ok,
			'Could not find the $files array in Plugin::load_dependencies(). If the '
			. 'loader was restructured, update this test — do not delete it: the '
			. 'omission it catches is a fatal error no other check can see.'
		);

		preg_match_all( "/'([^']+\.php)'/", $m[1], $paths );

		return $paths[1];
	}

	/**
	 * Every `.php` file that actually exists under `includes/`.
	 *
	 * @return string[] Repository-relative paths.
	 */
	private static function files_on_disk(): array {
		$root = self::root();
		$out  = array();

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $root . '/includes', FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			/** @var SplFileInfo $file */
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			$absolute = str_replace( '\\', '/', $file->getPathname() );

			// Strip the repository root, then any leading slash — in that
			// order. Trimming first breaks the anchor and every path stays
			// absolute, which makes the diff against the manifest meaningless.
			$out[] = ltrim( substr( $absolute, strlen( $root ) ), '/' );
		}

		sort( $out );

		return $out;
	}

	public function test_every_file_under_includes_is_in_the_require_list(): void {
		$manifest = self::manifest();
		$on_disk  = self::files_on_disk();

		// class-plugin.php is the one legitimate exception: the bootstrap
		// requires it directly, which is what makes load_dependencies()
		// reachable in the first place.
		$expected = array_values(
			array_filter(
				$on_disk,
				static fn( string $p ): bool => 'includes/class-plugin.php' !== $p
			)
		);

		$missing = array_values( array_diff( $expected, $manifest ) );

		$this->assertSame(
			array(),
			$missing,
			"These files exist under includes/ but are not in Plugin::load_dependencies().\n"
			. "Nothing autoloads, so each one is a fatal error as soon as its class is\n"
			. "reached. Add them to the \$files array, ahead of anything that uses them:\n  "
			. implode( "\n  ", $missing )
		);
	}

	public function test_every_path_in_the_require_list_exists(): void {
		$root    = self::root();
		$absent  = array();

		foreach ( self::manifest() as $path ) {
			if ( ! is_file( $root . '/' . $path ) ) {
				$absent[] = $path;
			}
		}

		$this->assertSame(
			array(),
			$absent,
			"Plugin::load_dependencies() requires files that do not exist. require_once\n"
			. "on a missing file is a fatal error at load time:\n  "
			. implode( "\n  ", $absent )
		);
	}

	public function test_the_require_list_has_no_duplicates(): void {
		$manifest   = self::manifest();
		$duplicated = array_values(
			array_unique(
				array_diff_assoc( $manifest, array_unique( $manifest ) )
			)
		);

		$this->assertSame(
			array(),
			$duplicated,
			"Duplicated entries in Plugin::load_dependencies(). Harmless to run, but it\n"
			. "means the list is being edited without being read:\n  "
			. implode( "\n  ", $duplicated )
		);
	}

	/**
	 * The specific regression, named so a failure is unambiguous.
	 *
	 * The test above would catch this too. This one exists so that if it ever
	 * breaks again, the failure output says which bug came back rather than
	 * leaving the next reader to rediscover why it mattered.
	 */
	public function test_booking_adapter_precedes_its_consumers(): void {
		$manifest = self::manifest();
		$adapter  = array_search( 'includes/integrations/class-booking-adapter.php', $manifest, true );

		$this->assertNotFalse(
			$adapter,
			'class-booking-adapter.php is missing from Plugin::load_dependencies(). This is '
			. 'the 2.0.0 regression: Waiver_Booking_Bridge::register() calls '
			. 'Booking_Adapter::hook() on init priority 4, and the Waiver Manager '
			. 'integration it is gated on defaults to enabled, so every stock install '
			. 'fatals on init.'
		);

		// Consumers that reference the class at file scope or from a hook that
		// can fire before the rest of the list has loaded.
		$consumers = array(
			'includes/integrations/class-booking-engine.php',
			'includes/integrations/class-pos-bridge.php',
			'includes/frontend/class-staff-dashboard.php',
			'includes/waivers/class-waiver-booking-bridge.php',
		);

		foreach ( $consumers as $consumer ) {
			$position = array_search( $consumer, $manifest, true );

			if ( false === $position ) {
				continue; // Covered by the manifest-completeness test above.
			}

			$this->assertLessThan(
				$position,
				$adapter,
				"class-booking-adapter.php must be required before {$consumer}, which uses it."
			);
		}
	}
}
