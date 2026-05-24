# Supported Dolibarr classes — state machine surface

Knot discovers **`STATUS_*` constants** and probable transitions via `StateMachineEngine` for **every active mapped object** exposed in capabilities, except slugs listed in `PilotDocuments::STATE_MACHINE_EXCLUDED_SLUGS`.

- **Schema snapshots** (golden compatibility JSON) still default to `PilotDocuments::SCHEMA_SNAPSHOT_SLUGS` (`facture`, `commande`, `propal`) — see ADR-010 / ADR-011.
- **Capabilities** (`api/capabilities.php`) fills `objects.list[].states_known` when extraction succeeds.
- **Canvas risk hints** (`change_status`) call verbs / probable transitions for **any** non-empty object slug (same APIs as the inspector).

To exclude additional classes from automatic extraction (e.g. unsafe legacy modules), extend `STATE_MACHINE_EXCLUDED_SLUGS` in `PilotDocuments.php` and document the rationale here.

| Slug | Excluded | Reason |
|------|-----------|--------|
| user | yes | Auth record — not a workflow document |
| usergroup | yes | ACL primitive |
| bookmark | yes | No business status lifecycle |
