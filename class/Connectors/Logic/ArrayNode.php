<?php

declare(strict_types=1);

namespace Knot\Connectors\Logic;

use Knot\Connectors\Connector;
use Knot\Connectors\ConnectorInterface;

#[Connector(id: 'logic.array', category: 'logic')]
final class ArrayNode implements ConnectorInterface
{
    public function getMetadata(): array
    {
        return [
            'id' => 'logic.array',
            'labelKey' => 'connectors.logic.array.label',
            'descriptionKey' => 'connectors.logic.array.description',
            'category' => 'logic',
            'riskLevel' => 'safe',
            'reversible' => true,
            'sideEffects' => [],
        ];
    }
    public function getConfigSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'operation' => [
                    'type' => 'string',
                    'titleKey' => 'connectors.logic.array.fields.operation.title',
                    'enum' => ['length', 'reverse', 'unique', 'first', 'last'],
                    'default' => 'length',
                    'descriptionKey' => 'connectors.logic.array.fields.operation.description',
                    'x-position' => 0,
                ],
                'path' => [
                    'type' => 'string',
                    'titleKey' => 'connectors.logic.array.fields.path.title',
                    'descriptionKey' => 'connectors.logic.array.fields.path.description',
                    'x-position' => 1,
                ],
            ],
        ];
    }
    public function getCredentialType(): ?string
    {
        return null;
    }
    public function getInputs(): array
    {
        return [['id' => 'main', 'label' => 'Main']];
    }
    public function getOutputs(): array
    {
        return [['id' => 'main', 'label' => 'Main']];
    }
    public function validate(array $config): array
    {
        return ['valid' => true, 'errors' => []];
    }
    public function execute(array $context): array
    {
        $config = is_array(($context['node']['config'] ?? null)) ? $context['node']['config'] : [];
        $items = is_array($context['json']['items'] ?? null) ? $context['json']['items'] : (is_array($context['json'] ?? null) ? $context['json'] : []);
        $operation = (string) ($config['operation'] ?? 'length');
        $result = match ($operation) {
            'reverse' => array_reverse($items),
            'unique' => array_values(array_unique($items, SORT_REGULAR)),
            'first' => $items[0] ?? null,
            'last' => $items[array_key_last($items)] ?? null,
            default => count($items),
        };
        return ['operation' => $operation, 'result' => $result, 'items' => $items];
    }
    public function test(array $config): array
    {
        return ['valid' => true, 'errors' => [], 'success' => true];
    }
}
