# Integrations

Every integration below is **off by default**. A freshly activated install
registers no integration hooks, queries no third-party table, and makes no
outbound HTTP request. Turn things on at **Memberistic → Integrations**.

Two entries are on by default — Email Automation and Waiver Manager. Neither
is a third-party integration: both are built-in features that send nothing
off-site, and disabling them by default would stop members receiving their own
account notices, which reads as a bug rather than a privacy control.

---

## Matrix

| Integration | Default | Dependency | Data leaves your site? | What it does |
|---|---|---|---|---|
| Stripe Checkout | Off (test mode) | None | **Yes** — to Stripe | Hosted subscription checkout, billing portal, signed webhooks |
| WooCommerce | Off | WooCommerce plugin | No | Completed-order sync, per-plan member discounts |
| Booking Engine | Off | A booking plugin, mapped via adapter | No | Member eligibility, member pricing, booking activity, front-desk visibility |
| POS Bridge | Off | A POS plugin, mapped via adapter | No | Counter member lookup, plan/status on the customer profile, check-in sync |
| coreSTORE (Coreware) | Off | coreSTORE account + API key | **Yes** — to Coreware | Pushes membership state so the till applies member pricing |
| Verifyistic | Off | Verifyistic plugin | No (by itself) | Stamps member records with verified age/DOB |
| Messageistic SMS | Off | Messageistic plugin | **Yes** — via your SMS gateway | Membership lifecycle text messages |
| Advanced FFL Checkout | Off | Advanced FFL Checkout plugin | No | Read-only FFL transfer history on the member dashboard. Firearms retail only |
| Email Automation | **On** | None | No — uses `wp_mail` | Lifecycle emails: checkout, activation, failed payment, renewal, expiry, waivers |
| Waiver Manager | **On** | None | No | Digital waivers, kiosk and guest signing, signature archive, expiry tracking |

---

## Adapters

Memberistic does not ship a booking engine or a POS. It integrates with
whichever plugin a site already runs, and the coupling to that plugin — its
hook prefix, its table prefix, its CSS class prefix — is declared in one place
rather than scattered through the codebase.

Two consequences worth knowing:

- **Nothing registers when no adapter resolves.** No filters are added, no
  foreign table is queried, and the integration reports itself as unavailable
  with an explanation rather than silently doing nothing.
- **Any plugin can be mapped.** You are not limited to the bundled presets.

### Booking engine

```php
add_filter( 'memberistic_booking_adapter', function () {
    return array(
        'label'        => 'Acme Bookings',
        'hook_prefix'  => 'acme',       // acme_booking_created, acme_booking_pricing, ...
        'table_prefix' => 'acme_',      // {$wpdb->prefix}acme_bookings
        'css_prefix'   => 'acme',       // .acme-shell, #acme-customer-name
        'shortcodes'   => array( 'acme_booking_form' ),
    );
} );
```

Hooks Memberistic attaches to, given `hook_prefix` of `acme`:

| Hook | Type | Memberistic's answer |
|---|---|---|
| `acme_user_is_member` | filter | Whether the authenticated user is an eligible member |
| `acme_booking_pricing` | filter | Member pricing, including "included with your plan" at £0 |
| `acme_booking_display_pricing` | filter | Same, for display-only price rendering |
| `acme_booking_created` | action | Links the booking to the membership and logs activity |
| `acme_payment_succeeded` | action | Records booking payment activity |
| `acme_lane_entitlement` | filter | Structured entitlement snapshot |
| `acme_waiver_satisfied` | filter | Waives the booking-form waiver tick when one is already on file |

Tables read (read-only except for a metadata stamp on `bookings`):
`{prefix}acme_bookings`, `{prefix}acme_resources`, `{prefix}acme_booking_types`.

Return `null` from the filter to disable the integration outright.

### Point of sale

```php
add_filter( 'memberistic_pos_adapter', function () {
    return array(
        'label'       => 'Acme POS',
        'hook_prefix' => 'acme_pos',    // acme_pos_membership_lookup, ...
    );
} );
```

| Hook | Type | Memberistic's answer |
|---|---|---|
| `acme_pos_membership_lookup` | filter | Live member status: plan, expiry, discounts |
| `acme_pos_membership_bookings` | filter | The customer's upcoming bookings |
| `acme_pos_membership_sold` | action | Mirrors a counter-sold membership into Memberistic |

### Bundled compatibility presets

Two presets ship so that installs upgrading from 1.x keep working without
reconfiguration. They are auto-detected — if the plugin they target is not
active, they never apply.

| Preset | Detects | Maps |
|---|---|---|
| Lane Booking Engine (legacy) | `G2AB_VERSION`, `G2AB_Plugin` | hooks `g2ab_*`, tables `{prefix}g2ab_*` |
| POS Core (legacy) | `G2A_POS_CORE_VERSION`, `G2A\POS\Core\Plugin` | hooks `g2a_pos_*` |

These identifiers belong to those third-party plugins. They cannot be renamed
here — querying a renamed table would find nothing — so they are confined to
these two presets and appear nowhere else in the codebase.

### Removing the bundled presets

If you would rather ship with no third-party identifiers in the codebase at
all, delete the `PRESETS` constant body in
`includes/integrations/class-booking-adapter.php` and
`includes/integrations/class-pos-bridge.php` (about fifteen lines between
them). Everything else keeps working: with no preset to auto-detect, both
integrations simply report as unavailable until an adapter is mapped.

Any site that was relying on auto-detection then needs a small mu-plugin:

```php
<?php
// wp-content/mu-plugins/memberistic-booking-map.php
add_filter( 'memberistic_booking_adapter', function () {
    return array(
        'label'        => 'Lane Booking Engine',
        'hook_prefix'  => 'g2ab',
        'table_prefix' => 'g2ab_',
        'css_prefix'   => 'g2ab',
        'shortcodes'   => array( 'g2a_lane_booking', 'g2a_booking_form' ),
    );
} );

add_filter( 'memberistic_pos_adapter', function () {
    return array(
        'label'       => 'POS Core',
        'hook_prefix' => 'g2a_pos',
    );
} );
```

The trade-off is real either way: keeping the presets means two clearly
labelled files mention another plugin's API, and upgrades need no action.
Removing them means a clean codebase and one manual step per affected site —
which, if missed, silently disables that site's booking integration.

---

## Third-party services

Detail on what is transmitted, when, and under whose terms.

### Stripe

- **When:** only when Stripe is enabled and keys are configured, and only at
  checkout, billing-portal access, subscription changes, and webhook receipt.
- **Sent:** member name, email, plan name, amount, currency, and your
  Stripe account identifiers.
- **Not sent:** card numbers. Card data goes from the customer's browser
  directly to Stripe; this plugin never receives or stores it.
- **Received:** webhook events, rejected unless signed with your endpoint
  secret and within a 300-second window.
- **Terms:** <https://stripe.com/legal> · **Privacy:** <https://stripe.com/privacy>

### coreSTORE (Coreware)

- **When:** only when enabled and an API key is saved. On membership
  activation, expiry, status change, and payment, plus a daily reconcile.
- **Sent:** member name, email, plan, status, expiry date, and a price-tier
  identifier.
- **Terms and privacy:** contact Coreware for current documents.

### Verifyistic

- **When:** only when enabled.
- **Sent:** nothing off-site by Verifyistic itself. It runs on your server and
  writes verified age/DOB back to the member record. Any verification provider
  you configure *inside* Verifyistic is governed by that provider's terms.

### Messageistic

- **When:** only when enabled, on membership lifecycle events.
- **Sent:** the member's phone number and the message text, passed to
  Messageistic, which delivers via the SMS gateway you configure there.

### Nothing else

The plugin has no telemetry, no analytics, no update check, and no licence
phone-home. Outside the services above — each of which you switch on and
configure yourself — it makes no outbound network request.
