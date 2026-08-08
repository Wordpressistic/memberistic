## What this changes

<!-- One paragraph: the behaviour before, the behaviour after, and why. -->

Closes #

## Type

- [ ] Bug fix
- [ ] Feature
- [ ] Refactor (no behaviour change)
- [ ] Documentation
- [ ] Security fix
- [ ] Release/CI plumbing

## Rules touched

Tick only what this PR actually goes near. If you tick one, explain below how
the rule still holds. These are the promises in `CONTRIBUTING.md` and are
enforced by `tests/unit/`.

- [ ] PMPro runtime independence
- [ ] No seeded/priced plans on fresh install
- [ ] Entitlements fail closed
- [ ] A lapsed licence never breaks member access
- [ ] No outbound HTTP on fresh activation
- [ ] Every REST route has a real `permission_callback`
- [ ] None of the above

<!-- Explanation: -->

## Multi-edit checklist

- [ ] New class files added to the `require_once` list in
      `Plugin::load_dependencies()` — nothing autoloads
- [ ] Database change made in all three places (schema, migration,
      `MEMBERISTIC_DB_VERSION` + `Migrations::migrations()`), and the migration
      is idempotent and returns `true`
- [ ] New shortcode registered in `Plugin::enqueue_frontend_assets()` (and in
      `send_sensitive_page_cache_headers()` if it renders member data)
- [ ] New capability added to `Capabilities::get_all()` **and** granted to
      `administrator`
- [ ] Version bumped in all five places (see
      `docs/governance/RELEASE-PROCESS.md`) — release PRs only
- [ ] N/A

## Documentation

- [ ] `docs/HOOKS.md` — new/changed action or filter
- [ ] `docs/INTEGRATIONS.md` — new/changed integration or adapter
- [ ] `docs/entitlements.md` — entitlement rules
- [ ] `README.md` tables — new shortcode or REST route
- [ ] `CHANGELOG.md` (+ `readme.txt` if release-worthy) — user-visible change
- [ ] An ADR in `docs/governance/decisions/` — decision worth remembering
- [ ] No docs needed, because:

## What I ran

Paste real output, not ticks.

```
$ find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l

$ find assets -name '*.js' -print0 | xargs -0 -n1 node --check

$ vendor/bin/phpunit -c phpunit.xml

```

## What I did NOT test

<!-- Be specific and honest. "Stripe webhooks — no test-mode account in this
     environment" is a useful answer. An empty section is not. -->

## Risk and rollback

- Blast radius if this is wrong:
- How to roll it back:
- Does it need a data migration to reverse? yes / no
