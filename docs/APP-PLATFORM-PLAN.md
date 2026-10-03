# Memberistic platform plan

## Product direction

Memberistic should become the membership operating system behind WordPress sites, SaaS products, agencies, and service businesses. The WordPress plugin remains a strong connector and on-site membership layer; the hosted product becomes the system of record for identity, accounts, subscriptions, entitlements, communications, and automation.

Requested public structure:

- `memberistic.com` — marketing site, documentation, pricing, integrations, and partner content.
- `app.memberships.com` — hosted dashboard and member portal.
- `api.memberships.com` — versioned API and webhook receiver.
- Optional later split: `auth.memberships.com` for centralized identity and `hooks.memberships.com` for isolated inbound webhooks.

The `.com` naming decision should be validated for trademark, domain control, and customer clarity before launch. The implementation should keep the product name Memberistic even if the app domain remains `memberships.com`.

## Outseta benchmark

Outseta's public product model is a useful benchmark, not a template to copy. Its core shape combines authentication, billing, CRM, email, help desk, and integrations behind one account model. Its public materials also emphasize WordPress and framework integrations, branded sign-up/login/profile/billing surfaces, webhooks/API access, and team/group memberships.

The important architectural lesson is the identity relationship:

```text
Person ──< PersonAccount >── Account ──< Subscription ── Plan
   │                            │
   ├── login / profile          ├── billing owner / team
   ├── roles                    ├── CRM / support history
   └── consent                  └── entitlements / integrations
```

Memberistic should use stable internal UUIDs for these relationships. Provider IDs such as Stripe customer, subscription, invoice, and payment-intent IDs remain external references and never become primary keys.

## Recommended hosted architecture

### Frontend

- Next.js App Router, TypeScript, and a small Memberistic design system.
- Server-rendered public pages where SEO matters; authenticated dashboard routes behind a server-side session check.
- Shared components for account switcher, plan/billing, people/team, invoices, usage, support, integrations, and workflow history.
- No provider secret, API key, webhook secret, or privileged tenant token in browser code.

### API and data

- Laravel API on PHP 8.3+ at `api.memberships.com`, matching the team's WordPress/PHP strengths.
- PostgreSQL for tenant-scoped relational data; Redis for queues, rate limits, idempotency locks, and short-lived sessions.
- Object storage for signed documents and exports; malware scanning and private-by-default URLs.
- Worker process for email, webhook delivery, imports, billing reconciliation, and automation actions.
- OpenAPI-generated client for the app and WordPress connector.

### Provider boundary

Define provider interfaces for billing, email, SMS/WhatsApp, identity, storage, and help desk. Stripe Billing is the first provider, but the domain model must not make Stripe IDs or Stripe event names the business model. Every provider event is authenticated, normalized, idempotently claimed, relationship-checked, and audited before it changes access.

## Core domain model

1. `accounts`: tenant/business workspace, brand, timezone, locale, billing owner, plan.
2. `people`: human identity, email verification, consent, profile, status.
3. `account_people`: person-to-account membership, role, invitation state, limits.
4. `plans`: product plan, price versions, included seats, features, limits.
5. `subscriptions`: account subscription, provider references, state machine, renewal dates.
6. `invoices` and `payments`: immutable financial records with provider reconciliation status.
7. `entitlements`: account/person access grants with source, expiry, and audit history.
8. `resources` and `access_rules`: protected content, routes, downloads, API scopes, and feature gates.
9. `contacts`, `activities`, `tickets`, and `notes`: CRM and support history.
10. `messages`, `templates`, `sequences`, and `workflow_runs`: communication and automation.
11. `integrations`, `api_keys`, `webhook_endpoints`, and `delivery_attempts`: controlled external access.
12. `audit_events`, `consents`, `exports`, and `deletion_requests`: compliance and accountability.

Every tenant-owned table needs an `account_id` and server-side authorization check. A request must resolve the account from the authenticated session and never trust an account ID supplied by the browser.

## MVP: the smallest sellable platform

### Phase 1 — platform core

- Account/person identity, email verification, passwordless or secure password login, sessions, logout, and account switching.
- Plan catalog and versioned prices.
- Stripe Checkout, customer portal, subscriptions, invoices, payment methods, webhooks, retries, and reconciliation.
- Team invitations, roles, seat limits, and account owner transfer.
- Hosted member profile, billing portal, invoices, and entitlement view.
- REST API, signed webhooks, idempotency keys, request IDs, audit log, and rate limits.
- WordPress connector that maps one site to one account and synchronizes member status/entitlements.

### Phase 2 — conversion and retention

- Protected WordPress pages/posts/downloads and SaaS feature gates.
- Forms, lead capture, CRM timeline, tags, notes, and imports.
- Transactional email templates, failed-payment sequences, cancellation surveys, verification reminders, and renewal notifications.
- Discount codes, trials, coupons, affiliate/referral attribution, and usage limits.
- Member self-service: upgrade, downgrade, pause, cancel-at-period-end, invite team members, update profile, and export data.

### Phase 3 — ecosystem and agency scale

- White-label domains and email branding.
- Agency workspaces with delegated access and cross-account reporting.
- OIDC/OAuth clients, API keys with scopes, sandbox accounts, and test webhooks.
- WhatsApp/SMS integrations through Messageistic, CRMistic, and Chatbotistic.
- WordPress plugin marketplace, add-on entitlements, and Licenseistic validation.
- Usage metering, overage billing, revenue analytics, and customer health automation.

## WordPress plugin role

The standalone plugin should remain useful without the hosted app:

- local membership tables and account pages;
- Stripe/WooCommerce checkout and payment integrity;
- content restriction and member verification;
- people, groups, waivers, bookings, POS/coreSTORE, and FFL adapters;
- admin reports, privacy tools, exports, and WP-CLI diagnostics.

The cloud connector should be opt-in and additive. It should store only the minimum site identifier, account mapping, public configuration, and encrypted server-side credential needed to sync. It should use signed requests, replay protection, scoped capabilities, backoff, and an outbound delivery log. A cloud outage must not silently grant access or destroy local membership records.

## Security baseline

- Verify JWTs against a rotating JWKS; validate issuer, audience, expiry, nonce, and tenant claims.
- Keep API keys server-side and scope them to an account and operation set.
- Verify webhook signatures over the exact raw request body before JSON parsing; use event IDs and idempotency locks.
- Encrypt provider credentials and sensitive integration secrets at rest; never put them in logs or client bundles.
- Use strict tenant authorization on every query and mutation, with owner/admin/member/support roles.
- Add login, invitation, checkout, API, and webhook rate limits; use CAPTCHA only where abuse evidence warrants it.
- Make exports, deletion, bulk changes, refunds, and account ownership changes explicit, audited, and recoverable.
- Minimize PII in analytics and operational logs; define retention and deletion policies before public launch.

## Monetization model

Do not copy Outseta's price points or packaging word-for-word. Use the market benchmark only to validate willingness to pay. A practical Memberistic structure is:

- Starter: one site/account, core memberships, checkout, member portal, and limited email automation.
- Growth: multiple plans, teams, discount codes, CRM, workflows, and integrations.
- Pro: API/webhooks, advanced reporting, white-label controls, higher limits, and priority support.
- Agency: many client accounts, delegated access, reusable templates, client billing, and white-label delivery.

Price by account value and platform limits rather than raw user count alone. Meter optional drivers such as active accounts, automation runs, outbound messages, storage, and agency workspaces. Keep WordPress plugin licensing separate from hosted usage so customers can adopt the local plugin before committing to SaaS.

## Delivery gates

The app should not be called production-ready until it proves:

1. isolated tenant tests for every account-owned query;
2. payment webhook replay and out-of-order event tests;
3. Stripe test and live-mode account separation;
4. clean WordPress connector install, upgrade, disconnect, and retry behavior;
5. privacy export/erase and data-retention checks;
6. backup/restore rehearsal for database, object storage, and webhook delivery state;
7. browser proof for signup, login, team invite, checkout, portal, cancellation, and entitlement changes;
8. monitoring for failed webhooks, payment discrepancies, queue backlog, auth errors, and tenant-isolation violations.

## Research references

- [Outseta](https://www.outseta.com/)
- [Outseta help and integration documentation](https://go.outseta.com/support/kb/articles/6481692-using-outseta-with-your-website-or-app)
- [Outseta REST API documentation](https://go.outseta.com/support/kb/articles/9127311-outseta-api)
- [Outseta agent toolkit](https://github.com/outseta/agent-toolkit)
- [Outseta GitHub organization](https://github.com/outseta)
