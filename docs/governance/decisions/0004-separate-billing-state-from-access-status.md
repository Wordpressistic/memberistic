# ADR 0004: Separate billing state from access status

- **Status:** Accepted
- **Date:** 2026-08-12
- **Deciders:** Maintainer

## Context

Until 2.1.0 a membership had one status field. `memberistic_memberships.status`
answered "may this member get in today", and it was written by two very
different kinds of author:

- **Staff**, through the admin screens and the REST API, using values no payment
  provider has ever heard of — `comped`, `paused`, `suspended`, `needs_review`.
- **Payment webhooks**, which set it to `active`, `past_due`, `cancelled` or
  `expired` directly from whatever Stripe reported.

Everything downstream reads it: the door and check-in flow, the booking bridge,
role sync, the member dashboard, the entitlement service.

Two problems followed from the single field, and both are the same problem seen
from different ends.

A payment event could silently overwrite a business decision. A manager comps a
member; three days later the card on file expires; the `invoice.payment_failed`
handler sets `status = 'past_due'` and the comp is gone, with nothing recording
that it ever existed. The manager's only recourse is to set it back and hope no
further events arrive.

And a business decision could hide a billing fact. A paused membership tells you
nothing about whether its subscription is still being billed, still in dunning,
or cancelled at the provider a month ago — so reconciliation had nothing local
to compare the provider against, and "is this membership actually paying" was
not a question the database could answer.

The obvious fix, and the one we rejected, is to widen the vocabulary of the
single field: add `trialing`, `grace_period`, `cancel_at_period_end` to the same
column. That preserves the collision. Whatever the values are, one writer still
overwrites the other, because there is still only one place to write.

## Decision

Two fields, with one direction of derivation.

- **`status`** remains the access decision. Staff own it. Its vocabulary is
  unchanged, so every existing reader, filter and integration keeps working.
- **`billing_status`** is new and describes the subscription at the provider:
  `pending`, `trialing`, `active`, `past_due`, `grace_period`,
  `cancel_at_period_end`, `cancelled`, `expired`. Payment events own it
  completely, and nothing else writes it.

Access is *derived* from the billing state through exactly one function,
`Subscription_State_Machine::access_status_for()`. The Payment Integrity Gate
applies that derivation when it commits a transition — **except** when `status`
currently holds a staff-owned value (`comped`, `paused`, `suspended`,
`needs_review`, `inactive`), in which case it records the billing fact and
leaves access alone.

`billing_status` NULL means "no billing lifecycle is tracked here" — a comped
member, or one created by staff with no subscription. It is read as `pending`,
never as `active`, so a renewal event cannot restore a membership that never had
a subscription.

## Consequences

**Good.**

- A failed card can no longer erase a comp, and a comp can no longer hide a
  cancellation. Both facts are recorded, and the conflict is visible instead of
  resolved by whichever writer went last.
- Reconciliation has something to compare: `billing_status` against the
  provider's current status, per membership.
- The state machine has a field it fully owns, which is what makes a
  fail-closed transition matrix possible at all. A matrix over a field that
  staff also edit would reject legitimate manual changes.
- The billing-to-access mapping exists in one place, so changing the policy —
  should a trial grant access? should a grace window? — is a settings change,
  not a hunt through webhook handlers.

**Costs.**

- Two fields to keep in mind. Anyone reading a membership row has to know which
  question they are asking. The docblock on `Subscription_State_Machine` and
  `docs/PAYMENT-INTEGRITY.md` both lead with the distinction for that reason.
- A row where the two disagree is legitimate and will be seen: `comped` +
  `cancelled` is a member whose subscription ended and whom the business decided
  to keep. That looks like a bug until you know it is the design.
- Backfill is a judgement call. Migrating `comped` to a billing state would
  invent a subscription that does not exist, so those rows get NULL — which
  means a comped member has no billing history recorded before 2.1.0, and none
  can be reconstructed.

**Alternatives considered.**

- *Widen the single field.* Rejected: preserves the collision, as above.
- *Keep billing state entirely in the event ledger and derive it on read.*
  Rejected: every access check would become a scan of event history, and the
  answer would depend on retention policy — pruning old events would change a
  member's current state.
- *Let payment events write `status` but log what they overwrote.* Rejected:
  a log of destroyed decisions is not the same as not destroying them, and the
  manager still has no way to make a comp stick.
