<?php
/**
 * Guards the PMPro removal in Memberistic.
 *
 * Memberistic never depended on PMPro at runtime, and must not start. The one
 * legitimate exception is the CSV importer, which exists precisely to migrate
 * a legacy PMPro member base INTO Memberistic — that is migration tooling, not
 * a runtime dependency, so it is allow-listed explicitly below.
 *
 * @package Memberistic\Tests
 */

use PHPUnit\Framework\TestCase;

final class PmproRemovalTest extends TestCase {

	/**
	 * Files allowed to mention PMPro, with the reason.
	 *
	 * @var array<string,string>
	 */
	private const MIGRATION_ALLOWLIST = array(
		'includes/admin/class-import-page.php'                => 'Legacy PMPro CSV importer — one-way migration into Memberistic.',
		'includes/database/class-migrations.php'              => 'Comment describing data carried over from a PMPro import.',
		'includes/database/class-memberships-repository.php'  => 'Comment about imported (PMPro) members lacking a Stripe customer id.',
	);

	private static function root(): string {
		// Normalized to forward slashes to match production_files(), which
		// does the same. Without this the allowlist lookup never matches on
		// Windows (dirname() returns backslashes there), so every
		// allow-listed file reports as an offender.
		return str_replace( '\\', '/', dirname( __DIR__, 2 ) );
	}

	/**
	 * @return string[] Production PHP/JS files, excluding tests and tooling.
	 */
	private static function production_files(): array {
		$root     = self::root();
		$skip_dir = array( '/tests/', '/vendor/', '/node_modules/', '/.git/', '/build/' );
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
			if ( ! in_array( strtolower( $file->getExtension() ), array( 'php', 'js' ), true ) ) {
				continue;
			}
			$out[] = $path;
		}

		return $out;
	}

	public function test_only_migration_tooling_mentions_pmpro(): void {
		$offenders = array();

		foreach ( self::production_files() as $path ) {
			$relative = str_replace( self::root() . '/', '', $path );
			if ( isset( self::MIGRATION_ALLOWLIST[ $relative ] ) ) {
				continue;
			}
			$contents = (string) file_get_contents( $path );
			if ( preg_match( '/pmpro|paid-memberships-pro/i', $contents ) ) {
				$offenders[] = $relative;
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			"Only the documented migration tooling may mention PMPro. Offending files:\n" . implode( "\n", $offenders )
		);
	}

	/**
	 * Even the importer must never CALL PMPro — it reads a CSV export, it does
	 * not talk to a live PMPro installation.
	 */
	public function test_no_pmpro_runtime_calls_anywhere(): void {
		$forbidden = array(
			'pmpro_hasMembershipLevel',
			'pmpro_getMembershipLevelForUser',
			'pmpro_getMembershipLevelsForUser',
			'pmpro_getAllLevels',
			'pmpro_changeMembershipLevel',
			'PMPRO_VERSION',
			'pmpro_memberships_users',
			'pmpro_membership_levels',
		);

		foreach ( self::production_files() as $path ) {
			$contents = (string) file_get_contents( $path );
			foreach ( $forbidden as $needle ) {
				$this->assertStringNotContainsString(
					$needle,
					$contents,
					sprintf( '%s calls PMPro symbol %s', str_replace( self::root() . '/', '', $path ), $needle )
				);
			}
		}
	}

	/**
	 * The allowlist must stay honest: if a migration file stops mentioning
	 * PMPro, the entry should be removed rather than silently masking a
	 * future regression.
	 */
	public function test_allowlist_has_no_stale_entries(): void {
		foreach ( self::MIGRATION_ALLOWLIST as $relative => $reason ) {
			$path = self::root() . '/' . $relative;
			$this->assertFileExists( $path, sprintf( 'Allow-listed file %s no longer exists.', $relative ) );
			$this->assertMatchesRegularExpression(
				'/pmpro|paid-memberships-pro/i',
				(string) file_get_contents( $path ),
				sprintf( 'Allow-listed file %s no longer mentions PMPro — drop it from the allowlist.', $relative )
			);
		}
	}
}
