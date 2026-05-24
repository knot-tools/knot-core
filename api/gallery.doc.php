<?php

declare(strict_types=1);

use Knot\Api\Doc\Operation;

#[Operation(
    method: 'GET',
    path: '/api/gallery.php',
    summary: 'Proxy for the public knot-templates gallery',
    description: "Without `slug`, returns the gallery index. With `slug=<category/name>`,\n"
        . "returns the corresponding workflow JSON ready to import.",
    tags: ['templates'],
    responseSchema: 'Envelope',
    permission: 'knot->workflow->read',
    responseStatuses: [200, 400, 401, 403, 404, 502],
)]
final class KnotApiDoc_Gallery
{
}
