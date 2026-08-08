# Memberistic Membership Solutions

A membership operations engine for service businesses running on WordPress.

Plans, members, linked family accounts, digital waivers, check-ins, payments,
and staff workflows — in one admin, backed by its own database tables rather
than bent out of posts and post meta.

- **Version:** 2.0.0
- **Requires:** WordPress 6.8+, PHP 8.2+
- **Licence:** GPL-2.0-or-later
- **Author:** [WordPressistic](https://www.wordpressistic.com)

---

## Contents

1. [What Memberistic is](#what-memberistic-is)
2. [Who it is for](#who-it-is-for)
3. [Features](#features)
4. [Requirements](#requirements)
5. [Installation](#installation)
6. [Getting started](#getting-started)
7. [Shortcodes](#shortcodes)
8. [Developer hooks](#developer-hooks)
9. [REST API](#rest-api)
10. [Template overrides](#template-overrides)
11. [Integrations](#integrations)
12. [Third-party services](#third-party-services)
13. [Privacy and data](#privacy-and-data)
14. [Licensing and support](#licensing-and-support)
15. [Changelog](#changelog)

---

## What Memberistic is

Memberistic runs the operational side of a membership business: who is a
member, what they pay for, who else is on their account, whether their waiver
is current, and whether they are allowed through the door today.

It is built around a few decisions worth knowing up front:

- **Its own tables.** Members, plans, people, payments, check-ins, and waivers
  live in dedicated tables. A membership is not a post, and a member is not a
  post-meta bag — so member queries stay fast on a site with tens of thousands
  of records, and nothing collides with the rest of the site's content.
- **A membership can cover several people.** Plans declare how many people
  they include. Linked family members get their own profile, their own waiver,
  and their own check-in history, under one billing relationship.
- **Waivers are first-class.** Signing, expiry, re-consent on a new waiver
  version, an immutable signature archive, kiosk and guest signing surfaces.
- **Staff-facing, not just member-facing.** A front-desk dashboard for
  check-ins and lookups, alongside the member's own account area.

## Who it is for

Any business where people pay for ongoing access and staff need to verify that
access at a counter: gyms and fitness studios, yoga and dance studios, social
and sports clubs, professional associations, climbing walls, makerspaces,
shooting ranges, and similar.

It is a poor fit for content-only paywalls — if all you need is "subscribers
can read this post", a content-restriction plugin will be simpler.

## Features

**Members and plans**
- Unlimited plans with monthly and annual pricing and per-plan included-people
  limits
- Linked family and additional members under one membership
- Statuses covering active, trial, comped, past due, paused, suspended,
  expired, cancelled, and needs-review
- Notes, activity timeline, and per-member history
- Bulk actions and saved views in the admin

**Waivers and documents**
- Digital waiver signing with tokenised member links, guest forms, and a kiosk
  mode
- Waiver versioning with re-consent when terms change
- Expiry tracking and automated follow-up reminders
- Immutable signature archive with a separate document store
- Import for historical waivers from a previous system

**Check-in**
- Front-desk staff dashboard with member lookup
- Self-service kiosk page
- QR code verification on the member's digital card
- Check-in history per person

**Payments**
- Stripe Checkout for subscriptions, with a hosted billing portal for members
- Signature-verified webhooks with replay protection and idempotent handling
- WooCommerce order sync as an alternative path
- Manual and counter payment recording
- Per-plan WooCommerce member discounts

**Email**
- Transactional templates for the whole membership lifecycle
- Merge tags, per-template overrides, and a delivery log
- HTML layout that picks up the site's own logo and colours

**Admin**
- React-free vanilla JS admin apps built on `wp.element`
- Dashboard with revenue history, expiring memberships, and recent activity
- CSV import for members and payments from a previous system
- Corporate/group memberships with invoicing and payment links

## Requirements

| | |
|---|---|
| WordPress | 6.8 or newer |
| PHP | 8.2 or newer |
| MySQL | 5.7+ / MariaDB 10.3+ |
| WooCommerce | Optional. Any currently supported version. HPOS and cart/checkout blocks are declared compatible. |

PHP 8.0 and 8.1 are both past end of security support and are not supported.
The plugin checks both floors at load and deactivates itself with an admin
notice rather than fataling if they are not met.

## Installation

1. Upload the plugin folder to `wp-content/plugins/`, or install the zip via
   **Plugins → Add New → Upload Plugin**.
2. Activate it.
3. Go to **Memberistic → Settings** and set your business name, currency, and
   email sender details.

On activation the plugin creates its tables, adds its roles, and creates a
single `/check-in/` page. It does **not** create any membership plans, does not
enable any integration, and makes no outbound network request.

## Getting started

1. **Create a plan.** **Memberistic → Plans** starts empty. Add your first
   plan, or start from one of the bundled templates in
   [`templates/plans/`](templates/plans/README.md) (gym, studio, club,
   association, range, or generic tiered). Templates import as *inactive* so
   you can review the pricing before anything goes on sale.
2. **Set up payments.** **Settings → Payments**, add your Stripe test keys
   first. Stripe starts disabled and in test mode. Add the webhook endpoint
   (`/wp-json/memberistic/v1/webhooks/stripe`) and paste the signing secret —
   without it, webhooks are rejected.
3. **Write your waiver.** **Memberistic → Waivers**, set the text and the
   validity period.
4. **Publish the member pages.** Add `[memberistic_plans]`,
   `[memberistic_account]`, and `[memberistic_checkout]` to pages.
5. **Give staff access.** Assign the Memberistic Staff or Manager role to the
   people who work the counter, and point them at `[memberistic_staff_dashboard]`.
6. **Go live.** Switch Stripe to live mode and swap in your live keys.

## Shortcodes

**Member-facing**

| Shortcode | Renders |
|---|---|
| `[memberistic_plans]` | Plan grid with a monthly/annual toggle |
| `[memberistic_plan]` | A single plan card |
| `[memberistic_checkout]` | Checkout form |
| `[memberistic_account]` | Member dashboard — membership, billing, people, waivers, digital card |
| `[memberistic_login]` | Login, lost password, and password reset |
| `[memberistic_renewal]` | Renewal prompt |
| `[memberistic_thank_you]` | Post-purchase confirmation |
| `[memberistic_payment_failed]` | Failed-payment recovery |
| `[memberistic_payment_history]` | The member's payments |
| `[memberistic_booking_history]` | The member's bookings, when a booking engine is mapped |
| `[memberistic_people]` | Linked/additional members management |
| `[memberistic_profile_summary]` | Compact profile block |
| `[memberistic_status]` | Current membership status |
| `[memberistic_expiring_notice]` | Notice shown when a membership is near expiry |

**Staff and public terminals**

| Shortcode | Renders |
|---|---|
| `[memberistic_staff_dashboard]` | Front-desk dashboard |
| `[memberistic_frontdesk]` | Compact check-in lookup |
| `[memberistic_kiosk]` | Self-service check-in kiosk |
| `[memberistic_guest_waiver]` | Guest waiver form |
| `[memberistic_guest_pass]` | Guest pass issuance |
| `[memberistic_group_portal]` | Corporate/group member portal |

## Developer hooks

Full reference: [`docs/HOOKS.md`](docs/HOOKS.md).

The ones most people need first:

```php
// Seed plans on first install (the plugin ships none).
add_filter( 'memberistic_default_plans', function ( $plans ) { /* ... */ } );

// Change the customer-facing business name used in emails, waivers, and PDFs.
add_filter( 'memberistic_brand_label', function ( $label ) { /* ... */ } );

// Connect a booking plugin.
add_filter( 'memberistic_booking_adapter', function () { /* ... */ } );

// Connect a point-of-sale plugin.
add_filter( 'memberistic_pos_adapter', function () { /* ... */ } );

// React to lifecycle events.
add_action( 'memberistic_membership_activated', function ( $membership_id ) { /* ... */ } );
```

## REST API

Namespace: `memberistic/v1`. Every route has a real capability check —
there is no `permission_callback => __return_true` anywhere in the plugin.
Authenticated requests from the admin need the `X-WP-Nonce` header.

Main route groups:

| Group | Routes |
|---|---|
| Plans | `/plans`, `/plans/{id}`, `/plans/stats` |
| Memberships | `/memberships`, `/memberships/{id}`, `/memberships/stats`, `/memberships/bulk-waiver` |
| Membership detail | `/memberships/{id}/people`, `/payments`, `/checkins`, `/notes`, `/activity`, `/emails`, `/bookings` |
| People | `/people/{id}` |
| Payments | `/payments`, `/payments/stats` |
| Check-ins | `/checkins` |
| Activity | `/activity`, `/activity/types` |
| Dashboard | `/dashboard/stats`, `/dashboard/revenue-history`, `/dashboard/expiring-soon`, `/dashboard/recent-activity` |
| Email | `/email-templates`, `/emails/stats`, `/emails/directory` |
| Settings | `/settings`, `/settings/pages`, `/settings/pages-options` |
| Saved views | `/saved-views`, `/saved-views/{id}` |
| Webhooks | `/webhooks/stripe`, `/webhooks/woocommerce` |

Webhook routes are necessarily public but are not unauthenticated: Stripe
requests are verified against the endpoint signing secret with a timing-safe
comparison and a 300-second replay window, and WooCommerce requests against an
HMAC shared secret. Unsigned or stale requests are rejected before the payload
is parsed.

## Template overrides

Copy any file from the plugin's `templates/` directory into a `memberistic/`
directory in your theme, keeping the filename:

```
your-theme/memberistic/account.php
your-theme/memberistic/plans-grid.php
your-theme/memberistic/checkout.php
your-theme/memberistic/staff-dashboard.php
```

A child theme wins over a parent theme, which wins over the plugin. To resolve
templates from somewhere else entirely, filter the resolved path:

```php
add_filter( 'memberistic_locate_template', function ( $path, $template ) {
    return $path;
}, 10, 2 );
```

Styling is driven by the `--memberistic-*` custom properties defined in
`assets/token-bridge.css`. Each one falls back to a neutral default but
prefers a matching design token from the active theme, so a theme that
publishes its own `--color-*` scale gets Memberistic blended into it without
a plugin edit. Define any `--memberistic-*` property on `:root` in your theme
to override outright.

## Integrations

Every integration that touches a third-party plugin or an off-site service is
**disabled by default** and must be turned on at **Memberistic → Integrations**.
A fresh install talks to nothing.

Full matrix, including exactly what each one transmits:
[`docs/INTEGRATIONS.md`](docs/INTEGRATIONS.md).

| Integration | Type | Default |
|---|---|---|
| Stripe Checkout | Off-site payments | Off, test mode |
| WooCommerce | Local plugin | Off |
| Booking Engine | Local plugin, via adapter | Off |
| POS Bridge | Local plugin, via adapter | Off |
| coreSTORE (Coreware) | Off-site API | Off |
| Verifyistic | Local plugin | Off |
| Messageistic SMS | Local plugin | Off |
| Advanced FFL Checkout | Local plugin, firearms retail only | Off |
| Email Automation | Built-in, uses `wp_mail` | On |
| Waiver Manager | Built-in | On |

The last two are on by default because neither sends anything off-site and
turning them off would stop members receiving their own account notices.

## Third-party services

Enabling certain integrations sends data to services outside your site. Each
is opt-in and off until you enable and configure it.

**Stripe** — payment processing. When enabled, checkout sends the member's
name, email, and the plan and amount to Stripe, and Stripe sends webhooks back.
Card details go directly from the customer's browser to Stripe; this plugin
never sees or stores them.
[Terms](https://stripe.com/legal) · [Privacy](https://stripe.com/privacy)

**coreSTORE (Coreware)** — point-of-sale sync. When enabled and configured
with an API key, membership state (name, email, plan, status, expiry, and a
price tier) is pushed to your coreSTORE account so the till can apply member
pricing. Contact Coreware for their current terms and privacy policy.

**Verifyistic** — age/ID verification. Runs on your own site; it transmits
nothing off-site by itself. Whatever verification provider you configure
inside Verifyistic is governed by that provider's terms.

**Messageistic** — SMS. When enabled, membership notification text and the
member's phone number are passed to Messageistic, which sends them via the
gateway you configure there.

No other integration transmits data off your site. The plugin itself has no
telemetry, no analytics, and no licence phone-home: it makes no outbound
request that you have not switched on and configured.

## Privacy and data

- **GDPR export and erasure** are registered with WordPress core, so
  **Tools → Export/Erase Personal Data** covers Memberistic records.
- **Erasure anonymizes rather than deletes** where a row must survive for
  totals to stay consistent. Signed waivers and payment records are *retained*
  by default and reported as retained, with the reason — a waiver is the
  evidence of what someone agreed to, and payment records carry statutory
  retention. Override waiver retention with
  `memberistic_privacy_erase_waivers`.
- **Suggested privacy-policy text** is registered with core's policy editor.
- **Data retention** windows for check-in and activity history are
  configurable and default to *keep indefinitely*, so no update ever starts
  silently deleting your history.
- **Deactivation keeps everything.** Uninstalling keeps everything too, unless
  you tick "Delete all data on uninstall" in Settings first.

## Licensing and support

Released under the GPL-2.0-or-later. See [`LICENSE`](LICENSE).

Bundled third-party code and its licences:
[`THIRD-PARTY-LICENSES.md`](THIRD-PARTY-LICENSES.md).

2.0.0 ships no licence key system and no auto-update client. The extension
points a licensing add-on would use are defined in
`includes/class-licensing.php`, with the policy such an add-on must follow —
notably that an expired licence must never break an existing member's access
or block a check-in.

Support: <https://memberistic.com/support>

## Changelog

See [`CHANGELOG.md`](CHANGELOG.md). Upgrading from 1.x?
[`docs/UPGRADE-2.0.md`](docs/UPGRADE-2.0.md) covers what changed and what to
check.
