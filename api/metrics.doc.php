<?php

declare(strict_types=1);

use Knot\Api\Doc\Operation;

#[Operation(
    method: 'GET',
    path: '/api/metrics.php',
    summary: 'Prometheus metrics endpoint (or JSON for the dashboard heatmap)',
    description: "Gated by `KNOT_METRICS_PROMETHEUS_ENABLED`. Authenticated either by\n"
        . "bearer token (`KNOT_METRICS_BEARER_TOKEN`) or by being called from localhost.\n"
        . "Pass `?format=json` to receive structured JSON for the dashboard.",
    tags: ['metrics'],
    responseSchema: 'Envelope',
    authRequired: false,
    responseStatuses: [200, 401, 403, 404],
)]
final class KnotApiDoc_Metrics
{
}
