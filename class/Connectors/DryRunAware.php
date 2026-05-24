<?php

declare(strict_types=1);

namespace Knot\Connectors;

/**
 * Optional contract for connectors that distinguish a real execution from
 * a dry-run simulation.
 *
 * Connectors that have side effects (HTTP calls, DB writes, emails, etc.)
 * SHOULD implement this interface so the engine can route to {@see simulate()}
 * when {@see ExecutionContext} carries `execution.dryRun = true`.
 *
 * Connectors that are pure data transforms (Set, JSON, Filter, IfElse, ...)
 * do NOT need to implement this interface — their {@see execute()} is already
 * deterministic and side-effect free.
 */
interface DryRunAware
{
    /**
     * Simulate the connector without producing real side effects.
     *
     * Implementations MUST:
     *  - never call external services,
     *  - never write to the Dolibarr database,
     *  - never send emails / SMS / push notifications,
     *  - return a "would-do" preview instead.
     *
     * The returned payload SHOULD include a `_dryRun` => true marker so the
     * UI can highlight simulated steps.
     *
     * @param array<string, mixed> $context Execution context (same shape as execute())
     * @return array<string, mixed>
     */
    public function simulate(array $context): array;
}
