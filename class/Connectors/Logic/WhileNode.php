<?php

declare(strict_types=1);

namespace Knot\Connectors\Logic;

use Knot\Connectors\Connector;
use Knot\Connectors\ConnectorInterface;

#[Connector(id: 'logic.while', category: 'logic')]
final class WhileNode implements ConnectorInterface
{
    public function getMetadata(): array
    {
        return [
            'id' => 'logic.while',
            'labelKey' => 'connectors.logic.while.label',
            'descriptionKey' => 'connectors.logic.while.description',
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
                'maxIterations' => [
                    'type' => 'integer',
                    'titleKey' => 'connectors.logic.while.fields.maxIterations.title',
                    'default' => 100,
                    'descriptionKey' => 'connectors.logic.while.fields.maxIterations.description',
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
        $max = max(1, min(1000, (int) (($context['node']['config']['maxIterations'] ?? 100))));
        return ['maxIterations' => $max, 'note' => 'While is represented as a guarded loop marker in V1.'];
    }
    public function test(array $config): array
    {
        return ['valid' => true, 'errors' => [], 'success' => true];
    }
}
