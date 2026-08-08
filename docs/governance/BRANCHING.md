# Branching

## The branches that exist

| Branch | Meaning |
|---|---|
| `main` | The current development state. Always installable, always green on CI. |
| `release/X.Y.Z` | Cut from `main` when a release is being stabilised. Only fixes for that release land here. Merged back into `main`. |
| `feat/*`, `fix/*`, `chore/*`, `docs/*`, `security/*` | Short-lived work branches. |
| `claude/*` | Branches created by an automated agent session. Same rules as any other work branch. |

`main` is never committed to directly. Everything arrives through a pull
request.

## Naming

```
feat/corporate-seat-transfer
fix/waiver-expiry-timezone
chore/php-84-matrix
docs/entitlement-examples
security/rest-idor-coverage
release/2.0.1
```

Lowercase, hyphenated, describing the outcome rather than the file touched.
`fix/stripe` says nothing; `fix/stripe-webhook-replay-window` says everything.

## Lifetime

Work branches are short. A branch open longer than a couple of weeks is a
signal that the change is too large — split it. Refactors of the large classes
(`class-corporate-module.php`, `class-waivers.php`, `class-stripe-service.php`)
should land as a series of behaviour-preserving PRs, each with tests written
*before* the move, not as one enormous branch.

Delete the branch after merge. The PR keeps the history.

## Rebasing and force-pushing

- Rebase your own branch onto `main` freely while it is unreviewed.
- Once someone is reviewing, prefer merge commits from `main` so their
  comments keep pointing at real lines.
- Force-push only to a branch nobody else has based work on, and only with
  `--force-with-lease`.

## This repository's relationship to upstream

`Shubochandrosarker/memberistic` is a full-history mirror of
`Wordpressistic/memberistic`, which remains the public/organisation home of the
product. Both share the 2.0.0 baseline and the `public_release_v_2.0.0` commit.

When both repositories are live, decide per change where it originates and
port deliberately — do not let the two diverge silently. The `upstream` remote
is configured for exactly this:

```bash
git remote add upstream https://github.com/Wordpressistic/memberistic
git fetch upstream
git log --oneline main..upstream/main   # what upstream has that we do not
git log --oneline upstream/main..main   # what we have that upstream does not
```

Record any intentional divergence as an ADR in `decisions/`.

## Merge strategy

Squash-merge work branches into `main`: one PR, one commit, a message that says
what changed and why. Merge commits are reserved for release branches and for
the upstream mirror, where the individual history is worth keeping.

Every commit message ends with the co-author trailer when it was produced in an
agent session, so authorship stays honest.
