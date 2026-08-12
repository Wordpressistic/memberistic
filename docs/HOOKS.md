# Developer hook reference

Memberistic ships a stable set of actions and filters so integrators can extend
it without forking. Hook names are stable across minor releases; anything
removed goes through a deprecation cycle first.

All hooks use the `memberistic_` prefix. Hooks belonging to a *connected*
booking or POS plugin are resolved through an adapter and documented in
[`INTEGRATIONS.md`](INTEGRATIONS.md), not here.

---

## Actions

| Action | Args | When it fires |
|---|---|---|
| `memberistic_loaded` | — | After all classes are loaded and hooks registered |
| `memberistic_plan_created` | `int $plan_id` | After a plan row is inserted |
| `memberistic_plan_updated` | `int $plan_id` | After a plan row is updated |
| `memberistic_membership_created` | `int $membership_id` | After a membership is inserted (checkout, staff dashboard, REST) |
| `memberistic_membership_activated` | `int $membership_id` | When a membership flips to `active` |
| `memberistic_membership_expired` | `int $membership_id` | When a membership flips to `expired` |
| `memberistic_membership_status_changed` | `int $membership_id, string $old_status` | On any status transition |
| `memberistic_membership_payment_recorded` | `int $membership_id, int $payment_id, string $gateway` | After a gateway records a payment |
| `memberistic_person_added` | `int $person_id, int $membership_id` | After a primary or linked person is inserted |
| `memberistic_booking_recorded` | `int $membership_id, int $person_id, mixed $booking_id` | After a booking is linked to a membership |
| `memberistic_stripe_webhook_event` | `string $type, array $object, array $event` | For each verified Stripe event, before dispatch |
| `memberistic_payment_event_duplicate` | `string $event_id, string $type, string $provider` | A redelivery was recognised; nothing was repeated |
| `memberistic_payment_event_rejected` | `string $reason, int $membership_id, array $event` | An event failed an integrity check |
| `memberistic_payment_transition_rejected` | `string $from, string $to, int $membership_id, array $event` | A billing transition outside the matrix was refused |
| `memberistic_payment_provision_member_user` | `int $membership_id` | A verified activation should create the WordPress user, before any member email |
| `memberistic_payment_audit_recorded` | `array $row, int $id` | An audit row was written |
| `memberistic_payment_dunning_swept` | `array $counts` | The daily dunning sweep changed memberships |

Payment actions fire **after** the change is committed, and never for a
duplicate or rejected event. See [PAYMENT-INTEGRITY.md](PAYMENT-INTEGRITY.md).

## Filters

### Plans and entitlements

| Filter | Default | Purpose |
|---|---|---|
| `memberistic_default_plans` | `[]` | Plans created on first install. Empty — the plugin ships none |
| `memberistic_lane_included_plan_slugs` | Option value, else `[]` | Plan slugs whose membership includes bookings at no charge |
| `memberistic_lane_eligible_statuses` | `['active','comped']` | Statuses that count as an eligible member |
| `memberistic_can_book_as_member` | `false` | Grant member booking status through custom logic |
| `memberistic_booking_discount` | `0` | Apply a custom plan-based booking discount |

### Branding and presentation

| Filter | Default | Purpose |
|---|---|---|
| `memberistic_brand_label` | `business_name`, else site name | Customer-facing business name in emails, waivers, PDFs |
| `memberistic_admin_menu_label` | `Memberistic` | Admin menu title |
| `memberistic_member_id_prefix` | `MEM` | Prefix on the digital member card ID |
| `memberistic_member_display_id` | `MEM-YYYY-NNNN` | The fully formatted member card ID |
| `memberistic_locate_template` | Resolved path | Where a frontend template is loaded from |
| `memberistic_email_logo_url` | Custom Logo → Site Icon | Logo in HTML email headers |

### Email

| Filter | Default | Purpose |
|---|---|---|
| `memberistic_email_templates` | The transactional templates | Register custom templates |
| `memberistic_email_template_subject` | Default subject | Override one template's subject |
| `memberistic_email_template_body` | Default body | Override one template's body |
| `memberistic_email_merge_tags` | Default merge tags | Add custom merge tags |
| `memberistic_should_send_email` | `true` | Short-circuit an individual send |

### Roles, capabilities, access

| Filter | Default | Purpose |
|---|---|---|
| `memberistic_roles` | The six staff roles | Add or rename roles |
| `memberistic_capabilities` | Default capability set | Extend capabilities |
| `memberistic_staff_dashboard_capabilities` | Check-in + management caps | Who may render the staff dashboard |
| `memberistic_walkin_roles` | `[]` | Roles stripped from a user when they become a member |
| `memberistic_restriction_exempt_post` | Booking pages | Posts exempt from content restriction |

### Payments

| Filter | Args | Purpose |
|---|---|---|
| `memberistic_payment_providers` | `array $providers` | Register a payment provider adapter |
| `memberistic_billing_transitions` | `array $transitions` | The billing state transition matrix. Widening it removes a guard |
| `memberistic_provider_state_map` | `array $map, string $provider` | Provider status → billing state |
| `memberistic_access_status_for_billing_status` | `string $status, string $billing_status` | Billing state → membership access status |
| `memberistic_grace_period_days` | `int $days` | Dunning window after a failed payment |
| `memberistic_trial_grants_access` | `bool $grants` | Whether a trialing membership has access |
| `memberistic_grace_period_grants_access` | `bool $grants` | Whether the dunning window retains access |
| `memberistic_payment_amount_tolerance` | `float $tolerance, array $membership` | Allowed difference between expected and paid |
| `memberistic_payment_event_retention_days` | `int $days` | Ledger retention window |
| `memberistic_payment_clock_timestamp` | `int $timestamp` | The payment layer's "now". For tests; shifting it shifts freshness and grace decisions |

### Integrations

| Filter | Default | Purpose |
|---|---|---|
| `memberistic_integration_definitions` | Built-in integrations | Register an integration card |
| `memberistic_booking_adapter` | Auto-detected, else `null` | Map a booking plugin |
| `memberistic_pos_adapter` | Auto-detected, else `null` | Map a POS plugin |
| `memberistic_woocommerce_enabled` | `woocommerce_enabled === 'yes'` | Force-enable or disable the Woo bridge |

### Pages and retention

| Filter | Default | Purpose |
|---|---|---|
| `memberistic_required_pages` | Default frontend pages | Customise auto-created pages |
| `memberistic_clean_page_slugs` | Default slug map | Change the page slug scheme |
| `memberistic_email_log_retention_days` | `90` | Email log retention window |
| `memberistic_checkin_retention_days` | Setting, else `0` | Check-in history retention. `0` = keep forever |
| `memberistic_activity_retention_days` | Setting, else `0` | Activity history retention. `0` = keep forever |

### Privacy and licensing

| Filter | Default | Purpose |
|---|---|---|
| `memberistic_privacy_erase_waivers` | `false` | Whether a GDPR erasure anonymizes signed waivers |
| `memberistic_licence_status` | `unlicensed` | Reported licence status |
| `memberistic_licence_can_use` | `true` unless expired/invalid | Whether a licence-gated feature may run |

---

## Cron hooks

| Hook | Schedule | Does |
|---|---|---|
| `memberistic_daily_renewal_reminders` | daily | Emails members 30 / 7 / 1 day before renewal |
| `memberistic_daily_expire_memberships` | daily | Flips active memberships past renewal into `expired` |
| `memberistic_daily_waiver_followup` | daily | Nudges members with missing or expired waivers, max weekly |
| `memberistic_daily_prune_logs` | daily | Prunes the email log and applies retention windows |
| `memberistic_daily_backfill_renewals` | daily | Backfills missing renewal dates on imported rows |
| `memberistic_hourly_prune_rate_limits` | hourly | Clears expired rate-limit rows |
| `memberistic_daily_payment_dunning` | daily | Moves `past_due` → `grace_period` → `expired` against the stored grace deadline |
| `memberistic_daily_prune_payment_events` | daily | Prunes settled payment-event ledger rows; never rejections or manual-review items |
| `memberistic_corestore_reconcile` | daily | coreSTORE reconcile. Only scheduled when that integration is on |

To change a schedule, unschedule and reschedule the hook.

---

## REST extension

Every controller extends `WordPressistic\Memberistic\REST\REST_Controller`, so
new routes can reuse its permission helpers rather than reinventing the checks:

- `admin_permissions_check()` — staff / manager / admin
- `manage_members_permissions_check()` — staff who may create or edit members
- `manage_payments_permissions_check()` — cashier / manager
- `checkin_permissions_check()` — anyone with `memberistic_checkin_members`

To register your own route:

```php
add_action( 'rest_api_init', function () {
    register_rest_route( 'memberistic/v1', '/my-route', array(
        'methods'             => 'GET',
        'callback'            => 'my_callback',
        'permission_callback' => function () {
            return current_user_can( 'memberistic_checkin_members' );
        },
    ) );
}, 11 );
```

`permission_callback` is not optional and `__return_true` is not an acceptable
value — no route in this plugin uses it, and an extension should not be the
one that introduces it.

---

## Template overrides

```
your-theme/memberistic/account.php
your-theme/memberistic/plans-grid.php
your-theme/memberistic/checkout.php
your-theme/memberistic/staff-dashboard.php
```

Child theme wins over parent theme, which wins over the plugin. To resolve
from elsewhere:

```php
add_filter( 'memberistic_locate_template', function ( $path, $template ) {
    if ( 'account.php' === $template ) {
        return MY_PLUGIN_PATH . 'templates/account.php';
    }
    return $path;
}, 10, 2 );
```

The returned path must resolve inside the child theme's, parent theme's, or
plugin's template directory. Anything outside those roots is discarded and the
unfiltered path is used — a template lookup must not become an arbitrary-file
include just because a filter is available.
