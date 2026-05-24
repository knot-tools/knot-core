# `ObjectAction` vs native Dolibarr methods (Phase 4 roadmap)

| Area | Current state | Next steps |
|------|---------------|------------|
| Transitions | `change_status` delegates to user-provided `statusMethod` (default `setDraft`) after `fetch`. | Build allowlist per object class for safe transition methods; surface in UI with doc links. |
| PDF / notes | `generate_pdf`, `add_note` call Dolibarr APIs when available. | Add integration tests on Docker matrix for top MAP objects. |
| Lines | Validated through `SchemaBuilder` + `ObjectAction::validateAndCoercePayload`. | Extend line schema when descriptor declares `supportsLines` without curated MAP alias. |
| Verbs palette | Verb discoverer emits methods not executed automatically. | Decide policy: whitelist execution path vs documentation-only tooling. |

Use **single Dolibarr entity** regression datasets until dedicated multi-entity mission starts.
