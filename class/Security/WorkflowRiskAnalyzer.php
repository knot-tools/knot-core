<?php

declare(strict_types=1);

namespace Knot\Security;

use Knot\Connectors\ConnectorInterface;
use Knot\Connectors\ConnectorRegistry;
use Knot\Connectors\RiskLevels;
use Knot\Extension\ExtensionRegistry;

/**
 * Server-side workflow risk aggregation (mirrors frontend useNodeRisk).
 */
final class WorkflowRiskAnalyzer
{
    /** @var array<string, int> */
    private const RISK_RANK = [
        RiskLevels::SAFE => 0,
        RiskLevels::CAUTION => 1,
        RiskLevels::CRITICAL => 2,
    ];

    /** @var array<string, ConnectorInterface> */
    private array $connectors;

    public function __construct(
        private readonly ConnectorRegistry $registry = new ConnectorRegistry(),
        ?ExtensionRegistry $extensions = null,
    ) {
        $this->connectors = $this->registry->allWithExtensions($extensions);
    }

    /**
     * @param array<string, mixed> $definition
     */
    public function analyze(array $definition): WorkflowRiskReport
    {
        $nodes = is_array($definition['nodes'] ?? null) ? $definition['nodes'] : [];
        $worst = RiskLevels::SAFE;
        $criticalNodes = [];
        $allNodes = [];
        $sideEffects = [];

        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            $nodeId = (string) ($node['id'] ?? '');
            $nodeType = (string) ($node['type'] ?? '');
            $nodeLabel = (string) ($node['label'] ?? $nodeId);
            $config = is_array($node['config'] ?? null) ? $node['config'] : [];

            $resolved = $this->resolveNodeRisk($nodeType, $config);
            $level = $resolved['riskLevel'];
            $worst = $this->pickHighest($worst, $level);

            foreach ($resolved['sideEffects'] as $eff) {
                if (!in_array($eff, $sideEffects, true)) {
                    $sideEffects[] = $eff;
                }
            }

            $entry = [
                'nodeId' => $nodeId,
                'nodeType' => $nodeType,
                'nodeLabel' => $nodeLabel,
                'riskLevel' => $level,
            ];
            $allNodes[] = $entry;

            if ($level === RiskLevels::CRITICAL) {
                $criticalNodes[] = array_merge($entry, [
                    'riskReason' => $resolved['riskReason'],
                    'sideEffects' => $resolved['sideEffects'],
                ]);
            }
        }

        return new WorkflowRiskReport($worst, $criticalNodes, $allNodes, $sideEffects);
    }

    /**
     * @param array<string, mixed> $config
     * @return array{riskLevel: string, sideEffects: list<string>, riskReason: string}
     */
    private function resolveNodeRisk(string $nodeType, array $config): array
    {
        $connector = $this->connectors[$nodeType] ?? null;
        if ($connector === null) {
            return [
                'riskLevel' => RiskLevels::SAFE,
                'sideEffects' => [],
                'riskReason' => 'unknown_connector',
            ];
        }

        $meta = $connector->getMetadata();
        $metaLevel = (string) ($meta['riskLevel'] ?? RiskLevels::SAFE);
        if (!in_array($metaLevel, RiskLevels::ALL, true)) {
            $metaLevel = RiskLevels::SAFE;
        }

        $configLevel = $this->readConfigLevel($config, $meta);
        $expertLevel = $this->readDolibarrExpertRegistryRisk($config);

        $mergedFromConfig = null;
        if ($configLevel !== null || $expertLevel !== null) {
            $mergedFromConfig = $this->pickHighest($configLevel ?? RiskLevels::SAFE, $expertLevel ?? RiskLevels::SAFE);
        }

        $resolvedOverride = null;
        if (method_exists($connector, 'riskFor')) {
            /** @var callable(array<string, mixed>): string $riskFor */
            $riskFor = [$connector, 'riskFor'];
            $resolvedOverride = $riskFor($config);
        }

        $candidate = $resolvedOverride ?? $mergedFromConfig ?? $metaLevel;
        $riskLevel = $this->pickHighest($candidate, $metaLevel);

        $sideEffects = is_array($meta['sideEffects'] ?? null) ? $meta['sideEffects'] : [];
        $sideEffects = array_values(array_filter(
            $sideEffects,
            static fn ($e): bool => is_string($e) && in_array($e, RiskLevels::ALL_SIDE_EFFECTS, true)
        ));

        $riskReason = $this->buildRiskReason($config, $meta, $riskLevel);

        return [
            'riskLevel' => $riskLevel,
            'sideEffects' => $sideEffects,
            'riskReason' => $riskReason,
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $meta
     */
    private function buildRiskReason(array $config, array $meta, string $riskLevel): string
    {
        if ($riskLevel !== RiskLevels::CRITICAL) {
            return $riskLevel;
        }
        $key = (string) ($meta['riskFieldKey'] ?? 'operation');
        $op = strtolower((string) ($config[$key] ?? ''));
        if ($op !== '') {
            return 'operation:' . $op;
        }

        return 'connector:' . (string) ($meta['id'] ?? '');
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $meta
     */
    private function readConfigLevel(array $config, array $meta): ?string
    {
        $riskByConfig = $meta['riskByConfig'] ?? null;
        if (!is_array($riskByConfig) || $riskByConfig === []) {
            return null;
        }
        $key = (string) ($meta['riskFieldKey'] ?? 'operation');
        $value = strtolower((string) ($config[$key] ?? ''));
        if ($value === '') {
            return null;
        }
        $level = $riskByConfig[$value] ?? null;
        if (!is_string($level) || !in_array($level, RiskLevels::ALL, true)) {
            return null;
        }

        return $level;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function readDolibarrExpertRegistryRisk(array $config): ?string
    {
        if ((string) ($config['objectRegistryMode'] ?? '') === 'discovery_unverified') {
            return RiskLevels::CAUTION;
        }

        return null;
    }

    private function pickHighest(string $a, string $b): string
    {
        $ra = self::RISK_RANK[$a] ?? 0;
        $rb = self::RISK_RANK[$b] ?? 0;

        return $ra >= $rb ? $a : $b;
    }
}
