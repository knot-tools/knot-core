## Strategic Dolibarr coverage mission — recap

Audience: founders + technical reviewers evaluating Knot’s interoperability story.

### What shipped

1. Architecture audit (`architecture-genericity-audit.md`) — maps generic surfaces vs unavoidable special cases (`FIELD_TO_PROPERTY_ALIASES`, expedition line handling).
2. Phase 2 inventory CLI (`scripts/scan-all-dolibarr-classes.php`) + committed JSON artefacts.
3. Curated **`expedition`** slug with `ExpeditionLigne` fallback create path avoiding invoice-shaped `addline()` misuse.
4. CI monitoring workflow + documentation on auto-refresh cadence (`auto-update-system-report.md`).
5. Opt-in Playwright probes for schema fetch parity, shipments REST DELIV sampling, latency scale probes.

### Honest KPIs to quote

Prefer **measurable** statements tied to artefacts:

- `N = 27` curated MAP slugs (PHPUnit enforced).
- `M ≈ descriptors in dolibarr-catalog.json scan array` (~100+) documenting auto-discovered objects for a pinned Dolibarr tree.
- `P` gated Playwright suites (session + REST key dependent).

Avoid claiming **“every Dolibarr screen / API / trigger”**. Use **“maximum practical coverage of CommonObject business documents Knot recognises.”**
