<?php

declare(strict_types=1);

namespace Knot\Connectors\Logic;

use Knot\Connectors\Connector;
use Knot\Connectors\ConnectorInterface;

#[Connector(id: 'logic.split', category: 'logic')]
final class SplitNode implements ConnectorInterface
{
    public function getMetadata(): array
    {
        return [
            'id' => 'logic.split',
            'labelKey' => 'connectors.logic.split.label',
            'descriptionKey' => 'connectors.logic.split.description',
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
                'chunkSize' => [
                    'type' => 'integer',
                    'titleKey' => 'connectors.logic.split.fields.chunkSize.title',
                    'default' => 1,
                    'descriptionKey' => 'connectors.logic.split.fields.chunkSize.description',
                    'x-position' => 0,
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
        $chunkSize = max(1, (int) (($context['node']['config']['chunkSize'] ?? 1)));
        $items = is_array($context['json']['items'] ?? null) ? $context['json']['items'] : [];
        return ['chunks' => array_chunk($items, $chunkSize), 'chunkSize' => $chunkSize];
    }
    public function test(array $config): array
    {
        return ['valid' => true, 'errors' => [], 'success' => true];
    }
}
