# State machine — what Knot does not infer

These limits apply to the **hybrid** lifecycle layer (`StateMachineEngine`, ADR-005 / ADR-012). They keep expectations aligned with Dolibarr reality.

## Business rules

- **Guards** (stock, lines required, payment terms, accounting locks, hooks from third-party modules) are **not** modeled as a formal graph. Knot surfaces **verbs** and **probable** transitions; Dolibarr remains authoritative when it rejects a change.
- **Custom workflows** built only via SQL triggers or external modules are invisible unless they use standard object APIs Knot already calls.

## Scope

- **Pilot objects** are representative (invoice, sales order, proposal in compatibility tooling); other MAP objects follow the same runtime patterns only where introspection and lifecycle methods exist.
- **Integer status values** are not hard-coded product-side; logical keys derive from reflected constants (`STATUS_*` / `STATUT_*`) on the live class.

## Third-party modules

- Modules that replace core classes, add parallel status fields, or expose non-standard setters may yield **`KNOT_SM_METHOD_NOT_FOUND`** or **`KNOT_SM_EXTRACTION_FAILED`**. No automatic detection of overridden lifecycle graphs.

## Performance

- Heavy reflection runs are cached under `documents/knot/state-machine/<Dolibarr version>/`. Very large custom classes may still stress PHP memory or timeouts on constrained hosting.

See also [`README.md`](README.md) and [`docs/ux/risk-grammar.md`](../ux/risk-grammar.md) for inspector risk cues.
