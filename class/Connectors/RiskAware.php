<?php

declare(strict_types=1);

namespace Knot\Connectors;

/**
 * Risk-grammar trait for connectors.
 *
 * Provides safe defaults for the V2.5 visual risk grammar
 * (`docs/ux/risk-grammar.md`). Implementing connectors override these
 * methods when they need a richer behaviour:
 *
 *  - {@see riskLevel()} returns one of `safe` / `caution` / `critical`.
 *    Default: `safe` (read-only, idempotent).
 *  - {@see riskFor()} computes the *effective* level given a config.
 *    Polymorphic connectors (`dolibarr.object`, `http`, ...) override
 *    this to return e.g. `critical` when `operation = 'delete'` but
 *    `safe` when `operation = 'read'`.
 *  - {@see sideEffects()} declares the side-effect surfaces the
 *    connector touches (`db`, `mail`, `http`, `accounting`, `fs`,
 *    `external-paid`).
 *  - {@see reversible()} reports whether the side effect can be
 *    undone after execution.
 *  - {@see requiredPermissionsFor()} enumerates the Dolibarr permission
 *    slugs (or Knot internal permission slugs) the connector consumes
 *    for a given config. The frontend renders one pill per slug on
 *    the node and greys out the node when the current user lacks one.
 *
 * The trait does NOT alter `getMetadata()` automatically; connectors
 * SHOULD merge `riskLevel`/`reversible`/`sideEffects` themselves into
 * the array they return. Helper {@see decorateMetadata()} is provided
 * for connectors that just want to splice the defaults in:
 *
 *   public function getMetadata(): array
 *   {
 *       return $this->decorateMetadata([
 *           'id' => 'my.connector',
 *           'label' => 'My Connector',
 *       ]);
 *   }
 */
trait RiskAware
{
    /**
     * Default risk level. Override per connector.
     *
     * Canonical string values live on {@see RiskLevels}; constants are not
     * declared on this trait so the codebase stays compatible with PHP 8.1
     * (trait constants require PHP 8.2+).
     */
    public function riskLevel(): string
    {
        return RiskLevels::SAFE;
    }

    /**
     * Compute the *effective* risk level given a config.
     * Default: returns {@see riskLevel()}.
     *
     * @param array<string, mixed> $config
     */
    public function riskFor(array $config): string
    {
        return $this->riskLevel();
    }

    /**
     * Side-effect surfaces touched by the connector.
     *
     * @return array<int, string>
     */
    public function sideEffects(): array
    {
        return [];
    }

    /**
     * Whether the connector's side effect can be undone.
     * Default: true (safe defaults to a read which is reversible by definition).
     */
    public function reversible(): bool
    {
        return true;
    }

    /**
     * Dolibarr (or Knot internal) permission slugs required to execute
     * with the given config. Used by the frontend to render
     * pastilles + grey out non-executable nodes.
     *
     * @param array<string, mixed> $config
     * @return array<int, string>
     */
    public function requiredPermissionsFor(array $config): array
    {
        return [];
    }

    /**
     * Splice the trait's risk fields into the array a connector returns
     * from {@see ConnectorInterface::getMetadata()}. Existing fields in
     * `$base` win — the trait never overrides explicit author values.
     *
     * @param array<string, mixed> $base
     * @return array<string, mixed>
     */
    protected function decorateMetadata(array $base): array
    {
        return $base + [
            'riskLevel' => $this->riskLevel(),
            'reversible' => $this->reversible(),
            'sideEffects' => $this->sideEffects(),
        ];
    }
}
