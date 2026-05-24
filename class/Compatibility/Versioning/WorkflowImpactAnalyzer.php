<?php

declare(strict_types=1);

namespace Knot\Compatibility\Versioning;

/**
 * Cross-checks workflow JSON definitions against compatibility findings.
 */
final class WorkflowImpactAnalyzer
{
    /**
     * @param array<string, mixed>                             $definition Workflow canvas definition (`nodes`, `edges`, ...)
     * @param list<array<string, mixed>>                         $breaking   Output of {@see BreakingChangeDetector::classify()}
     *
     * @return list<array<string, mixed>>
     */
    public function analyzeDefinition(array $definition, array $breaking): array
    {
        $nodes = is_array($definition['nodes'] ?? null) ? $definition['nodes'] : [];
        $hints = [];

        $removedPropsBySlug = [];
        $removedVerbsBySlug = [];
        foreach ($breaking as $row) {
            $detail = is_array($row['detail'] ?? null) ? $row['detail'] : [];
            $slug = (string) ($detail['slug'] ?? '');
            $kind = (string) ($detail['kind'] ?? '');
            if ($slug === '') {
                continue;
            }
            if ($kind === 'property_removed') {
                $removedPropsBySlug[$slug][(string) ($detail['property'] ?? '')] = true;
            }
            if ($kind === 'transition_verb_removed') {
                $removedVerbsBySlug[$slug][strtolower((string) ($detail['verb'] ?? ''))] = true;
            }
        }

        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            $type = (string) ($node['type'] ?? '');
            if ($type !== 'dolibarr.object') {
                continue;
            }
            $nodeId = (string) ($node['id'] ?? '');
            $config = is_array($node['config'] ?? null) ? $node['config'] : [];
            $objectType = trim((string) ($config['objectType'] ?? ''));

            $hits = [];
            if ($objectType !== '' && isset($removedPropsBySlug[$objectType])) {
                $fields = is_array($config['fields'] ?? null) ? $config['fields'] : [];
                foreach (array_keys($fields) as $fieldName) {
                    $fieldName = (string) $fieldName;
                    if (isset($removedPropsBySlug[$objectType][$fieldName])) {
                        $hits[] = [
                            'reason' => 'field_removed',
                            'field' => $fieldName,
                        ];
                    }
                }
            }

            if ($objectType !== '' && isset($removedVerbsBySlug[$objectType])) {
                $operation = strtolower(trim((string) ($config['operation'] ?? '')));
                $method = strtolower(trim((string) ($config['statusMethod'] ?? '')));
                $custom = strtolower(trim((string) ($config['statusMethodCustom'] ?? '')));
                foreach ([$method, $custom] as $candidate) {
                    if ($candidate !== '' && isset($removedVerbsBySlug[$objectType][$candidate])) {
                        $hits[] = [
                            'reason' => 'transition_removed',
                            'verb' => $candidate,
                        ];
                    }
                }
                if ($operation === 'change_status' && $method !== '' && isset($removedVerbsBySlug[$objectType][$method])) {
                    $hits[] = [
                        'reason' => 'transition_removed',
                        'verb' => $method,
                    ];
                }
            }

            if ($hits !== []) {
                $hints[] = [
                    'node_id' => $nodeId,
                    'object_type' => $objectType,
                    'hits' => $hits,
                ];
            }
        }

        return $hints;
    }

    /**
     * @param iterable<int|string, array<string, mixed>> $workflows Each item may contain `definition` JSON Decoded array or JSON string
     *
     * @param list<array<string, mixed>> $breaking
     *
     * @return list<array<string, mixed>>
     */
    public function analyzeMany(iterable $workflows, array $breaking): array
    {
        $merged = [];
        foreach ($workflows as $wf) {
            $definition = $wf['definition'] ?? null;
            if (is_string($definition)) {
                $decoded = json_decode($definition, true);
                $definition = is_array($decoded) ? $decoded : [];
            }
            if (!is_array($definition)) {
                continue;
            }
            foreach ($this->analyzeDefinition($definition, $breaking) as $hint) {
                $hint['workflow_label'] = (string) ($wf['label'] ?? $wf['ref'] ?? '');
                $merged[] = $hint;
            }
        }

        return $merged;
    }
}
