# Plan template library

Starting points for a plan catalogue. Memberistic ships **no plans** — a new
install's Plans screen is empty on purpose, because a membership plan's name,
price, and inclusions are commercial decisions specific to one business.

These templates exist so you don't have to start from a blank screen anyway.

## What's here

| File | For |
|---|---|
| `generic-tiered.json` | Individual / couple / family. Start here if nothing else matches. |
| `gym.json` | Gym or fitness facility — off-peak, full access, household. |
| `studio.json` | Yoga, pilates, or dance studio — class pack vs unlimited. |
| `club.json` | Social or sports club — individual, couple, family. |
| `association.json` | Professional or trade body — student, associate, corporate. |
| `range.json` | Indoor shooting range — individual, couple, family. |

## Important

Every price and benefit in these files is an **example**. Review all of them
against your own business before you publish anything.

Plans carry `"status": "inactive"`, so importing one never puts a plan on sale.
Nothing is offered to customers until you open it in **Memberistic → Plans**,
check the details, and activate it.

## Importing one

The import UI ships in 2.1.0. Until then, apply a template with the
`memberistic_default_plans` filter on a site that has **no plans yet** — it
only runs on first install, and it skips any slug that already exists:

```php
add_filter( 'memberistic_default_plans', function ( $plans ) {
    $file = WP_PLUGIN_DIR . '/memberistic/templates/plans/gym.json';

    if ( ! is_readable( $file ) ) {
        return $plans;
    }

    $template = json_decode( (string) file_get_contents( $file ), true );

    return isset( $template['plans'] ) ? $template['plans'] : $plans;
} );
```

Or create the plans directly with WP-CLI / your own provisioning code — the
`plans` array in each file matches the shape `Plans_Repository::create()`
accepts.

## Format

```jsonc
{
  "id": "gym",
  "label": "Gym / fitness",
  "description": "...",
  "template_version": 1,
  "currency": "USD",          // advisory: set your real currency in Settings
  "status_on_import": "inactive",
  "notice": "...",
  "plans": [
    {
      "name": "Full Access",
      "slug": "full-access",     // stable id — used by entitlement rules
      "description": "...",
      "monthly_price": 39.0,
      "annual_price": 399.0,
      "included_people": 1,      // 1 = primary only; >1 allows linked members
      "benefits": ["...", "..."],
      "is_featured": 1,          // 0 or 1 — highlights the card on the grid
      "sort_order": 20,
      "status": "inactive"
    }
  ]
}
```

`slug` is the stable identifier. Entitlement rules
(`memberistic_lane_included_plan_slugs`), integrations, and reporting all key
on it, so choose it deliberately and avoid renaming it after members have
signed up.

Adding your own template: drop a JSON file in this directory following the
same shape. Nothing scans this folder at runtime, so a malformed file cannot
break the plugin.
