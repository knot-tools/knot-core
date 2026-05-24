# Architecture — generic Dolibarr object surface (`dolibarr.object`)

## Goal

Expose **all manipulable Dolibarr `CommonObject` types** through a **single Knot connector** (`dolibarr.object`, implemented by `ObjectAction`) plus **descriptors** (`ObjectFactory`, introspection cache, `SchemaBuilder`), instead of generating **one PHP Connector class per Dolibarr business class**.

See also: [`connector-generation-decision.md`](connector-generation-decision.md) (phase-3 outcome).

## Why generic `ObjectAction` first

1. **One regression surface** — security (entity, ACL, CSRF), payload validation, credential handling, audit, and `fetch` export rules live in **one** codebase path. Dolibarr upgrades or Knot hardening fixes apply everywhere.

2. **No template drift** — mass-generated `*Connector.php` files tend to duplicate the same `fetch`/`create` glue. Any bug fix requires **regeneration or N file edits**; the unified connector fixes behaviour once.

3. **Operational footprint** — hundreds of connector classes enlarge the deployed tree and review noise without changing runtime semantics if they all delegate to identical patterns.

4. **UI and engine contract** — workflows already persist `dolibarr.object` with `operation` + `objectType`. Expanding **`listSupported()` / descriptors** scales the palette without multiplying connector IDs.

## When to add a separate Connector (exceptional)

- Behaviour **cannot** be expressed through `CommonObject` + `$fields` + documented operations (`create`, `update`, `fetch`, `delete`, `change_status`, …).
- **Side effects** that must stay isolated (e.g. guarded specialised flows) — still avoid duplicating CRUD already covered by `ObjectAction`.

## Registry growth model

| Layer | Responsibility |
|-------|----------------|
| **`ObjectFactory::MAP`** | Curated high-traffic slugs with guaranteed line companions where needed (stable for Dolistore / docs). |
| **Descriptor cache / catalog** | Broader Dolibarr surface from introspection; merged at runtime via `lookupDiscovered()`. |
| **`data/compatibility/dolibarr-catalog.json`** | CI / audit snapshot; regenerate from a Dolibarr tree (see compatibility README). |

Adding a **slug to `MAP`** (e.g. `expedition`) improves **predictability** and **first-class line support** for common logistics flows without introducing a second connector type.

## Performance note

Measured user impact is dominated by **Dolibarr I/O**, **workflow graph size**, and **payload shape** (e.g. exported lines). The unified connector keeps optimisations (**fetch export, aliases, batching**) in **one place** rather than scattering them across generated files.

## Marketing wording (honest)

Prefer: **“Knot drives Dolibarr business objects through a unified Dolibarr object connector backed by introspection.”**

Avoid implying **every** obscure class behaves identically without checking **module activation**, **rights**, and **edge behaviours** documented in compatibility audits.
