<?php

declare(strict_types=1);

use Knot\Api\Doc\Operation;

#[Operation(
    method: 'GET',
    path: '/api/license_status.php',
    summary: 'Inspect the active licences for installed Knot extensions',
    description: "Returns one entry per discovered extension manifest with its\n"
        . "validation status (`valid`, `expired`, `not_required`,\n"
        . "`invalid_signature`…), expiry date and issuer where available.\n",
    tags: ['licensing'],
    responseSchema: 'Envelope',
    permission: 'knot->workflow->read',
    responseStatuses: [200, 401, 403, 500],
)]
final class KnotApiDoc_LicenseStatus
{
}
