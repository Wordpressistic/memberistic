# Release process

A release is not "the code looks done." It is a tagged, reproducible artifact
that has been installed on a clean WordPress and upgraded from the previous
version, with a checksum published and a rollback that works.

## 1. Decide the version

Read `VERSIONING.md`. Decide MAJOR/MINOR/PATCH, and decide separately whether
`MEMBERISTIC_DB_VERSION` moves.

## 2. Cut a release branch

```bash
git checkout main && git pull
git checkout -b release/2.0.1
```

Only fixes for this release land on it. New features keep going to `main`.

## 3. Bump the version in all five places

1. `Version:` header in `memberistic-membership-solutions.php`
2. `MEMBERISTIC_VERSION` in the same file
3. `Stable tag:` in `readme.txt`, plus a `== Changelog ==` entry
4. `**Version:**` in `README.md`
5. A new `CHANGELOG.md` section, Keep a Changelog format

Bump `MEMBERISTIC_DB_VERSION` only if the schema changed, and only alongside a
registered, idempotent migration.

## 4. Pass the release gate

Nothing below is optional, and nothing below may be reported as passing when it
was skipped. If a suite does not exist yet, say so in the release notes rather
than ticking the box — the missing suites are tracked in
`../strategy/09-execution-backlog.md`.

### Automated

- [ ] `php -l` passes on every PHP file, on PHP 8.2, 8.3 and 8.4
- [ ] `node --check` passes on every `assets/*.js`
- [ ] The PHPUnit unit suite passes
- [ ] The guard tests pass by name: `FreshInstallDefaultsTest`,
      `PmproRemovalTest`
- [ ] WordPress integration tests pass *(suite not built yet — see backlog)*
- [ ] REST authorization and IDOR tests pass *(not built yet)*
- [ ] Stripe test-mode contract tests pass *(not built yet)*
- [ ] WooCommerce integration tests pass *(not built yet)*
- [ ] Playwright critical journeys pass *(not built yet)*
- [ ] WordPress Plugin Check passes, or every exception is written down with a
      reason *(not wired up yet)*
- [ ] WPCS passes on all changed files

### Manual

- [ ] Clean install on a fresh WordPress: activate, complete onboarding, create
      a plan, add a member
- [ ] Upgrade from the previous supported version on a site with real data —
      confirm migrations ran and `memberistic_db_version` advanced
- [ ] Confirm a fresh activation makes **no outbound HTTP request**
- [ ] Confirm no priced plans are seeded on fresh install
- [ ] Uninstall behaviour matches the retention setting, in both states
- [ ] Tested against the current stable WordPress and the current stable
      WooCommerce (see the matrix in `../strategy/03-quality-and-release.md`)
- [ ] Multisite: activate, uninstall, privacy export/erase

### Artifact

- [ ] Zip built from the tag, not from a working tree
- [ ] Zip contains no `tests/`, `vendor/`, `.github/`, `composer.*`,
      `phpunit.xml`
- [ ] Zip smoke-installed on a clean WordPress
- [ ] SHA-256 published
- [ ] Changelog and, where relevant, migration notes published
- [ ] Rollback plan written down (below)

## 5. Merge and tag

```bash
git checkout main && git merge --no-ff release/2.0.1
git tag v2.0.1
git push origin main
git push origin v2.0.1
```

Pushing the `v*` tag triggers `.github/workflows/release.yml`, which re-verifies
the five version sites against the tag, lints, builds the zip from
`.distignore`, asserts no dev files leaked in, computes the SHA-256, and opens a
**draft** GitHub Release with both files attached.

> **Environment note.** Tag pushes are blocked by the network proxy in some
> automated agent sessions (HTTP 403). If `git push origin v2.0.1` fails that
> way, create the tag from a normal environment or through the GitHub UI — the
> commit itself is already pushed, so nothing is lost.

## 6. Publish

Review the draft release: read the notes, download the zip, install it on a
clean site one more time, then publish. Publishing is a human decision, which
is why the workflow only ever drafts.

## 7. After publishing

- Merge `release/2.0.1` back into `main` if it received fixes
- Announce in the changelog surfaces that matter (readme.txt, site, docs)
- Open follow-up issues for anything deferred during stabilisation

## Rollback

Every release needs an answer to "what if this is wrong" *before* it ships.

1. **Code:** site owners reinstall the previous zip. Keep the previous release
   downloadable — never delete old release artifacts.
2. **Schema:** migrations are forward-only. A release that changes the schema
   must state in its notes whether the previous version can still read the new
   schema. If it cannot, that is a MAJOR and the notes must say "restore from
   backup to roll back."
3. **Payments:** never roll back through a window where webhooks were processed
   by the new version and would be reprocessed by the old one. Check
   idempotency keys before advising a downgrade.
4. **Communication:** a yanked release gets a changelog entry saying it was
   yanked and why. Silent removal is worse than the bug.
