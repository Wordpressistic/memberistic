# CLAUDE.md

Guidance for AI assistants working in this repository.

## What this is

**Memberistic Membership Solutions** — a WordPress plugin (not a framework app,
not an SPA) that runs the operational side of a membership business: plans,
members, linked family accounts, digital waivers, check-ins, payments, staff
workflows, and corporate/group memberships.

- Version `2.0.0`, GPL-2.0-or-later, text domain `memberistic`
- Requires **PHP 8.2+** and **WordPress 6.8+** (both floors enforced at load)
- PHP namespace root: `WordPressistic\Memberistic`
- **No Composer runtime dependencies. No build step. No bundler.**
  Composer exists only for PHPUnit; `vendor/` is gitignored and never shipped.
- Everything lives in its own database tables (`{prefix}memberistic_*`) — a
  membership is *not* a post, a member is *not* post meta.

## Commands

```bash
composer install                    # dev-only: installs phpunit/phpunit ^10.5
vendor/bin/phpunit -c phpunit.xml   # run the unit suite (47 tests currently green)

# Lint exactly what CI lints
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l
find assets -name '*.js' -print0 | xargs -0 -n1 node --check
```

`composer test` is defined in `composer.json` but **aborts when running as
root** (Composer refuses to load plugins as super-user). In this container call
`vendor/bin/phpunit` directly.

CI (`.github/workflows/ci.yml`) runs four jobs on every push and PR:

| Job | What | Blocking |
|---|---|---|
| `php-lint` | `php -l` on every PHP file, matrix 8.2 / 8.3 / 8.4 | Yes |
| `phpunit` | The unit suite (Composer, with a phar fallback) | Yes |
| `js-lint` | `node --check` on every `assets/*.js` | Yes |
| `phpcs` | PSR-12 on `class-entitlement-service.php` only | **No** — advisory, always exits 0 |

There is no linter enforcing the plugin's own style, so match the surrounding
code by hand.

## Layout

```
memberistic-membership-solutions.php   Bootstrap: constants, requirements gate,
                                       duplicate-copy guard, activation hooks
includes/
  class-plugin.php                     Coordinator — the manual require list + hook wiring
  class-installer.php                  Install/upgrade coordinator (tables, defaults, pages)
  class-activator.php / -deactivator.php
  class-membership-service.php         Membership lifecycle / status transitions
  class-capabilities.php, class-roles.php
  class-content-restrictions.php, class-privacy.php, class-licensing.php
  class-scheduler.php                  All cron hooks + handlers
  class-account-provisioner.php, class-router.php
  database/    class-schema.php (dbDelta CREATE TABLEs), class-migrations.php,
               class-*-repository.php  (static, no ORM, raw $wpdb)
  rest/        class-rest-controller.php (base) + six controllers
  admin/       class-admin-menu.php + one class per admin screen
  frontend/    class-auth.php, class-shortcodes.php, class-staff-dashboard.php
  waivers/     Signing, versioning, kiosk, PDF, immutable archive, import
  integrations/ Registry + one bridge/adapter per third-party surface
  payments/    class-stripe-service.php (checkout, portal, webhooks, retries)
  corporate/   class-corporate-module.php — group memberships (self-contained, 3.6k lines)
  cli/         WP-CLI commands
  utilities/   security.php, helpers.php, formatting.php, global-functions.php, QR, verification
assets/        Hand-written CSS + JS. No JSX, no transpile, no minification.
templates/     Frontend templates (theme-overridable) + templates/plans/*.json
docs/          HOOKS.md, INTEGRATIONS.md, INSTALL.md, UPGRADE-2.0.md, entitlements.md,
               guest-pass-audit.md
tests/         bootstrap.php (WP stubs + repository fixtures) + tests/unit/
```

## Non-obvious things that will bite you

### There is no autoloader

`Plugin::load_dependencies()` in `includes/class-plugin.php` is a hand-ordered
`require_once` array of ~70 files. **A new class file is invisible until you add
it to that array**, and order matters where one file's class is referenced at
load time. Add new entries next to their siblings in the existing grouping.

### File and class naming

- Files: `class-{kebab-name}.php` (WordPress convention), utilities are plain
  `{name}.php`.
- Classes: `Snake_Case_With_Underscores`, almost always `final`, almost always
  all-static (repositories, services, bridges, admin pages). Instantiated
  classes are the exception: `Plugin` and the REST controllers.
- Namespace mirrors the directory: `includes/database/` →
  `WordPressistic\Memberistic\Database`, `includes/rest/` → `...\REST`, etc.
- Every PHP file starts with a docblock and `if ( ! defined( 'ABSPATH' ) ) { exit; }`.

### Database changes need three edits

1. Add/adjust the `CREATE TABLE` in `Database\Schema::create_tables()` (dbDelta).
2. Add a migration method in `Database\Migrations` keyed by the new DB version;
   for a pure new-table change the body is just `Schema::create_tables(); return true;`.
   Column changes use the idempotent `add_column_if_missing()` /
   `add_index_if_missing()` helpers.
3. Bump `MEMBERISTIC_DB_VERSION` in the main plugin file and register the
   migration in `Migrations::migrations()`.

Migrations must be idempotent and must `return true` on success — returning
`false` halts the runner and leaves `memberistic_db_version` where it was so
the upgrade resumes next request. Current DB version: `1.11.0`.

Queries are raw `$wpdb` with `prepare()`. Where a table name has to be
interpolated, it comes from a repository's `table()` method (never from input)
and the line carries a targeted `// phpcs:ignore WordPress.DB.PreparedSQL.*`.

### Settings live in one option

`memberistic_settings` is a single array option. Read it through
`memberistic_get_setting( $key, $default )` — never `get_option()` directly.
Stripe secrets can be locked by `wp-config.php` constants
(`MEMBERISTIC_STRIPE_LIVE_SECRET_KEY`, `..._TEST_SECRET_KEY`,
`..._WEBHOOK_SECRET`); when a constant is set the option value must not be
overwritten and the secret must never be returned in plain text over REST (see
`memberistic_secret_setting_keys()` / `memberistic_mask_secret()`).

### REST conventions

Namespace `memberistic/v1`. Every controller extends
`REST\REST_Controller`, which supplies the permission callbacks:

- `admin_permissions_check()` — staff / manager / admin
- `pii_permissions_check()` — anything exposing member contact data, staff
  notes, waiver status, or bulk exports. Deliberately narrower than the admin
  check: cashier / POS / instructor roles have dashboard access but not
  `view_memberistic_pii`.

**`permission_callback` is mandatory and `__return_true` is banned** — no route
in the plugin uses it, and the docs promise that. Public webhook routes are the
only unauthenticated entry points and authenticate by signature instead: Stripe
via the endpoint signing secret with a timing-safe compare and a 300-second
replay window, WooCommerce via an HMAC shared secret, both verified *before*
the payload is parsed.

### Capabilities, not roles

Gate on capabilities (`manage_memberistic`, `view_memberistic_pii`,
`memberistic_checkin_members`, …) listed in `Capabilities::get_all()`. Six
plugin roles map to them in `Capabilities::assign_capabilities()`. New
capabilities must be added there *and* granted to `administrator`, or an admin
silently loses the screen. Admin-side helpers may use
`memberistic_current_user_can()`, which falls back to `manage_options`.

### Admin JS is vanilla `wp.element` — no React toolchain

`assets/admin-*.js` are IIFEs using `wp.element.createElement` aliased to `h`,
with `wp.apiFetch` and `wp.i18n`. No JSX, no imports, no build output. Ship
edits to these files directly. They are enqueued conditionally per screen in
`Plugin::maybe_enqueue_react_app()`, keyed on `$_GET['page']`; PHP passes
initial state via `wp_add_inline_script()` into `window.memberistic*Settings`.

Frontend assets load only on pages that actually use a Memberistic shortcode or
are a configured Memberistic page (`Plugin::enqueue_frontend_assets()`) — a
deliberate perf decision. Adding a new frontend shortcode means adding its tag
to that list (and to `send_sensitive_page_cache_headers()` if the page shows
member-specific data). Frontend JS reads `window.memberisticApi`, never the
global `wpApiSettings`.

Styling goes through the `--memberistic-*` custom properties in
`assets/token-bridge.css`, which prefer a matching theme token and fall back to
a contrast-checked neutral. Don't hard-code colours in templates.

### Integrations are off by default

`Integrations\Integrations_Registry` owns the toggle for every integration.
Anything that touches a third-party plugin or off-site service is
`'default' => 'no'`; only Email Automation and Waiver Manager default to `yes`
(both built-in, both send nothing off-site). A fresh activation makes **no
outbound HTTP request at all** — preserve that. Register integration hooks
inside an `is_enabled()` guard on `init`, the way `class-plugin.php` already
does for booking, WooCommerce, and the waiver booking bridge.

### Templates are theme-overridable

`memberistic_locate_template()` resolves child theme → parent theme → plugin,
via `your-theme/memberistic/{file}.php`. The `memberistic_locate_template`
filter exists, but the returned path is validated to stay inside one of those
three roots — a template lookup must never become an arbitrary-file include.

### Cron

All scheduled work lives in `Scheduler` as `HOOK_*` constants (daily renewal
reminders, auto-expire, waiver follow-up, log pruning, renewal backfill; hourly
rate-limit pruning). Register the handler in `Scheduler::register()` and the
schedule in the same class so `Deactivator` can unschedule it.

### WP-CLI

```
wp memberistic guest-pass-audit      # classify/expire legacy auto-created guest passes
wp memberistic stripe-audit
wp memberistic stripe-reconcile
wp memberistic import-waivers <file.csv> [--dry-run] [--no-pdf] [--fresh] [--limit=N]
```

Commands register through a static `register()` guarded by WP-CLI's presence.

## Guard tests — read before changing defaults or copy

Two suites in `tests/unit/` assert against the **source files themselves**, not
behaviour, and will fail a PR that reintroduces something the 2.0.0 release
deliberately removed:

- `FreshInstallDefaultsTest` — no seeded/priced plans on a fresh install, no
  plan silently entitled to free bookings, no partner branding anywhere in
  shipped PHP/JS/CSS.
- `PmproRemovalTest` — no runtime PMPro dependency. Only three files may even
  mention PMPro, each allow-listed with a reason (the CSV importer is migration
  tooling, not a dependency).

If you legitimately need a new exception, extend the allowlist *with the
reason*, don't loosen the matcher.

`EntitlementServiceTest` and `MembershipServiceTest` are ordinary behavioural
tests. `tests/bootstrap.php` runs them with **no live WordPress**: it stubs the
WP functions used (`add_filter`, `get_option`, sanitizers, `WP_Error`, a
minimal `$wpdb`) and shadows the `Database\*` repository classes with static
fixtures *before* the service under test is required. Only two production files
are loaded there (`class-entitlement-service.php`, `class-membership-service.php`);
testing anything else means extending the stub set.

## Code style

WordPress Coding Standards, not PSR-12 (the advisory phpcs job covers exactly
one new file). Concretely:

- **Tabs** for indentation; spaces inside parentheses: `foo( $bar )`.
- **Yoda conditions**: `if ( 'active' === $status )`.
- `array()`, not `[]`, in PHP.
- Escape on output (`esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`),
  sanitize on input via the `memberistic_sanitize_*` / `memberistic_validate_*`
  helpers in `includes/utilities/security.php`.
- Verify nonces for every admin POST/GET action
  (`memberistic_verify_admin_nonce()`).
- Every user-facing string is translatable with the `memberistic` text domain,
  with `/* translators: */` comments on anything with placeholders.
- **Comments explain *why*, not *what*.** This codebase's most distinctive
  convention: non-obvious decisions carry a paragraph on the reasoning and the
  failure mode avoided (see the bootstrap file's duplicate-copy guard,
  `Installer::preserve_pre_2_0_defaults()`, or the integrations default-off
  policy). Match that when you make a judgement call; drop it when the code is
  self-evident.

## Release checklist

Version appears in five places and CI won't catch a mismatch:

1. `Version:` header in `memberistic-membership-solutions.php`
2. `MEMBERISTIC_VERSION` constant in the same file
3. `Stable tag:` in `readme.txt` (plus its `== Changelog ==` entry)
4. `**Version:**` in `README.md`
5. A new section in `CHANGELOG.md` (Keep a Changelog format)

Bump `MEMBERISTIC_DB_VERSION` separately, only when the schema changes.
`.distignore` defines what is stripped from the distributable zip — anything
dev-only added at the root belongs there.

## Documentation to keep in sync

Behaviour changes are expected to land with their docs:

| Change | Also update |
|---|---|
| New/changed action or filter | `docs/HOOKS.md` |
| New/changed integration or adapter | `docs/INTEGRATIONS.md` |
| Entitlement rules | `docs/entitlements.md` |
| New shortcode or REST route | `README.md` tables |
| Anything user-visible | `CHANGELOG.md`, and `readme.txt` if it's release-worthy |

## Git workflow

- Default branch: `main`. Feature work goes on a branch; open a PR.
- `vendor/`, `.phpunit.cache/`, `build/`, and `*.zip` are gitignored.
- Repository files are LF (`.gitattributes` sets `* text=auto eol=lf`).

See `docs/governance/BRANCHING.md` for the branch-name conventions and
`docs/governance/RELEASE-PROCESS.md` for what a release actually requires.

## This repository

`Shubochandrosarker/memberistic` is the personal working copy of the plugin,
mirrored with full history from `Wordpressistic/memberistic` (which stays the
public/organisation home of the product). Both carry the same 2.0.0 baseline.

Where to look before starting work:

| You need | Read |
|---|---|
| How the code is built and what will bite you | this file |
| What we are building next, and why | `docs/strategy/00-master-plan.md` |
| A concrete, ready-to-pick-up task | `docs/strategy/09-execution-backlog.md` |
| The rules a change must never break | `docs/strategy/00-master-plan.md` § Invariants |
| How to contribute / open a PR | `CONTRIBUTING.md` |
| Why a past decision was made | `docs/governance/decisions/` |

The strategy documents are planning material, not shipped product. Nothing in
`docs/strategy/` may be treated as an existing feature — check the code.
