<?php

declare(strict_types=1);

namespace Knot\Tests\Marketplace\Fixtures;

use Knot\Extension\ExtensionRegistry;

/**
 * Tiny ExtensionRegistry double — overrides `discover()` so the gate
 * sees a deterministic snapshot. Shared between TierGateTest and
 * WorkflowTierAuditorTest. Kept here (not in tests/stubs/) because
 * `tests/stubs/` is excluded from the suite by phpunit.xml.dist.
 */
final class StubExtensionRegistry extends ExtensionRegistry
{
    /** @param array<string, array<string, mixed>> $snapshot */
    public function __construct(private readonly array $snapshot)
    {
        parent::__construct([sys_get_temp_dir() . '/knot-tier-gate-tests']);
    }

    public function discover(): array
    {
        return $this->snapshot;
    }

    public function clearCache(): void
    {
        // No-op — the snapshot is immutable.
    }
}
