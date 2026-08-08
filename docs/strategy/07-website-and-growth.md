# Website and growth

The $1M model in [`04-business-model.md`](04-business-model.md) is not delivered
by pricing. It is delivered by distribution. This document is the distribution
plan.

---

## 1. Property split

| Property | Purpose | Stack |
|---|---|---|
| `memberistic.com` | Marketing, SEO, content, docs | WordPress |
| `app.memberistic.com` | SaaS control plane | Cloudflare Workers |
| `docs.memberistic.com` | Documentation, if it warrants its own surface | either |
| `*.members.memberistic.com` (or a shorter dedicated portal domain) | Customer member portals | Workers + SSL for SaaS |

WordPress for the marketing layer is the right call here specifically because
the audience is WordPress-native and the content workflow is straightforward —
and because a membership plugin whose own site does not run WordPress invites an
obvious question.

---

## 2. Homepage structure

1. **Hero** — outcome-led. Primary CTA *Download Free*, secondary *View Live
   Demo*. State the promise immediately: no Memberistic transaction fee.
2. **Why Memberistic** — billing + people + access + operations + community as
   one concept.
3. **Operational differentiators** — linked people, waivers, member
   verification and check-in, groups and corporate, dedicated data
   architecture. This is the section that does the actual differentiating.
4. **Member experience demo** — account, directory, portal, community preview.
5. **Pricing** — Free / Pro Annual / Lifetime, with Cloud shown as optional and
   clearly not required to use the plugin.
6. **Migration** — "move from your current membership plugin without rebuilding
   your business."
7. **Developers** — REST API, hooks, webhooks, adapter architecture.
8. **Trust** — public changelog, security policy, privacy and data ownership,
   compatibility matrix, status page.
9. **Comparison** — MemberPress, PMPro, SureMembers, WooCommerce Memberships.
10. **Final CTA** — Download Free.

Section 8 is doing more work than it appears to. A new membership plugin asking
people to trust it with member PII and payments needs to publish its
compatibility matrix and security policy before anyone will.

---

## 3. Required pages

```
/free/          /pro/           /lifetime/      /cloud/
/features/      /member-portal/ /community/     /automations/
/templates/     /migrations/    /developers/    /docs/
/security/      /changelog/     /roadmap/       /status/      /support/

/compare/memberpress/
/compare/paid-memberships-pro/
/compare/suremembers/
/compare/woocommerce-memberships/
```

`/security/` and `/status/` are not decoration — they are conversion assets for
exactly the operationally serious buyers Memberistic is best suited to.

---

## 4. SEO / AEO / GEO

Answer engines and generative engines cite pages that are specific, dated and
verifiable. The same discipline that makes a comparison page honest makes it
citable.

### Commercial and problem intent first

WordPress membership plugin · free WordPress membership plugin · affordable
WordPress membership plugin · MemberPress alternative · Paid Memberships Pro
alternative · membership plugin with member directory · membership plugin for
family accounts · custom-domain member portal · WordPress membership community ·
membership automation · membership analytics · migrate membership plugin ·
membership management for clubs / associations / service businesses.

### The clusters nobody else owns

These map directly onto the differentiators and have far less competition than
the head terms:

- gym / studio / range membership management on WordPress
- digital waiver software for WordPress
- family membership plugin / linked member accounts
- member check-in and front desk for WordPress
- corporate and group memberships on WordPress
- association membership management

Write these first. They convert better and rank faster than "WordPress
membership plugin", where the incumbents have a decade of authority.

### Rules for every comparison page

Evidence-based · dated · sourced · updated on a schedule · honest about what the
competitor does better. Risk R9 in the master plan is a stale price on a public
page.

---

## 5. Distribution channels

### 1. WordPress.org — the main engine

After Plugin Directory compliance is complete this becomes the primary
acquisition channel.

Requirements: installable free core · clear readme and screenshots · onboarding
wizard · no artificial feature destruction · migration and import tools · strong
support docs · review requests only at genuine success moments (first member
added, first payment processed — never on activation).

Plugin Check compliance is a P0 item and blocks this entirely.

### 2. Comparison SEO

Alternatives, migrations, feature comparisons, cost comparisons, operational use
cases. High intent, and the cost comparison against WooCommerce
Memberships + Subscriptions (~$478/yr) is the strongest single argument
available.

### 3. Template-led acquisition

Importable starter kits per membership business model — the repository already
ships `templates/plans/*.json` for gym, club, studio, range, association and a
generic tiered model. That is a distribution asset sitting unused. Later, the
same templates feed the Cloud portal builder.

### 4. Migration as a growth feature

Guided importers from the major membership plugins. Migration is simultaneously:

- the highest-intent acquisition path (someone actively leaving a competitor);
- the strongest conversion path to Pro and Cloud;
- the best product feedback loop available — every migration shows exactly what
  the importer should have handled automatically;
- the entry point for the $499–$999 services tier.

It is worth more than most feature work of equivalent effort.

### 5. Agency and implementer channel

**After** single-site product-market fit, not before: partner licence · client
transfer · referral commission · certified partner directory · migration tooling
· white-label Cloud tier.

Do not lead the launch with agency plans. Keep the first pricing page simple —
a confusing pricing page costs more conversions than a missing tier.

---

## 6. Launch sequencing

| Phase | Depends on | Do |
|---|---|---|
| Pre-launch | M0 release trust | Compatibility page, security page, changelog, docs, status page |
| Soft launch | WordPress.org listing | Free core, onboarding, importer, support workflow |
| Content ramp | Soft launch | Differentiator clusters, then comparison pages |
| Pro launch | M3 licensing | Pricing page, checkout, account area, migration wizard |
| LTD window | Pro launch | Founder LTD, capped at 500, time-boxed |
| Cloud beta | M5 | Invite from existing Pro base — never cold |

The LTD window is a *window*. Left open indefinitely it becomes the default
purchase and the renewal engine never starts (risk R4).

---

## 7. What to measure

Full definitions in [`08-metrics.md`](08-metrics.md). The short list that tells
you whether distribution is working at all:

- free installs and activated sites
- onboarding completion, first plan created, first member added
- first paid membership processed — the real activation moment
- migration starts and completions
- Free → Pro conversion
- Pro → Cloud attach rate

If installs grow and "first paid membership processed" does not, the problem is
onboarding, not marketing.
