# Extending Knot for additional Dolibarr objects

Extensions should prefer the **generic** path:

1. **Discovery** — ensure Dolibarr classes live beneath allowlisted folders (`ObjectIntrospector::ALLOWED_MODULE_DIRS`).
2. **Registry** — add or confirm `ObjectFactory::MAP` entries only when stability / line companions mandate it; otherwise rely on regenerated `DescriptorCache`.
3. **Connector** — use **`dolibarr.object`**; avoid spawning new `*Connector.php` files unless a class truly cannot obey `fetch/create/update/delete` expectations.

## Custom modules (`htdocs/custom/...`)

- Default inventories **omit** `/custom/` unless **`KNOT_SCAN_INCLUDE_CUSTOM=1`** is exported before `scripts/scan-all-dolibarr-classes.php`.
- Contributing modules must ship **`CommonObject` heirs** with honest `$fields` definitions; Knot cannot safely introspect procedural scripts.

## Knot extension modules (`modKnot*`)

Expose additional descriptors under predictable `class/` trees so introspection picks them up, then regenerate the Dolibarr catalog snapshot when releasing.

## Promoting discovery rows into `ObjectFactory::MAP`

Curated MAP entries are **`fromMap` / tier-1** — they imply PHPUnit `describe` + verb drift coverage on the slug and a gate or checklist row inside **`docs/testing/demo-validation-matrix-phase4.md`** when the Dolibarr object touches customer-critical CRUD/state flows.

Discovery already exposes custom modules immediately; widening the MAP grows the schema Knot upholds across upgrades — treat curated additions as miniature releases rather than incidental scan fallout.

## Operational hooks

Purging seeded demo workflows labelled `KNOT-COVERAGE-*` (entity `1` by default, cascade through executions / schedules / tags, etc.): set `DOLIBARR_DOCUMENT_ROOT` to httpdocs if needed, then `php scripts/compatibility/purge_demo_coverage_seed.php confirm`.
