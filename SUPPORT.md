# Getting help

## Where to go

| You have | Go to |
|---|---|
| A bug — something behaves incorrectly | [Open a bug report](../../issues/new?template=bug_report.yml) |
| An idea or a missing capability | [Open a feature request](../../issues/new?template=feature_request.yml) |
| A security vulnerability | **Not an issue** — see [`SECURITY.md`](SECURITY.md) |
| A "how do I…" question | [Discussions](../../discussions), or the docs below |
| A commercial/support enquiry | https://memberistic.com |

## Read these first

- [`docs/INSTALL.md`](docs/INSTALL.md) — installation and first-run setup
- [`docs/UPGRADE-2.0.md`](docs/UPGRADE-2.0.md) — upgrading from 1.x
- [`docs/INTEGRATIONS.md`](docs/INTEGRATIONS.md) — WooCommerce, Stripe, booking,
  POS and the rest, and why they are all off by default
- [`docs/HOOKS.md`](docs/HOOKS.md) — actions and filters for developers
- [`docs/entitlements.md`](docs/entitlements.md) — how access decisions are made
- [`README.md`](README.md) — shortcodes, REST routes, capabilities

## What makes a bug report actionable

Include all of these — without them a report usually cannot be reproduced:

1. **Versions.** Memberistic version, WordPress version, PHP version, and (if
   involved) WooCommerce and theme.
2. **Steps.** Numbered, from a state someone else can reach.
3. **Expected vs actual.**
4. **Scope.** Does it happen with all other plugins deactivated and a default
   theme? That single check resolves a large share of reports.
5. **Errors.** The PHP error log entry and/or the browser console output.
   Redact keys, tokens and member personal data before pasting.

For anything touching payments, include whether Stripe was in **test** or
**live** mode, and the event type — never the secret key.

## Response expectations

This is maintained alongside other work. Issues are triaged in order of impact:
data loss and access-control problems first, then payment correctness, then
everything else. There is no support SLA on the free plugin. Commercial support
tiers are described in `docs/strategy/04-business-model.md` as a plan, not as a
currently available service — do not treat that document as an offer.
