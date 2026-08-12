<?php
/**
 * Guards the direct-access protection on every shipped PHP file.
 *
 * Two separate properties are asserted here, and the second one is the
 * non-obvious one.
 *
 * The first is the property everybody knows: a PHP file that can be requested
 * directly over HTTP has to refuse to run outside WordPress.
 *
 * The second is *where* the guard sits. Plugin Check — the tool the
 * wordpress.org reviewer runs, and the tool the `plugin-check` CI job runs —
 * looks for that guard in the first 50 lines of the file and nowhere else. A
 * long file docblock plus a long `use` block is enough to push a perfectly
 * correct guard past that window, at which point the listing check reports the
 * file as having no direct-access protection at all. That is exactly what
 * happened to class-payment-integrity-gate.php: the guard was present and
 * working on line 56, and the release gate failed anyway.
 *
 * A line-number budget is an odd thing to assert, so it is worth being clear
 * that this is not cargo cult. The guard is real either way; what this test
 * protects is the ability to prove it to the tool that decides whether the
 * plugin can be listed. The margin is deliberately generous — files sit
 * comfortably under the limit today, and the failure message says what to do.
 *
 * Source inspection, not behaviour, for the same reason PmproRemovalTest and
 * FreshInstallDefaultsTest use it: the thing being protected is a property of
 * the files that ship.
 *
 * @package Memberistic\Tests
 */

use PHPUnit\Framework\TestCase;

final class DirectAccessGuardTest extends TestCase {

	/**
	 * How far into a file Plugin Check will look for the guard.
	 *
	 * Not a style preference: it is the window
	 * Direct_File_Access_Check::has_direct_access_protection() reads.
	 */
	private const GUARD_LINE_LIMIT = 50;

	private static function root(): string {
		return str_replace( '\\', '/', dirname( __DIR__, 2 ) );
	}

	/**
	 * Every PHP file that ends up in the distributable.
	 *
	 * Mirrors what bin/build-dist.sh prunes, so this list and the tree the
	 * plugin-check job inspects stay the same set.
	 *
	 * @return string[] Repository-relative paths.
	 */
	private static function shipped_php_files(): array {
		$root     = self::root();
		$skip_dir = array(
			'/tests/',
			'/vendor/',
			'/node_modules/',
			'/.git/',
			'/.github/',
			'/build/',
			'/bin/',
			'/docs/',
		);
		$out      = array();

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			/** @var SplFileInfo $file */
			if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
				continue;
			}

			$path = str_replace( '\\', '/', $file->getPathname() );

			foreach ( $skip_dir as $needle ) {
				if ( false !== strpos( $path, $needle ) ) {
					continue 2;
				}
			}

			$out[] = ltrim( substr( $path, strlen( $root ) ), '/' );
		}

		sort( $out );

		return $out;
	}

	/**
	 * The 1-based line the ABSPATH guard starts on, or 0 when there is none.
	 */
	private static function guard_line( string $relative_path ): int {
		$lines = file( self::root() . '/' . $relative_path, FILE_IGNORE_NEW_LINES );

		if ( false === $lines ) {
			return 0;
		}

		foreach ( $lines as $index => $line ) {
			// Both accepted spellings: the `if ( ! defined( ... ) )` block this
			// plugin uses everywhere, and the `defined( ... ) || exit;` one-liner
			// Plugin Check also recognises.
			if ( preg_match( "/^\s*(?:if\s*\(\s*!\s*)?defined\s*\(\s*'(?:ABSPATH|WPINC)'\s*\)/", $line ) ) {
				return $index + 1;
			}
		}

		return 0;
	}

	public function test_the_file_list_is_not_accidentally_empty(): void {
		// A broken skip list that filtered out everything would make every
		// assertion below pass vacuously.
		$this->assertGreaterThan(
			50,
			count( self::shipped_php_files() ),
			'The shipped-file scan found almost nothing, so the skip list is wrong.'
		);
	}

	public function test_every_shipped_php_file_refuses_direct_access(): void {
		$unguarded = array();

		foreach ( self::shipped_php_files() as $file ) {
			if ( 0 === self::guard_line( $file ) ) {
				$unguarded[] = $file;
			}
		}

		$this->assertSame(
			array(),
			$unguarded,
			"These shipped PHP files have no ABSPATH guard:\n  " . implode( "\n  ", $unguarded )
		);
	}

	public function test_the_guard_is_where_plugin_check_looks_for_it(): void {
		$too_deep = array();

		foreach ( self::shipped_php_files() as $file ) {
			$line = self::guard_line( $file );

			if ( $line > self::GUARD_LINE_LIMIT ) {
				$too_deep[] = $file . ' (line ' . $line . ')';
			}
		}

		$this->assertSame(
			array(),
			$too_deep,
			"Plugin Check reads only the first " . self::GUARD_LINE_LIMIT . " lines when it looks for the\n"
				. "direct-access guard, so it will report these files as unprotected even\n"
				. "though they are guarded. Move the guard up — directly under the\n"
				. "namespace declaration and above the use statements — or shorten the\n"
				. "docblock:\n  " . implode( "\n  ", $too_deep )
		);
	}
}
