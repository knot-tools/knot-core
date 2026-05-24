<?php

declare(strict_types=1);

use Knot\Api\Doc\Operation;

#[Operation(
    method: 'GET',
    path: '/api/executions.php',
    summary: 'List recent workflow executions or fetch a single one with its logs',
    description: 'List endpoint accepts optional `workflow_id`, `limit`, `offset`, and `status`. '
        . 'Allowed `status` values: `queued`, `running`, `success`, `error`, `timeout`, `cancelled`, '
        . 'and aggregate `failed` (matches `error` and `timeout`).',
    tags: ['executions'],
    responseSchema: 'Envelope',
    permission: 'knot->workflow->read',
    responseStatuses: [200, 400, 401, 403, 404, 500],
)]
final class KnotApiDoc_Executions
{
}
