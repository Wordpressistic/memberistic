# Memberistic 2.1.0 — release report

Payment integrity, subscription state machine, and WordPress compatibility.

**This release is not finished.** The blocking gates that require a real
WordPress and a real database have not been run, because this environment has
neither and cannot reach the hosts that would provide them. What that means and
what it leaves unproven is in [Release gates](#release-gates) and
[Remaining risks](#remaining-risks). Nothing below is reported as passing that
was not actually run.

---

## Repository baseline

| | |
|---|---|
| Repository | `Wordpressistic/memberistic` |
| Branch | `release/2.1.0-payment-integrity` |
| Starting commit | `f9a5499` (`main`) |
| Ending commit | see `git log -1` on the branch |
| Version | `2.0.1` → `2.1.0` |
| DB version | `1.11.0` → `1.12.0` |
| Tag | **not created** — see [Release gates](#release-gates) |

Version is consistent across all five homes: plugin header, `MEMBERISTIC_VERSION`,
`readme.txt` `Stable tag`, `README.md`, and a `CHANGELOG.md` entry.

---

## Security changes

Each item is a defect that existed in 2.0.1 and is closed here.

### Renewal granted from an unverified invoice event — P0

`handle_invoice_succeeded()` resolved a membership from the subscription id or,
failing that, from provider metadata; set its status to `active`; advanced the
renewal date; wrote a payment row and sent a receipt. It checked none of: who
paid, how much, in what currency, on which Stripe account, in which environment,
or whether the invoice had been paid at all.

The invoice is now re-read from the provider and verified — paid state, amount,
currency, customer, subscription, plan, account, environment, chronology — before
any field moves. A mismatch is recorded as `manual_review` and does not
reactivate the membership.

### Stale cancellation could cancel a replacement subscription

`handle_subscription_deleted()` fell back to `metadata.membership_id` whenever
the subscription lookup missed. A `customer.subscription.deleted` for a
subscription the member had already replaced would find their membership through
that fallback and cancel it.

The event's subscription id is now compared against the membership's current
authoritative subscription id; on mismatch nothing is touched, and the event is
recorded as `stale_subscription_event` for review. Metadata is evidence that may
help *find* a candidate, never authority that overrides a conflicting record.

### Late payment failure could revoke access from a member who had paid

`handle_invoice_failed()` moved a membership to `past_due` with no check that
the failure still stood. The gate re-reads the subscription and takes no action
when the provider reports it paid.

### Deduplication no longer depends on a lock

Replaced a capped 500-id option, checked and written under a MySQL advisory
lock, with a `UNIQUE` key over `(provider, provider_account_id, event_id)`. The
failed `INSERT` *is* the deduplication. The cap could drop an id while its retry
was still in flight; the lock is best-effort and silently unavailable on some
managed databases.

Second layer: a `UNIQUE` key over `(payment_gateway, gateway_transaction_id)` on
the payments table.

### Stripe signature verification

| Property | Before | After |
|---|---|---|
| Signing secret | one shared | per mode, legacy shared as fallback |
| Multiple `v1` | last parsed won | every candidate compared, any match accepted |
| Timestamp parsing | `(int)` cast | digits only |
| Tolerance | ±300s | ±300s, enforced in both directions |
| Header size | unbounded | 4 KB, refused before any HMAC work |
| Error detail | — | one uniform public message; reason to the ledger only |
| Body handling | raw | raw, verified before any parse |

The single-secret bug is worth naming separately: a site switched from test to
live verified live events against the test secret and rejected every one of
them, which looks exactly like an attack and silently stops renewing anybody.

### Account and environment binding

Events carrying a different Stripe account, or the wrong `livemode`, are
refused. Account identity is confirmed once on settings save and cached — not
per webhook, which would add a network dependency to the busiest path for an
answer that only changes when the API key does.

### Side-effect ordering

Emails, hooks and activity fire only after state is committed, and never for a
duplicate, rejected or stale event. The compare-and-swap that applies a
transition reports failure conservatively, so a lost race sends nothing.

### Staff decisions are protected

A payment event never overwrites `status` when it holds `comped`, `paused`,
`suspended`, `needs_review` or `inactive`.

### Secrets and PII

No secret is logged, returned, or written to the ledger or audit trail. Audit
context is reduced to scalars — arrays and objects become `[array:3]` — so a
future caller passing a whole Stripe object cannot persist card metadata into a
table support staff paste into tickets. Identifiers are masked.

---

## Database migration

DB version `1.12.0`. Additive, staged, idempotent. Nothing renamed, nothing
dropped.

### `memberistic_memberships` — new columns

`billing_status`, `payment_provider`, `provider_account_id`,
`provider_customer_id`, `provider_subscription_id`, `last_provider_event_id`,
`last_provider_event_created_at`, `last_provider_synced_at`,
`current_period_end`, `grace_period_ends_at`.

New indexes: `provider_subscription_id`, `billing_status`, `grace_period_ends_at`.

The `stripe_*` columns are retained, keep their meaning, and are still written
alongside the new ones, so a rollback to 2.0.x leaves a working membership.

### New tables

- `memberistic_payment_events` — the ledger. `UNIQUE (provider, provider_account_id, event_id)`.
- `memberistic_payment_audit` — one immutable row per decision.

### `memberistic_payments`

Empty `gateway_transaction_id` values become `NULL`, then
`UNIQUE (payment_gateway, gateway_transaction_id)` is added. If duplicates
already exist the index is **skipped** and the conflicts are recorded in
`memberistic_payment_txn_conflicts` for review. No row is deleted.

### Backfill

`billing_status` is derived from the access status (`active`→`active`,
`trial`→`trialing`, `past_due`, `cancelled`, `expired`, `pending`). `comped`,
`paused`, `suspended`, `inactive` and `needs_review` are left NULL — they are
operational decisions with no provider equivalent. Stripe identifiers are copied
to the provider columns only where those are still empty, so a re-run cannot
restore an identifier the running plugin has since corrected.

---

## State machine

```
pending              → trialing, active, cancelled, expired
trialing             → active, past_due, cancel_at_period_end, cancelled, expired
active               → past_due, cancel_at_period_end, cancelled, expired
past_due             → active, grace_period, cancel_at_period_end, cancelled, expired
grace_period         → active, cancelled, expired
cancel_at_period_end → active, cancelled, expired
cancelled            → pending, active
expired              → pending, active
```

Anything not listed is refused and recorded as `invalid_transition`. Same-state
is permitted and is not a change, because a renewal leaves a membership `active`
while moving its renewal date.

Access mapping lives in exactly one function. Both configurable rows —
trial access and grace-period access — default to **off**, matching 2.0.1, where
neither `trial` nor `past_due` was an eligible status. **Upgrading changes
nobody's access.**

---

## Provider event coverage

| Provider | Event | Intent | Verification | State effect |
|---|---|---|---|---|
| Stripe | `checkout.session.completed` | activation | signature, account, environment, membership, customer, plan, amount, currency, subscription re-read | → `active` or `trialing` |
| Stripe | `invoice.payment_succeeded` / `invoice.paid` | renewal | as above **plus** invoice re-read and paid-state check | → provider's current state; renewal date advanced |
| Stripe | `invoice.payment_failed` | failure | subscription re-read; no action if recovered | → `past_due`, grace deadline stamped once |
| Stripe | `customer.subscription.created` / `.updated` | update | membership, customer, subscription identity | trial conversion, `cancel_at_period_end`, reinstatement |
| Stripe | `customer.subscription.deleted` | cancellation | subscription must be the membership's **current** one | → `cancelled` |
| Stripe | `customer.subscription.trial_will_end` | notice | membership identity | none; email only |
| Stripe | anything else | ignore | — | none |
| WooCommerce | order → completed | activation | order re-read, paid state, plan-in-basket, customer, currency, amount ≥ plan price | → `active` |
| WooCommerce | order → refunded | cancellation | order identity, membership relationship | → `cancelled` |
| Internal | dunning sweep (daily) | — | stored deadline | `past_due` → `grace_period` → `expired` |
| Internal | checkout return / resume | activation | full activation path, deterministic event id | → `active` or `trialing` |

---

## Tests

### Ran, green

| Suite | Result |
|---|---|
| Unit (PHPUnit 10.5.64, PHP 8.4.19) | **121 tests, 1104 assertions, 0 failures, 0 errors, 0 warnings** |
| `php -l`, every non-vendor PHP file | clean |
| `node --check`, every `assets/*.js` | clean |
| `bin/build-dist.sh --zip 2.1.0` | built |
| `bin/assert-dist-clean.sh --zip 2.1.0` | **clean**, all 5 checks |

Unit coverage grew from 56 tests / 856 assertions on `main` to 121 / 1104. The
new files are `StripeSignatureTest` (21 tests) and
`SubscriptionStateMachineTest` (44 tests, mostly refusals). The pre-existing
guard suites — dependency manifest, outbound-HTTP allowlist, PMPro removal,
fresh-install defaults — all still pass, including against the new files.

### Integration suite — first CI run, 2026-08-12

Could not be run in the authoring environment (no MySQL; the network policy
blocks `wordpress.org` and `github.com`, so `bin/install-wp-tests.sh` cannot
fetch WordPress). CI ran it, and the result was **62 of 64 passing on the first
execution**, identically across all nine matrix jobs and the trunk canary.

Everything the release exists to prove passed on the first run: the stale
cancellation leaving a replacement subscription alone, duplicate delivery not
charging or emailing twice, a claim held by another worker, takeover of an
abandoned claim, a late failure not undoing a payment that succeeded, an event
older than one already applied, wrong account, wrong environment, wrong
customer, amount and currency mismatches, trials, cancel-at-period-end and its
withdrawal, provider outage staying retryable, the dunning sweep, and a comped
member surviving a cancellation event.

Two failures, both in the **test fixtures**, neither in the product:
`test_manual_payments_...` and `test_duplicate_transaction_ids_...` inserted
2.0.1-shaped payment rows while the unique key that activation had already
created was still in place, so the second insert was rejected before the
migration could normalise it. Both now drop the index before writing the fixture
and restore it afterwards. Fixed in the commit following this report's first
version; **the fix itself has not yet been through CI.**

### WordPress Coding Standards

Run locally with PHPCS 3.x + WPCS 3.x against the new and modified files.
Categorised as section 27 asks:

| Category | Count | Disposition |
|---|---|---|
| **Security relevant** — `WordPress.DB.PreparedSQL.*` in new code | 18 → **0** | **Resolved.** Every one was a table name interpolated from `$wpdb->prefix`. Suppressions now name both sniffs in the family; they previously named only `InterpolatedNotPrepared` while `NotPrepared` was the one firing, which is the same silent-suppression failure the 2.0.1 Plugin Check work recorded. One multi-line query needed a `disable`/`enable` pair because a line-scoped ignore cannot reach an interpolation three lines into a string literal. |
| **Intentional, documented** — `WordPress.DB.DirectDatabaseQuery` (24) | 24 | Memberistic stores its data in its own `{prefix}memberistic_*` tables; a membership is not a post, so there is no `WP_Query` path. Repository-wide architecture, already documented in the `plugin-check` job's rationale. |
| **Cleanup backlog** — array alignment, short ternary, missing `@param` tags, missing class comments (≈90) | ≈90 | Cosmetic. The repository has never been WPCS-clean — the CI `phpcs` job checks one file against PSR-12 and is advisory — so fixing these only in new files would make the codebase less consistent, not more. Belongs in P1-2. |
| **Release blocker** | 0 | none found |

`class-stripe-service.php` reports 94 errors, essentially all pre-existing in
code this release did not touch.

### Not run

- **WordPress Plugin Check** — requires the `WordPress/plugin-check-action`
  runner and a WordPress install. CI runs it against the built tree on every PR,
  blocking. **Unverified here.**
- **The nine-job compatibility matrix.** **Unverified here.**

---

## Upgrade compatibility, 2.0.1 → 2.1.0

Asserted by `PaymentMigrationTest` — **written, not executed**:

- new tables and all ten new columns exist;
- the `stripe_*` columns survive;
- identifiers copy to the provider columns, and a re-run cannot overwrite a
  corrected one;
- `billing_status` derives correctly for six access statuses, and stays NULL for
  the three staff-owned ones;
- running the migration twice produces byte-identical rows;
- empty transaction ids become NULL and a third manual payment still inserts;
- duplicate transaction ids are reported, not deleted, and the unique key is
  skipped;
- an active member's `status` is unchanged by the upgrade.

The migration returns `true` only on success; a failure leaves
`memberistic_db_version` where it was so the upgrade resumes on the next
request.

---

## Release artifact

| | |
|---|---|
| File | `build/memberistic-2.1.0.zip` |
| Size | 590,564 bytes |
| SHA-256 | `43998f14fb502d1088d1fc116f7e54fb71bd2dcadcf771f2d797f58bb63b674a` |
| Guard | `bin/assert-dist-clean.sh --zip 2.1.0` — clean |

Contains no `.git`, `.github`, `tests`, `bin`, `vendor`, `node_modules`,
`composer.*`, `phpunit*.xml`, `docs/strategy`, `docs/governance`, `CLAUDE.md`,
or `CONTRIBUTING.md`.

**This artifact is a build, not a release.** It was produced from the working
tree, not from a tag, and has not been installed on a WordPress site.

The checksum identifies *this* build only. Zip archives record file
modification times, so rebuilding the same tree produces a different hash; the
release workflow builds its own zip from the tag and publishes the checksum for
that one. Do not treat the value above as the release checksum.

---

## Release gates

| Gate | Status |
|---|---|
| PHP syntax | ✅ passed |
| PHPUnit unit suite | ✅ 121/121 |
| Payment-integrity regression suite (unit half) | ✅ 65 tests |
| Payment-integrity regression suite (integration half) | ⬜ **not run** |
| WordPress 6.8.6 / 6.9.5 / 7.0.2 integration | ⬜ **not run** |
| PHP 8.2 / 8.3 / 8.4 | ⚠️ syntax only; suite ran on 8.4 |
| Plugin Check — zero errors | ⬜ **not run** |
| WordPress Coding Standards | ✅ security-relevant resolved; rest categorised |
| Dependency manifest guard | ✅ passed |
| Outbound HTTP allowlist guard | ✅ passed |
| PMPro removal guard | ✅ passed |
| Distributable leak guard | ✅ passed |
| Database upgrade test | ⬜ **written, not run** |
| Fresh install | ⬜ **not run** |
| Upgrade from a 2.0.1 fixture | ⬜ **written, not run** |
| Stripe forged-webhook test | ✅ passed (unit) |
| Stripe duplicate-delivery test | ⬜ **written, not run** (needs the real unique key) |
| Out-of-order event test | ⬜ **written, not run** |
| Stale-cancellation test | ⬜ **written, not run** |
| Trial-to-paid test | ⬜ **written, not run** |
| Grace-period recovery test | ⬜ **written, not run** |
| Cancellation-at-period-end test | ⬜ **written, not run** |
| Staging smoke test | ⬜ **not run** |

**Twelve gates are unrun.** Push the branch, let CI run, and fix what it finds
before tagging.

---

## Remaining risks

### Blockers — must clear before tagging

1. **The integration suite has never executed.** 44 tests across two new files,
   written against a harness that could not be exercised. Expect failures on
   first run; they will be in the tests, the fixtures, or genuinely in the code,
   and it will not be obvious which until CI runs.
2. **Plugin Check still has not produced a result.** It ran and failed before
   reaching the plugin: `wp-env` could not fetch the WordPress `7.0.4` tag and
   the environment never started (`Environment not initialized`). That is
   upstream — most likely git-mirror lag shortly after the 7.0.4 release — not
   anything in this diff, and the job did not evaluate a single check. The 2.0.1
   work brought Plugin Check to zero errors; new files add i18n strings with
   placeholders, new `$wpdb` calls and new escaping sites, all categories that
   produced errors last time. **Still unverified.** Pinning `wp-version` would
   make the job pass but would contradict the documented P0-4 decision to run
   the full default check set on current WordPress.
3. **The compatibility matrix has not run**, so the `7.0.2` question below is
   open, and the resolver gate is itself untested against the live API.
4. **Every compatibility target is real, and every one is stale.** Settled by
   CI. The resolver validated all three against wordpress.org and passed, so
   `6.8.6`, `6.9.5` and `7.0.2` are genuinely published — the concern that
   `7.0.2` might not exist was unfounded. The advisory patch-drift check then
   reported:

   ```
   NOTE : 6.8.6 is pinned, but 6.8.8 is the newest patch on that line.
   NOTE : 6.9.5 is pinned, but 6.9.7 is the newest patch on that line.
   NOTE : 7.0.2 is pinned, but 7.0.4 is the newest patch on that line.
   ```

   So current stable is **7.0.4** — neither the `7.0.2` the task named nor the
   `7.0.3` this repository's older evidence pointed at. Certifying against an
   older patch is a legitimate release decision and the targets were declared as
   instructed, so they have been left alone; but three supported lines are each
   two patches behind, which means any security patch on them is untested here.
   **This is a decision for the maintainer**, and a one-line edit to
   `.github/wordpress-targets.json`.
5. **No staging smoke test.** No fresh install, activation, checkout, renewal,
   cancellation or upgrade has been exercised against a real WordPress. Section
   33 is entirely unperformed.

### Non-blocking

6. **`cancel_at_period_end` now grants access** where 2.0.1 would have shown the
   membership as cancelled once the flag was set. This is a deliberate
   correction — the member paid through the period — but it is a behaviour
   change, and a site that treated a scheduled cancellation as immediate will
   see members retain access for the rest of their term.
7. **The `past_due` → `grace_period` step happens on the daily sweep**, so a
   membership sits in `past_due` for up to a day before showing `grace_period`.
   Cosmetic: the deadline is stamped at the failure and drives expiry regardless.
8. **WooCommerce amount checking is "at least" rather than exact**, so an order
   whose total exceeds the plan price passes. Deliberate — tax and shipping
   legitimately inflate a cart — but it means a discounted order below the plan
   price is refused while an inflated one is not scrutinised.
9. **The legacy shared webhook secret still works.** A site that never sets the
   per-mode secrets keeps the pre-2.1.0 behaviour, including the
   switch-mode-and-silently-fail trap. Surfaced by the health screen, not
   enforced.
10. **Stripe API version remains pinned to `2024-04-10`.** Unchanged by this
    release; contract tests are still P1-9.
11. **`class-stripe-service.php` is still 2,000+ lines.** The gate took the
    webhook decision logic out of it, but the checkout, cancellation-retry and
    rate-limiting code remains. P1-1.

### Future enhancement

12. Per-provider webhook endpoints — WooCommerce still authenticates with a
    shared HMAC rather than through an adapter's `authenticate()`.
13. An admin screen for the manual-review queue. The data and the CLI exist; the
    UI is the health notice and `wp memberistic stripe health`.
14. PayPal was deliberately **not** added. The architecture is provider-neutral
    and an adapter would be additive; there is no documented product requirement,
    and section 23 says not to invent one.

---

## What was not done

- No tag, no GitHub Release, no publication. Blocked on the gates above.
- No `docs/strategy/09-execution-backlog.md` boxes were ticked. That file's own
  rule is that a box is ticked only when the evidence is in this repository *and
  its CI is green* — and CI has not run.
