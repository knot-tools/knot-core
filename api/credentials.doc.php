<?php

declare(strict_types=1);

use Knot\Api\Doc\Operation;

#[Operation(
    method: 'GET',
    path: '/api/credentials.php',
    summary: 'List, create, update or delete credentials (encrypted AES-256-GCM at rest)',
    description: "Secrets are never returned in clear; the response only echoes a masked\n"
        . "preview. POST/PUT/DELETE require a CSRF token in `X-Knot-Csrf` and the\n"
        . "`knot->credential->write` permission.\n",
    tags: ['credentials'],
    responseSchema: 'Envelope',
    permission: 'knot->credential->read',
    responseStatuses: [200, 400, 401, 403, 404, 500],
)]
final class KnotApiDoc_Credentials
{
}
