# Upgrading to Memberistic 2.0.0

**Short version:** there is no data migration. Your database is untouched by
this upgrade. What changes is defaults, naming of a few presentation-level
things, and the requirement floors.

---

## What does NOT change

This is the important part, because a major version bump usually implies
otherwise. Memberistic 1.x already used the final naming throughout, so 2.0.0
changes none of it:

| Surface | Status |
|---|---|
| Database table names | **Unchanged** — already `{prefix}memberistic_*` |
| Option keys | **Unchanged** — already `memberistic_*` |
| User meta keys | **Unchanged** |
| PHP namespace | **Unchanged** — already `WordPressistic\Memberistic` |
| Text domain | **Unchanged** — already `memberistic` |
| REST namespace | **Unchanged** — `memberistic/v1` |
| Cron hook names | **Unchanged** |
| Capabilities and roles | **Unchanged** |
| Every `memberistic_*` action and filter | **Unchanged** |

No table is renamed, copied, or dropped. No option is migrated. No serialized
data is rewritten. There is no upgrade routine to run and nothing to roll back,
because nothing is transformed.

---

## Before you upgrade

Take a database backup anyway. That advice is not conditional on this
changelog being right.

Then check three things:

1. **PHP 8.2 or newer.** 8.0 and 8.1 are both past end of security support and
   are no longer supported. If your host is on an older version, the plugin
   will not boot — it shows an admin notice and stays inactive rather than
   fataling, so the site keeps working, but Memberistic will be off until PHP
   is updated.
2. **WordPress 6.8 or newer.** Same handling.
3. **Whether you rely on any of the changes below.**

---

## What changes

### 1. Requirement floors

`Requires PHP: 8.2`, `Requires at least: 6.8`. Enforced at load, not just at
install time, so a later PHP downgrade is caught too.

### 2. No default plans on new installs

`Plans_Repository::seed_default_plans()` no longer ships built-in plans. **This
only affects fresh installs** — existing plans in your database are untouched,
including their slugs, prices, and attached memberships.

If you provision new sites and relied on the old seed, apply one of the
bundled templates in `templates/plans/` via `memberistic_default_plans`.

### 3. Booking and POS integrations now go through an adapter

Previously the booking and POS integrations hard-coded a specific plugin's
hook, table, and CSS names. They now resolve through
`Booking_Adapter` / `POS_Bridge::adapter()`.

**If you run the same booking or POS plugin as before, nothing changes.** Both
bundled presets auto-detect that plugin and map exactly the same names. You do
not need to configure anything.

If you run a different one, map it with `memberistic_booking_adapter` or
`memberistic_pos_adapter` — see [`INTEGRATIONS.md`](INTEGRATIONS.md).

**Renamed internal methods** on `Booking_Engine` (only relevant if your own
code called them directly, which is unlikely):

| 1.x | 2.0.0 |
|---|---|
| `g2ab_user_is_member()` | `adapter_user_is_member()` |
| `g2ab_booking_pricing()` | `adapter_booking_pricing()` |
| `g2ab_booking_created()` | `adapter_booking_created()` |
| `g2ab_payment_succeeded()` | `adapter_payment_succeeded()` |

The hooks these are attached to are unchanged, so nothing that hooks
*Memberistic* needs updating.

### 4. Integration defaults — handled for you

The Booking Engine integration previously defaulted to **on**. It now defaults
to **off**, like every other third-party integration.

**The upgrade handles this.** A default only applies when nothing was ever
saved, so an install that never opened the Integrations screen would have had
its booking integration silently switched off. The upgrade routine writes
`integration_booking_enabled = yes` explicitly for any site that has no stored
value, restoring exactly the previous behaviour. Re-enabling an integration
the site was already using cannot grant anything that was not already granted.

Runs once, and only on upgrade — never on a fresh install.

### 5. Entitlement allowlist — needs your decision ⚠️

`memberistic_lane_included_plan_slugs` decides which plans include bookings at
no charge. It previously defaulted to a built-in set of plan slugs; it now
defaults to empty.

**If you had this option saved, your value is used and nothing changes.**

**If you did not**, the upgrade deliberately does *not* guess. You will see an
admin notice asking you to choose. Until you do, members are charged the
standard booking price.

That is a conscious choice to fail closed. The old built-in list was a set of
slugs that only ever matched one plan catalogue: on a site whose plans share
those slugs, writing them back restores the old behaviour, but on a site where
they do not, it either does nothing or — if widened to "every active plan" —
starts giving away paid inventory. An entitlement should never be widened by
guesswork. Members being charged for something that ought to be included gets
reported the same day; free bookings quietly granted to the wrong plan show up
in the accounts months later.

To set it, either save the option, or filter it:

```php
add_filter( 'memberistic_lane_included_plan_slugs', function () {
    return array( 'individual', 'couple', 'family' ); // your own plan slugs
} );
```

Memberships, payments, waivers, and check-ins are unaffected either way.

### 6. Member card ID prefix

The digital member card's ID changed from `G2A-2026-0042` to `MEM-2026-0042`.

This is a **display label only**. It is never stored, never used as a lookup
key, and check-in and QR verification resolve on the membership UUID, not this
string. Nothing breaks. To keep the old prefix:

```php
add_filter( 'memberistic_member_id_prefix', function () {
    return 'G2A';
} );
```

### 7. Frontend styling now has its own fallbacks

`assets/token-bridge.css` previously mapped `--memberistic-*` straight onto one
theme's design tokens with no fallbacks, so on any other theme the frontend
rendered unstyled. Every token now falls back to a neutral, contrast-checked
palette while still preferring a matching theme token where one exists.

**On a theme that publishes those tokens, appearance is unchanged.** On any
other theme, the frontend now renders correctly instead of broken.

The corporate module's CSS classes were renamed from `.g2a-corp-*` to
`.memberistic-corp-*` and its custom properties from `--g2a-*` to
`--memberistic-corp-*`. If you wrote custom CSS targeting those, update the
selectors.

### 8. Email logo resolution

The email header logo previously fell back to a hard-coded path inside one
specific theme. It now resolves: the `logo_url` setting → the site's Custom
Logo → the Site Icon → the `memberistic_email_logo_url` filter → no logo (site
name as text).

If your logo was coming from that theme path, set it explicitly under
**Settings → Email**, or set a Custom Logo in the Customizer.

### 9. Guest-pass audit user meta key

`Guest_Pass_Audit_Command` now writes `_memberistic_customer_segment` =
`auto_created_guest` instead of the previous third-party-namespaced key.

Rows tagged by a previous run keep the old key. If you filter or report on it,
either update your query or re-run the command.

### 10. Removed

- The workflow that synced this repository to a private monorepo.
- `docs/PARTNERS.md`, `docs/AUDIT_REPORT.md`, and the checkout incident
  postmortem — internal documents, not product documentation.

---

## After upgrading

1. Load `/wp-admin/` and confirm no admin notice about PHP or WordPress
   versions.
2. **Memberistic → Plans** — your plans are all still there.
3. **Memberistic → Integrations** — confirm the toggles match what you expect,
   particularly Booking Engine (see §4).
4. Open the member account page on the frontend and check it renders.
5. If you use Stripe, confirm a test webhook still verifies.

## If something is wrong

Deactivating 2.0.0 and reinstalling 1.20.0 is safe: no schema or option
changes were made, so 1.20.0 finds the database exactly as it left it.
