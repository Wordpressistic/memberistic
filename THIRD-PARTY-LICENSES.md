# Third-party licences

## Runtime: none

Memberistic bundles **no third-party code**. The shipped plugin contains no
vendored PHP libraries, no bundled JavaScript frameworks, no web fonts, and no
images. `composer.json` declares zero runtime dependencies, and there is no
`vendor/` directory in the distributed package.

Everything the plugin ships is first-party code under GPL-2.0-or-later. That
includes pieces you might expect to be a library:

| Component | Notes |
|---|---|
| QR code generator (`includes/utilities/class-qr.php`) | Written in-house. Byte-mode QR with Reed–Solomon error correction, emitting SVG. It exists specifically so member details are never sent to a third-party QR service to render a member card. |
| Admin interface (`assets/*.js`) | Built on `wp.element` and `wp.apiFetch`, which ship with WordPress. No React bundle, no build step, no minified-only code, no sourcemaps. |
| Styling (`assets/*.css`) | Hand-written. No CSS framework. |

Because there is no build step, every file in the distributed package is the
original source. There is no minified or obfuscated code without corresponding
source, because there is no minified code at all.

## Fonts and icons

None bundled. Typography resolves through the `--memberistic-font-*` custom
properties, which prefer the active theme's font tokens and otherwise fall
back to the system font stack. No font file is loaded from this plugin and no
font is fetched from a third-party host.

## Development dependencies

These are used to test the plugin. They are **not** shipped in the
distributable package — `vendor/` is git-ignored and excluded from the build.
Listed for completeness.

| Package | Version | Licence |
|---|---|---|
| phpunit/phpunit | 10.5.64 | BSD-3-Clause |
| phpunit/php-code-coverage | 10.1.16 | BSD-3-Clause |
| phpunit/php-file-iterator | 4.1.0 | BSD-3-Clause |
| phpunit/php-invoker | 4.0.0 | BSD-3-Clause |
| phpunit/php-text-template | 3.0.1 | BSD-3-Clause |
| phpunit/php-timer | 6.0.0 | BSD-3-Clause |
| myclabs/deep-copy | 1.13.4 | MIT |
| nikic/php-parser | 5.8.0 | BSD-3-Clause |
| phar-io/manifest | 2.0.4 | BSD-3-Clause |
| phar-io/version | 3.2.1 | BSD-3-Clause |
| sebastian/* (16 packages) | various | BSD-3-Clause |
| theseer/tokenizer | — | BSD-3-Clause |

BSD-3-Clause and MIT are both GPL-compatible.

## Services

The plugin can send data to external services, but only ones you enable and
configure yourself. None is a code dependency and none ships with the plugin.
See the Third-Party Services section of `README.md` and `docs/INTEGRATIONS.md`
for what each transmits and when.

## Plugin licence

Memberistic Membership Solutions is licensed under GPL-2.0-or-later. See
[`LICENSE`](LICENSE) for the full text.
