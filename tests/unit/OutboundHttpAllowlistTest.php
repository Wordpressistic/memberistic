<?php
/**
 * Guard: every outbound HTTP call site is allow-listed, and gated off by default.
 *
 * Invariant I5 promises that a fresh activation makes no outbound request and
 * that everything touching an off-site service ships disabled.
 * `FreshInstallTest` proves the *runtime* half — activation and `init` reach
 * the network zero times — but it can only prove it for the code paths those
 * two moments happen to execute. A `wp_remote_post()` added next month behind
 * `admin_init`, a shortcode, a REST callback or a cron handler would leave
 * that suite perfectly green and still break the promise, and it would break
 * it on a customer's site rather than in CI.
 *
 * This is the static half. The set of files allowed to talk to the network is
 * written down here, each mapped to the integration whose toggle gates it. A
 * new call site fails until someone records which default-off integration owns
 * it — the same shape as the PMPro allow-list in PmproRemovalTest, and
 * deliberately a source scan so it runs in milliseconds and needs no
 * WordPress.
 *
 * Matching is done through `token_get_all()` rather than a regex because the
 * cheap version of this test is worse than no test: a regex for
 * `wp_remote_post` also matches the phpdoc sentence describing it, so the
 * allow-list fills up with files that never call anything, and a real call
 * site hides in the noise. The tokenizer only sees code.
 *
 * @package Memberistic
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/includes/integrations/class-integrations-registry.php';

/**
 * @group guard
 */
class OutboundHttpAllowlistTest extends TestCase {

	private function plugin_root(): string {
		return str_replace( '\\', '/', dirname( __DIR__, 2 ) );
	}

	/**
	 * Files permitted to make an outbound request, mapped to the registry key
	 * whose toggle gates them.
	 *
	 * Extend this *with the gating integration*, the way the PMPro allow-list
	 * carries a reason. Do not loosen the matcher, and do not add a file whose
	 * integration defaults to `'yes'` — that is the thing this test exists to
	 * stop.
	 *
	 * @return array<string, string> path relative to plugin root => registry key
	 */
	private function allowed_call_sites(): array {
		return array(
			'includes/integrations/class-corestore-client.php' => 'corestore',
			'includes/payments/class-stripe-service.php'       => 'stripe',
		);
	}

	/**
	 * Functions that put a request on the wire.
	 *
	 * `file_get_contents` is handled separately — it is overwhelmingly used on
	 * local paths, so flagging every call would make the allow-list meaningless.
	 * Only a literal `http(s)://` first argument counts.
	 *
	 * @return array<int, string>
	 */
	private function network_functions(): array {
		return array(
			'wp_remote_get',
			'wp_remote_post',
			'wp_remote_head',
			'wp_remote_request',
			'wp_safe_remote_get',
			'wp_safe_remote_post',
			'wp_safe_remote_head',
			'wp_safe_remote_request',
			'curl_init',
			'curl_exec',
			'fsockopen',
			'pfsockopen',
			'stream_socket_client',
		);
	}

	/**
	 * Every PHP file that ships to a customer.
	 *
	 * `tests/`, `bin/` and `vendor/` are excluded because they are not in the
	 * distributable — see `.distignore`. A test helper that talks to the
	 * network is a different problem from a plugin that does.
	 *
	 * @return array<string, string> relative path => absolute path
	 */
	private function shipped_php_files(): array {
		$root  = $this->plugin_root();
		$found = array();

		foreach ( array( 'includes', 'templates' ) as $dir ) {
			if ( ! is_dir( $root . '/' . $dir ) ) {
				continue;
			}

			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $root . '/' . $dir, FilesystemIterator::SKIP_DOTS )
			);

			foreach ( $iterator as $file ) {
				if ( 'php' !== strtolower( $file->getExtension() ) ) {
					continue;
				}

				// getPathname() uses the host separator; normalise so the keys
				// read the same on Windows and Linux.
				$absolute = str_replace( '\\', '/', $file->getPathname() );

				$found[ ltrim( str_replace( $root, '', $absolute ), '/' ) ] = $absolute;
			}
		}

		foreach ( array( 'memberistic-membership-solutions.php', 'uninstall.php', 'index.php' ) as $file ) {
			if ( is_file( $root . '/' . $file ) ) {
				$found[ $file ] = $root . '/' . $file;
			}
		}

		ksort( $found );

		return $found;
	}

	/**
	 * Find outbound-request calls in one file.
	 *
	 * @param string $path Absolute path.
	 * @return array<int, string> Descriptions, e.g. "wp_remote_request() on line 80".
	 */
	private function call_sites_in( string $path ): array {
		$source = file_get_contents( $path );

		if ( false === $source ) {
			$this->fail( "Could not read {$path}" );
		}

		$tokens  = token_get_all( $source );
		$targets = $this->network_functions();
		$found   = array();

		foreach ( $tokens as $index => $token ) {
			if ( ! is_array( $token ) || T_STRING !== $token[0] ) {
				continue;
			}

			$name = strtolower( $token[1] );

			$is_url_read = 'file_get_contents' === $name;

			if ( ! $is_url_read && ! in_array( $name, $targets, true ) ) {
				continue;
			}

			$previous = $this->significant_token( $tokens, $index, -1 );
			$next     = $this->significant_token( $tokens, $index, 1 );

			// `$obj->wp_remote_get()`, `Foo::wp_remote_get()` and
			// `function wp_remote_get()` are not calls to the global function.
			if ( is_array( $previous )
				&& in_array( $previous[0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NULLSAFE_OBJECT_OPERATOR ), true ) ) {
				continue;
			}

			if ( '(' !== $next ) {
				continue;
			}

			if ( $is_url_read ) {
				// Only a literal remote URL counts; local reads are the norm.
				$argument = $this->significant_token( $tokens, $index, 2 );

				if ( ! is_array( $argument )
					|| T_CONSTANT_ENCAPSED_STRING !== $argument[0]
					|| ! preg_match( '#^[\'"]https?://#i', $argument[1] ) ) {
					continue;
				}
			}

			$found[] = $name . '() on line ' . $token[2];
		}

		return $found;
	}

	/**
	 * Step over whitespace and comments to the nth significant token.
	 *
	 * @param array $tokens    Token list.
	 * @param int   $index     Starting index.
	 * @param int   $offset    How many significant tokens to move, signed.
	 * @return array|string|null
	 */
	private function significant_token( array $tokens, int $index, int $offset ) {
		$direction = $offset < 0 ? -1 : 1;
		$remaining = abs( $offset );
		$cursor    = $index;

		while ( $remaining > 0 ) {
			$cursor += $direction;

			if ( ! isset( $tokens[ $cursor ] ) ) {
				return null;
			}

			if ( is_array( $tokens[ $cursor ] )
				&& in_array( $tokens[ $cursor ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}

			--$remaining;
		}

		return $tokens[ $cursor ];
	}

	public function test_the_scan_actually_reads_the_plugin(): void {
		$files = $this->shipped_php_files();

		$this->assertGreaterThan(
			50,
			count( $files ),
			'The file scan found suspiciously few PHP files — has the layout moved? '
			. 'A scan that finds nothing passes every other test in this class.'
		);
	}

	/**
	 * The forward direction: nothing new starts calling out unnoticed.
	 */
	public function test_no_unlisted_file_makes_an_outbound_request(): void {
		$allowed    = $this->allowed_call_sites();
		$unexpected = array();

		foreach ( $this->shipped_php_files() as $relative => $absolute ) {
			if ( isset( $allowed[ $relative ] ) ) {
				continue;
			}

			$sites = $this->call_sites_in( $absolute );

			if ( array() !== $sites ) {
				$unexpected[] = $relative . ' — ' . implode( ', ', $sites );
			}
		}

		$this->assertSame(
			array(),
			$unexpected,
			"These shipped files make an outbound HTTP request but are not allow-listed:\n  - "
			. implode( "\n  - ", $unexpected )
			. "\n\nInvariant I5: anything reaching an off-site service must be gated by an "
			. "integration that defaults to 'no'. Add the file to allowed_call_sites() with "
			. 'the registry key that gates it, or move the call behind one that exists.'
		);
	}

	/**
	 * The reverse direction: an allow-list entry that no longer calls anything
	 * is a standing permission nobody is watching. Removing the call should
	 * shrink the list, not leave a blanket behind for the next change to
	 * shelter under.
	 */
	public function test_every_allow_listed_file_still_makes_a_request(): void {
		$stale = array();

		foreach ( $this->allowed_call_sites() as $relative => $integration ) {
			$absolute = $this->plugin_root() . '/' . $relative;

			if ( ! is_file( $absolute ) ) {
				$stale[] = $relative . ' (file no longer exists)';
				continue;
			}

			if ( array() === $this->call_sites_in( $absolute ) ) {
				$stale[] = $relative . ' (no outbound call found)';
			}
		}

		$this->assertSame(
			array(),
			$stale,
			"These allow-list entries are stale — remove them:\n  - " . implode( "\n  - ", $stale )
		);
	}

	/**
	 * The substantive assertion. Being on the list is not the point; being
	 * gated by a toggle that ships off is.
	 */
	public function test_every_allow_listed_call_site_is_gated_by_a_default_off_integration(): void {
		$definitions = \WordPressistic\Memberistic\Integrations\Integrations_Registry::definitions();

		$this->assertNotEmpty( $definitions, 'Integrations registry returned nothing.' );

		foreach ( $this->allowed_call_sites() as $relative => $key ) {
			$this->assertArrayHasKey(
				$key,
				$definitions,
				"{$relative} is gated by integration '{$key}', which is not in the registry. "
				. 'A call site gated by a toggle that does not exist is not gated at all.'
			);

			$this->assertSame(
				'no',
				$definitions[ $key ]['default'] ?? 'yes',
				"{$relative} reaches an off-site service, but integration '{$key}' ships enabled. "
				. 'Invariant I5 requires it to default to no.'
			);
		}
	}

	/**
	 * A matcher that cannot see a real call would let every other assertion
	 * here pass vacuously, so prove it fires on known-good input — including
	 * the phpdoc case that a regex would get wrong.
	 */
	public function test_the_matcher_detects_calls_and_ignores_prose(): void {
		$fixture = tempnam( sys_get_temp_dir(), 'memberistic_http_' );

		file_put_contents(
			$fixture,
			'<?php'
			. "\n/** Talks to the API with wp_remote_post() — a docblock, not a call. */"
			. "\n\$a = 'wp_remote_get( \$url )';"
			. "\n\$b = \$client->wp_remote_post( \$url );"
			. "\n\$c = file_get_contents( __DIR__ . '/local.json' );"
			. "\n\$d = wp_remote_request( \$url, \$args );"
			. "\n\$e = file_get_contents( 'https://example.test/x' );"
		);

		$sites = $this->call_sites_in( $fixture );

		unlink( $fixture );

		$this->assertSame(
			array( 'wp_remote_request() on line 6', 'file_get_contents() on line 7' ),
			$sites,
			'The matcher must find real calls, and must not be fooled by docblocks, '
			. 'string literals, method calls or local file reads.'
		);
	}
}
