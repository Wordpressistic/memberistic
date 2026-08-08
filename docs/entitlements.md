# Booking entitlements

`Entitlement_Service` (includes/integrations/class-entitlement-service.php) is
the single authority answering: *does this authenticated user's membership
include this booking at no charge?* A connected booking engine consumes it
through its own `<prefix>_lane_entitlement` filter (resolved by
`Booking_Adapter` — see [INTEGRATIONS.md](INTEGRATIONS.md)) and never grants a
zero-price booking any other way.

## Business rules

- **Included plans**: empty by default. Documented setting:
  `memberistic_lane_included_plan_slugs` (array of stable plan slugs). No plan
  includes bookings until an operator says which ones do — an entitlement that
  gives away paid inventory should never be something a default decides.
  `guest-pass` is force-removed even if a site adds it: a guest pass is a
  sellable product, but it never includes free booking time.
- **Eligible statuses**: `active`, `comped`. Documented setting:
  `memberistic_lane_eligible_statuses`. `trial`, `past_due`, `suspended`,
  `expired`, `cancelled`, `needs_review` and anything else are excluded unless
  the setting explicitly authorises them.
- **Authenticated only.** Entitlement resolves from a logged-in user id.
  A typed email address never grants — and never reveals — membership.
  A logged-out member either logs in to use the benefit or pays the public
  price as a guest.
- **Linked/family members** qualify through their own associated account: a
  `memberistic_people` row with their `wp_user_id` and `status=active` whose
  membership passes the same plan/status/renewal checks.
- **Expiry**: a `renewal_date` in the past (end of that day, site timezone)
  disqualifies; empty/zero renewal dates mean non-expiring.

## Result shape

```php
[
  'user_id'           => 123,
  'membership_id'     => 45,
  'plan_id'           => 3,
  'plan_slug'         => 'individual',
  'plan_name'         => 'Individual',
  'membership_status' => 'active',
  'eligible'          => true,
  'reason'            => 'member_included',   // stable reason codes below
  'pricing_type'      => 'member_included',    // or 'public_full_price'
  'amount_due'        => 0.0,                  // null when the engine prices it
  'allowed_gateway'   => 'member_included',    // or 'online'
  'checked_at'        => '2026-08-08 12:00:00',
]
```

Reason codes: `member_included`, `not_authenticated`, `no_membership`,
`status_not_eligible`, `plan_not_eligible`, `membership_expired`,
`linked_person_inactive`.

## What changed for Guest Pass

Automatic Guest Pass enrollment from bookings and WooCommerce product
purchases is removed. The explicit `[memberistic_guest_pass]` registration
form remains the only way to issue one, and even a sold Guest Pass never
zeroes a booking. Paid non-member bookers are classified by the booking engine
as its own guest customer segment (user meta + booking stats — no membership
row). Existing auto-created rows are handled by
`wp memberistic guest-pass-audit` (dry-run by default; see
docs/guest-pass-audit.md).
