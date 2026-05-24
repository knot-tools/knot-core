# Audit — architecture générique Knot ↔ Dolibarr

**Scope:** genericity of the Dolibarr interop layer for `CommonObject`-backed business objects.  
**Out of scope:** full Dolibarr product parity, utility classes, multi-entity, external I/O (see `architecture-generic-dolibarr-connector.md`).

## 1. Current state — components that are genuinely generic

| Component | Role | Generic? | Notes |
|-----------|------|----------|--------|
| **`ObjectAction` (`dolibarr.object`)** | Single connector for `fetch`, `create`, `update`, `delete`, `change_status`, `add_note`, `generate_pdf` | **Yes (by design)** | Operates on any slug `ObjectFactory::build()` returns. Payload validation uses `SchemaBuilder` + `describeForAction()`. |
| **`ObjectFactory`** | Slug → Dolibarr instance; `describe()` reads `$fields`; `buildLine()` for line companions | **Yes** | Curated `MAP` for stable slugs; `lookupDiscovered()` merges introspector cache so scan-only slugs work without editing `MAP` each time. |
| **`ObjectIntrospector`** | Regex scan of `*.class.php` under allowlisted module dirs; descriptors without `require` | **Yes** | Two-phase safety; `DescriptorCache` persists results. |
| **`SchemaBuilder`** | JSON schema + `x-dolibarr-permission` for create/update UX | **Yes** | Driven by `$object->fields`. |
| **`ExtrafieldsSchema`** | Merges `llx_extrafields` when running under Dolibarr | **Yes** | Conditional on `$db` + `$conf->entity`. |
| **`exportObject` / `exportObjectFromDescribedFields`** | Serialises `fetch` output from described keys + `array_options` + `lines` | **Yes** | Uses `FIELD_TO_PROPERTY_ALIASES` for FK property names; skips password-typed fields; normalises values. |
| **`ensurePermission`** | Gates mutating ops via `x-dolibarr-permission` | **Partially generic** | `fetch` intentionally ungated Knot-side (Dolibarr read still enforced by object/module). Fallback no-op if schema missing. |

## 2. Class-specific / non-generic areas (explicit)

| Location | Specificity | Rationale |
|----------|-------------|-----------|
| **`FIELD_TO_PROPERTY_ALIASES` in `ObjectAction`** | Per-field mappings (`fk_soc` → `socid`, dates → `date`, etc.) | Dolibarr uses inconsistent property vs SQL column names; must be enumerated until a rule engine exists. |
| **`ObjectFactory::MAP`** | Curated slug list + line companions | Stable UX, guaranteed line class paths for hot paths; complements discovery. |
| **`ObjectIntrospector::ALLOWED_MODULE_DIRS`** | Folder allowlist | Security + performance; excludes `install`, `theme`, etc. |
| **`ObjectIntrospector::CLASS_DENY_PATTERNS`** | Line classes, `Common*` bases | Lines are manipulated via parents. |
| **`changeStatus`** / **`addLines`** internals | Calls like `validate`, `delete`, line add APIs | Thin Dolibarr API surface — not arbitrary method dispatch. |
| **VerbDiscoverer / palette** (if present elsewhere) | May prioritise curated objects | Editorial; not inspected in this pass beyond `ObjectFactory::listSupported()`. |

**Question 1 — Is code really generic for manipulation?**  
**Yes for CRUD-shape operations** driven by `$fields` and Dolibarr’s `fetch`/`create`/`update`/`delete`. **No for arbitrary business methods** (e.g. `createFromOrder`, `closeProposal`) unless added as new `operation` enums or specialised connectors later.

**Question 2 — Class-specific hooks?**  
Main concentration: **`FIELD_TO_PROPERTY_ALIASES`**, curated **`MAP`**, **`SchemaBuilder`** conditional fields (`enabled`), and **line add** branching by object type where FK names differ (`defaultParentFkField` helpers).

**Question 3 — Generalisable without breaking callers?**

- Aliases → **declarative file** (YAML/PHP return array) merged at runtime (**Phase 1.5 candidate**).
- **`change_status`** generic dispatch already uses configurable `statusMethod`; edge cases remain class-specific.
- **Optional fetch behaviour** (load related collections, skip IMAP-backed objects) → **declarative `class-behaviour` config** per class name (**Phase 1.5**).

## 3. Target architecture (recap)

1. **Discovery** — introspector + cache + optional curated `MAP` overlays.  
2. **Connector** — **`dolibarr.object` only** for standard document CRUD; rare exceptions as separate connectors.  
3. **Field mapping** — `$fields`-driven + alias table + sensitive field filtering.  
4. **Workflows** — all slugs surfaced through the same connector id with `objectType`.  
5. **Permissions** — `SchemaBuilder` → `ensurePermission`; document gaps when schema lacks `x-dolibarr-permission`.

## 4. Gap list (prioritised follow-ups)

1. **Enumerate alias coverage** vs real Dolibarr 21 FK fields (automated diff from `$fields` keys during integration tests).
2. **Operations beyond current enum** — document which workflows still need Dolibarr REST or native UI (not `ObjectAction`).
3. **`fetch` parity vs REST API** — sample matrix in E2E; document intentional omissions (passwords).
4. **Heavy / side-effect classes** — mark `external_side_effect` in classification JSON (`Mailing`, collectors) and default `skip` or config flag when Phase 2 lands.

## 5. Refactoring decision (Phase 1.5)

| Item | Blocker for Phase 2–3? | Action now |
|------|------------------------|-----------|
| Mass extract `FIELD_TO_PROPERTY_ALIASES` | No | **Deferred** — keep single PHP const until Phase 2 scan highlights repeated patterns. |
| Fetch behaviour config file | No | **Deferred** — add when first class blocked by default `fetch()` shape. |
| Plugin hooks per class | No | **Deferred** |

**Decision:** **No Phase 1.5 code refactor mandatory before Phase 2 execution.** Proceed with inventory + MAP extensions + tests; revisit alias extraction after classification JSON exists.

## 6. Preservation guarantee

Existing curated slugs and `ObjectAction` operations must remain backward compatible; any new slug only **adds** registry entries unless a Dolibarr upgrade forces a deliberate breaking change (documented Phase 5 diff).
