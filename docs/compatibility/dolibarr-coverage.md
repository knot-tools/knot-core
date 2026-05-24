# Dolibarr business-object coverage (`dolibarr.object`)

This page answers **what Knot can manipulate** versus **what the Dolibarr product contains**.

## Truthful coverage (not “100 % Dolibarr”)

Knot automates **`CommonObject`-style business documents** described by Dolibarr’s `$fields` maps and surfaced through the single connector **`dolibarr.object`** plus curated slugs in `ObjectFactory::MAP` (`expedition`, `facture`, `thirdparty`, …).

| Dolibarr area | Knot scope in this roadmap |
|----------------|----------------------------|
| Core business documents (`CommonObject`) | Covered when slugs appear in **`ObjectFactory`** (MAP + introspector cache). |
| Core utility / UI helpers (`Html*`, cron shell jobs, ECM binary storage) | **Out of scope** for this roadmap — document explicitly excluded or future connectors. |
| REST API payloads | Sample parity only (`fetch` Knot vs Dolibarr JSON). Field names intentionally differ (`fk_soc` vs `socid`, secrets omitted). |
| Triggers Knot vs native Dolibarr hooks | Knot DB triggers are **enumerated deliberately** — not exhaustive parity with Dolibarr’s module hook graph. |
| Multi-company | **Excluded** (`docs/architecture-generic-dolibarr-connector.md` mission notes). |

## Metrics shipped in-repo

- `ObjectFactory::MAP` — stable slug count mirrored by `ObjectFactoryMapInventoryTest` (`27` curated slugs incl. **`expedition`** + `ExpeditionLigne`). See **[`dolibarr-crud-slug-matrix.md`](dolibarr-crud-slug-matrix.md)** for a regenerable CRUD × slug matrix (connector wiring vs runtime caveats).
- `data/compatibility/dolibarr-catalog.json` — portable scan snapshot (`scanDescriptors` mirrors `ObjectIntrospector` allowlist vs a pinned Dolibarr tree).
- `data/compatibility/dolibarr-classes-full-inventory.json` — filesystem or catalog-derived class inventory (`scripts/scan-all-dolibarr-classes.php`).
- `data/compatibility/demo-knot-tools-coverage-status.json` — KPI snapshot for demos.

Marketing-safe wording:

> Knot targets **maximum regenerable coverage** of Dolibarr business objects recognised by Knot’s registry for a **pinned Dolibarr version** and activated modules — **not every PHP class or screen** in Dolibarr.

## Line objects & shipments

Shipment headers (`slug: expedition`) reuse `Expedition` + `ExpeditionLigne`; Dolibarr’s `Expedition::addline()` signature differs from invoices, so Knot **routes line creation through `ExpeditionLigne::create`** when payloads include coherent `$fields`-backed props (fallback path in `ObjectAction::addLines`).

## Modules under `/custom`

Third-party code is opt-in scanning (`KNOT_SCAN_INCLUDE_CUSTOM=1`). See **`docs/compatibility/extending-knot.md`**.
