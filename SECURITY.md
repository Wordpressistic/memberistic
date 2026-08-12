# Security Policy

Memberistic handles member identity data, signed waivers, payment records and
front-desk access decisions. Vulnerability reports are taken seriously and
handled privately.

## Reporting a vulnerability

**Do not open a public issue, PR, or discussion for a security problem.**

Report privately through GitHub's private vulnerability reporting on this
repository (Security → Report a vulnerability), or by email to the maintainer
listed in `.github/CODEOWNERS`.

Please include:

- affected version (`MEMBERISTIC_VERSION`) and the WordPress/PHP versions;
- what an attacker gains — data read, data written, privilege gained, access
  granted;
- reproduction steps or a proof of concept;
- whether the issue is exploitable by an anonymous visitor, a logged-in member,
  or only by staff.

You can expect an acknowledgement within 5 working days and an assessment with
a target fix window within 10 working days. Please give a reasonable window for
a fix before public disclosure. Credit is given in the changelog unless you ask
otherwise.

## Supported versions

| Version | Supported |
|---|---|
| 2.1.x | Yes — security fixes |
| 2.0.x | Yes — security fixes |
| < 2.0 | No |

Only the latest patch of a supported minor is supported. Sites are expected to
upgrade within the minor line.

2.1.0 fixes payment-integrity issues in every earlier 2.x release, including
renewal granted from an unverified invoice event and a stale cancellation able
to cancel a replacement subscription. Sites taking payments should upgrade.

## What is in scope

- Authentication and authorization bypass, including REST routes reachable
  without the correct capability.
- IDOR — reaching another member's people, payments, documents, waivers, notes
  or account records by changing an ID.
- SQL injection, XSS, CSRF, SSRF, path traversal, arbitrary file read/write.
- File upload handling: extension, MIME, filename, storage location, and
  unauthenticated download of member documents or signed waivers.
- Webhook forgery — Stripe or WooCommerce payloads accepted without valid
  signature verification, outside the replay window, or replayed.
- Leaking secrets (API keys, webhook secrets, tokens) through REST responses,
  logs, diagnostics or exports.
- Privilege escalation between the plugin's roles, particularly anything that
  grants `view_memberistic_pii` to a role that should not have it.

## What is out of scope

- Vulnerabilities in WordPress core, WooCommerce, Stripe's platform, or other
  third-party plugins — report those to their maintainers.
- Issues that require an already-compromised administrator account.
- Missing hardening headers on a site that the plugin does not control.
- Automated scanner output with no demonstrated impact.
- Social engineering, physical access, or denial of service by volume.

## Security design notes for reporters

Some behaviours look like findings but are deliberate:

- **Secrets can be locked in `wp-config.php` constants.** When
  `MEMBERISTIC_STRIPE_LIVE_SECRET_KEY`, `MEMBERISTIC_STRIPE_TEST_SECRET_KEY` or
  `MEMBERISTIC_WEBHOOK_SECRET` is defined, the stored option must not be
  overwritten and the value must never be returned in plain text over REST
  (`memberistic_secret_setting_keys()`, `memberistic_mask_secret()`).
- **Webhook routes are unauthenticated by design** and authenticate by
  signature instead: Stripe via the endpoint signing secret with a timing-safe
  compare and a 300-second replay window enforced in both directions;
  WooCommerce via an HMAC shared secret, which is rejected outright when
  unconfigured rather than treated as open. Both verify against the exact raw
  request body, before the payload is parsed.
- **A valid signature is not authority.** Since 2.1.0 an authenticated event
  still has to pass account, environment, membership, customer, subscription,
  plan, amount, currency, chronology and state-transition checks before it can
  change anything, and is refused if any of them fail. An event that changes a
  membership without those checks *is* a finding. See
  `docs/PAYMENT-INTEGRITY.md`.
- **Rejected events are answered `200`, not retried.** A permanently
  unacceptable event is acknowledged so the provider stops resending it; only
  undecidable ones (a provider outage) answer `503`. This is not a swallowed
  error — every refusal is recorded in the audit trail with its reason.
- **Template overrides are path-validated.** The `memberistic_locate_template`
  filter exists, but the returned path is constrained to the child theme,
  parent theme or plugin root. A bypass of that constraint *is* a finding.
- **Restricted post content is redacted server-side**, not hidden with CSS.
  Content still present in the HTML source *is* a finding.
- **Uninstall is opt-in.** Data retention on uninstall is intentional, not a
  bug.

## Known gaps we are already tracking

Publicly documented in `docs/strategy/01-audit-findings.md` and scheduled in
`docs/strategy/09-execution-backlog.md`:

- route-by-route REST authorization and IDOR test coverage is incomplete;
- upload/download handling lacks a dedicated security test matrix;
- REST route coverage is broader than webhook coverage; webhook fuzzing (bad
  signature, stale timestamp, duplicate, reordered, malformed) is automated as
  of 2.1.0, but fuzzing of the WooCommerce HMAC path is not;
- no dependency/SBOM scanning in CI;
- the Stripe API version is pinned to `2024-04-10` and its upgrade path is not
  yet covered by contract tests.

Reports that come with a concrete exploit in these areas are still very
welcome — a known gap is not a closed door.
