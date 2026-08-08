# Metrics

Numbers worth collecting, and — more usefully — what each one is *for*. A
metric with no decision attached is a dashboard ornament.

---

## Acquisition

| Metric | Decision it informs |
|---|---|
| Free downloads / installations | Whether distribution is working at all |
| Activated sites | Whether the download turns into an install that runs |
| Onboarding completion | Whether the first-run experience is a wall |
| First plan created | The first real signal of intent |
| First member added | Setup is genuinely complete |
| **First paid membership processed** | **The true activation moment** |
| Migration starts | Competitor switching demand |
| Migration completions | Whether the importer actually works |

The gap between *migration starts* and *migration completions* is the single
most actionable number here. Every abandoned migration is a specific,
reproducible product failure — and a lost customer who had already decided to
leave a competitor.

---

## Monetisation

| Metric | Decision it informs |
|---|---|
| Free → Pro conversion | Whether Pro is worth its price |
| Free → Cloud conversion | Whether Cloud stands alone |
| Pro → Cloud attach rate | Whether the value ladder works |
| Annual renewal rate | Whether Pro keeps earning after the first year |
| LTD share of paid licences | Risk R4 — the LTD-dependence warning light |
| ARPU | Pricing sanity |
| MRR / ARR | The business |
| Service attach rate | Whether services are a real line or a favour |

**LTD share is a governance metric.** If lifetime licences exceed roughly a
third of paid volume beyond the launch window, close the window. The model in
`04-business-model.md` treats LTD as a cash-flow instrument, and it stops being
one the moment it becomes the default purchase.

---

## Product

| Metric | Decision it informs |
|---|---|
| Active member sites | Real usage, not installs |
| Payment success rate | Gateway and checkout health |
| Failed-payment recovery rate | Whether dunning is earning its build cost |
| Webhook errors | Integration health — leading indicator of billing bugs |
| Sync lag | Cloud connector health |
| Portal weekly active users | Whether the portal is used or merely purchased |
| Community engagement | Whether community retains or just exists |
| Support tickets per 100 active sites | The honest usability metric |

Support tickets per 100 active sites is the one that resists gaming. Raw ticket
count grows with success and tells you nothing; normalised, a rise means the
product got harder to use.

---

## Quality

| Metric | Decision it informs |
|---|---|
| Crash / fatal rate | Release health |
| Upgrade failure rate | Whether migrations are safe |
| CI pass rate | Whether the suite is trusted or routinely ignored |
| Security findings | M1 effectiveness |
| Regression count | Refactor safety — risk R3 |
| Median support resolution time | Support capacity |

A CI pass rate hovering near 100% is not necessarily good news — it can mean the
suite tests too little. Read it alongside regression count: high pass rate plus
rising regressions means the tests are not covering what breaks.

---

## Cloud (once M5 ships)

| Metric | Decision it informs |
|---|---|
| Tenants provisioned | Cloud demand |
| Custom domains configured | Whether the flagship feature is reachable |
| Portal WAU / member base | Portal value |
| Messages per active channel | Community viability |
| Automation runs and failure rate | Automation reliability |
| Cloud cost per tenant | Whether the pricing tiers are solvent |
| Sync error rate | Connector reliability |

**Cloud cost per tenant** is the metric that determines whether Cloud is a
business or a subsidy. Track it from the first tenant, not from the first
hundred — the per-tenant cost of chat, storage and email is exactly what makes a
lifetime licence with bundled cloud impossible.

---

## Reporting cadence

| Cadence | Look at |
|---|---|
| Weekly | Acquisition funnel, payment success, webhook errors, support volume |
| Monthly | Monetisation, renewals, LTD share, cloud cost per tenant |
| Per release | Quality metrics, regression count, CI pass rate |
| Quarterly | Strategy review: is the plan still right? Stripe API review (risk R1). Competitor pricing re-verification (risk R9) |

The quarterly Stripe API review is a standing engineering task, not a reminder.
It is the mitigation for the highest-consequence risk in the register.

---

## What not to measure

- **Vanity install counts** without activation. An install that never creates a
  plan is not a user.
- **Total support tickets** without normalising by active sites.
- **Feature usage** without a decision attached — knowing 12% of sites use
  kiosk mode is only useful if there is a threshold below which something
  changes.
- **Anything requiring telemetry the plugin does not have.** A fresh activation
  makes no outbound HTTP request (invariant I5), and adding usage tracking to
  fill a dashboard would break that promise. Any telemetry must be opt-in,
  disclosed, and worth the trust it spends.

That last one is a real constraint, not a caveat. Several metrics above are only
obtainable through WordPress.org statistics, Cloud (where the customer has
opted in by connecting), or the vendor's own commerce systems — not from
plugin-side tracking.
