# Market position

All competitor observations are as of **2026-08-08** and must be re-verified
before publication. See [`sources.md`](sources.md).

---

## 1. Positioning

> **Memberistic is the membership operating system for WordPress — manage
> people, plans, recurring billing, protected access, member operations and
> community from one platform.**

### Do not position as

- a cheap MemberPress clone;
- another content-restriction plugin;
- "every competitor feature on day one."

Each of those puts Memberistic in a comparison it currently loses, on ground it
did not choose.

### Core promises

1. **Free means usable.** No fake free plan and no Memberistic transaction fee.
2. **Affordable Pro.** One simple annual licence, not a maze of feature
   bundles.
3. **Operations-first.** Family and linked people, waivers, front desk, groups,
   real business workflows.
4. **Cloud when you need it.** Custom-domain portal, community, automation and
   analytics — without replacing WordPress.
5. **No hostage licensing.** An expired Pro licence never disables existing
   memberships or member access.
6. **Portable data.** Owners keep their membership data and can export it.

Promise 5 is unusually concrete and unusually rare. It should be stated in the
licensing terms, not just in marketing — see
[`04-business-model.md`](04-business-model.md) §7.

---

## 2. Capability cross-match

Where Memberistic 2.0.0 actually stands.

| Capability | Status |
|---|---|
| Plans and members | Strong |
| Recurring Stripe subscriptions | Present |
| Member billing portal | Present |
| WooCommerce sync | Present |
| Manual payment records | Present |
| **Linked family / people** | **Differentiator** |
| **Waivers, re-consent, archive** | **Differentiator** |
| **Front desk, check-in, kiosk, QR** | **Differentiator** |
| **Corporate / group memberships** | **Differentiator** |
| **Operational staff workflows** | **Differentiator** |
| **Dedicated membership tables** | **Architecture differentiator** |
| Basic post/page restriction | Present |
| Transactional email and log | Present |
| CSV migration | Present |
| GDPR tools | Present |
| Licensing framework | Seam only, not implemented |
| Community / chat | Missing |
| Hosted custom-domain portal | Missing |
| Content drip / scheduling | Missing or limited |
| Coupons / promotions | Not mature |
| Checkout trials | Not mature |
| Proration / upgrade engine | Not mature |
| Gifting | Missing |
| Abandoned-checkout recovery | Missing |
| Dunning / revenue recovery | Missing |
| Full affiliate system | Missing |
| Course / LMS suite | Missing |
| Mature community suite | Missing |
| Multi-gateway ecosystem | Limited |
| Deep marketing integrations | Limited |
| Retention / cohort / LTV analytics | Missing or limited |
| One-click competitor migration | Generic/legacy import only |
| Hosted SaaS control plane | Missing |

Read the two halves together: the differentiators are all **operational**, and
every gap is either **growth tooling** or **member experience**. That is the
whole strategy in one table — defend operations, buy growth tooling with Pro,
buy member experience with Cloud.

---

## 3. The competitors

### Paid Memberships Pro

Free self-hosted open-source platform; Standard $499/yr; Hosted Max $999/yr;
higher managed tier $2,999/yr.

**Strengths:** very generous open-source core, large add-on ecosystem, long
market history, strong developer extensibility, managed hosting and
done-for-you services producing high ARPU.

**Lesson:** do not be afraid of a strong free product. Monetise support,
premium growth modules, hosting/cloud and implementation.

### MemberPress

Launch $199.50/yr promo (list $399) **with a 4.9% MemberPress transaction fee**;
Growth $314.55 (list $699); Scale $399.60 (list $999), both without the fee.

**Strengths:** polished setup and monetisation, courses/LMS, coupons and
upgrade/downgrade mechanics, paywall depth, member dashboard, growing community
features (forums, directory, messaging), large integration surface.

**Lesson:** Memberistic needs growth and revenue tooling plus community — not
only back-office membership management. And the transaction fee on their entry
tier is the single clearest thing to compete against.

### SureMembers

Starter $69/yr promo (reg. $99); Pro $149 (reg. $199); Business $199 (reg.
$299); Business Lifetime $599 for up to 100 sites.

**Strengths:** modern interface, straightforward access control, affordable
positioning, a strong lifetime benchmark.

**Lesson:** the closest pricing comparison for the proposed launch. Memberistic
can sit near or below the $99 one-site anchor — but needs stronger
differentiation than price.

### WishList Member

Basic $149.50/yr (list $299); Plus $249.50 (list $499); Pro $349.50 (list $699).

**Strengths:** content drip and scheduling, courses, payment integrations,
automation ecosystem, mature upgrade paths.

**Lesson:** drip, automation and integrations are table stakes for content and
creator segments. Memberistic does not have to win that segment, but it cannot
show up empty-handed.

### Restrict Content Pro

1 site Pro $99/yr; 5 sites $149; unlimited $249; 34 pro add-ons marketed with
the paid plan.

**Strengths:** lean, developer-friendly access product, long-standing
restriction model.

**Lesson:** $99/yr is the credible "affordable professional plugin" anchor.

### Ultimate Member

Free; Standard $276/yr one site; Pro $348/yr two sites.

**Strengths:** profiles, registration/login, member directories, community
extensions (groups, social activity, messaging, photos), very large free
install footprint.

**Lesson:** a modern directory and community layer is required to own the whole
member experience — which is exactly what Cloud is for.

### WooCommerce Memberships

Memberships $199/yr; Subscriptions $279/yr. Recurring membership use cases
commonly need both — roughly **$478/yr** before any further extensions.

**Strengths:** deep product and store integration, product/member discounts,
dripped content, strong fit for commerce-first sites.

**Lesson:** the strongest commercial argument available. Memberistic offers one
integrated recurring membership experience instead of two expensive extensions
assembled by the customer.

---

## 4. Segments to win first

Ordered by how well 2.0.0 already serves them.

| Segment | Why Memberistic fits | Competitor weakness |
|---|---|---|
| Gyms, studios, ranges, clubs | Waivers, check-in, kiosk/QR, family accounts, front desk — all already built | Content-first plugins have none of this; operators use spreadsheets alongside them |
| Associations and professional bodies | Corporate/group memberships with seats, directory, documents | Group billing is an add-on or absent elsewhere |
| Family-membership businesses | Linked people as a first-class concept, not a hack | Most competitors model one member per account |
| WooCommerce store owners running memberships | One plugin instead of two extensions at ~$478/yr | Cost and complexity of assembling Woo extensions |
| Agencies serving the above | Portable data, developer hooks, REST API, no transaction fee | Per-site licence costs and fee-taking |

Content creators and course sellers are **not** the first segment. MemberPress
and WishList are strong there and Memberistic has no LMS. Enter that market
later, through integration rather than replacement.

---

## 5. Comparison-page discipline

Comparison pages are among the highest-intent acquisition assets available, and
also the easiest way to lose credibility permanently.

Rules:

- Every claim about a competitor is **dated** and links to the source.
- Prices are quoted as observed, with the observation date visible on the page.
- No exaggeration, no strawmen, no screenshots of outdated interfaces.
- State honestly what the competitor does better. A page that claims total
  victory reads as marketing; a page that concedes the LMS gap reads as true —
  and gets trusted on everything else.
- Re-verify on a schedule. A stale price on a public comparison page is risk R9.

Pages to build: MemberPress · Paid Memberships Pro · SureMembers · WooCommerce
Memberships. Detail in [`07-website-and-growth.md`](07-website-and-growth.md).
