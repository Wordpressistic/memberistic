# Sources

Every external fact in the strategy documents traces back to this list.

**Observation date: 2026-08-08.** Everything below was current then and may not
be now.

---

## The re-check rule

> **Re-verify before publishing.** No competitor price, platform capability,
> version number or API version from this list goes onto memberistic.com, into a
> comparison page, into marketing copy, or into a customer conversation without
> being checked against the live source first.

A stale price on a public comparison page is risk **R9** — it costs credibility
with exactly the buyers who check, and it can attract a legitimate complaint.

**Cadence:** quarterly for competitor pricing, or immediately before publishing
anything that cites it. Stripe API and WordPress versions are on the same
quarterly review (risk R1, risk R2).

---

## Platform and standards

| Source | Used for |
|---|---|
| https://wordpress.org/news/2026/08/wordpress-7-0-3-release/ | Current stable WordPress at audit date (7.0.3, released 2026-08-06) |
| https://make.wordpress.org/core/2026/07/03/wordpress-7-1-release-party-schedule/ | 7.1 ship date (2026-08-19) — the matrix target moves when it lands |
| https://make.wordpress.org/core/2026/05/14/wordpress-7-0-field-guide/ | 7.0 breaking changes, checked against the plugin: none apply |
| https://wordpress.org/download/releases/ | Release history and the supported-version matrix |
| https://developer.wordpress.org/coding-standards/wordpress-coding-standards/ | WPCS as the standards target |
| https://developer.wordpress.org/plugins/developer-tools/helper-plugins/ | Plugin Check guidance |

## Payments

| Source | Used for |
|---|---|
| https://docs.stripe.com/api/versioning | API version pinning; current version `2026-02-25.clover` vs the plugin's pinned `2024-04-10` |

## Competitors

All pricing observed on official public pages, 2026-08-08.

| Source | Used for |
|---|---|
| https://www.paidmembershipspro.com/pricing/ | Free core; $499 / $999 / $2,999 per year |
| https://memberpress.com/plans/pricing/ | $199.50 promo (list $399) with 4.9% fee; $314.55 (list $699); $399.60 (list $999) |
| https://suremembers.com/pricing/ | $69 / $149 / $199 promo; $99 / $199 / $299 regular; $599 lifetime up to 100 sites |
| https://wishlistmember.com/pricing/ | $149.50 / $249.50 / $349.50 promo; $299 / $499 / $699 list |
| https://restrictcontentpro.com/affiliate-pricing/ | $99 / $149 / $249 per year; 34 pro add-ons |
| https://ultimatemember.com/pricing/ | Free; $276/yr 1 site; $348/yr 2 sites |
| https://woocommerce.com/products/woocommerce-memberships/ | $199/yr |
| https://woocommerce.com/products/woocommerce-subscriptions/ | $279/yr — combined ≈ $478/yr for standard recurring memberships |

Promotional and list prices are recorded separately on purpose. Quoting a
promotional price as if it were the standard price is the most common way a
comparison page becomes misleading, and it is the version competitors will
object to.

## Cloud infrastructure

| Source | Used for |
|---|---|
| https://developers.cloudflare.com/cloudflare-for-platforms/workers-for-platforms/ | Why per-tenant isolated Workers are a V2 decision, not a V1 one |
| https://developers.cloudflare.com/use-cases/saas/ | SSL for SaaS, custom hostnames, multi-tenant routing |
| https://developers.cloudflare.com/durable-objects/ | Stateful realtime chat, hibernatable WebSockets, per-channel objects |

---

## Facts that came from the repository, not the web

These need no external re-check — verify them against the code instead:

| Fact | Where |
|---|---|
| 85 PHP files, 11 JS files, 128 files total | `find` over the tree |
| 38 `register_rest_route` calls | `grep` over `includes/rest/` |
| God-class sizes | `ls -la` on the files in [`01-audit-findings.md`](01-audit-findings.md) §6 |
| Pinned Stripe API version | `includes/payments/class-stripe-service.php` |
| DB version 1.11.0 | `MEMBERISTIC_DB_VERSION` in the main plugin file |
| Requires PHP 8.2 / WordPress 6.8 | plugin headers and the runtime requirements gate |
| Four unit test classes, two of them guard tests | `tests/unit/` |
| 47 tests / 831 assertions | **Verified** 2026-08-08 on PHP 8.4.19 / PHPUnit 10.5.64 — was an unreproduced claim at audit time |

On that last row: 746 of the 831 assertions come from the two guard tests, which
scan source files rather than exercise behaviour. The suite genuinely passes;
quoting "831 assertions" as a measure of behavioural coverage would still be
misleading.
