# ADR 0002: Mirror the plugin into a personal repository with full history

- **Status:** Accepted
- **Date:** 2026-08-08
- **Deciders:** Shubo Chandro Sarker

## Context

`Wordpressistic/memberistic` is the organisation repository holding Memberistic
Membership Solutions 2.0.0 — 128 files, 85 of them PHP, plus a `release/2.0.0`
branch and a `public_release_v_2.0.0` tag.

`Shubochandrosarker/memberistic` existed as an empty placeholder: a single
commit containing a two-word `README.md`.

The intent is to have a personal working repository for this product — one that
can be organised for long-term maintenance, carry the planning material derived
from the 2026 audit, and serve as the day-to-day home for development, while
the organisation repository remains the public face of the product.

The options were a snapshot import (copy the current tree as one fresh commit)
or a full-history mirror.

## Decision

Mirror `Wordpressistic/memberistic` with its complete commit history, its
`release/2.0.0` branch and its `public_release_v_2.0.0` tag.

Mechanically: the branch was created from `upstream/main`, and the personal
repo's placeholder commit was folded in with
`git merge --allow-unrelated-histories` so `main` and the working branch share
an ancestor and a normal pull request is possible. The upstream `README.md` won
the add/add conflict, being the real product README.

The imported tree is byte-identical to `upstream/main` —
`git diff upstream/main HEAD` is empty at the mirror commit. Everything added
afterwards is new files only; no plugin source was modified during the
migration.

An `upstream` remote is kept configured so divergence between the two
repositories stays visible and deliberate.

## Consequences

### What this makes easier

- `git blame` and `git log` keep working, so the reasoning embedded in past
  commits stays reachable.
- The 2.0.0 release commit remains identifiable, which matters because the
  audit's first release-engineering task is to produce a proper tagged,
  checksummed artifact for exactly that commit.
- Changes can be ported in either direction with ordinary git, rather than by
  copying files.

### What this makes harder

- Two repositories now hold the same product and can drift. That is a real
  ongoing cost and needs the discipline described in `../BRANCHING.md`.
- The unrelated-histories merge leaves two "Initial commit" roots in the graph.
  Cosmetically odd; harmless.

### What this rules out

- Treating the personal repository as a clean-slate rewrite. It carries the
  product's actual history, including its mistakes.

## Alternatives considered

**Snapshot import.** One commit, no history. Smaller and simpler, and it would
have made the repository look tidy — at the cost of throwing away every commit
message explaining why the code is the way it is, on a codebase whose most
distinctive convention is explaining *why*. Rejected.

**A GitHub fork.** Keeps the connection to upstream automatically, but marks
the repository as a fork in the UI, ties issue/PR behaviour to the parent, and
makes the personal repository subordinate rather than a peer. Rejected for a
repository intended as a primary working home.

**Git submodule or subtree.** Solves a dependency problem, not this one.

## Known limitation

The `public_release_v_2.0.0` tag could not be pushed from the automated session
that performed the migration: the network proxy returns HTTP 403 on tag pushes.
The tagged commit itself (`18ebcb9`) is present in this repository's history and
is also reachable through the mirrored `release/2.0.0` branch, so no content is
missing — only the tag reference. It should be created from a normal
environment or via the GitHub UI. Recorded here rather than left as a surprise.

## Revisit when

The two repositories have diverged enough that porting is routine work rather
than an occasional act — at that point one of them should become authoritative
and the other should become a mirror in the strict sense, or be retired.
