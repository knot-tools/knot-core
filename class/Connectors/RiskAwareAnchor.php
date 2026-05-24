<?php

declare(strict_types=1);

namespace Knot\Connectors;

/** @internal Analysis anchor so PHPStan sees {@see RiskAware} as used in `class/`. */
abstract class RiskAwareAnchor
{
    use RiskAware;
}
