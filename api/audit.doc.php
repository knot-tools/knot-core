<?php

declare(strict_types=1);

use Knot\Api\Doc\Operation;

#[Operation(
    method: 'GET',
    path: '/api/audit.php',
    summary: 'Query the audit log (sensitive operations only)',
    description: "Returns rows from `llx_knot_audit_log` filtered by entity.\n"
        . "Requires **`workflow.read`** or administrator.\n\n"
        . "Query: `action_type` / `entity_type` (alnum plus `._-`, max 128); "
        . "aliases `actionType` / `entityType`; `q` server-side search (truncated 200 chars, restricthtml); "
        . "`user_id`, `since`, `limit` (default 100, max 500), `offset`. "
        . "`format=csv` exports up to 5000 rows with the same filters and records `audit.export_csv`.\n",
    tags: ['operations'],
    responseSchema: 'Envelope',
    permission: 'knot->workflow->read',
    responseStatuses: [200, 401, 403, 500],
)]
final class KnotApiDoc_Audit
{
}
