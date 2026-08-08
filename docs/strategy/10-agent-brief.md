# Agent brief

A standing brief for an automated implementation session working on Memberistic.
Use it after creating a dedicated development branch.

Read alongside — not instead of — [`../../CLAUDE.md`](../../CLAUDE.md), which
describes how the code actually works, and
[`../../CONTRIBUTING.md`](../../CONTRIBUTING.md).

---

## The brief

```text
You are the senior release engineer, WordPress plugin architect, security
engineer and QA owner for Memberistic Membership Solutions.

Baseline: 2.0.0.

GOAL
Upgrade Memberistic from a strong public foundation into a production-grade,
commercially extensible membership platform without breaking any existing
membership, payment, linked-person, waiver, check-in, corporate/group, email,
import, WooCommerce or REST behaviour.

NON-NEGOTIABLE RULES
1.  Never reintroduce Paid Memberships Pro as a runtime dependency. Existing
    PMPro references are permitted only in documented one-way migration/import
    tooling.
2.  Never seed paid membership plans or prices on fresh activation.
3.  Never give away a paid entitlement by default. Entitlement decisions fail
    closed.
4.  Never let an expired Memberistic vendor licence break existing memberships,
    member access, local payment renewals or other core local operations.
5.  Do not edit WordPress or WooCommerce core.
6.  Follow WordPress Coding Standards for all new and changed code.
7.  Sanitize input, validate domain rules, escape output, enforce nonces and
    capabilities, parameterize SQL, and protect every REST route.
8.  Preserve backward compatibility unless a breaking change is explicitly
    versioned and migrated.
9.  Do not make a network call on a visitor-facing request merely to validate a
    software licence.
10. Every fix requires tests before it is considered done.

PHASE A — RELEASE / COMPATIBILITY
- Build a reproducible release pipeline triggered by semantic version tags.
- Produce a clean zip using .distignore.
- Test WordPress 6.8.x, 6.9.x and current 7.0.x; prepare 7.1 pre-release checks.
- Test PHP 8.2, 8.3 and 8.4.
- Test the latest supported WooCommerce, HPOS and blocks integration.
- Add WordPress Plugin Check.
- Add WPCS with blocking checks for all changed files.
- Add PHPStan at a practical baseline and increase it over time.

PHASE B — SECURITY TESTS
- Enumerate every REST route and record method, auth type, capability and owned
  resource.
- Add positive and negative authorization tests.
- Add IDOR tests for every member-owned resource.
- Test webhook signatures, replay window, malformed events and duplicate events.
- Test file uploads and downloads against extension, MIME, filename, path
  traversal and unauthorized access attacks.
- Verify secrets are never returned in REST responses, logs or diagnostic
  exports.
- Add rate-limit tests for public and token endpoints.

PHASE C — REFACTOR
Split large classes without functional behaviour changes:
- Stripe into client / checkout / subscription / portal / webhook /
  reconciliation services.
- Waivers into repository / version / signature / token / reminder / document
  services.
- Corporate into group / member / payment-link / portal / notification services.
- The memberships REST controller into resource-specific controllers.
Add tests before and after each refactor.

PHASE D — COMMERCIAL ARCHITECTURE
- Keep all existing 2.0.0 public features available in Free.
- Implement a separate Pro/licensing add-on architecture.
- Licence state must be cached, and network failures must fail open for
  already-licensed sites.
- Premium feature gates may disable only premium modules, never core membership
  access.
- Support production-domain plus staging/local recognition.
- Build a secure update manifest and a signed, reproducible package process.

PHASE E — PRO FEATURES
Implement modularly, with individual feature flags and tests:
- CPT / taxonomy / category / partial content rules.
- Drip and scheduled content.
- Coupons and promotions.
- Checkout free trials.
- Upgrade / downgrade / proration.
- Payment plans.
- Failed-payment recovery and dunning.
- Abandoned checkout recovery.
- Gifting.
- Advanced email automation.
- Webhook / API automation.
- Advanced analytics: MRR, ARR, churn, LTV and cohorts.
- Guided importers from major WordPress membership plugins.
- Social login / SSO extension points.

PHASE F — QA
Build Playwright tests for:
- fresh install and onboarding;
- create plan;
- join and pay;
- account and billing;
- linked people;
- waiver;
- staff verification and check-in;
- content restriction;
- membership status transitions;
- import;
- corporate/group lifecycle;
- uninstall and retention behaviour.

RELEASE REQUIREMENT
Before producing a release candidate, run every lint, static analysis, unit,
integration and E2E suite. Do not claim production readiness if any test is
skipped or failed. Provide a final QA report listing exact versions tested,
commands run, results, known limitations and rollback steps.
```

---

## Notes for whoever uses this

**The phases are not a sprint plan.** They map onto milestones M0–M5 in
[`00-master-plan.md`](00-master-plan.md), which carry the dependencies. Phase C
before Phase B is a mistake — refactoring without the security suite means
splitting REST controllers with nothing to catch a dropped permission callback.

**Pick a backlog item, not a phase.** Items in
[`09-execution-backlog.md`](09-execution-backlog.md) have acceptance criteria
and dependency notes. A phase does not.

**The ten rules are the same invariants** as §3 of the master plan, phrased for
a session that has not read the repository. Where they differ in wording, the
master plan is authoritative and names the test that enforces each one.

**Report honestly.** The last line of the brief is the important one. A QA
report that says "Stripe contract tests: not run, no test-mode credentials in
this environment" is useful. One that implies everything passed is worse than no
report — it means the next person trusts something they should not.

**Scope discipline.** If the work reveals a problem outside the item's scope,
finish the item and file the finding. Fixing three adjacent things in one PR is
how a behaviour-preserving refactor stops being behaviour-preserving.
