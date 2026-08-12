<?php
/**
 * Directory index stub.
 *
 * Exists so a misconfigured server cannot serve a listing of this directory.
 * WordPress core's own stubs are the bare `// Silence is golden.` one-liner,
 * and this was too until Plugin Check began reporting it as an error:
 *
 *   PHP file should prevent direct access. Add a check like:
 *   if ( ! defined( 'ABSPATH' ) ) exit;
 *
 * The check does not special-case index stubs, and it is right not to bother:
 * the guard costs three lines and removes the need to reason about whether a
 * given file is harmless when reached directly.
 *
 * @package Memberistic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
