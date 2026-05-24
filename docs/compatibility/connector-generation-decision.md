# Connector mass-generation (Phase 3 decision)

Generating dozens of handcrafted `Connector` PHP classes mirroring each Dolibarr `CommonObject`:

- duplicates logic already centralized in **`dolibarr.object`** (`ObjectAction`) + runtime introspection;
- diverges quickly from **`VerbDiscoverer`** (palette) + schema cache;
- multiplies regression surface (dangerous operations, ACL mapping, licences per generated file).

**Recommendation:** retain `ObjectAction` as the general runtime surface, supplement with:

1. `data/compatibility/dolibarr-catalog.json` for CI validation (method existence / signature smoke).
2. Targeted micro-connectors only when a module needs non-object side effects not expressible via `CommonObject` APIs.

Narrative rationale (performance, drift, deployment): [`architecture-generic-dolibarr-connector.md`](architecture-generic-dolibarr-connector.md).

Revisit if/when Dolibarr ships an official machine-readable operation catalog we can diff against without vendoring GPL sources.
