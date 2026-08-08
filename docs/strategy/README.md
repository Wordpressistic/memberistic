# Memberistic strategy documentation

## What this is

A working program of record for Memberistic: where the product stands, what it
should become, in what order, and what must never break along the way.

It is derived from the **Memberistic 2026 Master Audit** (audit date
2026-08-08, audited release 2.0.0) and restructured from a report into
something executable — workstreams with dependencies, milestones with exit
criteria, a risk register, and a backlog with acceptance criteria.

## What this is not

**Not a description of shipped functionality.** A capability appearing in these
documents means it is planned, considered, or being argued about. Nothing here
is evidence that code exists. Check the source.

**Not a price list or a public offer.** `04-business-model.md` proposes a
commercial model. Until it is published on memberistic.com, it is a plan.

**Not a forecast.** The revenue model in `04-business-model.md` is arithmetic
showing one mix that would reach a target. It is stated as a target model with
its assumptions written down, not as a prediction.

## Reading order

| # | Document | Read it when |
|---|---|---|
| 00 | [Master plan](00-master-plan.md) | Always. The spine — everything else is detail hanging off it. |
| 01 | [Audit findings](01-audit-findings.md) | You want to know what is actually wrong right now. |
| 02 | [Architecture](02-architecture.md) | You are about to touch a large class or set a coding standard. |
| 03 | [Quality and release](03-quality-and-release.md) | You are writing tests or cutting a release. |
| 04 | [Business model](04-business-model.md) | You are deciding whether a feature is free, paid or cloud. |
| 05 | [Market position](05-market-position.md) | You are writing copy, comparison pages, or arguing about scope. |
| 06 | [SaaS architecture](06-saas-architecture.md) | You are building Memberistic Cloud. |
| 07 | [Website and growth](07-website-and-growth.md) | You are working on memberistic.com or distribution. |
| 08 | [Metrics](08-metrics.md) | You need to know whether any of this is working. |
| 09 | [Execution backlog](09-execution-backlog.md) | You want a task you can start today. |
| 10 | [Agent brief](10-agent-brief.md) | You are briefing an automated implementation session. |
| — | [Sources](sources.md) | You are about to publish a claim about a competitor. |

## Shareable summary

A designed, self-contained web version of the master plan — the decision it
rests on, invariants, workstream dependency diagram, milestones M0–M5, the
commercial ladder and the risk register — is published as a private artifact:

**https://claude.ai/code/artifact/17985cb6-3d5f-46e2-b49b-412f72387f99**

Private by default; shareable from the page's own share menu. It is a summary,
not a replacement — [`00-master-plan.md`](00-master-plan.md) is authoritative,
and the artifact is regenerated from it rather than edited separately.

## Maintaining these documents

**Date every external fact.** Competitor pricing, WordPress versions, Stripe
API versions and platform capabilities all move. Anything sourced from outside
this repository is stamped `as of 2026-08-08` and must be re-verified before it
is used in public marketing. `sources.md` holds the list.

**The backlog is the live surface.** `09-execution-backlog.md` changes as work
lands. The other documents change when the *thinking* changes, which should be
much rarer.

**Decisions graduate to ADRs.** When something in here stops being a proposal
and becomes a commitment, write it up in `../governance/decisions/` and link it
from `00-master-plan.md`. The strategy documents describe intent; ADRs record
what was actually decided and why.

**Do not let this drift from the code.** If a document describes behaviour the
plugin no longer has, that is a bug in the document. Fix it in the same PR that
changed the behaviour.
