<?php

declare(strict_types=1);

use Knot\Api\Doc\Operation;

#[Operation(
    method: 'GET',
    path: '/api/health.php',
    summary: 'Module healthcheck (tables, schedules, executions, license dev mode)',
    tags: ['operations'],
    responseSchema: 'Envelope',
    permission: 'knot->workflow->read',
    responseStatuses: [200, 401, 403, 500],
)]
final class KnotApiDoc_Health
{
}
