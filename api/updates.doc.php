<?php

declare(strict_types=1);

use Knot\Api\Doc\Operation;

#[Operation(
    method: 'GET',
    path: '/api/updates.php',
    summary: 'Notify-only auto-update status for Knot Core and installed extensions',
    description: 'Aggregates the installed version of Knot Core (`Knot\\Version::current()`)'
        . " and every discovered extension (via `ExtensionRegistry::discover()`),"
        . " then compares each against the latest release manifest published by"
        . " `license.knot.tools/api/products/{slug}/latest`.\n\n"
        . "Results are cached in `llx_knot_config` (24h TTL, entity-aware) so the"
        . " response stays available when the central server is briefly unreachable."
        . " The response carries no download URL — triggering an install still"
        . " goes through the existing licensed flow (`api/license_download_token.php`).\n\n"
        . "Requires `knot.workflow.read`.",
    tags: ['updates'],
    responseSchema: 'Envelope',
    permission: 'knot->workflow->read',
    responseStatuses: [200, 401, 403],
)]
final class KnotApiDoc_Updates
{
}
