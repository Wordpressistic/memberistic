# Business model

**Status: proposal.** Nothing here is published pricing or a commercial offer.
All competitor figures are as observed on **2026-08-08** and must be
re-verified before appearing anywhere public — see [`sources.md`](sources.md).

---

## 1. The shape

```
Free (WordPress.org)  →  Pro $79/yr  →  Lifetime $299  →  Cloud $19–79/mo  →  Services
     acquisition          low-friction     cash flow        recurring margin    high margin
```

The central decision: **keep the plugin inexpensive and make the cloud layer
the recurring-margin product.** The plugin buys the customer; the cloud keeps
them.

---

## 2. Free — $0 forever

**Memberistic transaction fee: 0%.** Not now, not later. This is the clearest
promise available against the market leader, and it is not recoverable once
given up.

### What stays in Free

Everything already public in 2.0.0 stays free. **Do not remove existing
capability to manufacture a paid tier** — that is risk R6, and it would destroy
the acquisition engine to win one comparison table.

Unlimited members · unlimited basic plans · monthly and annual pricing · Stripe
basic recurring checkout · member account and billing link · basic post/page
restriction · linked people and family support · basic waiver workflows · basic
check-in and front-desk · standard transactional emails · basic WooCommerce
sync · CSV import/export · privacy, export and erase tools · developer hooks ·
basic dashboard and reporting.

This is deliberately generous. It buys installs, reviews, SEO mentions,
migration volume and trust — the things a new entrant cannot buy any other way.

**The lesson from Paid Memberships Pro:** a very generous open-source core has
not stopped them charging $499–$2,999/year for support, add-ons and hosting.
Do not be afraid of a strong free product.

---

## 3. Pro — $79/year

- **Licence:** 1 production domain, plus local, staging and dev environments
- **Renewal:** same annual price; grandfather existing customers where
  commercially practical

### Built from *new* value only

Advanced access rules for CPTs, taxonomies and categories · partial-content
protection · content drip and scheduled unlocks · coupons and promotions ·
checkout free trials · upgrade/downgrade with proration · payment plans ·
failed-payment dunning · abandoned-checkout recovery · gifting · advanced email
automation · webhook/API automation builder · advanced Woo member pricing ·
additional gateway add-ons · social login and SSO · advanced directory controls
· advanced corporate/group workflows · advanced reports (MRR, ARR, churn, LTV,
cohorts, retention) · one-click migrations from major membership plugins ·
premium portal templates · priority support · the Cloud connector.

### Why $79

Positioning, not cost-recovery. Restrict Content Pro anchors "affordable
professional plugin" at $99/year for one site; SureMembers starts at $69–$99.
$79 sits below the credible anchor while staying above the price point that
signals a hobby project.

Price alone is not a strategy. The differentiation has to come from the
operational features — family accounts, waivers, front desk, groups — not from
being cheapest.

---

## 4. Lifetime — $299 one-time

One production domain.

Optional launch tactic: **Founder LTD at $249, capped at the first 500
licences**, then $299–$349 standard.

### What lifetime means

- perpetual use of the licensed Pro plugin on the licensed domain
- lifetime plugin updates while the product is maintained
- **12 months** priority support included
- optional support renewal thereafter (example: $39/year)

### What lifetime must never include

Perpetual unlimited cloud compute, chat, email, AI, storage, custom-hostname
infrastructure, or any managed SaaS feature. Those carry continuing cost per
customer per month and must remain subscriptions. A lifetime licence that
includes recurring infrastructure is a liability that grows with success.

### Never sell cheap unlimited-site lifetime

It creates permanent support and infrastructure obligations while removing the
renewal engine entirely. SureMembers' $599 for up to 100 sites is a useful
benchmark for how *not* to price a one-site product, and a reminder that LTD
buyers are the highest-support, lowest-revenue cohort you will ever have.

LTD is a **launch and cash-flow instrument**, not the business model. Risk R4.

---

## 5. Memberistic Cloud

A separate product layer. **A customer can use the WordPress plugin forever
without Cloud** — that is not a concession, it is what makes the plugin
trustworthy.

### Cloud Starter — $19/month or $190/year

1 connected site · hosted member portal · `brand.memberistic.app` subdomain ·
portal templates and branding · a sensible included active-member allowance ·
member directory · announcements · basic chat/community · basic automations ·
basic cloud analytics.

### Cloud Growth — $39/month or $390/year

Custom domain · white-label portal · advanced community channels · automation
workflows · webhook/API integrations · advanced analytics · file/resource
library · custom transactional email branding · additional administrators ·
higher usage limits.

### Cloud Business — $79/month or $790/year

Multiple portals or locations · SSO · advanced permissions · audit-log
retention · advanced branding · priority sync and queues · higher chat and
storage limits · advanced analytics and export · an SLA-style support target
where commercially supportable.

Usage-based overages for heavy chat, storage, email and AI workloads can come
later. Do not make launch pricing confusing.

---

## 6. Services

| Service | Indicative price |
|---|---|
| Migration & launch | $499–$999 depending on scope |
| Done-for-you member-site setup | fixed package, higher |
| Custom portal design | project |
| Data cleanup and migration | project |
| Automation setup | project |
| Agency implementation partnerships | negotiated |

High margin, high signal — every migration teaches you exactly what the
importer should have handled automatically.

---

## 7. Licensing rules

These are product constraints, not commercial preferences.

1. **An expired licence never disables member access**, local payments, renewals
   or any core local operation. It may disable premium modules only. (Invariant
   I4.)
2. **No network call on a visitor-facing request** merely to validate a licence.
   Licence state is cached.
3. **Network failure fails open** for a site that is already licensed. A DNS
   problem at the vendor must never become an outage at the customer.
4. **Feature gates disable premium modules, never core membership access.**
5. **Production domain plus staging/local recognition** — developers must not
   burn a licence seat on a staging copy.
6. **Data stays portable.** Site owners retain their membership data and can
   export it, regardless of licence state. (Invariant I7.)

Rules 1–3 are what "no hostage licensing" means concretely. They are also a
marketing asset — very few competitors will state them this plainly.

---

## 8. The $1M model

**This is arithmetic, not a forecast.** It shows one mix that reaches the
target. The volumes are aggressive for a new product and assume distribution
that does not exist yet.

| Stream | Volume | Price | Revenue |
|---|---:|---:|---:|
| Pro Annual | 4,000 | $79/yr | $316,000 |
| Lifetime | 1,000 | $299 | $299,000 |
| Cloud | 500 avg active | $39/mo avg | $234,000 |
| Migration/launch services | 155 | $999 | $154,845 |
| **Total** | | | **$1,003,845** |

### What it actually requires

- 50k–100k free installs, downloads or exposures over the year
- strong activation to a first plan
- 3–6% conversion of qualified active users to paid plugin products
- Cloud conversion concentrated in businesses that need a branded portal or
  community
- migrations and agency referrals as the high-intent acquisition channels

Pricing does not deliver this. **Distribution does.** See
[`07-website-and-growth.md`](07-website-and-growth.md).

### Revenue quality in year two

Reduce LTD dependence. Increase annual renewals, Cloud ARR, higher-value
business accounts, and partner/implementation revenue. A second year that looks
like the first — heavy on one-time licences — is a business that has not
compounded.

---

## 9. Competitor pricing context

Observed 2026-08-08. **Re-verify before publishing.**

| Product | Public pricing observed |
|---|---|
| Paid Memberships Pro | Free core; Standard $499/yr; Hosted Max $999/yr; managed $2,999/yr |
| MemberPress | Launch $199.50/yr promo (list $399) with 4.9% transaction fee; Growth $314.55 (list $699); Scale $399.60 (list $999) |
| SureMembers | Starter $69/yr promo (reg. $99); Pro $149 (reg. $199); Business $199 (reg. $299); Business Lifetime $599 for up to 100 sites |
| WishList Member | Basic $149.50/yr (list $299); Plus $249.50 (list $499); Pro $349.50 (list $699) |
| Restrict Content Pro | 1 site $99/yr; 5 sites $149; unlimited $249 |
| Ultimate Member | Free; Standard $276/yr 1 site; Pro $348/yr 2 sites |
| WooCommerce Memberships | $199/yr, plus Subscriptions at $279/yr — about $478/yr combined for standard recurring membership |

The WooCommerce line is the strongest commercial argument Memberistic has:
customers routinely assemble two expensive extensions to get recurring
memberships that Memberistic does in one plugin.

Analysis in [`05-market-position.md`](05-market-position.md).
