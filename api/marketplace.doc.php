<?php

declare(strict_types=1);

use Knot\Api\Doc\Operation;

#[Operation(
    method: 'GET',
    path: '/api/marketplace.php',
    summary: 'Curated marketplace index of Knot extensions',
    description: 'Reads the curated catalog (extensions + mirrored templates metadata)'
        . " aggregated for the Knot SPA Marketplace view.\n\n"
        . "Requires `knot.workflow.read`.\n\n"
        . "**HTTP 403** with `{ success: false, error.code = marketplace_ui_disabled }` "
        . "when the Dolibarr constant **`KNOT_MARKETPLACE_UI_ENABLED`** disables the catalog "
        . "aggregator (license activation endpoints stay on separate routes). "
        . "Administrators toggle this from **Knot → Setup → Marketplace**.",
    tags: ['marketplace'],
    responseSchema: 'Envelope',
    permission: 'knot->workflow->read',
    responseStatuses: [200, 401, 403, 404, 502],
)]
final class KnotApiDoc_Marketplace
{
}
