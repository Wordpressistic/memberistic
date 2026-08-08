# Execution backlog

Every item is independently startable and has acceptance criteria. **Pick work
from here**, not from the master plan.

Status: `open` · `in progress` · `done` · `blocked`

Priorities: **P0** blocks serious public promotion · **P1** product quality and
Pro foundation · **P2** differentiation and SaaS.

---

## Provenance — read before trusting any status on this page

This backlog was written in the personal working copy
(`Shubochandrosarker/memberistic`) and ported here as part of
`chore/platform-foundation-sync`. It arrived with 21 acceptance criteria ticked.
**Every one of them was reset to unchecked on arrival**, because they recorded
work done in the working copy against files that do not exist in this
repository. A status page that describes a different tree is worse than no
status page: it reports P0-4, P0-5 and P0-6 as largely finished here, and none
of them has started.

The rule, from here on: **a box is ticked on this page only when the evidence
for it is in this repository and its CI is green.** Tick it in the same pull
request that delivers it (see `CONTRIBUTING.md`).

### Available to port from the working copy

This work exists and is reviewable, but has **not** been evaluated or merged
here. Each needs its own pull request, its own verification against this tree,
and its own security review — porting is not merging.

| Artifact | What it covers | Backlog item |
|---|---|---|
| `bin/install-wp-tests.sh`, `phpunit-integration.xml`, `.github/workflows/integration.yml` | Real WordPress integration harness and its CI matrix | P0-0 |
| `tests/integration/RestAuthorizationTest.php` | REST authorization matrix, negative + positive controls | P0-5 |
| `tests/integration/RestOwnershipTest.php` | Record ownership / IDOR on `/profile/image` | P0-5 |
| `tests/integration/WebhookSecurityTest.php` | Stripe signature, replay, idempotency, malformed payloads | P0-6 |
| `tests/unit/DependencyManifestTest.php` | Guards the manual require list against omissions | P0-2 |
| `plugin-check` job in `ci.yml` | WordPress Plugin Check against the distributable tree | P0-4 |
| Runtime fixes in `class-plugin.php`, `class-stripe-service.php`, `class-licensing.php`, `uninstall.php`, `readme.txt` | See the working copy's `CHANGELOG.md` under Unreleased | P0-2, P0-6 |

One of those runtime fixes is not optional and is not waiting on a port: the
missing `Booking_Adapter` require is a fatal error on `init` for every install
of the published 2.0.0. It is reproduced and tracked as **P0-11** below, which
outranks every other item on this page.

---

## P0 — before serious public promotion

### P0-11 · The published 2.0.0 fatals on `init` — start here
**Status:** open · **Workstream:** WS-1 · **Milestone:** M0

`includes/integrations/class-booking-adapter.php` shipped in 2.0.0 but was
never added to the manual require list in `Plugin::load_dependencies()`, and
the plugin has no autoloader. It is the only file under `includes/` that is
absent from that list (71 listed, 73 on disk — the other omission is
`class-plugin.php` itself, which is required by the bootstrap).

`Waiver_Booking_Bridge::register()` calls `Booking_Adapter::hook()` as its
first statement. It is registered on `init` priority 4 whenever the Waiver
Manager integration is enabled, and that integration's `default` is `'yes'`.
So the chain fires on a stock install with no configuration at all:

```
init(4) → Waiver_Booking_Bridge::register()
        → Booking_Adapter::hook('waiver_satisfied')
        → Error: Class "…\Integrations\Booking_Adapter" not found
```

`Booking_Engine`, `POS_Bridge` and `Staff_Dashboard` reach the same class.

**Reproduced** by loading only the files `load_dependencies()` lists and
invoking the `init` path: `class_exists()` is `false`, the file is present on
disk, and the call throws. Neither `php -l` nor the unit suite can see this —
every file parses perfectly on its own, and nothing in the unit suite boots the
plugin.

**Acceptance**
- [ ] `class-booking-adapter.php` required ahead of its consumers
- [ ] A guard test that fails if *any* file under `includes/` is missing from
      `load_dependencies()`, so the class of bug cannot recur — not just this
      instance of it
- [ ] Guard test blocking in CI
- [ ] Fix verified against the real `init` sequence, not only the guard test
- [ ] Released as `v2.0.1` (P0-1, P0-2)

---

### P0-0 · WordPress integration test harness
**Status:** open · **Workstream:** WS-2 · **Milestone:** M0 · **Finding:** F-15

Blocks P0-5, P0-6, P0-7, P0-8, P0-10 and P1-9 — every suite that needs a real
WordPress. `tests/bootstrap.php` stubs WordPress rather than loading it, which
is exactly right for the fast unit suite and useless for anything that has to
exercise a route, a capability, an upload or a webhook. Only two production
files load under it. See [`03-quality-and-release.md`](03-quality-and-release.md)
§2, which already names this as the missing piece;
[`00-master-plan.md`](00-master-plan.md) makes it the M1 entry condition. It has
never had a backlog item, so the dependency surfaces on the first day someone
picks up P0-5 instead of before.

**The PHPUnit constraint.** The WordPress test library cannot run on PHPUnit 10.
`WP_UnitTestCase::set_up()` calls `expectDeprecated()`, which reads
`@expectedDeprecated` docblocks via `PHPUnit\Util\Test::parseTestMethodAnnotations()`
— a method PHPUnit 10 removed. Every integration test errors before its first
assertion, identically on WordPress 6.8, 6.9, 7.0 and trunk, so this is not
something a newer WordPress resolves. The integration suite is therefore pinned
to PHPUnit 9.6 in CI while the unit suite stays on 10.5; the unit suite never
loads WordPress, so it is unaffected. Anything added to `tests/integration/`
must be PHPUnit 9-compatible.

**Acceptance**
- [ ] A real WordPress test environment (`wp-env` or equivalent) runs locally
      and in CI
- [ ] Integration suite independently runnable — `phpunit-integration.xml`,
      separate from `phpunit.xml` by necessity, not preference: the WordPress
      core test library still calls
      `PHPUnit\Util\Test::parseTestMethodAnnotations()`, which PHPUnit 10
      removed, so the two suites cannot share a PHPUnit major. Verified on
      6.8, 6.9, 7.0.3 and trunk. Merge them if WordPress adopts PHPUnit 10+
- [ ] The existing `unit` suite still runs **without** WordPress and is
      unchanged — it is fast, it works, and the two guard tests depend on
      scanning source rather than booting anything
- [ ] Factories for member, plan, membership, linked person, payment, waiver,
      check-in, corporate group, staff user, admin user
- [ ] A role fixture covering every capability in `Capabilities::get_all()` and
      the six roles in `Capabilities::assign_capabilities()`
- [ ] An HTTP interception layer so no test reaches the network — prerequisite
      for the Stripe suites (P1-9) and for the P0-10 network-silence assertion
- [ ] `phpunit.xml` `<source>` widened beyond `class-entitlement-service.php`,
      so coverage describes the plugin rather than one file — now `includes/`
      plus the bootstrap and uninstaller
- [ ] Both suites blocking in CI — `ci.yml` runs the unit suite on PHP 8.2 /
      8.3 / 8.4, `integration.yml` runs the integration suite on the full
      matrix; neither is `continue-on-error`

---

### P0-1 · Support current WordPress
**Status:** in progress · **Workstream:** WS-1 · **Milestone:** M0 · **Finding:** F-02

Test and fix against the full matrix in
[`03-quality-and-release.md`](03-quality-and-release.md) §3, then update the
declared compatibility.

**Acceptance**
- [ ] Full activation, onboarding, plan creation, join/pay, check-in, waiver and
      import flows exercised on WordPress 6.8.x, 6.9.x and 7.0.3
- [ ] PHP 8.2, 8.3, 8.4 all pass
- [ ] A real WordPress integration harness exists — `bin/install-wp-tests.sh`,
      `tests/integration/`, `phpunit-integration.xml`, and a CI matrix in
      `.github/workflows/integration.yml` covering WP 6.8 / 6.9 / 7.0.3 ×
      PHP 8.2 / 8.3 / 8.4, plus a non-blocking trunk canary
- [ ] Deprecation notices fail the suite, so incompatibilities surface without
      maintaining a per-release deprecation list
- [ ] Installation flow covered: schema, DB version, capabilities, roles, the
      narrower PII capability, and scheduled tasks
- [ ] Remaining lifecycle flows covered: onboarding, plans, membership status
      transitions, linked-person ownership, payments (test-mode fixtures),
      waivers, check-in, import, privacy export/erase
- [ ] Multisite activation, uninstall and privacy export/erase pass
- [ ] PHP 8.2, 8.3, 8.4 all pass on all three WordPress lines
- [ ] Any incompatibility fixed, with a regression test
- [ ] `readme.txt` `Tested up to` raised **only after** the above
- [ ] Result recorded in the release notes

> **Static pre-check (2026-08-08).** No PHP 8.4 issues found: no implicitly
> nullable parameters, no `E_STRICT`, no `each()`/`create_function()`, no
> removed string functions. HPOS and cart/checkout blocks compatibility is
> already declared in the bootstrap via `FeaturesUtil::declare_compatibility()`.
> The WordPress 6.8 → 7.0 deprecation surface was **not** enumerated statically
> — that is what the matrix is for.

---

### P0-2 · Create the immutable release artifact
**Status:** open · **Workstream:** WS-1 · **Milestone:** M0 · **Finding:** F-01

Either tag the existing 2.0.0 tree or supersede it with `v2.0.1` after P0-1
lands. Superseding is preferable — a first public release that already includes
current-WordPress support is a better first impression than a tag pointing at
known-stale compatibility.

**Acceptance**
- [ ] `v2.0.x` tag exists and is immutable
- [ ] GitHub Release published with the production zip
- [ ] SHA-256 checksum published alongside
- [ ] Zip contains no `tests/`, `vendor/`, `.github/`, `composer.*`,
      `phpunit.xml`
- [ ] Zip smoke-installed on a clean WordPress
- [ ] Upgrade from the previous version tested on a site with real data

> **Note:** tag pushes are blocked by the proxy in automated agent sessions
> (HTTP 403). Create the tag from a normal environment or the GitHub UI.

---

### P0-3 · Release automation
**Status:** **done** · **Workstream:** WS-1 · **Milestone:** M0

`.github/workflows/release.yml` runs on `v*` tags only: verifies the version
string across its homes against the tag, lints, builds from `.distignore`,
asserts no dev or internal files leaked in, computes SHA-256, drafts the
Release. It never publishes — a human confirms the release-gate checklist in
`docs/governance/RELEASE-PROCESS.md` first.

The build and the leak-check live in `bin/build-dist.sh` and
`bin/assert-dist-clean.sh` rather than inline in the workflow, because
`ci.yml`'s `dist-guard` job runs the same two scripts on every pull request. A
rehearsal against a second copy of the logic proves nothing; this way the
archive a release ships is assembled by exactly the code CI proved clean.

The leak-check tests three things independently: that every `.distignore`
entry actually disappeared (catching a pattern that silently stopped matching),
that an explicit deny-list is absent regardless of what `.distignore` says
(`docs/strategy` carries pricing and revenue targets), and that the files a
plugin cannot work without are still present (catching a pattern so greedy it
ships an empty plugin). Both failure directions are covered by a negative test.

**Remaining:** attach integration-suite results to the release once those
suites exist here (P0-0).

---

### P0-4 · WordPress Plugin Check
**Status:** in progress · **Workstream:** WS-1 · **Milestone:** M0 · **Finding:** F-06

Blocks the WordPress.org listing, which blocks the primary acquisition channel.

The job checks the **distributable tree**, not the repository: it rebuilds the
release layout from `.distignore` the way `release.yml` does, so the check sees
what a reviewer sees rather than reporting on `tests/`, `bin/` and
`docs/strategy/`, none of which ship.

**Acceptance**
- [ ] Plugin Check runs in CI on every PR — `plugin-check` job in `ci.yml`
- [ ] All errors resolved
- [ ] Every remaining warning documented with a reason, in-repo
- [ ] Job is blocking — no `continue-on-error`, unlike the advisory `phpcs` job

---

### P0-5 · REST authorization and IDOR coverage
**Status:** in progress · **Workstream:** WS-2 · **Milestone:** M1 · **Finding:** F-03

The single highest-value security item. Invariant I6.

**The IDOR shape does not match this REST surface, and that changes the work.**
The audit assumed member-owned resources reachable by id. In practice every
`/memberships/{id}/*` route is gated on a *staff capability* —
`pii_permissions_check`, `manage_members_permissions_check`,
`manage_payments_permissions_check` — never on ownership of `{id}`. A member
never reaches the ownership question because they never clear the capability
gate; a user who does clear it is staff, who are meant to read every member.

So "member A cannot read member B's payments" passes today, but for the wrong
reason, and would keep passing if ownership scoping were removed entirely. It
is recorded as capability enforcement in `RestOwnershipTest`, not as an
isolation guarantee the code does not make. The only genuinely ownership-scoped
member-facing route is `/profile/image` (`member_self_permissions_check`), and
that one is tested properly, in both directions.

There are also no `/documents` or `/waivers` sub-routes — the audit's list was
wrong about those. Tests written against them would have 404'd, and a 404
satisfies "expected a rejection", so they would have been permanent false
passes. Every route this suite names is asserted to exist first.

**Acceptance**
- [ ] Every route enumerated with method, path and permission callback —
      `RestRouteInventoryTest`, plus a live inventory artifact per CI run
- [ ] Negative authorization tests for anonymous and plain-subscriber callers
      across every parameterless GET route
- [ ] Positive control: administrator reaches every one of those routes, so a
      route that rejected everybody could not pass as secure
- [ ] The PII boundary asserted in both directions — cashier / instructor /
      POS staff refused, manager / staff admitted
- [ ] Ownership tested where ownership exists (`/profile/image`), and the
      absence of other member-facing read routes recorded rather than faked
- [ ] A test asserting no route uses `__return_true`
- [ ] Per-record IDOR tests for member-facing read routes — **blocked until
      such routes exist**; add here when a real "my membership" endpoint lands
- [ ] Parameterised-route coverage for staff roles (a staff user reading a
      specific member) once fixtures for the full record graph exist
- [ ] Blocking in CI

---

### P0-6 · Webhook fuzzing and rate limits
**Status:** in progress · **Workstream:** WS-2 · **Milestone:** M1 · **Finding:** F-05

`WebhookSecurityTest` constructs **valid** signatures as well as bad ones. A
negative-only suite cannot tell "the signature check works" from "the endpoint
rejects everything", and the second would pass every negative test while
breaking every real payment.

**Acceptance**
- [ ] Stripe rejects: missing signature, invalid signature, a signature made
      with the wrong secret, a correctly-signed payload outside the 300-second
      window, and malformed JSON behind a *valid* signature
- [ ] Positive control: a correctly signed, in-window payload is accepted
- [ ] An unconfigured Stripe webhook returns 503 rather than processing
- [ ] Both verify **before** parsing the payload — proven by the malformed-JSON
      case needing a valid signature to reach the parser at all
- [ ] Idempotency: the processed-event store recognises a replayed event id
- [ ] A rejected webhook makes no outbound HTTP request
- [ ] WooCommerce HMAC path — **cannot run in the current matrix.**
      `WooCommerce_Bridge::is_enabled()` requires `class_exists( 'WooCommerce' )`,
      so with WooCommerce absent the handler returns 503 before reaching the
      comparison. Covered today only by asserting no configuration lets an
      unsigned POST succeed. Real coverage needs WooCommerce installed in
      `integration.yml`
- [ ] Reordered-event handling
- [ ] Rate-limit tests for public/token endpoints, sign-in and guest flows
- [ ] End-to-end idempotency: a replayed *payment* event does not create a
      second payment row or a second receipt email (needs Stripe test-mode
      fixtures, P1-9)

---

### P0-7 · Clean-install and upgrade E2E
**Status:** blocked by P0-0 · **Workstream:** WS-1/WS-2 · **Milestone:** M0/M1

**Acceptance**
- [ ] Playwright: install → activate → onboard → create plan → add member
- [ ] Playwright: upgrade from the previous version with existing data;
      migrations run; `memberistic_db_version` advances
- [ ] Uninstall respects the retention setting, tested in both states
- [ ] Runs in CI

---

### P0-8 · Upload and download security matrix
**Status:** blocked by P0-0 · **Workstream:** WS-2 · **Milestone:** M1 · **Finding:** F-04

Member documents and signed waivers. Legally significant records.

**Acceptance**
- [ ] Filename, extension, MIME and path-traversal handling tested
- [ ] Executable and double extensions rejected
- [ ] SVG policy explicit and enforced
- [ ] Storage location not publicly enumerable
- [ ] Download requires authentication **and** ownership or capability
- [ ] Signed waiver archive proven immutable — no code path rewrites a signed
      document

---

### P0-9 · Publish compatibility and security pages
**Status:** open · **Workstream:** WS-1

`/security/` and `/status/` on memberistic.com, plus the compatibility matrix.
Conversion assets for operationally serious buyers.

**Acceptance**
- [ ] Compatibility matrix published and dated
- [ ] Security policy published, matching `SECURITY.md`
- [ ] Public changelog
- [ ] Status page, even if minimal

---

### P0-10 · Prove the fresh-activation network silence
**Status:** blocked by P0-0 · **Workstream:** WS-2 · **Milestone:** M1 · **Finding:** F-07

Invariant I5 is currently upheld by design, not by test.

**Acceptance**
- [ ] Test asserting a fresh activation issues zero outbound HTTP requests
- [ ] Test asserting every third-party integration defaults to `'no'`
- [ ] Guard test failing if a new integration ships enabled by default

---

## P1 — product quality and Pro foundation

### P1-1 · Refactor the god classes
**Status:** open · **Workstream:** WS-3 · **Milestone:** M2 · **Finding:** F-09

Targets and class breakdowns in [`02-architecture.md`](02-architecture.md) §2.
Order: Stripe → waivers → corporate → memberships REST controller.

**Acceptance, per module**
- [ ] Characterisation tests written and passing **before** any code moves
- [ ] Split performed with no behaviour change
- [ ] Same tests pass after
- [ ] New classes registered in `Plugin::load_dependencies()`
- [ ] One module per PR; no feature work mixed in
- [ ] REST split happens **after** P0-5, so no route can silently lose its guard

---

### P1-2 · WPCS and PHPStan
**Status:** open · **Workstream:** WS-3 · **Milestone:** M2 · **Finding:** F-10

**Acceptance**
- [ ] WPCS blocking on new and changed files
- [ ] Legacy violations baselined, not ignored
- [ ] PHPStan at a level that currently passes, with a ratchet
- [ ] ESLint on admin and frontend JS
- [ ] Advisory PSR-12 job removed once WPCS lands

---

### P1-3 · Licensing and update add-on
**Status:** open · **Workstream:** WS-4 · **Milestone:** M3 · **Finding:** F-13

Extends the existing seam in `includes/class-licensing.php`. Blocks every Pro
feature.

**Acceptance**
- [ ] Pro ships as a separate add-on, not core-with-locks
- [ ] Licence state cached; network failure fails **open** for licensed sites
- [ ] No network call on a visitor-facing request
- [ ] Production domain + staging/local recognition
- [ ] Signed, reproducible update manifest
- [ ] **Test proving an expired licence leaves member access, local payments and
      renewals untouched** (invariant I4)
- [ ] Test proving a feature gate disables only premium modules

---

### P1-4 · Access rules, drip, coupons, trials, proration
**Status:** blocked by P1-3 · **Workstream:** WS-5 · **Milestone:** M4

CPT/taxonomy/category rules · partial-content protection · scheduled unlocks ·
coupons and promotions · checkout free trials · upgrade/downgrade with
proration · payment plans.

**Acceptance**
- [ ] Each behind its own feature flag
- [ ] Each independently tested, including the proration arithmetic
- [ ] Entitlement changes still fail closed (invariant I3)
- [ ] `docs/entitlements.md` updated

---

### P1-5 · Dunning and abandoned-checkout recovery
**Status:** blocked by P1-3, P1-9 · **Workstream:** WS-5 · **Milestone:** M4

**Acceptance**
- [ ] Failed-payment retry schedule configurable
- [ ] Recovery emails with merge tags, logged
- [ ] Abandoned checkout captured and recoverable
- [ ] Recovery rate measurable (see [`08-metrics.md`](08-metrics.md))
- [ ] No double-charging under retry — proven by test

---

### P1-6 · Competitor migration wizard
**Status:** open · **Workstream:** WS-5 · **Milestone:** M4

The highest-leverage growth feature in the plan — see
[`07-website-and-growth.md`](07-website-and-growth.md) §5.4.

**Acceptance**
- [ ] Guided importers for at least two major membership plugins
- [ ] Dry-run mode reporting what would happen before anything is written
- [ ] Field mapping surfaced to the user
- [ ] Rollback or clean re-run after a failed import
- [ ] Migration start and completion instrumented
- [ ] Invariant I1 respected: PMPro appears only in one-way migration tooling,
      allow-listed with a reason

---

### P1-7 · System health and diagnostic export
**Status:** open · **Workstream:** WS-1 · **Finding:** F-12

**Acceptance**
- [ ] System Status screen: plugin version, DB version, **pinned Stripe API
      version**, PHP, WordPress, active integrations, cron health
- [ ] One-click support export
- [ ] **Every secret redacted**, proven by test
- [ ] Error logging redacts secrets by default

Surfacing the pinned Stripe API version here is what makes risk R1 visible in
support conversations instead of invisible until it breaks.

---

### P1-8 · Onboarding and starter templates
**Status:** open · **Workstream:** WS-5

`templates/plans/*.json` already ships gym, club, studio, range, association
and generic tiered models. They are a distribution asset currently doing
nothing.

**Acceptance**
- [ ] Onboarding offers a starter kit by business type
- [ ] Importing a kit still seeds **no priced plans** without explicit consent
      (invariant I2 — `FreshInstallDefaultsTest` must still pass)
- [ ] Onboarding completion instrumented
- [ ] Kits documented for authors

---

### P1-9 · Stripe contract tests and API upgrade path
**Status:** blocked by P0-0 · **Workstream:** WS-2/WS-5 · **Milestone:** M2 · **Finding:** F-08

Risk R1. **Do not change the pinned version before this exists.**

**Acceptance**
- [ ] Test-mode fixtures: subscription create/update/cancel, checkout
      completion, invoice success and failure, billing portal
- [ ] Contract tests run against a newer API version in test mode
- [ ] Differences documented
- [ ] Upgrade shipped as its own compatibility release with migration notes
- [ ] Pinned version surfaced in System Status (P1-7)
- [ ] Standing quarterly Stripe API review task created

---

### P1-10 · Dependency and SBOM scanning
**Status:** open · **Workstream:** WS-2 · **Finding:** F-11

**Acceptance**
- [ ] Composer audit in CI
- [ ] npm audit where dependencies exist
- [ ] SBOM generated per release and attached to the GitHub Release
- [ ] Vulnerability alerts routed somewhere a human reads

---

## P2 — differentiation and SaaS

All blocked by M3. Design in [`06-saas-architecture.md`](06-saas-architecture.md).

### P2-1 · Cloud control plane
**Status:** blocked · **Workstream:** WS-6 · **Milestone:** M5 · **Finding:** F-14

Organisations, admin users, connected sites, subscription state, portal
deployment, usage, billing, audit logs.

**Acceptance**
- [ ] Tenant isolation proven by test
- [ ] Tenant IDs never trusted from the client
- [ ] Audit log for every administrative action
- [ ] **Cloud unreachable → local WordPress memberships unaffected** (risk R5),
      proven by test

---

### P2-2 · Custom-domain member portal
**Status:** blocked by P2-1 · **Workstream:** WS-6 · **Milestone:** M5

Subdomains first, then SSL for SaaS custom hostnames.

**Acceptance**
- [ ] `brand.memberistic.app` provisioning
- [ ] Custom hostname flow with certificate issuance
- [ ] Short-lived signed portal tokens carrying an entitlement version
- [ ] Membership state change invalidates outstanding tokens
- [ ] No payment secret or long-lived admin credential in any browser token

---

### P2-3 · Directory, community and chat
**Status:** blocked by P2-1 · **Workstream:** WS-6 · **Milestone:** M5

**Acceptance**
- [ ] One Durable Object per channel, never one global object
- [ ] Entitlement checked on channel join
- [ ] Moderation, reporting, blocking, mute and audit log **present at launch**
- [ ] Rate limits and retention configuration
- [ ] Attachment type and size policy
- [ ] Data export and delete integration

---

### P2-4 · Automation builder
**Status:** blocked by P2-1 · **Workstream:** WS-6

Triggers and actions listed in
[`06-saas-architecture.md`](06-saas-architecture.md) §3E.

**Acceptance**
- [ ] Runs are logged and re-runnable
- [ ] Failure does not silently drop the run
- [ ] Rate limits per tenant
- [ ] Failure rate measurable

---

### P2-5 · Advanced analytics
**Status:** blocked by P2-1 · **Workstream:** WS-6

MRR, ARR, churn, LTV, cohort retention, payment failure and recovery, plan
conversion, portal and community engagement.

**Acceptance**
- [ ] Definitions documented — churn calculated one way, written down
- [ ] Exportable
- [ ] Reconciles with the payment gateway, not just with local records

---

### P2-6 · Portal templates and theme builder
**Status:** blocked by P2-2 · **Workstream:** WS-6

Brand tokens, template selection, marketplace foundation.

---

### P2-7 · Workers for Platforms
**Status:** blocked, and deliberately deferred · **Workstream:** WS-6

**Do not start** until paying customers ask for custom per-tenant code or
generated applications. Requires an ADR recording the demand evidence before
any implementation.

---

## Working agreement

- Anything touching an invariant ([`00-master-plan.md`](00-master-plan.md) §3)
  says so in the PR.
- Anything blocked stays blocked. P1-4 before P1-3 produces a Pro feature with
  nothing to gate it; P0-5 before P0-0 produces a security suite with no
  WordPress to run it against.
- Ticking an item here is part of the PR that completes it, not a follow-up.
- An item without acceptance criteria is not ready to start — write them first.
