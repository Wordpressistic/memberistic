# Changelog

All notable changes are tracked here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## 2.0.1 - Compatibility and the init fatal (2026-08-08)

First release cut through the automated release pipeline, and the first whose
`Tested up to` is a statement about what was tested rather than what shipped.

### Fixed
- **Fatal error on `init` for every install.** `includes/integrations/class-booking-adapter.php` shipped in 2.0.0 but was never added to the manual require list in `Plugin::load_dependencies()`, and nothing autoloads. `Waiver_Booking_Bridge::register()` calls `Booking_Adapter::hook()` as its first statement and runs on `init` priority 4 whenever the Waiver Manager integration is enabled — and that integration's default is `'yes'`. So a stock install with no configuration at all fatalled on `init` with `Class "WordPressistic\Memberistic\Integrations\Booking_Adapter" not found`. `Booking_Engine`, `POS_Bridge` and `Staff_Dashboard` reach the same class. The file is now required ahead of every consumer.

### Changed
- **`Tested up to` raised from 6.8 to 7.0**, on evidence. The integration matrix exercises WordPress 6.8, 6.9 and 7.0.3 against PHP 8.2, 8.3 and 8.4 — nine jobs, each installing a real WordPress against a real MySQL and running the suite — plus a non-blocking trunk canary. `readme.txt` says 7.0 because wordpress.org accepts only major.minor there. A green canary is never a claim of support for an unreleased version.

### Added
- **WordPress integration test harness** (`bin/install-wp-tests.sh`, `phpunit-integration.xml`, `tests/integration/`). The unit suite stubs WordPress; nothing before this loaded it, which is how the `init` fatal below survived a green CI for the whole life of 2.0.0. Pinned to PHPUnit 9.6 because the WordPress core test library still calls `PHPUnit\Util\Test::parseTestMethodAnnotations()`, removed in PHPUnit 10; the unit suite stays on 10.5 and never loads WordPress.
- **Compatibility matrix in CI** (`.github/workflows/integration.yml`), covering activation, schema creation, DB version, capabilities, roles, scheduled tasks, fresh-install defaults, and a deprecation check that asserts WordPress reported nothing about the plugin during load or activation. A further test asserts the running WordPress matches the version the job installed, so the matrix cannot report green for a version it never exercised.
- **Release automation** (`.github/workflows/release.yml`): verifies the version string across its homes against the tag, lints, builds the distributable from `.distignore`, asserts no dev or internal files leaked in, computes SHA-256, and drafts — never publishes — the GitHub Release.
- **Dependency-manifest guard test** (`tests/unit/DependencyManifestTest.php`). Asserts that every `.php` file under `includes/` appears in `Plugin::load_dependencies()`, that every listed path exists, that the list has no duplicates, and that `Booking_Adapter` precedes its consumers. Because there is no autoloader, an omission there is a fatal at load time that no existing check can see — `php -l` proves each file parses, and it does parse perfectly on its own, while the unit suite never boots the plugin. CI was green for the whole life of the 2.0.0 release.

## 2.0.0 - Public release (2026-08-08)

Brand-neutral packaging, security and privacy hardening, and safe defaults.
**No new product features.** Upgrading from 1.x: there is no data migration —
tables, option keys, meta keys, hooks, capabilities, and the REST namespace are
all unchanged. See [`docs/UPGRADE-2.0.md`](docs/UPGRADE-2.0.md).

### Added
- **Privacy tooling.** GDPR exporter and eraser registered with WordPress core, so Tools → Export/Erase Personal Data covers Memberistic records. Suggested privacy-policy text registered with the core policy editor. Erasure anonymizes in place and reports what it retains, with the reason: signed waivers (legal-claims basis, overridable via `memberistic_privacy_erase_waivers`) and payment records (statutory financial retention).
- **Data retention.** Configurable windows for check-in and activity history, defaulting to keep-indefinitely so no update can start silently deleting a business's records.
- **Complete uninstall.** Covers every table including the corporate module, all options and transients, user meta, cron events, dynamically created member and per-plan roles, capabilities left on surviving roles, and the generated check-in page. Multisite-aware. Still opt-in, still off by default.
- **Theme template overrides.** Copy any file from `templates/` into a `memberistic/` directory in the theme to replace it. New `memberistic_locate_template` filter, with the resolved path validated to stay inside the theme or plugin template roots.
- **Plan template library** (`templates/plans/`) — importable JSON sets for gym, studio, club, association, range, and generic tiered catalogues. Import as inactive so example pricing can never go live unreviewed.
- **Booking and POS adapters.** `Booking_Adapter` and `POS_Bridge::adapter()` resolve a third-party plugin's hook, table, and CSS names from a single declared preset, auto-detected. Any booking or POS plugin can now be mapped with `memberistic_booking_adapter` / `memberistic_pos_adapter`. Nothing registers and no foreign table is queried when nothing is mapped.
- **Requirements gate.** Unsupported PHP or WordPress shows an admin notice and leaves the plugin inactive, instead of a fatal error that would lock the user out of the screen they need to fix it.
- **WooCommerce HPOS** and cart/checkout blocks compatibility declared.
- **Licensing seam** (`includes/class-licensing.php`) — the extension points and policy a licence add-on must follow. Contract only: registers no hooks, makes no request.
- **`languages/memberistic.pot`** — 1500 strings across 80 PHP and 11 JS files, with translator comments and plural forms.
- `docs/INTEGRATIONS.md` (integration matrix and adapter contracts), `docs/UPGRADE-2.0.md`, `THIRD-PARTY-LICENSES.md`.

### Changed
- **Requires PHP 8.2** (was 8.0) and **WordPress 6.8**. PHP 8.0 and 8.1 are both past end of security support.
- **New installs seed no membership plans.** `Plans_Repository::seed_default_plans()` is now purely an extension point driven by `memberistic_default_plans`; the hard-coded plan arrays are deleted, not commented out. Existing plans are untouched.
- **Every integration that touches a third-party plugin or off-site service now defaults to off.** Email Automation and Waiver Manager remain on: both are built-in, send nothing off-site, and disabling them would stop members receiving their own account notices.
- **Frontend design tokens fall back to a neutral palette.** `token-bridge.css` previously mapped `--memberistic-*` straight onto one theme's tokens with no fallbacks, so the frontend rendered unstyled on any other theme. Every token now prefers a matching theme token and falls back to a contrast-checked default. All fallback pairs verified at ≥ 4.5:1.
- Corporate module CSS renamed from `.g2a-corp-*` / `--g2a-*` to `.memberistic-corp-*` / `--memberistic-corp-*`.
- Email header logo resolves through the logo setting → the site's Custom Logo → the Site Icon → `memberistic_email_logo_url`, replacing a hard-coded theme path.
- Checkout failure messages name the configured business, falling back to generic support wording.
- Member card ID prefix is now `MEM`, filterable via `memberistic_member_id_prefix`. Display only — never stored, never a lookup key.
- Walk-in role stripping is filterable (`memberistic_walkin_roles`) instead of hard-coded to one role slug.
- Guest-pass audit tags users with `_memberistic_customer_segment` instead of a third-party-namespaced meta key.
- Plans screen gets a real first-run empty state with a primary action.
- CI matrix moved to PHP 8.2 / 8.3 / 8.4.

### Removed
- All partner branding from code, comments, templates, assets, and documentation.
- A background image hotlinked from an external domain.
- `docs/PARTNERS.md`, the internal audit report, and a checkout incident postmortem — internal documents, not product documentation.
- The workflow that synced this repository to a private monorepo.

### Security
- Verified every REST route carries a real capability check. No route in the plugin uses `permission_callback => __return_true`.
- Confirmed Stripe webhook signature verification uses `hash_equals` with a 300-second replay window, and rejects unsigned requests before parsing the payload.
- Template override resolution validates the filtered path against the allowed roots, so the filter cannot be turned into an arbitrary-file include.

### Upgrade notes
- The booking integration's default flipped from on to off. The upgrade writes the previous value explicitly for any site that never saved that screen, so behaviour is preserved.
- `memberistic_lane_included_plan_slugs` no longer has a built-in default. Sites that never set it explicitly get an admin notice asking which plans include bookings; until then, standard booking pricing applies. This fails closed deliberately rather than guessing an entitlement.

## 1.20.0 - Single membership authority (2026-08-08)

### Added
- **`Membership_Service`** (`includes/class-membership-service.php`) — the public façade every other plugin calls for membership state. Exposes `get_user_membership_status()`, `user_has_active_plan()`, `get_user_plan()`, `is_guest_user()`, `can_book_lane()`, `requires_payment_for_booking()`, `assign_plan_after_payment()`, `assign_plan_manually()` and `remove_plan()`. Lane-entitlement rules are delegated to `Integrations\Entitlement_Service`, never restated, so there is exactly one rulebook.
- Global helpers for cross-plugin use: `memberistic_get_membership_status()`, `memberistic_user_has_active_membership()`, `memberistic_can_user_book()`, `memberistic_booking_requires_payment()`, `memberistic_is_guest_user()`.
- Plan-assignment guard rails: `assign_plan_after_payment()` refuses without `payment_verified` **and** an order/transaction reference; `assign_plan_manually()` requires the management capability **and** a non-empty reason. Both write an activity entry and an audit-log row. `remove_plan()` expires the membership while preserving all history (bookings, payments, waivers, QR).
- docs/PMPRO-REMOVAL.md (in the booking engine) documents the removal, the replacement architecture, the assignment rules and the migration path.

### Changed
- Paid Memberships Pro is no longer referenced by any runtime code path anywhere in the system. The only remaining mentions are the **CSV importer** — one-way migration of a legacy PMPro member base into Memberistic — plus two explanatory comments. This is enforced in CI by `PmproRemovalTest`, which carries an explicit allowlist and fails if an allow-listed file stops mentioning PMPro.

## 1.19.0 - Lane entitlement policy, Guest Pass cleanup (2026-08-08)

### Changed
- **BREAKING (policy):** Only memberships on the included plan slugs (documented setting `memberistic_lane_included_plan_slugs`) in an eligible status (default `active`, `comped` — `memberistic_lane_eligible_statuses`) resolve lane bookings to $0. New `Entitlement_Service` answers the booking engine's `g2ab_lane_entitlement` filter with a structured snapshot (membership id, plan slug, status, eligibility reason code, pricing type, timestamp). Guest Pass never includes free lane time, even when sold intentionally.
- `g2ab_user_is_member` and `g2ab_booking_pricing` now resolve through the entitlement service: trials, past-due, suspended, expired and Guest Pass holders no longer count as members for booking purposes. Linked/family members qualify only through their own authenticated account.

### Removed
- **Automatic Guest Pass enrollment.** Booking a lane (`g2ab_booking_created` / `g2ab_booking_paid`) or buying a WooCommerce product no longer creates a Memberistic membership. The explicit `[memberistic_guest_pass]` registration form is the only remaining issue path. Non-member bookers are classified by the booking engine as the `range_guest` customer segment instead (user meta, no membership row).
- `g2ab_advisory_membership_hint` handler: a typed email address no longer reveals whether it belongs to a member, in any form.

### Added
- `wp memberistic guest-pass-audit` WP-CLI command: dry-run by default, CSV/JSON report, confidence-bucketed classification (auto-created vs legitimate vs ambiguous), batched + resumable, transaction-safe `--apply` that expires only high-confidence auto-created memberships (users, bookings, payments, waivers, QR history preserved; `range_guest` segment assigned), full audit journal, and `--rollback` support. See docs/guest-pass-audit.md.
- docs/entitlements.md — the lane-entitlement business rules and result contract.

## 1.18.6 - Branding split, notice scoping, plan entitlements (2026-07-31)

### Fixed
- The admin menu now always reads "Memberistic". It previously used the customer-facing brand label, so shortening that label for emails also renamed the entire admin menu and left staff without a recognisable entry.
- Customer-facing branding now prefers the **Business name** setting over the shorthand **Brand label**, falling back to the site name. An abbreviation intended for the admin sidebar no longer goes out on membership emails, waivers and PDFs.
- The Stripe webhook health notice was hooked to global `admin_notices`, so it rendered at the top of every wp-admin page — including other plugins' dashboards — with no dismiss control and no way to act on it. It is now dismissible, limited to the WordPress dashboard and Memberistic screens, and links to the payments screen. Dismissals are fingerprinted against the current warning set, so a new or worsening problem re-surfaces rather than staying hidden.

### Added
- Plans can now mark booking types as **included** via `settings.included_booking_types` (a list of booking type ids, or `'all'`). A Guest Pass sold at the counter — annually, monthly, or at a custom price — resolves lane bookings to $0 directly, instead of depending on a 100% discount rule being configured for every booking type.
- `g2ab_advisory_membership_hint` filter: reports that a typed email matches an active membership so the booking engine can route the customer to the front desk for a member-rate check. It carries no price and no access, preserving the audit C27 rule that a typed address alone never grants member pricing.

## 1.18.5 - Public checkout lock hotfix (2026-07-28)

### Fixed
- Fixed public checkout rate-limit advisory lock names so MySQL `GET_LOCK()` / `RELEASE_LOCK()` never receive names longer than the 64-character limit.
- `GET_LOCK()` results now distinguish acquired locks, real contention, and database/compatibility failures instead of reporting every failure as "Checkout is busy."
- Added a durable database-table fallback for checkout rate limiting when advisory locks are unavailable.
- Hardened the public checkout endpoint with no-cache headers, POST/action/nonce enforcement, same-origin referer checks when available, safe Stripe redirect host validation, and clearer customer-facing error messages.
- Prevented the checkout form from submitting twice client-side while preserving server-side idempotency as authoritative.
- Stopped sending the checkout-start membership email before Stripe Checkout Session creation succeeds; payment activation remains webhook/API-authoritative.

### Added
- `memberistic_rate_limits` table and hourly cleanup for durable fallback rate limiting.
- Developer incident note with confirmed failure path, cache exclusions, staging QA, deployment, and rollback steps.

## 1.18.4 - Account dashboard color-contrast fix (2026-07-20)

### Fixed
- The member account dashboard's `--ma-*` color variables were hardcoded hex snapshots instead of live theme tokens, so the "Welcome back" banner, member-since fields, status pills, sign-out link, and digital member card were structurally incapable of following the site's light/dark mode toggle. Rewired to the live token bridge and added light-mode-specific overrides where the digital card's face stays dark in both modes by design.
- Fixed a CSS specificity bug that made member avatar initials nearly invisible (~1.05:1 contrast) in both modes.

## 1.18.3 - Stripe incident hotfix (2026-07-20)

### Fixed
- Stripe webhook processing no longer marks an event processed before the handler finishes, and retryable failures now propagate to Stripe as 5xx instead of silent success.
- Pending signup retries reuse or reconcile the saved Checkout Session before any new Stripe Checkout Session is created.
- Checkout completion validates the authoritative Stripe session/subscription state against the local membership, plan, amount, currency, email, billing cycle, and site mode before activation.
- The thank-you shortcode no longer assumes success from query parameters; it confirms Stripe state server-side and shows honest active, processing, failed, or manual-review states.
- Renewal handlers support both legacy and nested Stripe invoice subscription references.
- Inconclusive Stripe API reconciliation no longer downgrades active recurring members.
- Membership role sync now runs after account provisioning.

### Added
- `stripe_checkout_session_id` and `stripe_checkout_expires_at` membership columns for safe pending checkout reuse.
- Admin webhook health notices and WP-CLI `memberistic stripe-audit` / `memberistic stripe-reconcile` commands.

## 1.13.1 — Waiver bridge hardening (2026-07-15)

- **Security:** `Waiver_Booking_Bridge` (the `g2ab_waiver_satisfied` hooker) now matches an on-file waiver by **email only**. The name fallback meant the public booking form's attacker-controlled `customer_name` could satisfy the waiver requirement by matching any prior signer. `Waivers_Archive::find_on_file()` keeps name/DOB matching for authenticated staff screens.

## 1.13.0 — Guaranteed Stripe cancellation (2026-07-15)

- **Stripe first, local status second.** Cancelling a membership (members app REST action, admin edit screen, legacy wp-admin links) now confirms the Stripe subscription is stopped **before** the local record flips to cancelled. Previously the DB was marked cancelled first; if the Stripe call then failed, the site showed "cancelled" while Stripe kept billing the member.
- On Stripe failure the membership keeps its current status, the operator gets an explicit error, a persistent admin notice lists every affected member, and retries run automatically with backoff (5m, 30m, 2h, 6h, 24h, 48h). A successful retry completes the local cancellation automatically.
- Explicit override: `POST /memberships/{id}/cancel` with `force=true` cancels locally even when Stripe is failing (retries keep running until Stripe confirms).
- Already-cancelled / missing subscriptions at Stripe still count as success (idempotent), and inbound webhook cancellations are unaffected.

## 1.12.0 — Crossmatch unification (2026-07-13)

### Changed
- **Unified the two divergent copies of the plugin** (a monorepo line, which had reached 1.10.7, and the dedicated memberistic-membership-solutions repo line, which had reached 1.11.0). Both locations now carry the identical 1.12.0 tree containing the newest code from each line: the monorepo's 1.10.1–1.10.7 fixes (Stripe cancel propagation, token-bridge stylesheet, audit hardening) **and** the dedicated repo's Advanced FFL Checkout bridge (1.11.0). No functional changes beyond the merge itself.

## 1.11.0 — Advanced FFL Checkout bridge

### Added
- **Advanced FFL Checkout integration.** New read-only bridge (`FFL_Checkout_Bridge`) surfaces a member's own online FFL firearm-transfer history — from the separate `advanced-ffl-checkout` WooCommerce storefront plugin — on their account dashboard (matched by email against that plugin's `transfers` table, same-site direct read, the same pattern the POS Bridge and coreSTORE Bridge already use), plus a quick heads-up on the staff "Member Verification" QR card if they have an open transfer in flight. New "Advanced FFL Checkout" card on Memberistic → Integrations (off by default; gated on that plugin being active).

## 1.10.1 — Cancelling on the site now cancels the Stripe subscription

### Fixed
- **Cancelling a membership in WordPress never cancelled the Stripe subscription, so Stripe kept billing the member.** Every on-site cancel path (the members app's Cancel action, an admin edit setting Status → Cancelled, and the legacy wp-admin members page) only flipped the local DB status. `Stripe_Service` now has a `cancel_subscription()` API call, and a listener on `memberistic_membership_status_changed` cancels the member's Stripe subscription whenever a membership is cancelled on the WordPress side. The call is idempotent ("already cancelled"/"no such subscription" responses are treated as done), skipped while inbound Stripe webhooks are being processed (Stripe told us — no need to tell Stripe back), and a failed cancel is logged to the membership's activity feed and the PHP error log instead of silently leaving billing live.
- **The admin REST cancel action and edit-screen status changes bypassed the canonical `change_status()` path**, so the `memberistic_membership_status_changed` hook (Stripe propagation, coreSTORE bridge) never fired for them. Both now route status changes through `change_status()`.

### Added
- `memberistic_stripe_cancel_at_period_end` filter — return `true` to stop billing at the end of the paid period instead of immediately.

## 1.10.0 — coreSTORE bridge, WooCommerce member discounts, dashboard Shop tab

### Added
- **coreSTORE (Coreware) POS bridge.** New integration — fully independent from the POS Bridge — that pushes membership state into the shop's hosted coreSTORE over its REST API (`x-api-key`): per-plan price-tier mapping (coreSTORE's tier pricing then applies member discounts at the register automatically), optional custom fields for plan/status/expiry on the cashier's customer screen, live push on activation/renewal/lapse, daily reconciliation cron, Test Connection + Sync Now buttons, and a capped sync log. Credential-ready: configure URL/key on Integrations whenever the store issues them.
- **Automatic WooCommerce member discounts.** Each plan carries its own discount %, category include/exclude rules, and a skip-sale-items switch (Integrations screen). Active members get a labeled negative fee at cart/checkout ("Member discount — <plan> (10%)"), orders are stamped with the saving (`_memberistic_member_discount`), and product pages show the member price. Membership products are never discounted.
- **Shop tab on the member dashboard.** The `/account/` dashboard now includes the member's WooCommerce world: order count / total spent / lifetime member savings, recent orders with per-order savings and view links, downloads, billing & shipping addresses with edit links, and payment-methods / account-details links — no separate my-account visit needed.
- **`memberistic_membership_status_changed` action** fires from the canonical `change_status()` path so integrations react to lapses/cancellations without polling.

## 1.9.9.5 — Post-login cache-buster

### Fixed
- **Sign-in could still appear dead behind an aggressive page cache/CDN.** After a successful login, `Auth` now redirects to the account page with a unique `mlogin` query arg, forcing a cache miss so a stale *logged-out* copy of `/account/` (which is the login form again) can never be served back to the freshly signed-in member. Pair with a one-time full CDN cache purge after deploying.

## 1.9.9.4 — Sign-out

### Fixed
- **Members couldn't sign out.** The theme filters `logout_url` to `/login/?action=logout&redirect_to=…&_wpnonce=…`, but `Auth` only handled login / lostpassword / reset — so the logout link landed on the login page with the member still signed in. `Auth::maybe_logout()` now handles it: verifies the standard `log-out` nonce, calls `wp_logout()`, and redirects to `redirect_to` (default home) through the `logout_redirect` filter. A stale-nonce link falls through to a one-tap confirm view (`render_logout_confirm()`) with a fresh logout URL. `retrievepassword` is also accepted as a legacy alias of `lostpassword`.

### Changed
- The account template's "no active membership" state now includes a **Sign Out** link, so a signed-in visitor without a membership isn't stranded.

## 1.9.9.3 — Live-site hardening

### Fixed
- **Forgot-password still looked dead on the live site** because a page cache / CDN served the cached *login* HTML for `/login/?action=lostpassword` and `/login/?action=rp`. `Auth::prevent_login_cache()` now sends `nocache_headers()` and defines `DONOTCACHEPAGE` / `DONOTCACHEOBJECT` etc. on the login surface (detected by auth action, login/account page id, or the login/account shortcode), so caching plugins and CDNs keep it dynamic. Clear the site cache once after updating.
- **"Set up member logins" admin notice would not clear** when a flagged membership had no email (the repair tool can't create a login without one), leaving a permanent banner. `count_missing_logins()` is now email-aware (joins the people table and only counts memberships that have a real email and no linked user), and `ensure_user_for_membership()` falls back to any linked person's email when the primary row has none — common in imported data — so imported members are reachable and the notice clears once the actionable work is done.

### Changed
- **Settings screen now has a single Save button.** Removed the duplicate header "Save changes" button; the sticky footer save bar remains.

## 1.9.9.2 — Member login & password recovery

### Fixed
- **Forgot-password / reset-password did nothing.** Themes that hide wp-login.php filter `login_url` / `lostpassword_url` to a branded `/login/` page, and WP's reset emails carry `wp-login.php?action=rp` links — but `[memberistic_login]` only rendered the *login* form, so `/login/?action=lostpassword` and `/login/?action=rp` dead-ended. A new `Frontend\Auth` handler turns `/login/` into a full, theme-independent auth surface: it processes login (via `wp_signon`), lost-password (via `retrieve_password`) and reset-password (via `check_password_reset_key` + `reset_password`) on `template_redirect`, with PRG redirects and inline notices. Works even when wp-login.php is blocked/redirected.
- **Password links bypassed the branded page.** WP builds reset and "set your password" links with `network_site_url('wp-login.php?action=rp…')`, side-stepping the theme filter. `Auth` now filters `retrieve_password_message` and `wp_new_user_notification_email` to rewrite those URLs to `/login/?action=rp`, so every link lands on the working handler.
- **Staff-added and imported members had no WP account.** Only the Stripe checkout and corporate paths created users; admin "Add Member" and CSV import left a membership with no `primary_user_id`, so those members couldn't sign in or set a password. New `Account_Provisioner` creates (or links) a WP user for a membership's primary person and emails a set-password link — wired to run on `memberistic_membership_activated`, on staff member creation (REST `create_item`), and as a one-click bulk **"Set up member logins"** repair tool surfaced via an admin notice. Idempotent; the set-password email is sent at most once per member.

## 1.46.1 — Booking integration correctness

### Fixed
- **Member booking discounts now apply.** `booking_discount()` / `discount_percent_for_booking_type()` read `booking_discounts` from `$membership['settings']`, but the `memberistic_memberships` table has no `settings` column (only `memberistic_plans` does), so the rules were always empty and members only ever got the booking type's base `member_discount`. Both now resolve the plan via `Plans_Repository::get($membership['plan_id'])` and read the rules from the plan settings.
- **Timezone-correct expiry check.** `membership_is_bookable()` appended `' 23:59:59'` to `renewal_date`, but that column is a `DATETIME` (`Y-m-d H:i:s`), producing a "double time specification" that made every active member read as **not bookable** (breaking member booking, pricing and member content access). It now takes the date part only and builds the end-of-day in `wp_timezone()`, treating `''`/`0000-00-00[ 00:00:00]` as non-expiring.
- **Booking-form pages are never content-restricted.** `Content_Restrictions` now exempts any page containing `[g2a_lane_booking]` / `[g2a_booking_form]` (filterable via `memberistic_restriction_exempt_post`) across all four gates, so a mis-set required-plan can't hide the lane-booking UI from guests.
- **Member state cleared on expiry.** A new `clear_roles_for_membership()` on `memberistic_membership_expired` strips the member + plan roles and the `memberistic_active_plan_id`/`_name` meta, guarded so a user who still holds another active membership keeps their roles (the survivor check excludes the just-expired row).
- **Consistent member resolution for content gating.** `current_user_has_any_plan()` now resolves through the booking integration's `get_active_membership_for_user()` (honors email-linked memberships + rechecks renewal) instead of a status-only `primary_user_id` lookup.
- **Correct membership level id in booking metadata.** `g2ab_booking_pricing` now carries the plan id (was the membership row id) so the booking engine's role/label assignment looks up the right plan.
- **Stripe checkout currency allowlist.** `create_checkout_session()` validates the configured currency against a known list and falls back to `usd`, so a corrupted setting can't make Stripe reject the session.

## 1.45.0 — Integrations toggle fix + POS Bridge + SMS module + waiver module

### Fixed
- **Integration toggles (Verifyistic et al.) finally persist.** `register_setting`'s sanitize callback ran on *every* `update_option('memberistic_settings')` — including the Integrations page save — and returned a fixed allowlist that stripped every `integration_*` key. Toggling Verifyistic ON, saving, and reloading showed it OFF again. The sanitizer now persists every Integrations Registry toggle, the Verifyistic sub-options, `email_reply_to_address`, `verifyistic_max_age_days`, and passes through unknown scalar keys instead of deleting them.
- React Settings → Integrations tab rendered only the WooCommerce toggle; it now renders every registry module (with availability/coming-soon states) from a new `_integrations` snapshot on `GET /settings`.

### Added
- **POS Bridge module** (Integrations toggle, requires a connected POS): answers the POS membership-lookup filter with live Memberistic status/plan/expiry/benefits at the counter, feeds upcoming range bookings to the POS dashboard via `g2a_pos_membership_bookings`, and stamps `pos_customer_id` on membership rows.
- **SMS Notifications (Messageistic) module** (Integrations toggle, requires Messageistic): one switch controls membership + booking SMS sent through Messageistic (incl. the local SMS gateway).
- **Waiver Manager module card** replaces the "Waiver Provider — coming soon" placeholder: the built-in waiver system (tokenized member signing, guest + kiosk surfaces, immutable archive, expiry tracking) is now surfaced as a real module; the toggle gates the booking-engine check-in mirror.
- New lifecycle hooks for add-ons: `memberistic_membership_expiring( $membership_id, $days_out )` (30/7/1-day windows, deduped) and `memberistic_membership_expired( $membership_id )`.

## 1.43.5 — Stabilization & integration hardening

### Fixed
- Duplicate Stripe payment row on first invoice (handle_invoice_succeeded now defers to handle_checkout_completed for the initial charge).
- Activity log mis-classified `payment_past_due` events; added to the valid-types whitelist alongside `payment_receipt` and `payment_refunded`.
- WooCommerce bridge now respects the Integrations Registry toggle.
- WooCommerce webhook endpoint refuses requests when the shared secret is empty (was: skip verification → effectively open auth).
- Verifyistic bridge cookie reader enforces a 30-day max age (default; configurable).
- Stripe webhook dedupe persists across object-cache flush via a capped option list.
- Hardcoded "From $29.99/mo" on login shortcode now reads the minimum active plan monthly price.
- Mesa AZ (UTC-7) and other non-UTC sites: monthly KPI cutoffs use `wp_date()` with `current_time('timestamp')`.
- Stripe checkout no longer creates duplicate pending memberships on form refresh.
- Generic fallback email "Status: {plan_name}" merge tag corrected to "{status}".
- Stripe subscription deletion: subscription_id is the primary lookup, metadata is the fallback.

## 1.40.0 — Public kiosk + profile image upload
- Auto-created `/check-in/` page and `[memberistic_kiosk]` shortcode for front-desk QR scan workflows.
- Profile image upload REST routes (`POST /profile/image`).

## 1.38.0 — Stripe billing portal
- Customer-facing "Manage billing" link via Stripe's Billing Portal.

## 1.35.0 — Corporate Guest auto-enroll
- Buyers without a membership are auto-enrolled on a hidden Guest Pass plan on `woocommerce_payment_complete` and on `g2ab_booking_created/paid`.

## 1.34.0 — Schema 1.4.0 / 1.5.0
- Added `memberistic_waiver_signatures`, `memberistic_documents`, `memberistic_waivers_archive` tables.

## 1.33.0 — Automatic Guest members for non-members

Non-members are now remembered. Anyone who books a range lane or buys a product without a membership level is saved automatically as a **Guest member** — with their own WordPress login account, a Digital Card with dynamic QR, a waiver request, and their contact details on file — so the next visit the front desk can pull them up by QR scan and their information persists.

### Added

- **Auto Guest enrollment from WooCommerce purchases.** `Corporate_Guest_Service::maybe_enroll_from_wc_order()` hooks `woocommerce_payment_complete` + `woocommerce_order_status_completed/processing`. Any buyer whose billing email has no membership is enrolled on the hidden Guest Pass plan (login account + QR Digital Card + waiver email). Membership/group-invoice orders are skipped (they are not product sales), and orders without an email are skipped.
- **Auto Guest enrollment from range bookings.** `Corporate_Guest_Service::maybe_enroll_from_booking()` hooks both `g2ab_booking_created` (passes a booking id) and `g2ab_booking_paid` (passes the booking row). The handler normalizes either payload, reads `customer_name` / `customer_email` / `customer_phone`, and enrolls the booker as a Guest member if they are not already a member.

### Changed

- **Idempotent by design.** A new `email_has_membership()` guard means existing members and existing guests are never re-enrolled and never re-emailed on repeat purchases or bookings. A booker/buyer with no name falls back to the email local-part so a card can always be issued.

### Notes

- No DB schema changes — guests reuse the existing membership, person, waiver, check-in, and QR verification infrastructure.

## 1.10.0 — Operations dashboard release

A broad admin operations upgrade: pagination, KPI cards across every list page, a card-based Plans console, a permissive importer that never drops a row, and a React-rebuilt Email Directory.

### Added

- **Members page — KPI cards & MoM growth.** Eight cards: Total Members, Active, Pending, Past Due, Expired, Cancelled, New This Month (with month-over-month % growth indicator), and Waiver Missing. Powered by a new `GET /memberistic/v1/memberships/stats` endpoint.
- **Members page — server-side pagination.** `GET /memberistic/v1/memberships` accepts `limit` + `offset` and returns `X-WP-Total` / `X-WP-TotalPages` headers. New `MemberShips_Repository::count_all()` powers the totals. UI gains «/Prev/Next/» controls plus a per-page selector (25/50/100/200).
- **Members page — bulk waiver action.** New `Change waiver status…` bulk action with a waiver-status sub-dropdown. Posts to a new `POST /memberistic/v1/memberships/bulk-waiver` endpoint that updates every person on each selected membership and writes a `waiver_signed` / `waiver_expired` activity row per membership.
- **Plans page — card UI.** Replaces the table with an animated card grid. Each card surfaces price (monthly + annual), capacity, the top 4 benefits, status, "Featured" ribbon, and a footer with three live member-count tiles: Total / Active / Other. Powered by a new `GET /memberistic/v1/plans/stats` endpoint.
- **Payments page — KPI cards.** Six cards: Lifetime revenue, Revenue this month (with MoM growth %), New-member payments (first completed payment per membership), Renewal payments (second-or-later), Failed payments, Visible on page. Powered by a new `GET /memberistic/v1/payments/stats` endpoint and `Payments_Repository::stats_summary()` + `count_all()`.
- **Payments page — pagination.** Same headers + UI pattern as the Members page. Per-page picker, prev/next/first/last, with full filter context preserved.
- **Payments page — richer CSV.** Export now includes Payment ID and Created columns.
- **Emails page — React directory.** Brand-new `assets/admin-emails.js` console with KPI cards (Sent today, Sent this week, Sent this month, Delivery rate, People with email), filters by member status and waiver status, paginated list with status pills and "Open member" links. CSV export gathers the full filtered set (chunked at 1000/req) and lays it out in 13 properly labelled columns including waiver signed/expires dates.
- **Import — "No Plan" sentinel.** Auto-created hidden plan that catches imported members with no recognised tier so historic rows are never dropped.
- **Import — Instore stub creation for orphan payments.** Orders whose email does not match an existing member create a stub Instore member; emailless orders attach to a single shared "Instore Walk-in" membership. Disposition is logged per row in the dry-run preview.

### Changed

- **Import — members analyzer / committer.** Now imports every row including those with no email (kept as primary contact via full name), unrecognised levels (attached to No Plan), and expired memberships (status auto-set to `expired`). Dry-run preview adds counters for expired, no-plan, and no-email rows.
- **Import — payments analyzer / committer.** Replaces the silent skip path with the orphan-membership flow above. Status mapping now also recognises `failed`/`error`/`declined`. Disposition column added to sample table.
- **Email Directory PHP page.** Reduced to a React mount point; the legacy server-rendered `?memberistic_export=emails` URL still works (now exports 13 columns instead of 6) for bookmarks.

### REST API additions (v1)

- `GET  /memberships/stats`
- `POST /memberships/bulk-waiver`
- `GET  /plans/stats`
- `GET  /payments/stats`
- `GET  /emails/directory` — paginated email directory with search + filters; sets `X-WP-Total` / `X-WP-TotalPages`.
- `GET  /emails/stats` — sent today/week/month, delivery rate, contact coverage.
- `GET  /memberships` and `GET  /payments` — now accept `offset` and emit `X-WP-Total` / `X-WP-TotalPages`.

### Notes

- No DB schema changes. DB version stays at `1.2.0`.
- Version bumped to `1.10.0`.

## 1.9.0 — Admin-side waiver management

### Added

- **Per-person Edit action on the Members detail panel.** The People tab in the slide-in detail panel now exposes an inline editor for every linked person on a membership. Admins can update full name, email, phone, relationship, **waiver status** (`missing` / `signed` / `expired` / `needs_review` / `rejected`), **waiver signed date**, **waiver expiry date**, and the person's own active/inactive/removed status without leaving the page.
- **Remove action** for non-primary linked members directly from the People row (the primary member is still protected server-side).
- **Waiver expiry hint** in the People table — the waiver pill now shows the expiry date underneath when one is set, so admins can see at a glance which signed waivers are aging out.

### Changed

- `PUT /memberistic/v1/people/{id}` now declares a typed argument schema covering `full_name`, `email`, `phone`, `date_of_birth`, `relationship`, `waiver_status` (enum), `waiver_signed_at`, `waiver_expires_at`, `status`, and `notes`. Previously the route accepted those fields but did not validate them at the REST layer.
- When `waiver_status` is set to `signed` and no `waiver_signed_at` is provided (and none was previously recorded), the API auto-stamps `waiver_signed_at` to the current time.
- Whenever `waiver_status` changes via `PUT /people/{id}`, an activity row is written: `waiver_signed` when the new status is `signed`, `waiver_expired` for `expired`/`rejected`/`needs_review`, and `membership_status_changed` otherwise. This makes the change visible on the Activity tab and Activity admin page.

### Notes

- UI-only update. Database schema unchanged (`memberistic_people` already had `waiver_status`, `waiver_signed_at`, `waiver_expires_at`).
- Version bumped to `1.9.0`. DB schema version stays at `1.2.0`.

## 1.8.0 — Member import

### Added

- **Import page** (`Memberistic → Import`) — upload a Paid Memberships Pro (PMPro) members CSV, or a payment/orders export, and bring the data into Memberistic. Columns are auto-detected through an alias map; legacy PMPro levels are mapped onto plan slugs; "Additional Member" levels import as linked people attached to a primary membership with open capacity.
- Two-step flow with a dry-run preview (row counts, plan breakdown, linked-member and duplicate detection, sample rows) before anything is committed.
- Imported members are linked to existing WordPress user accounts by email when one is found.

### Changed

- Version bumped to `1.8.0`. DB schema unchanged.

## 1.7.1 — Partnership documentation release

Documentation-only release. No functional code changes.

### Added

- **Plugin header** — `Description` and `Author` fields updated to acknowledge the partnership. `Plugin URI` and `Author URI` continue to point to WordPressistic.

### Changed

- Version bumped to `1.7.1`. DB schema unchanged at `1.2.0`.

## 1.7.0 — Audit + spec-completion pass

Full audit against the canonical Memberistic feature spec. Fixes 20 bugs and gaps and adds the remaining spec-required features so the engine is feature-complete against `CORE_MEMBERSHIP_PLAN_FEATURES.txt`.

### Added

- **Default plans seed** — a starter plan catalogue is created automatically on first install. (Removed in 2.0.0: new installs now start with an empty Plans screen.)
- **Email automation overhaul**
  - Six new transactional templates: `membership_renewed`, `expiring_30_days`, `expiring_7_days`, `expiring_tomorrow`, `membership_expired`, `linked_member_added`, `staff_manual`. The required-template set is now 12 of 12.
  - Full merge-tag rendering with all 20 documented tags (`{member_name}` … `{logo_url}`) plus a `memberistic_email_merge_tags` filter.
  - `wp_memberistic_email_logs` table records every send with template, recipient, subject, status, and any failure message.
- **Daily cron jobs** via the new `Scheduler` class:
  - Renewal reminders at 30 / 7 / 1 day windows.
  - Auto-expire of active memberships past their renewal date.
  - Waiver follow-up nudges for active memberships still missing a signed waiver.
- **REST API completions**
  - `PUT /people/{id}` and `DELETE /people/{id}` (the primary member is protected).
  - `POST /webhooks/woocommerce` with HMAC SHA-256 signature validation.
  - `GET /memberships/{id}/bookings` now returns `{ bookings, checkins }` instead of just check-ins.
  - `GET /memberships` accepts 9 filter args: `billing_cycle`, `waiver_status`, `expiring_in_days`, `checked_in_today`, `created_from`, `created_to`, `limit` (in addition to the existing `search`, `status`, `plan_id`).
- **WooCommerce bridge**
  - `WooCommerce_Bridge::ensure_default_products()` creates the hidden virtual products (one per plan × Monthly / Annual) and matches them to existing SKUs on re-run.
  - Refund and cancel hooks flip the linked membership to `cancelled` and log the activity.
- **New tables**: `wp_memberistic_email_logs`, `wp_memberistic_integrations`. Migration `1.2.0` creates them on existing installs.
- **New roles**: `memberistic_kiosk_operator`, `memberistic_pos_staff` placeholder roles for the future KIOSK and POS modules.
- **New statuses**: `suspended`, `needs_review` are now accepted everywhere and rendered as proper badges.
- **New activity event types**: `membership_expired`, `membership_upgraded`, `membership_downgraded`, `waiver_expired`.
- **Settings** — added `timezone`, `logo_url`, `accent_brand_color`, `woocommerce_webhook_secret` to the sanitizer.

### Fixed

- **Stripe `invoice.payment_succeeded` now handled** — recurring renewals advance `renewal_date`, record a payment row, fire the `membership_renewed` activity, and send the renewal receipt.
- **Stripe `customer.subscription.deleted` fallback** — looks up the membership by `stripe_subscription_id` if the metadata is absent.
- **`memberistic_validate_status()` accepts the full 10-status set** — previously it silently coerced `suspended` and `needs_review` to `pending`.
- **`People_Repository::update()` partial updates preserve existing values** — previously a partial update would clobber `status` / `waiver_status` back to defaults.
- **Member search now joins linked-member names and matches Stripe / Woo / POS customer IDs** — previously search only matched the primary person.
- **People sanitizer accepts `waiver_signed_at` and `waiver_expires_at`** — schema columns are now writable.

### Changed

- `Email_Service` rewritten around merge-tag substitution and email-log persistence.
- `Scheduler` is wired into activation (schedules) and deactivation (clears scheduled events).
- `Activity_Repository::types()` and the internal whitelist now cover the full 21 spec event types.

### Documentation

- `docs/AUDIT_REPORT.md` — complete, sectioned audit against the canonical spec. Every spec section reports `Done` / `Partial` / `Roadmap` and lists fixes shipped in this release.
- `README.md` rewritten for client delivery: what's in the box, install steps, REST surface, filter map, repo layout.
- `CHANGELOG.md` introduced as the durable history.

---

## 1.6.0 — Phase 5

- Retired the legacy server-rendered views in favour of React consoles.
- Email automation foundation.
- Saved-filter views per user.
- React Settings app on top of the new Settings REST controller.

## 1.5.6

- Membership user roles, plan-specific roles, and post / page restriction controls.
- Modern frontend restriction overlay for visitors without the required plan.

## 1.5.5

- Polished admin plan cards and smoother branded card motion.
- Expanded integrations panel with connected and coming-soon addon cards.
- Public-booking integration: active members detected by email for included bookings.

## 1.5.4

- Hardened public checkout redirects, restyled default login/result pages, and improved staff / admin operational screens.

## 1.5.3

- Staff frontend dashboard for walk-in membership creation, member search, check-ins, and recent booking visibility.
- Fixed Settings screen tabs.
- Added frontend dashboard page mapping.

## 1.5.2

- Routed public Stripe checkout submissions through the mapped Memberistic Checkout page to avoid login redirects before Stripe Checkout opens.

## 1.5.1

- Memberistic checkout form submits via a public frontend route so Stripe Checkout is not blocked by wp-admin/login rules.
- Connected to real booking engine hooks.

## 1.5.0

- Linked online Stripe checkout memberships to WordPress users or email-matched accounts.
- Real frontend account, people, payment history, and booking / check-in history shortcode views.
- Lifecycle email foundation for membership creation, activation, payment failure, cancellation, renewal, and waiver reminders.
- Booking Engine eligibility, discount, and activity hooks.
- Optional WooCommerce completed-order bridge foundation.
- Expanded REST endpoints for plan management, membership update / delete, payments, activity, bookings, renew, cancel, upgrade, and dashboard queues.
- Dashboard reporting with waiver / payment follow-up, expiring renewals, and revenue by plan.

## 1.4.3

- Ignored known legacy / common membership page mappings when branded Memberistic pages are available.

## 1.4.2

- Branded page URL fallbacks for checkout buttons and Stripe return URLs.

## 1.4.1

- Branded Memberistic URL slugs; remap action for existing installs.

## 1.4.0

- Stripe Checkout settings.
- Frontend membership checkout form.
- Pending membership creation before Stripe redirect.
- Stripe Checkout Session creation without the Stripe PHP SDK.
- Stripe webhook endpoint for checkout completion, failed invoices, and subscription cancellation.

## 1.3.0

- Frontend shortcode foundation.
- Public membership plan cards with monthly / annual toggle.
- Checkout, account, login, and renewal shortcode foundations.
- Page mapping settings and required-page creation action.

## 1.2.1

- Rounded dashboard revenue values to clean currency precision.

## 1.2.0

- Staff operation foundation: manual payments, check-ins, and staff notes.
- Functional Payments, Check-Ins, and Activity admin pages.
- Staff operation forms on the member profile screen.
- Authenticated REST endpoints for payment, check-in, and note creation.

## 1.1.0

- Phase 2 membership creation foundation.
- Primary person creation and linked member management.
- Member profile admin screen with people and activity sections.
- Authenticated memberships REST endpoints.

## 1.0.1

- Hardened REST controller compatibility with WordPress core inheritance.
- Removed PHP 8-only type syntax from runtime code for broader staging-host safety.

## 1.0.0

- Initial Phase 1 foundation build.
