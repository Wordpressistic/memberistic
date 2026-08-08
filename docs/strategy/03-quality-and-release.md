# Quality and release

How Memberistic proves it works before anyone else has to find out.

---

## 1. Where testing stands

Four unit test classes exist, and two of them are unusual in a good way.

| Suite | Type | What it protects |
|---|---|---|
| `MembershipServiceTest` | behavioural | Membership lifecycle and status transitions |
| `EntitlementServiceTest` | behavioural | Entitlement decisions, including fail-closed behaviour |
| `FreshInstallDefaultsTest` | **guard** | No seeded or priced plans on fresh install; no plan silently entitled to free bookings; no partner branding in shipped PHP/JS/CSS |
| `PmproRemovalTest` | **guard** | No runtime PMPro dependency; only three allow-listed files may even mention it |

The guard tests assert against the **source files themselves**, not behaviour.
They will fail a PR that reintroduces something 2.0.0 deliberately removed.
That is the right tool for encoding a promise. If you need a new exception,
extend the allow-list *with the reason* — do not loosen the matcher.

`tests/bootstrap.php` runs the behavioural tests with **no live WordPress**: it
stubs the WP functions used (`add_filter`, `get_option`, sanitizers, `WP_Error`,
a minimal `$wpdb`) and shadows the `Database\*` repositories with static
fixtures before the service under test is required. Only two production files
load there. Testing anything else means extending the stub set — which is the
main reason coverage has stayed narrow.

### The gap

38 REST routes. Payments. Webhooks. Uploads. Admin workflows. Imports. Waivers.
Check-in. Corporate group logic.

The suite passes — 47 tests, 831 assertions, reproduced on PHP 8.4.19 with
PHPUnit 10.5.64 (see [`01-audit-findings.md`](01-audit-findings.md) §3). The
problem is not that the tests fail; it is that four test classes cannot cover
that surface. 746 of those 831 assertions come from the two guard tests, which
scan source files rather than exercise behaviour. Behavioural coverage is
thinner than the headline number suggests.

---

## 2. The required test pyramid

### Unit

Repositories · membership status transitions · plan pricing calculations ·
entitlement rules · waiver expiration and versioning · email merge tags ·
payment state transitions · licensing feature gates.

Fast, no WordPress, extend the existing stub approach.

### WordPress integration

Needs a real WordPress test environment — this is the missing piece that unlocks
most of the remaining coverage.

Activation, schema creation and upgrades · capabilities and roles · REST
permissions · privacy exporter and eraser · cron and scheduler · content
restrictions · multisite install and uninstall · WooCommerce hooks.

### Payment integration

Stripe test-mode API contract tests · webhook replay and idempotency · failed
and retried invoice behaviour · cancellation and end-of-period behaviour ·
refunds and reconciliation.

These are the prerequisite for the Stripe API version upgrade (risk R1). Without
them the pinned version can never safely move.

### Security

Called out separately because it is the M1 gate, not an afterthought:

| Suite | Covers |
|---|---|
| Authorization | every route × {anonymous, member, staff, admin}, positive **and** negative |
| IDOR | every member-owned resource: people, payments, documents, waivers, notes, account |
| Uploads | filename, extension, MIME, path traversal, double extensions, SVG policy, storage visibility, authenticated download |
| Webhooks | invalid signature, stale timestamp, duplicate, reordered, malformed JSON |
| Rate limits | public/token endpoints, sign-in, guest flows, webhook abuse |
| Secret redaction | REST responses, logs, diagnostic exports |
| Network silence | fresh activation makes zero outbound HTTP requests |

### E2E (Playwright)

Install, activate, onboard · create a plan · purchase a membership · account
portal · add a linked person · sign a waiver · staff verification and check-in ·
cancel and reactivate · import CSV · corporate group lifecycle · content
protection · accessibility smoke tests.

These are the journeys where a regression is visible to a paying customer within
minutes.

---

## 3. Compatibility matrix

| Layer | Must pass |
|---|---|
| WordPress | 6.8.x, 6.9.x, 7.0.2 |
| Upcoming | current 7.1 pre-release/RC before it ships |
| PHP | 8.2, 8.3, 8.4 |
| Database | MySQL 8.x, currently supported MariaDB |
| WooCommerce | latest stable + previous supported stable, HPOS enabled |
| Checkout | classic and blocks, where integrated |
| Multisite | activation, uninstall, privacy export/erase |

`Tested up to` in `readme.txt` moves **only** after this passes. It is a
statement about what was tested, not about what exists.

---

## 4. CI

### Today

| Job | What | Blocking |
|---|---|---|
| `php-lint` | `php -l` on every PHP file, matrix 8.2 / 8.3 / 8.4 | Yes |
| `phpunit` | The unit suite (Composer, phar fallback) | Yes |
| `js-lint` | `node --check` on every `assets/*.js` | Yes |
| `phpcs` | PSR-12 on one file | **No** — advisory, always exits 0 |

The Composer/phar fallback in the `phpunit` job is deliberate: a Packagist
outage must not hide a real test regression behind a red infrastructure step.

### Target

Add, in roughly this order:

1. WordPress integration job against the WP test suite, matrixed
2. Security suites (authorization, IDOR, uploads, webhooks) — blocking
3. WordPress Plugin Check — blocking, with documented exceptions
4. WPCS on changed files — blocking (replacing the advisory PSR-12 job)
5. PHPStan at a passing baseline, ratcheting
6. Stripe test-mode contract tests — blocking, secrets from repository secrets
7. Playwright critical journeys — blocking on `main`, on a schedule for PRs if
   runtime becomes a problem
8. Dependency audit and SBOM generation per release

### Release packaging

`.github/workflows/release.yml` runs on `v*` tags only. It verifies the version
string across its five homes against the tag, lints, builds the zip from
`.distignore`, asserts no dev files leaked into the archive, publishes a
SHA-256, and **drafts** the GitHub Release. Publishing stays a human act.

---

## 5. Release gate

The operational checklist lives in
[`../governance/RELEASE-PROCESS.md`](../governance/RELEASE-PROCESS.md). The
principle behind it:

> No production release exists unless every blocking suite passed and the
> release zip was smoke-installed into a clean WordPress.

Two rules that make the checklist meaningful rather than decorative:

**A suite that does not exist is not a pass.** Write "not built yet" and link
the backlog item. Several suites in that checklist do not exist today; the
checklist says so.

**A skipped test is a failed test** for release purposes. "It passed locally
but not in CI" is a CI finding, not a green light.

---

## 6. Definition of done

For any change, at any size:

- [ ] The behaviour is covered by a test that fails without the change
- [ ] Lint passes — `php -l`, `node --check`, WPCS on changed files
- [ ] Documentation landed in the same PR (see the table in `docs/README.md`)
- [ ] Invariants in [`00-master-plan.md`](00-master-plan.md) §3 verified if touched
- [ ] Anything untested is stated explicitly in the PR
- [ ] `CHANGELOG.md` updated if user-visible

The fifth item is the one that gets skipped and the one that matters most. An
honest "I could not exercise the Stripe webhook path in this environment" is
worth more to the next person than a checked box.
