<?php

declare(strict_types=1);

use Knot\Api\Doc\Operation;

#[Operation(
    method: 'POST',
    path: '/api/execute.php',
    summary: 'Execute a workflow synchronously or asynchronously',
    tags: ['executions'],
    requestSchema: 'Envelope',
    responseSchema: 'Envelope',
    permission: 'knot->workflow->execute',
    responseStatuses: [200, 202, 400, 401, 403, 404, 500],
)]
final class KnotApiDoc_Execute
{
}
