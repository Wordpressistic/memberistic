# Audit findings

- **Audit date:** 2026-08-08
- **Audited release:** Memberistic Membership Solutions 2.0.0
- **Method:** static analysis of the release tree, public repository state,
  current market research

Severities: **P0** blocks serious public promotion · **P1** product quality and
Pro foundation · **P2** differentiation and SaaS.

---

## 1. Release identity

Observed in the release tree:

| Field | Value |
|---|---|
| Plugin version | 2.0.0 |
| Stable tag | 2.0.0 |
| Requires PHP | 8.2 |
| Requires WordPress | 6.8 |
| Tested up to | 6.8 |
| Licence | GPL-2.0-or-later |
| Changelog date | 2026-08-08 |

The public `main` branch contains the 2.0.0 release merge. The release commit
describes 2.0.0 as a de-brand, hardening and packaging release of the existing
engine, explicitly without major new product features.

### P0 — no immutable release artifact

A `v2.0.0` / `2.0.0` git tag was not resolvable during the audit. The plugin
metadata claims stable 2.0.0, but there is no immutable tag, no GitHub Release,
no downloadable production zip and no published checksum.

**Required:** create the tag, publish a Release, attach the production zip,
publish SHA-256.

> **Progress.** `.github/workflows/release.yml` in this repository now performs
> the build, the version-consistency check and the checksum on a `v*` tag push,
> and drafts the Release. The tag itself still has to be pushed. The mirrored
> `public_release_v_2.0.0` tag from upstream marks the 2.0.0 commit but does not
> follow the `v*` convention — see
> [`../governance/VERSIONING.md`](../governance/VERSIONING.md).

---

## 2. P0 — Compatibility gap

`readme.txt` declares:

- Requires at least: WordPress 6.8
- Tested up to: WordPress 6.8

Current stable WordPress at audit date: **7.0.2**, released 2026-07-17. The
plugin is two major lines behind on its declared testing.

This is not evidence of breakage. It is evidence that nobody has checked, which
for a plugin handling payments and access control is the same problem with a
better mood.

### Required matrix before broad promotion

| Layer | Must test |
|---|---|
| WordPress | 6.8.x, 6.9.x, 7.0.2 |
| Upcoming | the current 7.1 pre-release/RC before it ships |
| PHP | 8.2, 8.3, 8.4 |
| Database | MySQL 8.x, currently supported MariaDB versions |
| WooCommerce | latest stable + previous supported stable |
| Checkout | classic and blocks, where integrated |
| Multisite | activation, uninstall, privacy export/erase |

`Tested up to` moves only after this passes. Not before, and not because a new
WordPress shipped.

---

## 3. Static verification performed

The audited tree: **127 files** · 85 PHP · 11 JavaScript · 5 test-related PHP
files including 4 unit test classes · 38 observed `register_rest_route` calls.

*(This repository's mirror shows 128 files — the difference is `CLAUDE.md`,
added here.)*

### Results

| Check | Result |
|---|---|
| `php -l` on all 85 PHP files | **PASS** |
| `node --check` on all 11 shipped scripts | **PASS** |
| `eval`, `exec`, `shell_exec`, `system`, `passthru`, `proc_open` | none found |
| Unsafe `unserialize` | none found |
| REST routes using `__return_true` | **none** |
| Explicit permission callbacks | present — member-self, PII, admin, plans, payments, check-in |
| Nonce / sanitization / escaping usage | extensive |
| PMPro runtime dependency | absent, and guarded by regression test |

### Limitation, stated plainly

Composer and PHPUnit were unavailable in the audit environment and could not be
installed from the network. **The claimed test result was not independently
reproduced.** The release PR states 47 tests / 831 assertions passing; that is
useful evidence, not verification.

Release approval should rest on an actual GitHub Actions run attached to the
release, plus integration and E2E suites — not on a number in a PR description.

---

## 4. Security posture

### Already right

Worth listing, because each of these is a decision someone had to make
correctly:

- explicit REST capability/permission callbacks on every route;
- nonces on sensitive admin forms and actions;
- output escaping and input sanitization broadly applied;
- Stripe webhook HMAC validation with a timing-safe compare and a 300-second
  replay window;
- WooCommerce webhook **rejects an empty shared secret** instead of silently
  accepting anything — the correct failure direction;
- secrets returned by settings REST paths are masked;
- Stripe secrets can be locked in `wp-config.php` constants instead of the
  options table;
- external integrations default to disabled;
- fresh activation does not phone home;
- GDPR exporter and eraser support;
- uninstall is opt-in, not destructive by default;
- template override paths are constrained to approved roots — a filter that
  could otherwise become arbitrary file inclusion;
- restricted post bodies are **server-side redacted**, not hidden with CSS.

That last one separates real access control from decoration, and a surprising
number of plugins get it wrong.

### P0 — Still needed

1. **Route-by-route authorization suite.** Every route, positive and negative,
   for anonymous / member / staff / administrator.
2. **IDOR suite.** A member must never reach another member's people, payments,
   documents, waivers, notes or account records by changing an ID.
3. **Upload suite.** Filename, extension, MIME, path handling, executable
   extensions, double extensions, SVG policy, storage visibility, authenticated
   download.
4. **Webhook fuzzing.** Invalid signature, stale timestamp, duplicate event,
   reordered events, malformed JSON.
5. **Rate-limit tests.** Public and token endpoints, sign-in, guest flows,
   webhook abuse.

### P1 — Also needed

6. **Secrets at rest.** Constants are good. For cloud-connected Pro sites, add
   optional encrypted secret storage, key-management guidance and an explicit
   rotation flow.
7. **Dependency and SBOM scanning.** Composer audit, npm audit where relevant,
   generated SBOM per release.
8. **WordPress Plugin Check** before any WordPress.org submission and every
   public release.

---

## 5. P1 — Stripe API version

`Stripe_Service` pins:

```php
API_VERSION = '2024-04-10'
```

Stripe's current version at audit date: `2026-02-25.clover`.

**This does not mean the integration is broken.** Stripe supports version
pinning deliberately, and major upgrades can carry breaking behaviour. Blindly
replacing the string is the wrong move and could break live billing.

### The correct sequence

1. Write Stripe contract and integration tests first.
2. Build test-mode fixtures: subscription create/update/cancel, checkout
   completion, invoice success and failure, billing portal.
3. Test the migration against a newer API version in Workbench/test mode.
4. Upgrade through a documented compatibility release.
5. Surface the pinned version in System Status so it is visible in support.
6. Schedule a standing quarterly review of Stripe API changes.

Tracked as risk **R1** in [`00-master-plan.md`](00-master-plan.md).

---

## 6. P1 — Architecture: god classes

| File | Approx. size | Risk |
|---|---:|---|
| `includes/corporate/class-corporate-module.php` | 178 KB | Very high |
| `includes/waivers/class-waivers.php` | 98 KB | High |
| `includes/payments/class-stripe-service.php` | 85 KB | High |
| `templates/account.php` | 72 KB | High |
| `includes/rest/class-memberships-controller.php` | 63 KB | High |
| `includes/admin/class-import-page.php` | 49 KB | Medium-high |
| `includes/emails/class-email-service.php` | 35 KB | Medium |

Hard to unit test, hard to review, hard to extend safely. Refactor targets in
[`02-architecture.md`](02-architecture.md).

### What is good architecturally

- clear namespace and consistent internal identifiers;
- domain-specific repository classes;
- dedicated tables suited to operational data and high-volume lookups;
- integrations separated into adapters and services;
- licensing built as a **seam**, deliberately not coupled into membership
  access — and the stated policy that an expired licence must never break
  member access or local operations;
- fresh installs no longer seed unrequested plans or prices;
- integrations fail closed where giving away paid entitlements would be
  dangerous.

---

## 7. P1 — Coding standards

CI runs PSR-12 on exactly one service, advisory and non-blocking.

For a public WordPress plugin the target should be **WordPress Coding
Standards**, not PSR-12 — WPCS carries interoperability, translation and
security practices beyond visual style, and the codebase already follows it by
hand (tabs, Yoda conditions, `array()`, escaping helpers).

Migration path in [`02-architecture.md`](02-architecture.md).

---

## 8. Test coverage gap

Current unit tests cover membership service behaviour, entitlement behaviour,
fresh-install defaults and PMPro runtime removal. The last two are guard tests
asserting against the source files themselves — unusual, and genuinely valuable:
they encode promises, not just behaviour.

The problem is proportion. The plugin surface includes 38 REST routes, payments,
webhooks, uploads, admin workflows, imports, waivers, check-in and corporate
logic. Four unit test classes do not cover that.

Required pyramid in [`03-quality-and-release.md`](03-quality-and-release.md).

---

## 9. Findings index

| ID | Severity | Finding | Tracked |
|---|---|---|---|
| F-01 | P0 | No immutable release tag or artifact | Backlog P0-2, P0-3 |
| F-02 | P0 | WordPress 7.0.2 untested; `Tested up to` stale | Backlog P0-1 |
| F-03 | P0 | No REST authorization/IDOR coverage | Backlog P0-5 |
| F-04 | P0 | No upload/download security matrix | Backlog P0-8 |
| F-05 | P0 | No webhook fuzzing or rate-limit tests | Backlog P0-6 |
| F-06 | P0 | Plugin Check never run | Backlog P0-4 |
| F-07 | P0 | Fresh-activation no-HTTP promise untested | Backlog P0-10 |
| F-08 | P1 | Stripe API pinned two years back, upgrade untested | Backlog P1-9 |
| F-09 | P1 | God classes | Backlog P1-1 |
| F-10 | P1 | Coding standards advisory only | Backlog P1-2 |
| F-11 | P1 | No dependency/SBOM scanning | Backlog P1-10 |
| F-12 | P1 | No system-health/diagnostic export | Backlog P1-7 |
| F-13 | P2 | Licensing seam unimplemented | Backlog P1-3 |
| F-14 | P2 | No cloud control plane | Backlog P2-1 |
