# ADR 0001: Record architecture decisions

- **Status:** Accepted
- **Date:** 2026-08-08
- **Deciders:** Shubo Chandro Sarker

## Context

Memberistic already carries a number of decisions that are load-bearing but
invisible in the code:

- membership data lives in dedicated `{prefix}memberistic_*` tables rather than
  posts and post meta;
- there is deliberately no autoloader, no build step and no Composer runtime
  dependency;
- integrations default to off, and a fresh activation makes no outbound HTTP
  request at all;
- Paid Memberships Pro was removed as a runtime dependency and may only appear
  in one-way migration tooling;
- licensing is a seam, and an expired licence must never break member access.

Some of these are captured as comments in the source, some as guard tests in
`tests/unit/`, and some only as habit. That is enough for the person who made
them and not enough for anyone else — including an agent session that reads the
repository cold and reasonably concludes a constraint is an accident.

The immediate trigger: the repository is being organised for long-term,
multi-participant work, and the 2026 audit proposes substantial changes
(refactoring the large classes, adding a commercial licensing layer, building a
hosted cloud tier). Those decisions will be made over months. Without a record,
the reasons will be lost and re-litigated.

## Decision

Record significant decisions as numbered ADRs in
`docs/governance/decisions/`, using `adr-template.md`.

An ADR is warranted when a decision:

- is expensive or destructive to reverse (schema, data ownership, pricing
  architecture, platform choice);
- constrains future work in a way that is not obvious from the code;
- rejects an approach a reasonable contributor would otherwise take;
- encodes a promise to users (access, data portability, licensing behaviour).

Routine choices — naming, file placement, which helper to use — stay as code
comments. The existing convention of explaining *why* in comments is not
replaced by this; ADRs cover decisions too large to live next to one function.

ADRs are immutable once accepted. A changed decision gets a new ADR that
supersedes the old one, and the old one is marked, never deleted or edited.

## Consequences

### What this makes easier

- A new contributor, or an agent with no session history, can find out why a
  constraint exists before working around it.
- Guard tests get a home for their rationale: `PmproRemovalTest` asserts a
  fact, an ADR explains why the fact matters.
- Reversals become explicit and dated instead of quiet drift.

### What this makes harder

- Real overhead on every significant change. An ADR nobody writes is worthless,
  and an ADR written to satisfy a process is worse than none.

### What this rules out

- Deciding something significant in a PR description alone. The PR is where the
  code is reviewed; the ADR is where the decision survives.

## Alternatives considered

**Keep everything in code comments.** Works well for local reasoning and is
already the codebase's strength — but a decision spanning payments, licensing
and the cloud layer has no single file to live in.

**A wiki or an external document.** Drifts from the code, is not reviewed, and
does not arrive with the clone. ADRs are versioned with the thing they
describe.

**Retrofit ADRs for every past decision.** Rejected as make-work. Existing
decisions get an ADR when they are next touched or challenged; ADR 0002 records
this migration because it is happening now.

## Revisit when

The `decisions/` directory holds a dozen entries and the numbering or indexing
becomes awkward, or if ADRs are consistently written after the fact — which
would mean the practice is theatre rather than a decision record.
