<?php

declare(strict_types=1);

namespace Knot\Connectors;

use Attribute;

/**
 * Attribute used for connector discovery.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Connector
{
    public function __construct(
        public readonly string $id,
        public readonly string $category,
        public readonly string $version = '1.0'
    ) {
    }
}
