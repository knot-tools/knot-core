<?php

declare(strict_types=1);

namespace Knot\Api;

/**
 * Guards workflow POST create against empty assistant/import payloads
 * that would spawn ghost draft rows on retry.
 */
final class WorkflowCreateGuard
{
    /**
     * @param array<string, mixed>|null $payload
     * @param array<string, mixed>|null $definition
     */
    public static function rejectsEmptyImport(?array $payload, ?array $definition): bool
    {
        if (!is_array($payload)) {
            return true;
        }

        $explicitLabel = isset($payload['label']) && trim((string) $payload['label']) !== '';
        $explicitDescription = isset($payload['description']) && trim((string) $payload['description']) !== '';

        $nodes = [];
        if ($definition !== null && is_array($definition['nodes'] ?? null)) {
            $nodes = $definition['nodes'];
        }

        return $nodes === [] && !$explicitLabel && !$explicitDescription;
    }
}
