# Installation guide

This guide walks through getting Memberistic Membership Solutions installed and configured for a production launch.

> Requires WordPress 6.8+ and PHP 8.2+. If either floor is not met the plugin
> shows an admin notice and stays inactive rather than causing a fatal error.

## 1. Upload and activate

1. Copy `memberistic-membership-solutions/` into `wp-content/plugins/`.
2. Activate the plugin from the WordPress admin (Plugins → Installed Plugins).
3. On activation Memberistic will:
   - Create its custom tables.
   - Add the six staff roles and their capabilities.
   - Schedule the daily cron jobs (renewal reminders, auto-expire, waiver
     follow-up, log pruning).
   - Create a single `/check-in/` page.

   It will **not** create any membership plans, enable any integration, or
   make any outbound network request.

## 2. Create your first plan

**Memberistic → Plans** starts empty on purpose: a plan's name, price, and
inclusions are commercial decisions specific to your business, so the plugin
does not invent any.

Either add a plan directly, or start from one of the bundled templates in
[`templates/plans/`](../templates/plans/README.md) — gym, studio, club,
association, range, or generic tiered. Templates import as **inactive**, so
you can review every price before anything is offered for sale.

If you sell bookings and want a plan to include them at no charge, set the
included-plans allowlist afterwards — see [`entitlements.md`](entitlements.md).
Nothing is included until you say so.

## 3. Set the brand

Open **Memberistic → Settings → General** and fill in:

- Brand label (shown in admin menu, emails, status badges).
- Business name, phone, and address.
- Primary brand colour and (optional) logo URL.
- Default currency.
- Timezone (defaults to your site's timezone).

## 4. Map the frontend pages

Open **Memberistic → Tools → Page Mapping** and click **Create Pages**. Memberistic will create or remap eight branded pages with the right shortcodes:

| Setting key | Page slug | Shortcode |
|-------------|-----------|-----------|
| `plans_page_id` | `memberistic-memberships` | `[memberistic_plans]` |
| `checkout_page_id` | `memberistic-checkout` | `[memberistic_checkout]` |
| `account_page_id` | `memberistic-account` | `[memberistic_account]` |
| `renewal_page_id` | `memberistic-renewal` | `[memberistic_renewal]` |
| `login_page_id` | `memberistic-login` | `[memberistic_login]` |
| `thank_you_page_id` | `memberistic-thank-you` | `[memberistic_thank_you]` |
| `failed_payment_page_id` | `memberistic-payment-failed` | `[memberistic_payment_failed]` |
| `staff_dashboard_page_id` | `memberistic-staff-dashboard` | `[memberistic_staff_dashboard]` |

You can edit page titles and bodies freely afterwards — Memberistic only cares about the page IDs stored in settings.

## 5. Enable Stripe

1. Sign in to your Stripe dashboard and copy your publishable + secret keys (test or live).
2. In **Memberistic → Settings → Payments**:
   - Enable Stripe.
   - Pick **test** or **live** mode.
   - Paste the matching publishable and secret keys.
3. In the Stripe dashboard, **Developers → Webhooks**, add an endpoint:
   `https://your-site.com/wp-json/memberistic/v1/webhooks/stripe`
4. Select the events:
   - `checkout.session.completed`
   - `invoice.payment_succeeded`
   - `invoice.payment_failed`
   - `customer.subscription.created`
   - `customer.subscription.updated`
   - `customer.subscription.deleted`
   - `customer.subscription.trial_will_end` (only if you offer trials)
5. Copy that endpoint's signing secret into the field for the mode it belongs
   to: **Test keys → Webhook signing secret**, or **Live keys → Webhook signing
   secret**.

Stripe issues a **different signing secret per endpoint**, so your test endpoint
and your live endpoint have two different secrets. Fill in whichever you use,
and both if you use both — otherwise switching the site from test to live leaves
it verifying live events against the test secret and silently rejecting every
one of them.

The older shared **Webhook secret (shared, legacy)** field still works and is
used only when the current mode's field is empty, so an upgrade from 2.0.x keeps
working until you fill the new fields in.

A signing secret is **required** — the webhook route returns `503` if it is not
configured, so Stripe retries rather than dropping the event.

For live sites, keep secrets out of the database entirely by defining them in
`wp-config.php`; a constant takes precedence over the stored option and cannot
be overwritten through the REST API:

```php
define( 'MEMBERISTIC_STRIPE_LIVE_SECRET_KEY', 'sk_live_...' );
define( 'MEMBERISTIC_STRIPE_WEBHOOK_SECRET_LIVE', 'whsec_...' );
```

6. Check the result:

```bash
wp memberistic stripe health
```

This reports whether the API key and signing secret are present for the current
mode, whether the Stripe account behind the credentials has been verified, and
anything waiting for manual review. It never prints a secret.

### What happens to an event that arrives

Every event is verified before it can change a membership — signature, account,
environment, membership, customer, subscription, plan, amount, currency,
chronology and the requested state transition. A renewal is not granted because
an invoice event arrived; the invoice is re-read from Stripe and checked. See
[PAYMENT-INTEGRITY.md](PAYMENT-INTEGRITY.md).

### Failed payments

**Settings → Payments** controls what happens when a card fails: the grace
period in days (default 7, `0` expires immediately), and whether a member keeps
access during that window. Both default to ending access at the failed payment,
which is what Memberistic did before 2.1.0.

## 6. (Optional) Enable WooCommerce

1. In **Memberistic → Settings → Integrations** enable WooCommerce.
2. (Optional) set a shared WooCommerce webhook secret for signed webhook delivery.
3. From WP-CLI or a one-off admin action, fire `memberistic_ensure_woocommerce_products` to create the hidden virtual products (one per plan × Monthly / Annual). If you sell memberships only through Stripe Checkout, you can skip this step.

## 7. Email setup

1. **Memberistic → Settings → Emails**:
   - From-name (defaults to your brand label).
   - From-email (defaults to the WP admin email).
2. Confirm that WordPress can send mail (use a deliverability plugin like FluentSMTP or Postmark if needed).
3. The three daily cron jobs run automatically once a day at ~06:00. To verify, use a plugin like WP Crontrol and look for `memberistic_daily_renewal_reminders`, `memberistic_daily_expire_memberships`, `memberistic_daily_waiver_followup`.

## 8. Staff roles

Assign your team to the right roles in **Users → Edit user**:

| Role | Capabilities |
|------|--------------|
| `memberistic_manager` | Full Memberistic management |
| `memberistic_staff` | Search, view, create members, check-in, notes |
| `memberistic_cashier` | Create / renew members, manage payments |
| `memberistic_instructor` | View members and waiver eligibility |
| `memberistic_kiosk_operator` | Check-in only (reserved for the KIOSK module) |
| `memberistic_pos_staff` | Members + payments (reserved for the POS module) |

## 9. Smoke test

1. Open the public Memberistic Plans page — your active plans should appear with the monthly/annual toggle.
2. Choose a plan, pick **Monthly**, and continue to secure payment. Stripe Checkout should open in test mode.
3. Pay with `4242 4242 4242 4242` (any future expiry, any CVC).
4. Confirm:
   - You land on the Thank You page.
   - **Memberistic → Members** lists the new membership with status **Active**.
   - **Memberistic → Activity** shows `membership_created` and `membership_activated`.
   - **Memberistic → Payments** lists the Stripe payment.
   - The member receives the `membership_activated` email.

## 10. Uninstall behaviour

`uninstall.php` only drops data if **Memberistic → Settings → Advanced → Delete data on uninstall** is set to **Yes**. The default is **No** so re-activating the plugin restores state cleanly.
