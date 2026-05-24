<?php

declare(strict_types=1);

use Knot\Api\Doc\Operation;

#[Operation(
    method: 'POST',
    path: '/api/updates_apply.php',
    summary: 'Apply Knot Core or licensed extension update ZIP',
    description: 'Downloads and installs an update artefact for Knot Core (`slug=knot`)'
        . ' or a registered commercial extension. Core apply uses GitHub / knot.tools'
        . ' `releases.json`; extensions use `license.knot.tools` JWT download tokens.'
        . "\n\nEvery ZIP is verified with SHA-256. Detached Ed25519 signatures are"
        . ' mandatory for extensions and for Knot Core releases **>= 2.13.4**'
        . ' (older Core releases tolerate empty `signature_hex` for retrocompat).'
        . ' Failures return `release_signature_invalid` (422).'
        . "\n\nExtension metadata fetches honour `KNOT_RELEASE_CHANNEL` via"
        . ' `?channel=` on `/api/products/{slug}/signature`.'
        . "\n\nRequires `knot.admin.configure` and CSRF.",
    tags: ['updates'],
    responseSchema: 'Envelope',
    permission: 'knot->admin->configure',
    responseStatuses: [200, 401, 403, 419, 422, 423, 500, 502],
)]
final class KnotApiDoc_UpdatesApply
{
}
