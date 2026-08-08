# Versioning

## Scheme

Semantic versioning, read through a WordPress-plugin lens.

| Part | Bump when |
|---|---|
| **MAJOR** | A site owner has to do something on upgrade: a removed hook or shortcode, a changed entitlement outcome, a raised PHP/WordPress floor, a data shape that old code cannot read. |
| **MINOR** | New capability, backward compatible. New hooks, new REST routes, new admin screens, new optional integrations. |
| **PATCH** | Fixes and compatibility work with no new surface. |

Two rules that override the literal reading:

- **A security fix ships as a patch on the supported line**, even if the fix
  changes behaviour. Members losing access they should never have had is a fix,
  not a breaking change.
- **A silent change to who can see what is a MAJOR**, however small the diff.
  Access decisions are the product.

## The two independent numbers

**`MEMBERISTIC_VERSION`** is the plugin version. It changes every release.

**`MEMBERISTIC_DB_VERSION`** (currently `1.11.0`) is the schema version. It
changes *only* when the schema changes, and it moves on its own cadence.
Bumping it without registering a matching migration in
`Migrations::migrations()` leaves sites stuck re-running the upgrade path on
every request.

## The five places the plugin version lives

Nothing in CI catches a mismatch except the release workflow, and that only
runs on a tag. All five change together:

1. `Version:` header in `memberistic-membership-solutions.php`
2. `MEMBERISTIC_VERSION` constant in the same file
3. `Stable tag:` in `readme.txt` — plus its `== Changelog ==` entry
4. `**Version:**` in `README.md`
5. A new section in `CHANGELOG.md`, Keep a Changelog format

`.github/workflows/release.yml` verifies 1, 2, 3 and the changelog entry
against the git tag and fails the release on a mismatch.

## Tags

Release tags are `vMAJOR.MINOR.PATCH` — `v2.0.1`. The tag is immutable: a
mistake gets a new patch version, never a moved tag.

> **Historical note.** The 2.0.0 release predates this convention and is tagged
> `public_release_v_2.0.0`. It is kept as-is because moving a published tag
> breaks anyone who pinned it. Everything from 2.0.1 onward uses `v*`.

## Deprecation

Deprecated things survive at least one MINOR release before removal:

1. Keep the old hook/function/route working.
2. Call `_deprecated_function()` / `_deprecated_hook()` so it appears in debug
   logs.
3. Document the replacement in `docs/HOOKS.md` and `CHANGELOG.md`.
4. Remove only in a MAJOR, listed in the upgrade notes.

## Supported versions

Only the latest patch of the current minor line receives fixes. See
`SECURITY.md` for the security-support table.

## Compatibility floors

`Requires PHP` and `Requires at least` are floors, enforced twice — in the
plugin headers (which WordPress checks at install/update) and at runtime in the
bootstrap file (which catches a site that downgraded PHP or was updated over
FTP). Raising either is a MAJOR bump and needs an entry in the upgrade notes.

`Tested up to` is not a floor and is not a promise of anything except that the
matrix in `docs/strategy/03-quality-and-release.md` actually passed. Do not
raise it because a release came out — raise it because the tests ran.
