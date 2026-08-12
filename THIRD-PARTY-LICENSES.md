# Third-party licences

## Runtime: none

Memberistic bundles **no third-party code**. The shipped plugin contains no
vendored PHP libraries, no bundled JavaScript frameworks, no web fonts, and no
images. There are zero runtime dependencies to declare, and there is no
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

None in this repository. The test toolchain — PHPUnit and its transitive
packages, all BSD-3-Clause or MIT, all GPL-compatible — lives with the test
suites in the development repository,
[shubochandrosarker/memberistic](https://github.com/shubochandrosarker/memberistic).
Nothing from it is distributed, so nothing from it needs attribution here.

## Services

The plugin can send data to external services, but only ones you enable and
configure yourself. None is a code dependency and none ships with the plugin.
See the Third-Party Services section of `README.md` and `docs/INTEGRATIONS.md`
for what each transmits and when.

## Plugin licence

Memberistic Membership Solutions is licensed under GPL-2.0-or-later. See
[`LICENSE`](LICENSE) for the full text.
