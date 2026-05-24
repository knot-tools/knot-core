# Knot error catalogue

User-visible API errors use stable `KNOT_*` codes. Responses embed structured fields under `error.details.knot` (see ADR-007).

Legend: **Cause** / **Fix** — short guidance. Messages below are EN; the UI may localize separately.

---

## knot-critical-system

| Field | Value |
|-------|--------|
| Code | `KNOT_CRITICAL_SYSTEM` |
| Severity | critical |
| User EN | An unexpected critical failure occurred. Check server logs. |
| User FR | Une erreur critique inattendue s’est produite. Consultez les journaux serveur. |
| Cause | Unhandled fatal infrastructure fault. |
| Fix | Contact administrator; collect Dolibarr + PHP logs. |

---

## knot-database-connection

| Code | `KNOT_DATABASE_CONNECTION` |
| Severity | critical |
| User EN | Knot cannot reach the database. |
| User FR | Knot ne peut pas joindre la base de données. |
| Cause | DB down, credentials, or network. |
| Fix | Verify MariaDB/MySQL service and Dolibarr `$dolibarr_main_db_*`. |

---

## knot-dependency-failed

| Code | `KNOT_DEPENDENCY_FAILED` |
| Severity | error |
| User EN | A required dependency step failed in the workflow. |
| Cause | Upstream connector or sub-workflow error. |
| Fix | Inspect execution waterfall and prior node output. |

---

## knot-disk-full

| Code | `KNOT_DISK_FULL` |
| Severity | critical |
| User EN | Disk space is insufficient to complete the operation. |
| Cause | Volume full on host. |
| Fix | Free space or expand storage. |

---

## knot-dolibarr-integrity

| Code | `KNOT_DOLIBARR_INTEGRITY` |
| Severity | error |
| User EN | Dolibarr rejected the change due to inconsistent related data. |
| Cause | FK violation, duplicate key, DB constraint. |
| Fix | Validate linked objects (third party, lines, stock). |

---

## knot-dolibarr-record-not-found

| Code | `KNOT_DOLIBARR_RECORD_NOT_FOUND` |
| Severity | warning |
| User EN | The requested Dolibarr record was not found. |
| Cause | Deleted row or wrong id. |
| Fix | Refresh id from a fetch/search step. |

---

## knot-dolibarr-unexpected

| Code | `KNOT_DOLIBARR_UNEXPECTED` |
| Severity | error |
| User EN | Dolibarr returned an error while processing this step. |
| Cause | Generic Dolibarr/API failure not classified. |
| Fix | Read execution logs and Dolibarr logs for detail. |

---

## knot-execution-failed

| Code | `KNOT_EXECUTION_FAILED` |
| Severity | error |
| User EN | Workflow execution stopped with an error. |
| Cause | Connector threw or validation mid-run (includes **`logic.stop_error`** intentional halt). |
| Fix | Open execution detail; fix node configuration. |

---

## knot-execution-loop

| Code | `KNOT_EXECUTION_LOOP` |
| Severity | error |
| User EN | The workflow graph may contain a loop Knot refused to run. |
| Cause | Cycle detection / guard limits. |
| Fix | Redesign branches or increase limits where safe. |

---

## knot-execution-timeout

| Code | `KNOT_EXECUTION_TIMEOUT` |
| Severity | error |
| User EN | Execution exceeded the allowed time limit. |
| Cause | Long-running sync step or PHP max_execution_time. |
| Fix | Split workflow or run heavy steps asynchronously. |

---

## knot-invalid-config

| Code | `KNOT_INVALID_CONFIG` |
| Severity | warning |
| User EN | Knot configuration is invalid or incomplete. |
| Cause | Bad module setup or constants. |
| Fix | Review Knot admin setup pages. |

---

## knot-invalid-type

| Code | `KNOT_INVALID_TYPE` |
| Severity | warning |
| User EN | A value has the wrong type for this field. |
| Cause | Schema coercion failure. |
| Fix | Align payload types with Dolibarr extrafield/schema. |

---

## knot-module-not-activated

| Code | `KNOT_MODULE_NOT_ACTIVATED` |
| Severity | warning |
| User EN | The required Dolibarr module is not enabled. |
| Cause | Optional module disabled. |
| Fix | Enable module under Setup ▸ Modules. |

---

## knot-perm-dolibarr-denied

| Code | `KNOT_PERM_DOLIBARR_DENIED` |
| Severity | warning |
| User EN | Your Dolibarr profile does not allow this operation. |
| Cause | Dolibarr permission check failed. |
| Fix | Grant module/object rights to the executing user. |

---

## knot-perm-knot-denied

| Code | `KNOT_PERM_KNOT_DENIED` |
| Severity | warning |
| User EN | Your profile lacks the required Knot permission. |
| Cause | `hasRight('knot', ...)` failure. |
| Fix | Administrator adjusts Knot rights for the user/group. |

---

## knot-precondition-failed

| Code | `KNOT_PRECONDITION_FAILED` |
| Severity | warning |
| User EN | A precondition for this operation was not met. |
| Cause | Business guard before side effects. |
| Fix | Adjust workflow order or input data. |

---

## knot-rate-limited

| Code | `KNOT_RATE_LIMITED` |
| Severity | warning |
| User EN | Too many requests were sent; please slow down. |
| Cause | Knot rate limiter (e.g. execute endpoint). |
| Fix | Retry after delay; reduce automation frequency. |

---

## knot-schema-workflow-invalid

| Code | `KNOT_SCHEMA_WORKFLOW_INVALID` |
| Severity | warning |
| User EN | The workflow graph failed validation. |
| Cause | Invalid nodes, edges, or missing trigger. |
| Fix | Fix issues listed in `error.details.knot.context.issues`. |

---

## knot-state-already-exists

| Code | `KNOT_STATE_ALREADY_EXISTS` |
| Severity | warning |
| User EN | This record already exists or duplicates another. |
| Cause | Create when row already present. |
| Fix | Use update/fetch path or unique references. |

---

## knot-state-invalid-transition

| Code | `KNOT_STATE_INVALID_TRANSITION` |
| Severity | warning |
| User EN | This operation is not allowed for the current document status. |
| Cause | Dolibarr lifecycle rejects transition (e.g. paid invoice). |
| Fix | Align workflow with valid statuses (see ADR-005). |

---

## knot-sm-method-not-found

| Code | `KNOT_SM_METHOD_NOT_FOUND` |
| Severity | error |
| User EN | This Dolibarr object does not expose the expected status setter API. |
| Cause | No recognised `setStatut` / `validate` / lifecycle method on the live class while validating `change_status`. |
| Fix | Check Dolibarr version and object slug; avoid manual status writes; report gap if the core API differs. |

---

## knot-sm-extraction-failed

| Code | `KNOT_SM_EXTRACTION_FAILED` |
| Severity | warning |
| User EN | Knot could not infer the document status from Dolibarr data. |
| Cause | Missing `STATUS_*` / `STATUT_*` constants or unreadable object fields during state extraction. |
| Fix | Verify object instance and permissions; ensure introspection cache is fresh (`dolibarr_schemas.php?refresh=1`). |

---

## knot-system-unexpected

| Code | `KNOT_SYSTEM_UNEXPECTED` |
| Severity | error |
| User EN | An unexpected system error occurred. |
| Cause | Infrastructure outside Dolibarr-specific mapping. |
| Fix | Check PHP/OS logs; retry after verification. |

---

## knot-validation-invalid-value

| Code | `KNOT_VALIDATION_INVALID_VALUE` |
| Severity | warning |
| User EN | One or more values are not accepted by Dolibarr. |
| Cause | Enum/date/numeric validation. |
| Fix | Correct expressions against schema. |

---

## knot-validation-missing-field

| Code | `KNOT_VALIDATION_MISSING_FIELD` |
| Severity | warning |
| User EN | A mandatory field is missing for this operation. |
| Cause | Required column unset on create/update. |
| Fix | Populate required fields from mapping. |

---

## knot-webhook-invalid-payload

| Code | `KNOT_WEBHOOK_INVALID_PAYLOAD` |
| Severity | warning |
| User EN | Webhook payload JSON is invalid or incomplete. |
| Cause | Parser failure on inbound webhook. |
| Fix | Sender must emit valid JSON matching trigger expectations. |

---

## knot-credential-missing

| Code | `KNOT_CREDENTIAL_MISSING` |
| Severity | warning |
| User EN | Required credentials are missing for this connector. |
| Cause | Node references credential not defined. |
| Fix | Create credential in Knot credentials vault. |

---

## knot-credential-invalid

| Code | `KNOT_CREDENTIAL_INVALID` |
| Severity | warning |
| User EN | Stored credentials are invalid or expired. |
| Cause | Decryption failure or remote auth rejection. |
| Fix | Rotate secret; verify connector test connection. |

---

## knot-queue-overflow

| Code | `KNOT_QUEUE_OVERFLOW` |
| Severity | warning |
| User EN | The execution queue depth exceeds safe limits. |
| Cause | Cron backlog or burst traffic. |
| Fix | Increase cron frequency or reduce inbound triggers. |

---

## knot-queue-conflict

| Code | `KNOT_QUEUE_CONFLICT` |
| Severity | warning |
| User EN | Another execution holds a lock for this workflow. |
| Cause | `single_instance` or concurrent dequeue conflict. |
| Fix | Wait for running job to finish or adjust workflow settings. |

---

## knot-object-unsupported

| Code | `KNOT_OBJECT_UNSUPPORTED` |
| Severity | warning |
| User EN | This Dolibarr object type is not supported on this instance. |
| Cause | Module missing or registry filter. |
| Fix | Enable module or pick supported object type. |

---

## knot-object-id-required

| Code | `KNOT_OBJECT_ID_REQUIRED` |
| Severity | warning |
| User EN | A Dolibarr object id is required for this operation. |
| Cause | fetch/update/delete without id. |
| Fix | Pass numeric id from prior fetch. |

---

## knot-user-required

| Code | `KNOT_USER_REQUIRED` |
| Severity | error |
| User EN | An authenticated Dolibarr user is required. |
| Cause | CLI/cron context without user injection. |
| Fix | Ensure execution runs under authenticated session. |

---

## knot-json-invalid

| Code | `KNOT_JSON_INVALID` |
| Severity | warning |
| User EN | JSON could not be parsed. |
| Cause | Malformed API body or import file. |
| Fix | Validate JSON syntax externally. |

---

## knot-import-partial

| Code | `KNOT_IMPORT_PARTIAL` |
| Severity | warning |
| User EN | Import finished with some workflows skipped. |
| Cause | Tier gate or validation per workflow. |
| Fix | Review import summary errors array. |

---

## knot-template-unavailable

| Code | `KNOT_TEMPLATE_UNAVAILABLE` |
| Severity | warning |
| User EN | Requested template is unavailable offline or blocked. |
| Cause | Marketplace/cache miss or licence. |
| Fix | Retry later or pick another template. |

---

## knot-license-required

| Code | `KNOT_LICENSE_REQUIRED` |
| Severity | warning |
| User EN | This action requires an active Knot licence or entitlement. |
| Cause | Pro/feature gate. |
| Fix | Activate licence or upgrade tier (see docs). |

---

## knot-http-client-error

| Code | `KNOT_HTTP_CLIENT_ERROR` |
| Severity | warning |
| User EN | External HTTP call failed with a client error (4xx). |
| Cause | Bad URL, auth, or payload to remote API. |
| Fix | Fix HTTP node configuration. |

---

## knot-http-server-error

| Code | `KNOT_HTTP_SERVER_ERROR` |
| Severity | error |
| User EN | External HTTP call failed with a server error (5xx). |
| Cause | Remote downtime or bug. |
| Fix | Retry with backoff; contact remote provider. |

---

## knot-sandbox-violation

| Code | `KNOT_SANDBOX_VIOLATION` |
| Severity | error |
| User EN | Code execution violated sandbox rules. |
| Cause | Unsafe PHP in Code node. |
| Fix | Remove disallowed calls or use approved APIs. |

---

## knot-approval-required

| Code | `KNOT_APPROVAL_REQUIRED` |
| Severity | info |
| User EN | Workflow paused pending human approval. |
| Cause | Approval wait node. |
| Fix | Approve or reject in Approvals UI. |

---

## knot-approval-rejected

| Code | `KNOT_APPROVAL_REJECTED` |
| Severity | warning |
| User EN | Approval was rejected; downstream branch should handle this. |
| Cause | User rejected approval request. |
| Fix | Adjust data and resubmit if business allows. |

---

## knot-schedule-invalid-cron

| Code | `KNOT_SCHEDULE_INVALID_CRON` |
| Severity | warning |
| User EN | Cron expression or timezone is invalid. |
| Cause | Parser failure in schedule definition. |
| Fix | Use standard five-field cron and valid TZ. |

---

## knot-variable-undefined

| Code | `KNOT_VARIABLE_UNDEFINED` |
| Severity | warning |
| User EN | Referenced workflow variable is undefined. |
| Cause | Typo or scope issue in expression. |
| Fix | Declare variable or fix expression path. |

---

## knot-expression-eval-failed

| Code | `KNOT_EXPRESSION_EVAL_FAILED` |
| Severity | warning |
| User EN | Expression evaluation failed. |
| Cause | Syntax or type error in expression resolver. |
| Fix | Simplify expression; validate against sample context. |

---

## knot-connector-disabled

| Code | `KNOT_CONNECTOR_DISABLED` |
| Severity | warning |
| User EN | This connector is disabled by policy or licence. |
| Cause | Admin toggle or tier gate. |
| Fix | Enable connector or upgrade entitlement. |

---

## knot-idempotency-conflict

| Code | `KNOT_IDEMPOTENCY_CONFLICT` |
| Severity | info |
| User EN | Duplicate request matched idempotency replay. |
| Cause | Same idempotency key replayed. |
| Fix | Safe to treat as success when replay flag returned. |

---

## knot-workflow-inactive

| Code | `KNOT_WORKFLOW_INACTIVE` |
| Severity | warning |
| User EN | Workflow is disabled or archived. |
| Cause | Status not `active`. |
| Fix | Activate workflow before enqueueing runs. |

---

## knot-workflow-not-found

| Code | `KNOT_WORKFLOW_NOT_FOUND` |
| Severity | warning |
| User EN | Workflow was not found for this entity. |
| Cause | Wrong id or entity scope. |
| Fix | Verify workflow id and multi-entity rules. |

---

## knot-single-instance-blocked

| Code | `KNOT_SINGLE_INSTANCE_BLOCKED` |
| Severity | warning |
| User EN | Another run is already active for this workflow. |
| Cause | `single_instance` guard in worker. |
| Fix | Wait or disable single-instance if appropriate. |

---

## knot-capabilities-unavailable

| Code | `KNOT_CAPABILITIES_UNAVAILABLE` |
| Severity | warning |
| User EN | Instance capability manifest could not be built. |
| Cause | Rare bootstrap failure. |
| Fix | Retry; check Knot logs. |

---

## knot-ground-truth-mismatch

| Code | `KNOT_GROUND_TRUTH_MISMATCH` |
| Severity | warning |
| User EN | Introspection snapshot differs from Golden compatibility check. |
| Cause | Dolibarr upgrade drift vs Knot expectations. |
| Fix | Run compatibility scripts; update Knot when prompted. |

---

## knot-migration-blocked

| Code | `KNOT_MIGRATION_BLOCKED` |
| Severity | error |
| User EN | Database migration cannot proceed safely. |
| Cause | Migrator precondition failure. |
| Fix | Follow upgrade guide; resolve DB errors. |

---

## knot-dsl-invalid-structure

| Code | `KNOT_DSL_INVALID_STRUCTURE` |
| Severity | error |
| User EN | Workflow definition is missing required fields or uses an unsupported `schemaVersion`. |
| Cause | Malformed JSON definition root or nodes/edges not arrays. |
| Fix | Align with `docs/workflow-format.md` and `schemas/workflow-definition-v1.schema.json`. |

---

## knot-dsl-edge-invalid

| Code | `KNOT_DSL_EDGE_INVALID` |
| Severity | error |
| User EN | An edge references a node id that does not exist in the graph. |
| Cause | Stale or pasted edges after node deletion. |
| Fix | Rewire or delete the edge in the editor. |

---

## knot-dsl-node-reference-invalid

| Code | `KNOT_DSL_NODE_REFERENCE_INVALID` |
| Severity | error |
| User EN | Duplicate node id or invalid node reference in the graph. |
| Cause | Two nodes share the same `id`. |
| Fix | Rename nodes so every `id` is unique. |

---

## knot-dsl-unknown-connector

| Code | `KNOT_DSL_UNKNOWN_CONNECTOR` |
| Severity | warning |
| User EN | A node type is not registered on this instance (missing extension or licence tier). |
| Cause | Connector id not in merged Core + extensions registry. |
| Fix | Install the add-on, upgrade licence, or replace the node type. |

---

## knot-dsl-graph-cycle

| Code | `KNOT_DSL_GRAPH_CYCLE` |
| Severity | warning |
| User EN | The workflow graph contains a directed cycle. |
| Cause | Loop edges (intentional or accidental). |
| Fix | Confirm loops are intended; otherwise break the cycle. |

---

## knot-dsl-orphan-node

| Code | `KNOT_DSL_ORPHAN_NODE` |
| Severity | warning |
| User EN | A node is not reachable from any trigger via success edges. |
| Cause | Disconnected branch or wrong edge direction. |
| Fix | Connect the node from a trigger path or remove it. |

---

## knot-schema-workflow-invalid

| Field | Value |
|-------|--------|
| Code | `KNOT_SCHEMA_WORKFLOW_INVALID` |
| Severity | error |
| User EN | The workflow graph failed validation (see `issues` in error payload). |
| Cause | Blocking semantic or structural errors from `WorkflowValidator`. |
| Fix | Fix issues listed in the editor Problems panel or API `issues` array. |

---

## Related ADRs

- [ADR-007 — Normalised errors](../architecture/decisions/ADR-007-normalized-errors-framework.md)
- [ADR-005 — Hybrid state machine](../architecture/decisions/ADR-005-state-machine-hybrid-approach.md)
