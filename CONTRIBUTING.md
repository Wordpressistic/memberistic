# Contributing to Memberistic

Thanks for working on Memberistic. This document is the short version of how
this repository expects changes to arrive. `CLAUDE.md` is the deeper technical
orientation — read it before touching code, whether you are a person or an
agent.

## Before you start

1. Open (or find) an issue describing the problem. Bugs need reproduction
   steps; features need the membership-business use case they serve.
2. Check `docs/strategy/09-execution-backlog.md` — the work may already be
   scoped there with acceptance criteria.
3. Check `docs/governance/decisions/` — the approach may already have been
   decided and recorded.

## Local setup

No build step, no bundler, no runtime Composer dependencies. Composer exists
only to install PHPUnit.

```bash
composer install                    # dev-only: phpunit/phpunit ^10.5
vendor/bin/phpunit -c phpunit.xml   # the unit suite

# lint exactly what CI lints
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l
find assets -name '*.js' -print0 | xargs -0 -n1 node --check
```

### The integration suite

The unit suite runs with WordPress stubbed, which keeps it fast but means it
cannot see a schema problem, a capability that failed to land, or an API that
WordPress deprecated. The integration suite runs against a real WordPress and
a real database:

```bash
# needs a MySQL/MariaDB server; the DB is dropped and recreated
bin/install-wp-tests.sh wordpress_test root '' 127.0.0.1:3306 latest

# the WP test library requires PHPUnit 9.x — see below
composer require --dev --with-all-dependencies 'phpunit/phpunit:^9.6'
WP_TESTS_DIR=/tmp/wordpress-tests-lib vendor/bin/phpunit -c phpunit-integration.xml

# restore the unit suite's PHPUnit when you are done
composer install
```

**The two suites run on different PHPUnit majors, and cannot be merged.** The
WordPress core test library still calls
`PHPUnit\Util\Test::parseTestMethodAnnotations()`, which PHPUnit 10 removed, so
every integration test errors under 10.x — verified on WordPress 6.8, 6.9,
7.0.3 and trunk. The unit suite stays on 10.5; the integration suite runs on
9.6. Revisit if WordPress adopts PHPUnit 10+ in core.

Pass a WordPress version as the fifth argument (`6.8`, `7.0.2`, `latest`,
`nightly`). `6.8` resolves to the newest 6.8.x; an unpublished version fails
with the list of what actually exists rather than a download error.

CI runs this across the full supported matrix on every PR
(`.github/workflows/integration.yml`). **Any deprecation notice WordPress
raises fails the suite** — that is the mechanism that finds version
incompatibilities, rather than a hand-maintained list of what each release
deprecated.

`composer test` aborts when running as root — call `vendor/bin/phpunit`
directly in containers.

Requirements: PHP 8.2+, WordPress 6.8+, Node 20+ for the JS syntax check.

## Branching

Branch off `main`. Names follow `docs/governance/BRANCHING.md`:
`feat/`, `fix/`, `chore/`, `docs/`, `security/`, `release/`.

Never commit directly to `main`. Never force-push a branch someone else is
reviewing.

## The rules that are not negotiable

These are enforced by tests in `tests/unit/` and by review. A change that
breaks one of them will be rejected even if it is otherwise correct.

1. **No Paid Memberships Pro runtime dependency.** PMPro may only appear in
   documented one-way migration/import tooling. `PmproRemovalTest` scans the
   source for this and holds an allow-list of three files, each with a stated
   reason. Extend the allow-list *with the reason*; do not loosen the matcher.
2. **No seeded, priced plans on fresh install**, and no plan silently entitled
   to free inventory. Guarded by `FreshInstallDefaultsTest`.
3. **Entitlement decisions fail closed.** If the system cannot prove someone is
   entitled, they are not entitled.
4. **A lapsed vendor licence never breaks member access**, local payments, or
   any mission-critical local operation. It may disable premium modules only.
5. **A fresh activation makes no outbound HTTP request at all.** Integrations
   are `'default' => 'no'` unless they are built-in and send nothing off-site.
6. **`permission_callback` is mandatory on every REST route, and
   `__return_true` is banned.** Webhook routes authenticate by signature
   *before* parsing the payload.
7. **No edits to WordPress or WooCommerce core**, and no vendored copies of
   either.

## Code style

WordPress Coding Standards, not PSR-12. There is no linter enforcing the
plugin's own style yet, so match the surrounding code by hand:

- **Tabs** for indentation; spaces inside parentheses — `foo( $bar )`.
- **Yoda conditions** — `if ( 'active' === $status )`.
- `array()`, not `[]`, in PHP.
- Escape on output (`esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`);
  sanitize on input through the `memberistic_sanitize_*` /
  `memberistic_validate_*` helpers in `includes/utilities/security.php`.
- Verify a nonce on every admin POST/GET action
  (`memberistic_verify_admin_nonce()`).
- Gate on **capabilities**, never on role names.
- Every user-facing string is translatable with the `memberistic` text domain,
  with a `/* translators: */` comment on anything containing placeholders.
- **Comments explain _why_, not _what_.** Non-obvious decisions carry a short
  paragraph on the reasoning and the failure mode being avoided. This is the
  codebase's most distinctive convention — match it when you make a judgement
  call, and skip it when the code is self-evident.

## Things that need more than one edit

**A new class file is invisible until it is added to the hand-ordered
`require_once` list** in `Plugin::load_dependencies()`
(`includes/class-plugin.php`). There is no autoloader. Add new entries next to
their siblings in the existing grouping; order matters where one file's class
is referenced at load time.

**A database change needs three edits:**

1. the `CREATE TABLE` in `Database\Schema::create_tables()` (dbDelta);
2. a migration method in `Database\Migrations`, keyed by the new DB version,
   idempotent, returning `true` on success;
3. a bump of `MEMBERISTIC_DB_VERSION` in the main plugin file, plus
   registration in `Migrations::migrations()`.

Returning `false` from a migration halts the runner and leaves
`memberistic_db_version` untouched so the upgrade resumes next request. That is
deliberate — keep it.

**A new frontend shortcode** must be added to the tag list in
`Plugin::enqueue_frontend_assets()`, and to
`send_sensitive_page_cache_headers()` if the page shows member-specific data.
Frontend assets deliberately load only where they are used.

## Documentation goes with the change

| Change | Also update |
|---|---|
| New or changed action/filter | `docs/HOOKS.md` |
| New or changed integration/adapter | `docs/INTEGRATIONS.md` |
| Entitlement rules | `docs/entitlements.md` |
| New shortcode or REST route | `README.md` tables |
| Anything user-visible | `CHANGELOG.md`, and `readme.txt` if release-worthy |
| A decision worth remembering | a new ADR in `docs/governance/decisions/` |

## Pull requests

Fill in the PR template. In particular:

- state which of the seven rules above your change touches, if any;
- paste the actual output of the lint and test commands you ran;
- say plainly what you did **not** test.

An honest "I could not test the Stripe webhook path in this environment" is
worth more than a checked box. CI runs `php-lint` on PHP 8.2/8.3/8.4,
`phpunit`, and `js-lint` as blocking jobs; `phpcs` is advisory and always
exits 0.

## Releases

See `docs/governance/RELEASE-PROCESS.md`. The short version: the version string
lives in five places and CI will not catch a mismatch.

## Security issues

Do not open a public issue. See `SECURITY.md`.
