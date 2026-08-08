# memberistic-membership-solutions tests

## Unit tests (PHPUnit 10, no WordPress required)

The unit suite in `tests/unit/` runs against plain PHP 8.1+ — `tests/bootstrap.php`
stubs the WordPress functions the tested code uses (filters/options/sanitizers)
and replaces the `WordPressistic\Memberistic\Database` repository classes with
configurable static fixtures BEFORE the service under test is loaded. No
database, no live WordPress.

Covered — `WordPressistic\Memberistic\Integrations\Entitlement_Service`:

- `included_plan_slugs()` defaulting to empty, and the
  guarantee that `guest-pass`/`range-guest` are stripped even when injected via
  the option or the filter.
- `eligible_statuses()` defaults (`active`, `comped`).
- `resolve_for_user()` — anonymous users, missing memberships, primary members,
  guest-pass plans, ineligible statuses (trial/past_due/expired), expired
  renewal dates, linked/family people (active and inactive).
- `filter_lane_entitlement()` bridge semantics (never downgrades an earlier
  eligible result; answers authoritatively when eligible).

### Run

```bash
composer install          # installs phpunit/phpunit ^10.5 (dev only)
composer test             # = vendor/bin/phpunit -c phpunit.xml
```

Or with a phar (note: `phar.phpunit.de` may be blocked by an egress proxy in
sandboxed environments — Composer/Packagist is the reliable path):

```bash
curl -sSLo tests/bin/phpunit-10.phar https://phar.phpunit.de/phpunit-10.phar
php tests/bin/phpunit-10.phar -c phpunit.xml
```
