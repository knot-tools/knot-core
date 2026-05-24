<?php

declare(strict_types=1);

namespace Knot\Reporting;

final class CascadePredictor
{
    /**
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    public function predict(array $definition): array
    {
        $nodes = is_array($definition['nodes'] ?? null) ? $definition['nodes'] : [];
        $eventsProduced = [];
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            $type = (string) ($node['type'] ?? '');
            if (str_starts_with($type, 'dolibarr.') || str_starts_with($type, 'action.')) {
                $eventsProduced[] = [
                    'nodeId' => (string) ($node['id'] ?? ''),
                    'nodeType' => $type,
                    'event' => $this->eventForType($type),
                ];
            }
        }

        return [
            'eventsProduced' => $eventsProduced,
            'risk' => $eventsProduced !== [] ? 'info' : 'none',
            'message' => $eventsProduced !== []
                ? 'Ce workflow produit des effets de bord susceptibles de declencher d autres workflows.'
                : 'Aucune cascade evidente detectee.',
        ];
    }

    private function eventForType(string $type): string
    {
        return match (true) {
            str_contains($type, 'invoice') => 'BILL_*',
            str_contains($type, 'order') => 'ORDER_*',
            str_contains($type, 'propal') => 'PROPAL_*',
            default => 'SIDE_EFFECT',
        };
    }
}
