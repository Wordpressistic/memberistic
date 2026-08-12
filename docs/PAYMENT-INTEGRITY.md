# Payment integrity

How Memberistic decides whether a payment event may change a membership.

The rule everything here follows:

> **Payment-provider events are evidence to verify, never commands to trust.**

A webhook is an unauthenticated HTTP request until proven otherwise, and even
once its signature checks out it is only a claim about something that happened
somewhere else. Before it may change a membership it has to survive a fixed
sequence of checks. No handler activates, renews, downgrades, cancels or expires
a membership on its own.

---

## The gate

`Payments\Payment_Integrity_Gate` runs every payment event, from every provider,
through the same sequence:

| # | Step | Refusal reason |
|---|---|---|
| 1 | Authenticate the request against the raw body | `invalid_signature` |
| 2 | Confirm the provider account | `wrong_account` |
| 3 | Confirm the environment (live vs test) | `wrong_environment` |
| 4 | Normalise the payload into a provider-neutral shape | `malformed_event` |
| 5 | Claim the event atomically, or recognise a duplicate | `duplicate` |
| 6 | Resolve the membership from our own records | `membership_not_found` |
| 7 | Confirm the customer relationship | `customer_mismatch` |
| 8 | Confirm the subscription relationship | `subscription_mismatch`, `stale_subscription_event` |
| 9 | Confirm the plan relationship | `plan_mismatch` |
| 10 | Confirm amount and currency | `amount_mismatch`, `currency_mismatch` |
| 11 | Fetch the provider's current truth | `provider_unavailable` |
| 12 | Refuse anything older than what we already applied | `stale_event` |
| 13 | Ask the state machine whether the transition is permitted | `invalid_transition` |
| 14 | Commit the local changes (compare-and-swap) | — |
| 15 | Record the decision, whichever way it went | — |
| 16 | **Only then**, fire emails, hooks and activity | — |

Steps 14–16 are in that order deliberately. A receipt for a payment that failed
to record is worse than a late receipt, and a duplicate delivery that loses the
atomic claim must send nothing at all.

### Three answers, three HTTP responses

| Outcome | Meaning | Response | Provider behaviour |
|---|---|---|---|
| `processed` / `duplicate` / `unchanged` | Verified | `200` | Stops retrying |
| `rejected` | Permanently unacceptable | `200` | Stops retrying — resending changes nothing |
| deferred | Undecidable right now | `503` | Retries |

A rejected event is acknowledged rather than retried on purpose: asking Stripe
to redeliver a forged or contradictory event for three days changes nothing
except the size of the log. A deferred one — typically the provider being
briefly unreachable — must be retried, so it answers `503` and the ledger row
stays `failed_retryable`.

---

## Two status fields

`billing_status` was added in 2.1.0 alongside the existing `status`. They answer
different questions, and collapsing them is the obvious refactor that must not
be made.

| Field | Question | Owned by | Example values |
|---|---|---|---|
| `status` | May this member get in today? | Staff **and** billing | `active`, `comped`, `paused`, `needs_review` |
| `billing_status` | What does the subscription look like at the provider? | Payment events only | `active`, `past_due`, `cancel_at_period_end` |

Access is *derived* from `billing_status` through
`Subscription_State_Machine::access_status_for()` — the only place that mapping
exists. If a payment event wrote to `status` directly, a failed card would
silently overwrite a manager's decision to comp a member, and the manager would
have no way to make it stick. The gate refuses to touch `status` at all when it
holds a staff-owned value (`comped`, `paused`, `suspended`, `needs_review`,
`inactive`).

A membership with `billing_status` NULL is not billed by a provider — a comped
member, or one created by staff. NULL is read as `pending`, never as `active`.

---

## The state machine

Canonical states: `pending`, `trialing`, `active`, `past_due`, `grace_period`,
`cancel_at_period_end`, `cancelled`, `expired`.

```
pending              → trialing, active, cancelled, expired
trialing             → active, past_due, cancel_at_period_end, cancelled, expired
active               → past_due, cancel_at_period_end, cancelled, expired
past_due             → active, grace_period, cancel_at_period_end, cancelled, expired
grace_period         → active, cancelled, expired
cancel_at_period_end → active, cancelled, expired
cancelled            → pending, active          (re-subscribe on the same row)
expired              → pending, active          (re-subscribe on the same row)
```

**Anything not listed is refused.** A transition to the state already held is
allowed and is not a change — a renewal leaves a membership `active` while
moving its renewal date, and refusing that would refuse every renewal.

Refused transitions are recorded with `invalid_transition` and fire
`memberistic_payment_transition_rejected`.

### Access mapping

| Billing state | Access status | Note |
|---|---|---|
| `pending` | `pending` | |
| `trialing` | `trial` | `active` when **Access during a trial** is on |
| `active` | `active` | |
| `past_due` | `past_due` | `active` when **Access during the grace period** is on |
| `grace_period` | `past_due` | as above |
| `cancel_at_period_end` | `active` | paid through the period; not configurable |
| `cancelled` | `cancelled` | |
| `expired` | `expired` | |

Both configurable rows default to **no**, which is exactly what 2.0.1 did —
neither `trial` nor `past_due` was an eligible status. Upgrading grants nobody
access they did not already have.

---

## Idempotency

Deduplication is a `UNIQUE` key over
`(provider, provider_account_id, event_id)` on `{prefix}memberistic_payment_events`.
The second `INSERT` of an event fails, and **that failure is the deduplication**.

This replaced a capped option holding the last 500 event ids, checked and
written under an advisory lock. The cap was the smaller problem: a busy site
plus a retry storm can push an id off the end while the retry is still in
flight, and the member is charged twice. The larger problem was that
deduplication — the property that stops a double charge — depended on a lock
that is best-effort and silently unavailable on some managed databases.

### Ledger statuses

| Status | Meaning |
|---|---|
| `received` | Recorded, not yet worked on |
| `processing` | A worker holds it now |
| `processed` | Verified and applied |
| `duplicate` | A redelivery of something already handled |
| `rejected` | Failed an integrity check permanently; do not retry |
| `manual_review` | Genuine, but contradicts our records; a human must decide |
| `failed_retryable` | Transient failure; the provider should retry |

A claim held for more than 15 minutes may be taken over. Without that, a process
that died mid-event would leave the row `processing` forever and the event could
never be applied.

Second layer: a `UNIQUE` key over `(payment_gateway, gateway_transaction_id)` on
the payments table, so even an event that somehow arrives twice cannot write two
payment rows for one charge. Payments with no transaction id (cash, manual)
store `NULL`, which MySQL treats as distinct, so they never collide.

---

## Out-of-order events

Providers do not guarantee delivery order. Two protections:

1. **Freshness.** An event older than the last one applied to that membership is
   refused with `stale_event`. Equal timestamps are allowed through, because
   providers batch events within a second and equality is genuinely ambiguous.
2. **Provider truth.** Every destructive intent — renewal, failure,
   cancellation — re-reads the subscription or invoice before acting, which
   resolves the ambiguity with evidence rather than a coin toss.

Two cases worth stating:

- *A `payment_failed` delayed behind a successful retry.* The gate re-reads the
  subscription, sees it paid, and does nothing. The member keeps access and
  receives no failure email.
- *A `subscription.deleted` for a subscription the member already replaced.* The
  event's subscription id is compared against the membership's **current**
  authoritative subscription id. On mismatch the membership is not touched, the
  event is recorded as `stale_subscription_event` and held for manual review.
  Metadata claiming the same membership id does not override this — metadata is
  evidence that may help *find* a candidate, never authority.

---

## Dunning and grace

```
active → past_due          first failed payment; grace deadline stamped
past_due → grace_period    the daily sweep notices; the clock is running
grace_period → expired     the deadline passes
grace_period → active      a verified payment recovers it
```

The deadline is **stored**, not recalculated. Repeated `invoice.payment_failed`
events inside the window do not restart it — a subscription whose card fails
weekly would otherwise never expire, which is a membership that has become
permanently free.

Configured under **Settings → Payments**: grace period in days (default 7, `0`
expires on first failure), and whether the window retains access. Also
filterable via `memberistic_grace_period_days`.

Recovery is never inferred from the absence of further failures. It arrives as a
provider event and goes through the gate like everything else.

---

## Stripe setup

### Signing secrets, one per mode

Stripe issues a different signing secret for each endpoint, and a test endpoint
and a live endpoint are two endpoints. Set both under **Settings → Payments**:

- **Test keys → Webhook signing secret** — from the test-mode endpoint.
- **Live keys → Webhook signing secret** — from the live-mode endpoint.

The older shared **Webhook secret (shared, legacy)** field still works and is
used only when the current mode's secret is empty, so upgrading from 2.0.x
changes nothing until you fill the new fields in. The health screen will ask you
to.

Any of these can be locked in `wp-config.php`, which takes precedence over the
stored option and cannot be overwritten through the REST API:

```php
define( 'MEMBERISTIC_STRIPE_WEBHOOK_SECRET_LIVE', 'whsec_...' );
define( 'MEMBERISTIC_STRIPE_WEBHOOK_SECRET_TEST', 'whsec_...' );
define( 'MEMBERISTIC_STRIPE_LIVE_SECRET_KEY', 'sk_live_...' );
define( 'MEMBERISTIC_STRIPE_TEST_SECRET_KEY', 'sk_test_...' );
```

### What signature verification requires

Verified against the **exact bytes** of the request body, before any attempt to
parse it. All of the following are refused:

- no signing secret configured for the current mode (`503`, so Stripe retries)
- missing `Stripe-Signature` header
- a timestamp that is not digits only
- a timestamp more than 300 seconds from now, in **either** direction
- a header larger than 4 KB
- no `v1` signature, or none that matches

Multiple `v1` signatures are supported and any valid one is accepted, which is
what makes signing-secret rotation work. Comparison is constant-time. Every
failure returns the same public message; the specific reason goes to the ledger
and the audit trail, not to the requester.

### Account identity

The Stripe account behind the configured credentials is confirmed once when
settings are saved, and cached. Events carrying a different account are refused
with `wrong_account`. This is not re-checked per webhook: an API round trip on
the busiest path buys nothing, because the answer only changes when the API key
does.

Run `wp memberistic stripe health` if the account has never been verified.

### Events consumed

| Event | Intent | Effect |
|---|---|---|
| `checkout.session.completed` | activation | Verifies the subscription, then activates or starts a trial |
| `invoice.payment_succeeded`, `invoice.paid` | renewal | Re-reads the invoice; renews only if genuinely paid for the right amount |
| `invoice.payment_failed` | failure | `past_due` and a grace deadline, unless the subscription has recovered |
| `customer.subscription.created`, `.updated` | update | Trial conversion, scheduled cancellation, reinstatement |
| `customer.subscription.deleted` | cancellation | Only for the membership's current subscription |
| `customer.subscription.trial_will_end` | notice | Email only; no state change |

Anything else is ignored by default. That default is deliberate: Stripe adds
event types regularly, and an unknown event must do nothing rather than fall
through to a handler that happens to be nearby.

---

## WooCommerce

WooCommerce order transitions go through the same gate. There is no signature to
check — the events are raised in-process from a database row — but every failure
mode after authentication is shared:

- Order status hooks fire more than once in ordinary use. The event id is
  derived from the order and the transition, so re-fires deduplicate.
- `_memberistic_membership_id` records what an order was created *for*, not what
  was paid for. The gate checks that a product mapping to the membership's plan
  actually appears in the basket.
- Order totals are checked as **at least** the plan price rather than exactly
  equal: tax, shipping and fees legitimately push a cart above it. Underpayment
  is the direction an attacker travels; over-payment is a tax rate.

---

## Manual review

An event reaches `manual_review` when it is verifiably genuine but contradicts
this site's records — a stale subscription, a customer mismatch, an unexpected
amount, or an already-active membership being handed a second subscription. No
membership is changed.

Find them with:

```bash
wp memberistic stripe health          # counts and configuration problems
wp memberistic stripe reconcile --all # compare every membership with Stripe
```

Resolve by deciding what actually happened at the provider, correcting the
membership by hand, and — if the underlying subscription relationship was wrong
— running `reconcile --apply` for that membership.

---

## Reconciliation

```bash
wp memberistic stripe reconcile --membership=42
wp memberistic stripe reconcile --subscription=sub_123
wp memberistic stripe reconcile --all --limit=500
wp memberistic stripe reconcile --all --apply
```

Reports by default; `--apply` writes corrections. Two cases are **never**
repaired automatically:

- **A customer mismatch.** One of the two records is about somebody else.
- **Drift the transition matrix forbids.** Applying it would make an illegal
  transition legal by running a command, which is the property the matrix exists
  to prevent.

Both are reported and left for someone who can see the account.

---

## Audit trail

Every decision writes one row to `{prefix}memberistic_payment_audit`, whether it
acted or refused. The refusals are the valuable half: when a member says their
access disappeared, the answer is a row naming which check failed. Without it
the only evidence is an absence, and an absence cannot be distinguished from an
event that never arrived.

Rows are written once and never updated; a correction is a new row.

**Never stored:** API keys, signing secrets, card details, full provider
payloads, or personal data beyond the identifiers needed to find the records.
Identifiers are masked. Context values are reduced to scalars — an array or
object is summarised as `[array:3]`, so a future caller passing an entire Stripe
object cannot persist card metadata into a table support staff paste into
tickets.

Retention: `processed` and `duplicate` ledger rows are pruned after 180 days
(`memberistic_payment_event_retention_days`). Rejections, manual-review items
and retryable failures are never pruned — they are evidence about something that
went wrong, and the moment they become inconvenient is not the moment to delete
them.

---

## Time zones

Every datetime added in 2.1.0 is **UTC**: `last_provider_event_created_at`,
`last_provider_synced_at`, `current_period_end`, `grace_period_ends_at`, and
everything in the event ledger. Produce them with `Payments\Payment_Clock`, never
`current_time()`.

The original columns (`start_date`, `renewal_date`, `paid_at`, every
`created_at`) remain site-local and are unchanged.

The mixture is deliberate. The new columns are compared against provider
timestamps, which are UTC epochs, to decide which of two events happened first —
and event ordering is what stops a stale cancellation removing a member's
access. Site-local storage would make that comparison wrong by the site's offset,
and would silently reorder stored events the moment an admin changed the site's
time zone.

---

## Upgrading from 2.0.x

The migration is additive and idempotent. Nothing is renamed and nothing is
dropped:

- New columns on `memberistic_memberships`; the `stripe_*` columns keep their
  meaning and their values, and are still written alongside the new ones, so a
  rollback leaves a working membership.
- `billing_status` is derived from the existing access status. `comped`,
  `paused`, `suspended`, `inactive` and `needs_review` get NULL — they are
  operational decisions with no provider equivalent.
- Empty `gateway_transaction_id` values become `NULL` so the new unique key can
  distinguish "no gateway reference" from "this exact charge".
- If two payment rows already share a transaction id, the unique key is **not**
  created and the conflicts are recorded for review. Nothing is deleted: two
  rows claiming one charge is either a double-charge the member can see or a
  double-insert we caused, and deleting one destroys the evidence needed to tell
  which.

---

## Troubleshooting

| Symptom | Likely cause | Check |
|---|---|---|
| Renewals stopped after going live | Live signing secret missing | `wp memberistic stripe health` |
| Every event returns 400 | Wrong mode's secret, or an endpoint pointed at the wrong site | Health screen; Stripe dashboard endpoint |
| Events return 503 repeatedly | Provider unreachable, or a stuck claim | Ledger `failed_retryable` count |
| A member paid but has no access | Event in `manual_review` | `reconcile --membership=<id>` |
| Access ended unexpectedly | A cancellation applied, or a grace deadline passed | Audit rows for that membership |
| Duplicate receipts | Should be impossible in 2.1.0; report it | Payments table, `provider_txn` index |

---

## Hooks

| Hook | Type | Fires |
|---|---|---|
| `memberistic_payment_providers` | filter | Registering a provider adapter |
| `memberistic_billing_transitions` | filter | The transition matrix |
| `memberistic_provider_state_map` | filter | Provider status → billing state |
| `memberistic_access_status_for_billing_status` | filter | Billing state → access status |
| `memberistic_grace_period_days` | filter | Dunning window length |
| `memberistic_trial_grants_access` | filter | Trial access policy |
| `memberistic_grace_period_grants_access` | filter | Grace access policy |
| `memberistic_payment_amount_tolerance` | filter | Allowed amount difference |
| `memberistic_payment_event_retention_days` | filter | Ledger retention |
| `memberistic_payment_clock_timestamp` | filter | "Now", for tests |
| `memberistic_payment_audit_recorded` | action | An audit row was written |
| `memberistic_payment_event_duplicate` | action | A redelivery was recognised |
| `memberistic_payment_event_rejected` | action | An event failed an integrity check |
| `memberistic_payment_transition_rejected` | action | A transition was refused |
| `memberistic_payment_provision_member_user` | action | A verified activation should create the WP user |
| `memberistic_payment_dunning_swept` | action | The dunning sweep changed memberships |

Widening `memberistic_billing_transitions` or
`memberistic_payment_amount_tolerance` removes a guard. Narrowing either is the
safe direction.
