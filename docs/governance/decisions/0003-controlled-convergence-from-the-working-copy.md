# ADR 0003: Converge governance and release engineering only, and evaluate runtime code separately

- **Status:** Accepted
- **Date:** 2026-08-08
- **Deciders:** Shubo Chandro Sarker

## Context

`Wordpressistic/memberistic` is the official product repository: the public home
of Memberistic, the tree releases are cut from, and the tree that becomes the
distributable plugin. At 2.0.0 it carried the product and a single CI workflow,
and nothing else — no contributor guide, no security policy, no release
automation, no architecture decisions, no written plan for what comes next.

`Shubochandrosarker/memberistic` is a personal full-history working copy from
the same 2.0.0 baseline. It had since accumulated three distinct kinds of
material:

1. **Governance and strategy** — `CLAUDE.md`, `CONTRIBUTING.md`, `SECURITY.md`,
   `SUPPORT.md`, `CODE_OF_CONDUCT.md`, issue and PR templates, `CODEOWNERS`,
   the `docs/governance/` and `docs/strategy/` trees.
2. **Release engineering** — `release.yml`, a `.distignore` that excludes
   internal planning material, a Plugin Check job.
3. **Runtime plugin code changes** — fixes in `class-plugin.php`,
   `class-stripe-service.php`, `class-licensing.php`, `uninstall.php`,
   `readme.txt`, a new integration test suite, and a rename of the plugin
   directory.

The tempting move is to merge the working copy wholesale, since it is strictly
newer. The three categories carry very different risk, and the third contains
at least one change — the plugin directory rename — with a documented upgrade
consequence for existing installs.

## Decision

Port categories 1 and 2 in one pull request
(`chore/platform-foundation-sync`), with **no runtime behaviour change**.

Evaluate category 3 change by change, each on its own evidence, each with its
own tests, its own security review and its own pull request. Being newer in the
working copy is not evidence. A change ports when it has been reproduced or
justified against *this* tree.

Two corollaries, both learned from doing the port:

- **Status carried in ported documents is not evidence.** The execution backlog
  arrived with 21 acceptance criteria ticked, all of them describing files that
  do not exist in this repository. They were reset to unchecked, and the
  backlog now carries a provenance banner and an inventory of what remains
  available to port. A box is ticked here only when the evidence is here and
  CI is green.
- **Owner-specific references must be reoriented, not copied.** Issue-template
  links, `CLAUDE.md`'s repository section and `BRANCHING.md`'s upstream section
  all described the working copy as primary. They now describe this repository
  as primary and the working copy as the drafting tree.

## Consequences

### What this makes easier

Contributors and agent sessions get the orientation, invariants and branch
conventions immediately, without waiting on any runtime review. Releases become
reproducible and gated on a human today rather than after the refactors.
`docs/strategy/09-execution-backlog.md` becomes a single ordered queue, so work
can be picked up without re-deriving priorities.

### What this makes harder

The two repositories now differ in a second way: this one has governance the
working copy will need to re-sync with, and the working copy has runtime fixes
this one does not. Every future port has to state which direction it moves in.
Porting the runtime changes one at a time is slower than one merge, and the
interval is not free — the `init` fatal (P0-11) is live in the published
release for as long as it takes.

### What this rules out

A wholesale merge of the working copy, now or later. After this ADR the two
trees have deliberately diverged, so a merge would have to reconcile governance
in one direction and runtime in the other. Ports are per-change from here on.

## Alternatives considered

**Merge the working copy's `main` wholesale.** It would have delivered the
`init` fatal fix immediately, which is the strongest argument for it. It was
rejected because it also delivers the plugin directory rename — which makes
WordPress treat the upgrade as a different plugin and leaves existing installs
with two listed copies — plus a Stripe change, a licensing change and an
integration suite, none reviewed against this tree, in a single commit with one
combined justification. The fatal is better served by a focused fix that can be
reviewed in minutes and released as `v2.0.1`.

**Port nothing and write fresh governance here.** Rejected as pure duplication:
the working copy's material is specific to this codebase and already accurate.
The cost of porting is reorienting a handful of references, which is far below
the cost of rewriting 3,600 lines of strategy.

**Port governance and the runtime fixes together in one pull request.** The
combination that §82 of the engineering brief exists to prevent: a reviewer
cannot approve documentation and a fatal-error fix with the same attention, and
a rollback would take both.

## Revisit when

The working copy stops being used for drafting, or the two repositories'
governance trees drift far enough that a port stops being mechanical. At that
point either retire the working copy or promote it to a true fork with its own
ADR.
