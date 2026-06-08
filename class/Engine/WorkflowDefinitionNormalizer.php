<?php

declare(strict_types=1);

namespace Knot\Engine;

/**
 * Repairs common assistant / import mistakes before validation and persistence.
 */
final class WorkflowDefinitionNormalizer
{
    /**
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    public function normalize(array $definition): array
    {
        $nodes = is_array($definition['nodes'] ?? null) ? $definition['nodes'] : [];
        if ($nodes === []) {
            return $definition;
        }

        $normalizedNodes = [];
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                $normalizedNodes[] = $node;
                continue;
            }
            $type = is_string($node['type'] ?? null) ? (string) $node['type'] : '';
            $config = is_array($node['config'] ?? null) ? $node['config'] : [];
            $node['config'] = $this->normalizeNodeConfig($type, $config);
            $normalizedNodes[] = $node;
        }

        $definition['nodes'] = $normalizedNodes;

        return $definition;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function normalizeNodeConfig(string $type, array $config): array
    {
        return match ($type) {
            'logic.if' => $this->normalizeIfConfig($config),
            'dolibarr.sql_query' => $this->normalizeSqlQueryConfig($config),
            'dolibarr.read_object', 'dolibarr.object' => $this->normalizeReadObjectConfig($config),
            'action.email' => $this->normalizeEmailConfig($config),
            default => $this->normalizeExpressionStrings($config),
        };
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function normalizeIfConfig(array $config): array
    {
        $conditions = is_array($config['conditions'] ?? null) ? $config['conditions'] : [];
        $repaired = [];
        foreach ($conditions as $cond) {
            if (!is_array($cond)) {
                $repaired[] = $cond;
                continue;
            }
            if (isset($cond['operator']) && is_string($cond['operator'])) {
                $cond['operator'] = IfConditionOperator::normalize($cond['operator']);
            }
            $repaired[] = $this->normalizeExpressionStrings($cond);
        }
        $config['conditions'] = $repaired;

        return $config;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function normalizeSqlQueryConfig(array $config): array
    {
        if (
            (!isset($config['query']) || trim((string) $config['query']) === '')
            && isset($config['sql'])
            && trim((string) $config['sql']) !== ''
        ) {
            $config['query'] = (string) $config['sql'];
            unset($config['sql']);
        }

        return $this->normalizeExpressionStrings($config);
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function normalizeReadObjectConfig(array $config): array
    {
        if (isset($config['objectType']) && is_string($config['objectType'])) {
            $config['objectType'] = DolibarrObjectTypeAliases::normalize($config['objectType']);
        }

        return $this->normalizeExpressionStrings($config);
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function normalizeEmailConfig(array $config): array
    {
        if (isset($config['body']) && is_string($config['body'])) {
            $config['body'] = $this->normalizeEmailBody($config['body']);
        }

        return $this->normalizeExpressionStrings($config);
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function normalizeExpressionStrings(array $values): array
    {
        $out = [];
        foreach ($values as $key => $value) {
            if (is_string($value)) {
                $out[$key] = $this->normalizeExpressionString($value);
                continue;
            }
            if (is_array($value)) {
                $out[$key] = $this->normalizeExpressionStrings($value);
                continue;
            }
            $out[$key] = $value;
        }

        return $out;
    }

    private function normalizeExpressionString(string $value): string
    {
        // Bracket indices are supported at runtime; dot form is preferred in docs only.

        return $value;
    }

    private function normalizeEmailBody(string $body): string
    {
        if (!str_contains($body, '\\n')) {
            return $body;
        }

        return str_replace(['\\r\\n', '\\n', '\\r'], "\n", $body);
    }
}
