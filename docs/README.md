# Memberistic documentation

Everything written down about this project, in one index.

## Start here

| If you are… | Read |
|---|---|
| Installing the plugin | [INSTALL.md](INSTALL.md) |
| Upgrading from 1.x | [UPGRADE-2.0.md](UPGRADE-2.0.md) |
| Writing code here | [`../CLAUDE.md`](../CLAUDE.md), then [`../CONTRIBUTING.md`](../CONTRIBUTING.md) |
| Deciding what to build next | [strategy/00-master-plan.md](strategy/00-master-plan.md) |
| Looking for a task to pick up | [strategy/09-execution-backlog.md](strategy/09-execution-backlog.md) |
| Reporting a vulnerability | [`../SECURITY.md`](../SECURITY.md) |
| Cutting a release | [governance/RELEASE-PROCESS.md](governance/RELEASE-PROCESS.md) |

## Product documentation

How the plugin that exists today actually behaves.

| Document | Covers |
|---|---|
| [INSTALL.md](INSTALL.md) | Requirements, installation, first-run setup |
| [UPGRADE-2.0.md](UPGRADE-2.0.md) | Migrating from the 1.x line |
| [HOOKS.md](HOOKS.md) | Actions and filters available to developers |
| [INTEGRATIONS.md](INTEGRATIONS.md) | WooCommerce, booking, POS and the rest — and why they are off by default |
| [entitlements.md](entitlements.md) | How access decisions are made, and where they fail closed |
| [guest-pass-audit.md](guest-pass-audit.md) | The legacy guest-pass classification and the WP-CLI audit command |

## Strategy

Where the product is going. **Planning material, not shipped features** — never
read a capability described here as one that exists. Check the code.

| Document | Covers |
|---|---|
| [strategy/README.md](strategy/README.md) | How to read and maintain this set |
| [strategy/00-master-plan.md](strategy/00-master-plan.md) | The spine: objective, invariants, workstreams, milestones, risks |
| [strategy/01-audit-findings.md](strategy/01-audit-findings.md) | What the 2026-08-08 audit found, with severities |
| [strategy/02-architecture.md](strategy/02-architecture.md) | God-class refactor targets and the coding-standards migration |
| [strategy/03-quality-and-release.md](strategy/03-quality-and-release.md) | Test pyramid, compatibility matrix, release gate |
| [strategy/04-business-model.md](strategy/04-business-model.md) | Free / Pro / Lifetime / Cloud, licensing rules, revenue model |
| [strategy/05-market-position.md](strategy/05-market-position.md) | Competitor landscape and positioning |
| [strategy/06-saas-architecture.md](strategy/06-saas-architecture.md) | Memberistic Cloud: tenancy, portal auth, Cloudflare staging |
| [strategy/07-website-and-growth.md](strategy/07-website-and-growth.md) | memberistic.com, SEO/AEO/GEO, distribution channels |
| [strategy/08-metrics.md](strategy/08-metrics.md) | What gets measured, and what each number is for |
| [strategy/09-execution-backlog.md](strategy/09-execution-backlog.md) | P0/P1/P2 items with acceptance criteria |
| [strategy/10-agent-brief.md](strategy/10-agent-brief.md) | Standing brief for an automated implementation session |
| [strategy/sources.md](strategy/sources.md) | External sources, dated, with a re-check rule |

## Governance

How this repository is run.

| Document | Covers |
|---|---|
| [governance/BRANCHING.md](governance/BRANCHING.md) | Branch names, lifetime, merge strategy, upstream relationship |
| [governance/VERSIONING.md](governance/VERSIONING.md) | SemVer rules, the five version sites, deprecation policy |
| [governance/RELEASE-PROCESS.md](governance/RELEASE-PROCESS.md) | The release gate, tagging, publishing, rollback |
| [governance/decisions/](governance/decisions/) | Architecture decision records |

## Repository map

```
memberistic-membership-solutions.php   Bootstrap: constants, requirements gate,
                                       duplicate-copy guard, activation hooks
uninstall.php                          Opt-in data removal
index.php                              Directory-listing guard

includes/
  class-plugin.php                     Coordinator — the manual require list
  class-installer.php                  Install/upgrade coordinator
  class-membership-service.php         Membership lifecycle / status transitions
  class-content-restrictions.php       Server-side content redaction
  class-capabilities.php, class-roles.php, class-privacy.php,
  class-licensing.php, class-scheduler.php, class-account-provisioner.php,
  class-router.php, class-activator.php, class-deactivator.php
  database/      class-schema.php, class-migrations.php, *-repository.php
  rest/          class-rest-controller.php (base) + six controllers
  admin/         class-admin-menu.php + one class per admin screen
  frontend/      auth, shortcodes, staff dashboard
  waivers/       Signing, versioning, kiosk, PDF, immutable archive, import
  integrations/  Registry + one bridge/adapter per third-party surface
  payments/      class-stripe-service.php
  corporate/     class-corporate-module.php — group memberships
  cli/           WP-CLI commands
  utilities/     security, helpers, formatting, global functions, QR, verification

assets/          Hand-written CSS + JS. No JSX, no transpile, no minification.
templates/       Theme-overridable frontend templates + templates/plans/*.json
languages/       memberistic.pot
tests/           bootstrap.php (WP stubs + fixtures) + tests/unit/
docs/            You are here
.github/         CI, release packaging, issue/PR templates, CODEOWNERS
```

Full technical orientation — including the things that will bite you, like the
absent autoloader and the three-edit database rule — lives in
[`../CLAUDE.md`](../CLAUDE.md).

## Keeping documentation honest

A change is not finished until its documentation lands with it:

| Change | Also update |
|---|---|
| New/changed action or filter | `HOOKS.md` |
| New/changed integration or adapter | `INTEGRATIONS.md` |
| Entitlement rules | `entitlements.md` |
| New shortcode or REST route | `../README.md` tables |
| Anything user-visible | `../CHANGELOG.md`, and `../readme.txt` if release-worthy |
| A decision worth remembering | a new ADR in `governance/decisions/` |
| A shipped strategy item | tick it in `strategy/09-execution-backlog.md` |
