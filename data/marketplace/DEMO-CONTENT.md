# Marketplace editorial fallback — demo content inventory

Bundled file: **`editorial-fallback.json`** (schema **v2**, locales **`fr`**, **`en`**, **`es`**, **`it`**, **`de`**, **`pt`**).

Last meta timestamp in fallback: **`2026-05-25T10:00:00Z`**.

## Taxonomy categories

| Slug        | Icon        | Color   | Purpose |
|-------------|-------------|---------|---------|
| `packs`     | `package`   | `#6366f1` | Commercial Knot extensions |
| `templates` | `workflow`  | `#0ea5e9` | Workflow templates catalogue |
| `migration` | `refresh-cw`| `#f59e0b` | Migration product family |

## Home spotlight (2 items)

| Kind  | Slug             | Accent      | CTAs |
|-------|------------------|-------------|------|
| pack  | `knot-pro-pack`  | `pro`       | knot.tools/pro-pack/, knot.tools/pricing |
| pack  | `knot-migration` | `migration` | knot.tools/migration/ |

## Home collections (3)

| Slug               | Sort     | Category   | Limit |
|--------------------|----------|------------|-------|
| `recent-templates` | `recent` | —          | 12    |
| `popular-templates`| `popular`| —          | 8     |
| `pro-picks`        | `popular`| `packs`    | 6     |

## Product pages

### `knot-pro-pack`

- **Hero tier:** `pro`, version `2.13.7`, author Knot Tools
- **Pricing:** 19.90 EUR / month → license.knot.tools
- **Tabs:** `overview` (richtext), `features` (2 items), `changelog` (1 entry)

### `knot-migration`

- **Hero tier:** `migration`, version `0.21.0`
- **Tabs:** `overview` (richtext), `features` (list, 2 rows), `screenshots` (hidden, 1 CDN image)

## Template pages

### `invoice-reminder`

- **Hero tier:** `core`
- **Tabs:** `overview` (richtext), `templates` (slug pin + recent sort)
- **Prerequisites:** Knot Core 2.13+
- **Use cases:** Accounts receivable reminders

## Sidebar badge

All locales expose a short **`New` / localized equivalent** badge (`variant: info`, `hasUnread: false`).

## Assets referenced

| Asset | URL |
|-------|-----|
| Migration screenshot | `https://cdn.knot.tools/marketplace/migration-mission-control.png` |

## Validation

Every locale slice passes **`Knot\Marketplace\EditorialValidator::validate()`** (PHPUnit: **`tests/Marketplace/EditorialValidatorTest::testBundledFallbackJsonValidForAllLocales`**).
