<?php

declare(strict_types=1);

namespace Knot\Security;

/**
 * Aggregated risk analysis for a workflow definition.
 */
final class WorkflowRiskReport
{
    /**
     * @param list<array{nodeId: string, nodeType: string, nodeLabel: string, riskLevel: string, riskReason: string, sideEffects: list<string>}> $criticalNodes
     * @param list<array{nodeId: string, nodeType: string, nodeLabel: string, riskLevel: string}> $nodes
     * @param list<string> $sideEffects
     */
    public function __construct(
        public readonly string $worstLevel,
        public readonly array $criticalNodes,
        public readonly array $nodes,
        public readonly array $sideEffects,
    ) {
    }

    public function hasCritical(): bool
    {
        return $this->worstLevel === 'critical' || $this->criticalNodes !== [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'worstLevel' => $this->worstLevel,
            'hasCritical' => $this->hasCritical(),
            'criticalNodes' => $this->criticalNodes,
            'sideEffects' => $this->sideEffects,
        ];
    }
}
