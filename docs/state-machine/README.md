# Dolibarr state machine (operator notes)

This complements **`docs/architecture/state-machine-design.md`** (intent) and **ADR-012** (runtime decision).

Out-of-scope behaviour (guards, modules, perf): **[`limitations.md`](limitations.md)**.

## Runtime components

| Piece | Role |
| --- | --- |
| `StateExtractor` | Infer logical status from Dolibarr constants / fields |
| `TransitionDetector` | Map verbs via `VerbDiscoverer` |
| `RuntimeValidator` | Validate / apply transitions using native Dolibarr APIs |
| `StateMachineEngine` | Orchestration + probable transition hints |
| `StateMachineCache` | File cache under `documents/knot/state-machine/<Dolibarr version>/` |

## Internal API

Base path: **`/custom/knot/api/state_machine.php`**

Typical GET actions: `states`, `transitions`, `probable_transitions`, `current` (all require `workflow.read`).

POST `transition` requires **`workflow.execute`** and a CSRF token (`token` field or `X-Csrf-Token` header).

## Connector behaviour

- Live **`change_status`** operations pass through **`RuntimeValidator`**.
- Dry-run / simulate responses include **probable transitions** metadata when enabled.

## Troubleshooting

- **`KNOT_SM_EXTRACTION_FAILED`** — Refresh introspection (`dolibarr_schemas.php?refresh=1`) and verify the slug maps to a supported pilot/class.
- **`KNOT_SM_METHOD_NOT_FOUND`** — The Dolibarr class on this version does not expose the expected setter; treat as unsupported transition path for that object/version.
