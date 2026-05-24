<?php

declare(strict_types=1);

use Knot\Api\Doc\Operation;

#[Operation(
    method: 'GET',
    path: '/api/connectors.php',
    summary: 'Discover registered connectors and their JSON schema',
    description: "Returns the connectors discovered by the {@see Knot\\Connectors\\ConnectorRegistry}\n"
        . "including those provided by Knot Pro Pack when its license is valid.\n"
        . "Connectors gated by an inactive licence are returned with `available=false`\n"
        . "so the editor can render them locked instead of hiding them.\n",
    tags: ['catalog'],
    responseSchema: 'Envelope',
    permission: 'knot->workflow->read',
    responseStatuses: [200, 401, 403, 500],
)]
final class KnotApiDoc_Connectors
{
}
