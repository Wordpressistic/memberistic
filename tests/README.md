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

---

## Integration tests (real WordPress, real database)

The integration suite in `tests/integration/` boots an actual WordPress and
activates the plugin against a real MySQL database. It is what proves the
things the unit suite structurally cannot: routes, capabilities, activation,
schema, cron registration and webhook handling.

### The PHPUnit 9.6 constraint

**The integration suite runs on PHPUnit 9.6, not the 10.5 the unit suite uses,
and this is forced by WordPress rather than chosen.** The WP core test library
calls `PHPUnit\Util\Test::parseTestMethodAnnotations()`, which PHPUnit 10
removed. Under 10.x every integration test errors with "Call to undefined
method" before its first assertion — verified on WordPress 6.8, 6.9, 7.0.3 and
trunk, so it is not a matter of picking a newer WordPress branch.

Consequence: the two suites cannot share one config, because they cannot share
a PHPUnit major. CI installs 9.6 over the top for the integration job only; the
unit suite keeps 10.5 from `composer.lock`. Anything added to
`tests/integration/` must be PHPUnit 9-compatible. Re-check when WordPress
adopts PHPUnit 10 in core.

### Run it locally

Needs a MySQL/MariaDB server you are willing to let the suite drop and recreate
a database on.

```bash
# 1. Install WordPress core + the test library (once per WP version)
bin/install-wp-tests.sh wordpress_test root '' 127.0.0.1:3306 7.0.3

# 2. Install the PHPUnit 9 stack for this suite
composer require --dev --with-all-dependencies \
  'phpunit/phpunit:^9.6' 'yoast/phpunit-polyfills:^1.1'

# 3. Run
WP_TESTS_DIR=/tmp/wordpress-tests-lib vendor/bin/phpunit -c phpunit-integration.xml --testdox
```

Step 2 rewrites `composer.json`/`composer.lock` in your working copy. Run
`git checkout composer.json composer.lock && composer install` to get back to
the PHPUnit 10 stack the unit suite needs.

The two suites are independent — `vendor/bin/phpunit -c phpunit.xml` still runs
without WordPress, a database, or any network access.

### Adding a fixture

Use `Memberistic_Record_Factory` (`tests/integration/class-memberistic-record-factory.php`)
rather than writing `$wpdb->insert()` by hand:

```php
$member = Memberistic_Record_Factory::member();          // user + plan + membership
$person = Memberistic_Record_Factory::person( $member['membership_id'] );
$note   = Memberistic_Record_Factory::note( $member['membership_id'], $staff_id );

// Any column can be overridden.
$expired = Memberistic_Record_Factory::membership(
    $plan_id,
    $user_id,
    array( 'status' => 'expired', 'renewal_date' => '2020-01-01 00:00:00' )
);
```

Two reasons to prefer it over a hand-rolled insert:

1. **It throws on a failed insert.** `$wpdb->insert()` returns `false` on a
   column mismatch rather than raising. An unchecked failure leaves
   `insert_id` at `0`, the test then addresses `/memberships/0/…`, gets a 404 —
   and a "cannot read another member's data" assertion passes for entirely the
   wrong reason.
2. **It knows the NOT NULL columns.** `memberships` needs `membership_uuid` and
   `billing_cycle`; `checkins` needs `person_id` and `checked_in_at`; `notes`
   needs `note` and `created_by`. Omitting any of them is case 1.

No teardown is needed. `WP_UnitTestCase` wraps each test in a transaction and
rolls it back, and fixture rows are written inside it. The plugin's tables
survive because they are created before the suite starts — see
`tests/integration/bootstrap.php`.

### Asserting no outbound HTTP

`Memberistic_Integration_TestCase` provides the interception layer. Arm it, do
the thing, assert:

```php
$this->block_and_record_http();
Activator::activate();
$this->assertNoOutboundHttp( 'Activation must not phone home.' );
```

`block_and_record_http()` filters `pre_http_request` at `-PHP_INT_MAX`, which
short-circuits `WP_Http` before any transport runs. It both records the attempt
and guarantees the suite itself never touches the network, so a misconfigured
integration cannot quietly call a third party mid-run.
