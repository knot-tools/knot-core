# Dolibarr compatibility audit (Knot Core)

_Knot roadmap: cover curated MAP objects, introspection scan (`ObjectIntrospector`), workflow connector `ObjectAction`, and Verb palette (`VerbDiscoverer / dolibarr_schemas.php`). Multi-entity–specific QA is deferred (see glossary)._

## Baseline and regeneration

Metric tables are generated from the repository (no Dolibarr code vendored):

```bash
php scripts/compatibility/export_dolibarr_audit_tables.php \
  --dol-root=<DOL_DOCUMENT_ROOT> \
  --write-catalog=data/compatibility/dolibarr-catalog.json
```

- **Portable catalog:** [`data/compatibility/dolibarr-catalog.json`](../data/compatibility/dolibarr-catalog.json) (`format=knot.dolibarr-catalog`).
- **Machine tables:** annex [`_generated_audit_tables.inc.md`](_generated_audit_tables.inc.md).

The sample counts below use baseline folder basename **`21.0.4`** (Dolibarr source tree aligned with Knot CI matrix `21.0`).

## Glossary vs dashboard wording

| User-facing tier | Knot implementation pointers |
|------------------|-----------------------------|
| **Read (scan)** | `ObjectIntrospector::scan()` over `ALLOWED_MODULE_DIRS`, `SKIP_DIRS`, `CLASS_DENY_PATTERNS`. Results cached via `DescriptorCache` → API list when refresh run. |
| **Read (typed schemas)** | `ObjectFactory::describeForAction` + `SchemaBuilder`. Discovered-only slugs get lines only when curated `MAP` declares `line` (limitation documented §1.4). |
| **CRUD (workflow-safe)** | `dolibarr.object` (`ObjectAction`): `fetch`, `create`, `update`, `delete`, `change_status`, `statusMethod`, `add_note`, `generate_pdf`. Risk metadata in `ObjectAction::getMetadata`. |
| **Business verbs palette** | `ObjectFactory::discoverVerbs` → `VerbDiscoverer`. Surfaced UI: [`frontend/src/lib/api.ts`](../frontend/src/lib/api.ts) `getDolibarrVerbs` → [`DolibarrObjectPanel.vue`](../frontend/src/components/inspector/panels/DolibarrObjectPanel.vue). Experimental / verified tagging from discoverer simulation. |
| **Hooks / triggers** | Knot triggers [`trigger.dolibarr_event`](../frontend/src/canvas/nodeRegistry.ts) (+ legacy alias). Dolibarr core hook classes under `custom/knot/core/triggers/` (see connectors doc). Cron: `CronWorker`. |
| **SQL escape hatch** | `dolibarr.sql_query` — SSRF/policy controls in connector; MAP does not enumerate it. |

**Out of scope (this mission)** — documented only: exhaustive multi-entity scenario testing and cross-tenant guarantees.

---

## §1 Narrative synthesis

### 1.1 Curated MAP (26 Knot slugs)

The canonical keyed list mirrors `ObjectFactory::MAP`:

- Duplicate **PHP classes** reused under several slugs (`product` + `service`, `agenda` + `actioncomm`) for UX.
- **Line-backed** workflows exist only where `MAP[slug]['line']` is populated (customer/supplier proposals, invoices, contracts, orders, etc.).
- `ObjectAction` honours the schema permission metadata (`x-dolibarr-permission`) emitted by `SchemaBuilder`.

_Table: see annex §“Generated: MAP inventory”._

**PHPUnit:** `tests/Dolibarr/ObjectFactoryMapInventoryTest.php` locks slug cardinality + critical entries.

### 1.2 Introspector scan

On Dolibarr `21.0.4` tree: **`106` descriptors**.

Scan never executes PHP modules; regex discovery + allow/deny patterns keep surface predictable. Custom folders skip `modKnot*` + `knot` to prevent connector noise.

_Table: annex §“Generated: scan inventory”._

**Optional CI golden test:** export `KNOT_COMPAT_DOL_ROOT=/path/to/dolibarr-tree` locally in integration runs to assert minimum descriptor count (`tests/Dolibarr/ObjectIntrospectorGoldenTest.php`).

### 1.3 Delta MAP vs scan (`N = |scan slug| − MAP-only additions`)

Measured on the same baseline:

- `|scan| = 106`
- MAP keys excluding alias-only entries still appear in slug list individually → **`|scan − MAP keyed slugs|` = `82`** auto-discovered slugs awaiting product decision per module (do not confuse with anecdotal outdated “gap of 106−24”; MAP is already 26 slugs curated).

Canonical MAP-only discrepancies versus scan slug keys:

- **`service`** and **`agenda`**: purposeful aliases referencing existing scan slugs `product`, `actioncomm`.

_Details / full slug list_: annex §“Generated: delta”.

### 1.4 Structural gaps (engineering backlog)

| Theme | Observation |
|-------|---------------|
| `ObjectFactory::describe()` | ~~Used MAP class key~~ Fixed: emits short class name of instantiated object (`get_class`). |
| `ObjectFactory::getVersionHash` | Previously MAP-only hashes; extended to append **`DescriptorCache` JSON hash** (`descriptors:<hash>|`) while keeping MAP field hashes intact. Frontend `dolibarr_schemas.php?hash=1` aligns after refresh/regeneration. |
| `describeForAction` line schema | Only checks curated `MAP` for `line`, not descriptor `supportsLines` boolean for scan-only slug — UI may omit line editor even if Dolibarr object supports nested lines manually. |
| `listSupported()` vs API | REST list uses `listObjectsForApi`; `listSupported` remains MAP curator helper only — document when building admin UX. |
| Cold cache | Missing `documents/knot/dolibarr_descriptors.json` ⇒ discovered list empty until POST refresh (`api.ts` exposes `refreshDolibarrDescriptors`). |
| Marketplace tiering | `WorkflowTierAuditor` may block workflow JSON while engine still exposes Dolibarr objects — distinguish marketing gating vs runtime capability. |
| Sync timeout | `api/execute.php` sets `set_time_limit(25)` for synchronous runs; long Dolibarr operations require async/cron path. |
| SQL connector | `dolibarr.sql_query` bypasses object safety—documented for internal smoke only; keep strict allowlists. |
| Tier-3 connector generation | Mass producing `Connector` classes risks divergence with `dolibarr.object` + Verb palette — see [`connector-generation-decision.md`](connector-generation-decision.md). |

**Frontend path (verbs):** `knotApi.getDolibarrVerbs` → `DolibarrObjectPanel` uses palette entries (JSON template insert) separate from `ObjectAction` enum.

### 1.5 Risk register (Phases 2–3)

See [`connector-generation-decision.md`](connector-generation-decision.md). Key point: prefer runtime introspection + catalog validation over shipping dozens of hand-written connector classes.

### 1.6 External modules

Real deployments add `htdocs/custom/*` modules; descriptor count **varies**. Always state instance scope when claiming “100 % coverage”.

---

## Phase 2 deliverable — `dolibarr-catalog.json`

Generator: `Knot\Compatibility\DolibarrCatalogGenerator`. CLI flag `--write-catalog` on export script.

## Phase 3 decision — mass connector generation

See [`connector-generation-decision.md`](connector-generation-decision.md).

## Phase 4 roadmap — business methods

See [`objectaction-method-roadmap.md`](objectaction-method-roadmap.md).

## Phase 5 — version drift tool

Script: [`scripts/compatibility/check_dolibarr_version.php`](../scripts/compatibility/check_dolibarr_version.php) (compare committed catalog metadata with latest GitHub tag from major.minor pin).

## Phase 6 — Extrafields

`Knot\Dolibarr\ExtrafieldsSchema` merges `llx_extrafields` definitions into create/update schemas when `MAIN_DB_PREFIX` + `$conf` exist (typical web context). Types map conservatively (varchar/int/boolean/date…).

## Phase 7 — Index

Compatibility docs: start at [`README.md`](README.md).

---

## Annex — machine-generated tables

See [`_generated_audit_tables.inc.md`](_generated_audit_tables.inc.md).
