=== Memberistic Membership Solutions ===
Contributors: wordpressistic
Tags: membership, members, waivers, check-in, subscriptions
Requires at least: 6.8
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: 2.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Membership operations for service businesses: plans, members, linked family accounts, digital waivers, check-ins, payments and staff workflows.

== Description ==

Memberistic runs the operational side of a membership business — who is a member, what they pay for, who else is on their account, whether their waiver is current, and whether they are allowed through the door today.

It stores members, plans, people, payments, check-ins, and waivers in dedicated database tables rather than bending posts and post meta to the job, so member queries stay fast on a site with tens of thousands of records and nothing collides with the rest of the site's content.

**Built for**

Gyms and fitness studios, yoga and dance studios, social and sports clubs, professional associations, climbing walls, makerspaces, shooting ranges — anywhere people pay for ongoing access and staff verify that access at a counter.

It is a poor fit for content-only paywalls. If all you need is "subscribers can read this post", a content-restriction plugin will be simpler.

**Members and plans**

* Unlimited plans with monthly and annual pricing and per-plan included-people limits
* Linked family and additional members under one membership, each with their own profile, waiver, and check-in history
* Statuses covering active, trial, comped, past due, paused, suspended, expired, cancelled, and needs-review
* Notes, activity timeline, bulk actions, and saved views

**Waivers**

* Digital signing with tokenised member links, guest forms, and a kiosk mode
* Waiver versioning with re-consent when your terms change
* Expiry tracking and automated follow-up reminders
* Immutable signature archive and a separate document store
* Import for historical waivers from a previous system

**Check-in**

* Front-desk staff dashboard with member lookup
* Self-service kiosk page
* QR verification on the member's digital card

**Payments**

* Stripe Checkout for subscriptions, with a hosted billing portal for members
* Signature-verified webhooks with replay protection and idempotent handling
* WooCommerce order sync as an alternative path, HPOS compatible
* Manual and counter payment recording
* Per-plan WooCommerce member discounts

**Email**

* Transactional templates covering the whole membership lifecycle
* Merge tags, per-template overrides, and a delivery log
* HTML layout that picks up your site's own logo and colours

**What it does not do on install**

A fresh activation creates no membership plans, enables no integration, and makes no outbound network request. Plans are yours to define; the plugin bundles importable templates for several industries as a starting point.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install the zip via Plugins > Add New > Upload Plugin.
2. Activate the plugin.
3. Go to Memberistic > Settings and set your business name, currency, and email sender details.
4. Go to Memberistic > Plans and create your first plan, or start from one of the templates in `templates/plans/`.
5. Add `[memberistic_plans]`, `[memberistic_account]`, and `[memberistic_checkout]` to pages.
6. Assign the Memberistic Staff or Manager role to counter staff and point them at `[memberistic_staff_dashboard]`.

Requires WordPress 6.8+ and PHP 8.2+. If either floor is not met the plugin shows an admin notice and stays inactive rather than causing a fatal error.

== Frequently Asked Questions ==

= Does it create any plans for me? =

No. The Plans screen starts empty on purpose — a plan's name, price, and inclusions are commercial decisions specific to your business. The plugin bundles example plan sets for gyms, studios, clubs, associations, and ranges in `templates/plans/`. They import as inactive so you can review the pricing before anything goes on sale.

= Do I need WooCommerce? =

No. WooCommerce is an optional integration and is disabled by default. Stripe Checkout works standalone.

= Does it work with block themes? =

Yes. Frontend output is rendered through shortcodes and templates that work in both block and classic themes.

= Can I restyle the member-facing pages? =

Yes, two ways. Copy any file from the plugin's `templates/` directory into a `memberistic/` directory in your theme to replace it outright. Or override the `--memberistic-*` CSS custom properties — each falls back to a neutral default but prefers a matching design token from your theme, so a theme that publishes its own colour scale gets Memberistic blended into it automatically.

= Does it support multisite? =

Yes. Each site has its own tables, settings, and roles. Uninstall cleans up per site, so a network delete does not orphan tables on subsites.

= What happens to my data if I deactivate or delete the plugin? =

Deactivation clears scheduled events and leaves all data intact. Deleting the plugin also leaves data intact, unless you first tick "Delete all data on uninstall" in Settings. That setting is off by default.

= Does the plugin phone home? =

No. There is no telemetry, no analytics, no update check, and no licence check. The only outbound requests are to services you explicitly enable and configure — see Third-Party Services below.

= Are signed waivers deleted by a GDPR erasure request? =

Not by default. A signed waiver is the record of what a named person agreed to and when; erasing the name destroys the evidence it exists to provide, so waivers are retained and reported as retained, with the reason. The `memberistic_privacy_erase_waivers` filter changes this if your retention basis does not apply.

= I'm upgrading from 1.x. Is there a data migration? =

No. Table names, option keys, meta keys, hooks, capabilities, and the REST namespace are all unchanged, so nothing is transformed and there is nothing to roll back. Two defaults changed — see `docs/UPGRADE-2.0.md`.

== Screenshots ==

1. Dashboard with revenue history, expiring memberships, and recent activity.
2. Plans screen with per-plan pricing, capacity, and benefits.
3. Member detail with linked people, payments, waivers, and activity timeline.
4. Front-desk staff dashboard for check-ins and member lookup.
5. Member account area with the digital membership card.
6. Waiver signing on the kiosk surface.
7. Integrations screen, everything off by default.
8. Settings, including data retention and uninstall behaviour.

== Third-Party Services ==

Memberistic makes no outbound request on its own. The services below are used only when you enable and configure the matching integration; each is disabled by default.

**Stripe** — payment processing. When Stripe is enabled and keys are configured, checkout sends the member's name, email, plan name, amount, and currency to Stripe, and Stripe sends webhook events back. Card details pass from the customer's browser directly to Stripe and are never seen or stored by this plugin. Terms: https://stripe.com/legal — Privacy: https://stripe.com/privacy

**coreSTORE (Coreware)** — point-of-sale synchronisation. When enabled and an API key is saved, membership state (name, email, plan, status, expiry, and a price-tier identifier) is sent to your coreSTORE account on membership activation, expiry, status change, and payment, plus a daily reconcile, so the till can apply member pricing. Contact Coreware for their current terms and privacy policy.

**Messageistic** — SMS notifications. When enabled, the member's phone number and the notification text are passed to the Messageistic plugin, which delivers them through the SMS gateway you configure there.

**Verifyistic** — age and ID verification. Runs on your own server and transmits nothing off-site by itself. Any verification provider you configure inside Verifyistic is governed by that provider's terms.

No other data leaves your site.

== Privacy ==

Memberistic stores personal data: member names, email addresses, phone numbers, dates of birth, signed waivers (including the signer's IP address), check-in history, and payment references. No card details are stored.

It registers exporter and eraser callbacks with WordPress core, so Tools > Export Personal Data and Tools > Erase Personal Data cover Memberistic records. It also registers suggested privacy-policy text with the core policy editor.

Erasure anonymises records in place rather than deleting rows. Signed waivers and payment records are retained by default — waivers under a legal-claims basis, payment records under statutory financial retention — and every retained record is reported back with its reason rather than silently kept.

Retention windows for check-in and activity history are configurable and default to keeping data indefinitely, so no update ever starts deleting your history on its own.

== Upgrade Notice ==

= 2.0.0 =
Requires PHP 8.2 and WordPress 6.8. No data migration. New installs ship no default plans, and every third-party integration defaults to off. The booking integration is restored automatically; the included-plans entitlement list needs your decision. See docs/UPGRADE-2.0.md.

== Changelog ==

= 2.0.0 =

**Release focus: brand-neutral packaging, security and privacy hardening, and safe defaults. No new features.**

Added:
* GDPR exporter and eraser registered with WordPress core, plus suggested privacy-policy text
* Configurable data-retention windows for check-in and activity history, defaulting to keep-indefinitely
* Complete uninstall routine covering every table, option, transient, user meta key, cron event, role, capability, and generated page — still opt-in and still off by default, and multisite-aware
* Theme template overrides via a `memberistic/` directory in the theme, plus a `memberistic_locate_template` filter with path validation
* Importable plan template library for gyms, studios, clubs, associations, ranges, and a generic tiered set
* Booking and POS adapters, so any booking or POS plugin can be mapped with a filter instead of the integration being hard-wired to one
* Requirements gate that shows an admin notice instead of causing a fatal error on unsupported PHP or WordPress
* WooCommerce HPOS and cart/checkout blocks compatibility declarations
* Licensing extension points for a future licence add-on — contract only, no phone-home
* `languages/memberistic.pot` with 1500 strings

Changed:
* Requires PHP 8.2 (was 8.0) and WordPress 6.8. PHP 8.0 and 8.1 are past end of security support
* New installs seed no membership plans
* Every integration that touches a third-party plugin or off-site service now defaults to off
* Frontend design tokens now fall back to a neutral, contrast-checked palette instead of depending on one specific theme's tokens
* Email header logo resolves through the logo setting, then the site's Custom Logo, then the Site Icon
* Checkout failure messages name the configured business, or fall back to generic support wording
* Member card ID prefix is now `MEM` and is filterable — display only, not a lookup key

Removed:
* All partner branding from code, comments, templates, assets, and documentation
* A hotlinked background image loaded from an external domain
* Internal audit and incident documents, and a workflow that synced this repository to a private monorepo

Security:
* Verified every REST route has a real capability check — no route uses `__return_true`
* Confirmed Stripe webhook signature verification uses a timing-safe comparison with a 300-second replay window

== Arbitrary section ==

= Developers =

Hook reference: `docs/HOOKS.md`. Integration matrix and adapter contracts: `docs/INTEGRATIONS.md`. Upgrade notes: `docs/UPGRADE-2.0.md`.

REST namespace is `memberistic/v1`. All plugin code lives under the `WordPressistic\Memberistic` namespace, global functions are prefixed `memberistic_`, constants `MEMBERISTIC_`, and tables `{$wpdb->prefix}memberistic_`.
