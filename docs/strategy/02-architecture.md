# Architecture

What to change in the codebase so it can absorb a year of feature work, and —
just as important — what to leave alone.

---

## 1. What must not change

The audit found these to be sound. Feature pressure will push against several
of them; they should hold.

**Dedicated tables.** Memberships live in `{prefix}memberistic_*`, not in posts
and post meta. This is the architectural differentiator: it makes lifecycle
queries, check-in lookups and group billing tractable at volumes where a
post-meta design collapses. Do not migrate anything back into post meta for
convenience.

**No autoloader, no build step, no Composer runtime dependency.** The
hand-ordered `require_once` list in `Plugin::load_dependencies()` is a real cost
— a new class is invisible until it is registered — but it buys a plugin that
installs from a zip and runs, with no `vendor/` to ship and nothing to compile.
For a WordPress.org distribution that is the right trade. Revisit only if the
require list becomes genuinely unmanageable, and record it as an ADR.

**Licensing as a seam.** `class-licensing.php` exists but is not wired into
membership access. That separation is the reason invariant I4 is achievable.
Implementing Pro must extend the seam, never thread licence checks through
access decisions.

**Integrations default to off.** `Integrations_Registry` owns the toggle and
everything third-party is `'default' => 'no'`. A fresh activation makes no
outbound HTTP request at all. New integrations register their hooks inside an
`is_enabled()` guard on `init` — the pattern `class-plugin.php` already uses for
booking, WooCommerce and the waiver booking bridge.

**Capabilities, not roles.** Gate on `manage_memberistic`,
`view_memberistic_pii`, `memberistic_checkin_members` and friends. The PII check
is deliberately narrower than the admin check — cashier, POS and instructor
roles have dashboard access without member contact data. Preserve that
distinction; it is a privacy control, not an oversight.

**Server-side redaction of restricted content.** Not CSS hiding. Ever.

---

## 2. The refactor: splitting the god classes

Seven files carry most of the risk. The four largest are the priority.

### Sequencing rule

**Characterisation tests first.** Before moving a single line, write tests that
capture the current behaviour of the code being moved — including the
behaviour you suspect is wrong. Then move. Then, in a separate PR, fix what the
tests revealed.

A refactor PR that also changes behaviour is not reviewable, and in payments
and waivers it is how silent money and legal-record errors get shipped. Risk
**R3** in the master plan.

**One module per PR.** No feature work inside a refactor PR.

### Stripe — `includes/payments/class-stripe-service.php` (85 KB)

Currently: checkout, portal, webhooks and retries in one class.

| New class | Responsibility |
|---|---|
| `Stripe_Client` | HTTP transport, API version pinning, error normalisation |
| `Checkout_Service` | Session creation, success/cancel handling |
| `Subscription_Service` | Create, update, cancel, end-of-period behaviour |
| `Billing_Portal_Service` | Portal session creation and return handling |
| `Webhook_Verifier` | Signature, timing-safe compare, replay window — **verify before parse** |
| `Webhook_Handler` | Event dispatch and idempotency |
| `Payment_Reconciliation_Service` | Reconciliation and the recovery CLI paths |

`Stripe_Client` is where the pinned API version becomes a single, visible,
testable value — which is what makes the R1 upgrade path tractable at all.

### Waivers — `includes/waivers/class-waivers.php` (98 KB)

| New class | Responsibility |
|---|---|
| `Waiver_Repository` | Persistence |
| `Waiver_Version_Service` | Versioning and re-consent rules |
| `Signature_Service` | Capture and validation |
| `Waiver_Token_Service` | Kiosk and remote-signing tokens |
| `Waiver_Reminder_Service` | Expiry and follow-up scheduling |
| `Waiver_Document_Service` | PDF generation and the immutable archive |
| thin controllers | Admin and front-end entry points |

Waivers are legal records. The immutable archive stays immutable — no refactor
may introduce a path that rewrites a signed document.

### Corporate — `includes/corporate/class-corporate-module.php` (178 KB)

The largest file in the codebase and self-contained, which makes it both the
biggest risk and the cleanest to extract.

| New class | Responsibility |
|---|---|
| Group repository | Persistence |
| Seat/member service | Seat allocation, transfer, removal |
| Invoice/payment-link service | Group billing |
| Portal controller | Group admin front end |
| Notification service | Group emails |
| Admin views | Presentation only |

### REST — `includes/rest/class-memberships-controller.php` (63 KB)

Split by resource: membership · people · documents · payments · check-in ·
import/sync · webhooks.

Every split controller re-declares its `permission_callback` explicitly. This
is the moment where a route can silently lose its guard, so the M1 authorization
suite must exist **before** this split, not after.

### Also on the list

- `templates/account.php` (72 KB) — split into partials, keeping the
  theme-override contract intact. Overrides resolve child theme → parent theme
  → plugin, and the resolved path is validated to stay inside those three
  roots. Splitting the template changes what a theme overrides, so this needs
  an upgrade note.
- `includes/admin/class-import-page.php` (49 KB) — separate parsing, validation,
  mapping and execution. The importer is also the PMPro migration path, so
  invariant I1's allow-list applies here.
- `includes/emails/class-email-service.php` (35 KB) — separate templating,
  merge tags, sending and logging.

---

## 3. Coding standards

### Target: WordPress Coding Standards

Not PSR-12. WPCS covers escaping, sanitization, translation and interoperability
practices that PSR-12 has no opinion about, and the codebase already follows it
by hand: tabs, spaces inside parentheses, Yoda conditions, `array()` over `[]`,
`esc_*` on output, `memberistic_sanitize_*` on input.

Today CI runs PSR-12 against one file, advisory, always exiting 0. That is worse
than nothing in one specific way: it looks like a standard is enforced.

### Migration

1. **Now:** WPCS blocking on new and changed files only. A PR that touches a
   file must leave that file no worse.
2. **Then:** baseline existing violations so the historical debt is visible but
   not blocking.
3. **Then:** burn the baseline down module by module, ideally alongside the
   refactor PRs that are touching those files anyway.
4. **Then:** add PHPStan at a level the codebase can actually reach, and ratchet
   it. Level chosen by what passes, not by ambition.
5. **Also:** ESLint for admin and frontend JavaScript, using the WordPress
   config.

Replace the advisory PSR-12 job when WPCS lands. Do not run both.

### The convention worth protecting

Comments explain **why**, not what. Non-obvious decisions carry a paragraph on
the reasoning and the failure mode avoided — the duplicate-copy guard in the
bootstrap file, `Installer::preserve_pre_2_0_defaults()`, the integrations
default-off policy. No linter enforces this and none can. It is the single most
valuable thing about this codebase for anyone arriving cold, including an agent.

Match it when you make a judgement call. Drop it when the code is self-evident.

---

## 4. Frontend architecture

**Admin JavaScript is vanilla `wp.element`.** IIFEs using
`wp.element.createElement` aliased to `h`, with `wp.apiFetch` and `wp.i18n`. No
JSX, no imports, no build output. Scripts enqueue conditionally per screen in
`Plugin::maybe_enqueue_react_app()`, keyed on `$_GET['page']`, with initial
state passed via `wp_add_inline_script()` into `window.memberistic*Settings`.

Introducing a build step here is a real decision with real costs — a toolchain
to maintain, a `node_modules` to secure, a compile step between the source and
the shipped file. If it ever happens, it needs an ADR, not a pull request.

**Frontend assets load only where used** — pages with a Memberistic shortcode
or a configured Memberistic page. A deliberate performance decision. New
shortcodes must be added to that list, and to
`send_sensitive_page_cache_headers()` if they render member-specific data.

**Styling goes through `--memberistic-*` custom properties** in
`assets/token-bridge.css`, which prefer a matching theme token and fall back to
a contrast-checked neutral. No hard-coded colours in templates. This is what
lets the plugin look native in an arbitrary theme.

Frontend JS reads `window.memberisticApi`, never the global `wpApiSettings`.

---

## 5. Data layer

Repositories are static, no ORM, raw `$wpdb` with `prepare()`. Where a table
name must be interpolated it comes from a repository's `table()` method — never
from input — and the line carries a targeted
`// phpcs:ignore WordPress.DB.PreparedSQL.*`.

Keep that shape. An ORM would add a runtime dependency the plugin deliberately
does not have.

**Schema changes need three edits** — schema, migration, version bump plus
registration. Migrations are idempotent and return `true`; returning `false`
halts the runner and leaves `memberistic_db_version` where it was so the upgrade
resumes next request. That resumability is deliberate. Full procedure in
[`../../CONTRIBUTING.md`](../../CONTRIBUTING.md).

**Settings live in one option.** `memberistic_settings` is a single array, read
through `memberistic_get_setting()` — never `get_option()` directly. Stripe
secrets can be locked by constants, and when a constant is set the option value
must not be overwritten and the secret must never be returned in plain text over
REST.

---

## 6. What the architecture is missing

Gaps that will need architectural answers, listed here so they are not
discovered mid-feature:

| Gap | Needed for |
|---|---|
| Pro module loader with per-feature flags | M3, M4 |
| Signed update manifest and reproducible packaging | M3 |
| Cloud connector: signed webhooks, idempotency keys, entitlement tokens | M5 |
| A queue or job abstraction beyond WP-Cron | dunning, automation, cloud sync |
| Structured logging with secret redaction | support diagnostics, M1 |
| Feature-flag mechanism usable in both Free and Pro | M4 |

Each of these should get an ADR when it is designed, not after it is built.
