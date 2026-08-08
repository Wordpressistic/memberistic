# Memberistic Cloud — SaaS architecture

Working name: **Memberistic Cloud** (alternatively Memberistic Portal).

**Status: design proposal.** No cloud code exists.

---

## 1. The goal, and the boundary

Do not replace WordPress. Make WordPress the membership **source of truth and
runtime**, and let Cloud supply the modern hosted experience WordPress sites
struggle to deliver consistently:

custom-domain member portal · member directory · real-time community and chat ·
branded pages and templates · automation · analytics · integrations ·
cloud-managed assets and optional managed services.

### The boundary that must not move

> **A Memberistic Cloud outage must never disable a customer's local WordPress
> memberships.**

Risk R5. This is not a resilience nicety; it is the difference between a
value-add service and a single point of failure bolted onto someone's business.

Concretely:

- WordPress `memberistic_*` tables remain authoritative for local membership and
  access state.
- Cloud stores a **synchronised projection**, sufficient for portal, community
  and automation — never the authority for local access.
- Local authorization never makes a synchronous call to Cloud.
- There is an explicit test: cloud unreachable → local memberships, check-in and
  payments all still work.

---

## 2. Staged architecture

### The recommendation that saves the most time

**Do not use Workers for Platforms for every tenant on day one.**

Cloudflare describes Workers for Platforms as infrastructure for multi-tenant
platforms running customer-supplied or AI-generated code in isolated Workers.
That is excellent for an advanced template/app builder. It is substantial
complexity to serve identical HTML with different colours.

A configuration-driven portal launches faster and cheaper on a **shared
multi-tenant Worker** with tenant routing and SSL for SaaS.

### V1 — shared multi-tenant platform

| Layer | Choice |
|---|---|
| Control plane UI | SaaS admin template as the starting point, then a Memberistic design system |
| API | Cloudflare Worker, typed and documented via Chanfana/OpenAPI |
| Relational data | Managed PostgreSQL — tenants, orgs, billing, config, analytics metadata |
| Hot config | Cloudflare KV — hostname → tenant mapping and config cache |
| Assets | R2 — portal media, exports |
| Realtime | Durable Objects — chat, presence |
| Async | Queues / Workflows — webhook ingestion, sync, email and automation jobs |
| Routing | Wildcard portal subdomains; SSL for SaaS custom hostnames for customer domains |

### V2 — Workers for Platforms

Adopt it when a customer can genuinely:

- deploy a custom portal application;
- generate a unique Worker app from AI or template code;
- add custom business logic;
- run isolated extensions;
- publish independent template apps at scale.

Then: dispatch namespace · dynamic dispatch Worker · per-customer user Worker ·
tenant tags (`tenant_id`, `plan`, `environment`) · explicit resource bindings ·
custom hostname routing · per-tenant limits and observability.

**Trigger for the V1 → V2 decision:** paying customers asking for custom code or
per-tenant logic, not internal enthusiasm. Record it as an ADR when it happens.

---

## 3. Modules

### A. Control plane

Organisations and tenants · users and admin roles · connected WordPress sites ·
licence and subscription state · portal deployment · custom domains · template
selection · usage · billing · audit logs.

### B. WordPress connector

The plugin and Cloud communicate over signed APIs and webhooks.

Events: member created/updated · membership activated/paused/expired/cancelled ·
plan changed · payment succeeded/failed/refunded · linked person added/removed ·
waiver state changed · group membership changed · profile updated · check-in and
engagement events **only where the site owner explicitly enables cloud
analytics**.

Rules:

- HMAC-signed webhooks, verified before parsing.
- Idempotency keys on every event.
- **Never trust a tenant ID supplied by a client.** Resolve it from
  authenticated connection state.
- The connector is an integration and therefore defaults to off, like every
  other integration.
- Sync failure degrades the portal, never local operations.

### C. Portal builder

Template-driven sections: portal home · member profile · directory · plans and
upgrade · billing · events and resources · documents · announcements ·
community · support · custom pages.

Brand tokens: logo · typography · primary and secondary colours · radius and
spacing presets · button styles · navigation · footer · custom domain.

### D. Community

Channels · plan/group-based channels · announcements · threaded discussion
(later) · direct messages (later) · presence indicators · moderation, reporting
and blocking · retention configuration · attachments under policy · notification
preferences.

**Chat topology:** one Durable Object per logical channel — never one global
object for all customers. Object ID derives from tenant + channel. Hibernatable
WebSockets; SQLite-backed DO state for the live message window; archive to
Postgres or R2 where needed. Entitlement checked on channel join, using a
short-lived signed Memberistic access token.

### E. Automation

**Triggers:** signup · activation · failed payment · expiration approaching ·
cancellation · plan change · waiver expiration · inactivity · community event ·
profile completion · webhook.

**Actions:** email · notification · webhook · add/remove tag · grant/revoke
portal section · add/remove community channel · create staff task · send to
external integration · delay/wait · branch by condition.

### F. Analytics

Active members · new and cancelled · MRR/ARR · churn · LTV · cohort retention ·
payment failures and recovery · plan conversion · portal engagement · community
engagement · automation performance.

---

## 4. Tenancy and authentication

### Hierarchy

```
Organization
├── Sites (connected WordPress installs)
├── Portals
├── Custom domains
├── Admin users
├── Members (cloud projection — reference, not authority)
├── Communities
├── Automations
└── Usage / billing
```

### Member portal auth

1. Member authenticates through the connected site, or through a Memberistic
   Cloud identity bridge.
2. The server issues a **short-lived signed portal token** carrying tenant,
   site, member and entitlement-version claims.
3. Cloud verifies signature and token age.
4. Sensitive operations revalidate membership state.
5. A membership state change **increments the entitlement version**, so
   outstanding tokens become unusable quickly.

Never put payment secrets or long-lived WordPress admin credentials in a
browser token.

The entitlement-version mechanism is what makes short-lived tokens safe: without
it, a cancelled member keeps portal access until their token expires.

---

## 5. Community security and moderation

Not optional, and not a phase-two concern. Before any public community launch:

channel membership authorization · tenant isolation · rate limits · abuse and
report flow · block and mute · moderator actions · audit log · configurable
retention · attachment type and size restrictions · content security policy ·
safe link handling · notification preferences · integration with data export and
delete.

> Community is not just WebSockets. Moderation and authorization are product
> requirements, and shipping chat without them creates a liability that arrives
> faster than the revenue.

---

## 6. Service selection

| Need | Service |
|---|---|
| Relational tenant/billing/config data | PostgreSQL |
| Fast tenant config lookup | Cloudflare KV |
| Portal media and exports | R2 |
| Realtime chat and presence | Durable Objects |
| Async webhook and automation jobs | Queues / Workflows |
| Edge API | Cloudflare Workers |
| Per-tenant isolated customer code | Workers for Platforms (V2 only) |
| Custom customer domains | SSL for SaaS / custom hostnames |
| API contract and docs | Chanfana / OpenAPI Worker |

Use D1 selectively where edge-local operational simplicity genuinely wins for a
specific module. Do **not** fragment core billing and tenant relational data
across multiple databases without a written reason.

---

## 7. API surface

Versioned, contract-first, generated docs:

```
/v1/sites          /v1/tenants      /v1/members
/v1/memberships    /v1/portal       /v1/domains
/v1/community      /v1/automations  /v1/webhooks
```

A formal OpenAPI contract buys generated interactive documentation, request
validation, straightforward SDK generation and much easier partner
integrations. Worth doing from the first endpoint rather than retrofitting.

---

## 8. Open questions

To be answered with ADRs before Cloud implementation starts:

1. **Data residency.** Where does member PII physically live, and what is
   offered to EU customers?
2. **Deletion contract.** When a member is erased in WordPress, what removes the
   cloud projection, on what timeline, and how is it proven?
3. **Connector auth.** Site-issued key, OAuth-style flow, or both?
4. **Sync direction.** Is the portal ever authoritative for anything — profile
   fields, community preferences — and if so, how does that write back?
5. **Free-tier abuse.** What stops a connected site pushing unbounded events?
6. **Migration path.** How does a customer leave Cloud with their community
   content and portal configuration intact? (Invariant I7 applies to Cloud too.)

Question 6 is the one most likely to be skipped and the one most likely to be
asked by exactly the customers worth having.
