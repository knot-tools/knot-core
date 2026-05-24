<?php

declare(strict_types=1);

namespace Knot\Connectors\Logic;

use Knot\Connectors\Connector;
use Knot\Connectors\ConnectorInterface;

/**
 * Merge node — combines incoming JSON payloads from multiple branches
 * into a single output. Strategy: "shallow" (array_replace) by default,
 * or "deep" recursive merge.
 */
#[Connector(id: 'logic.merge', category: 'logic')]
final class MergeNode implements ConnectorInterface
{
    public function getMetadata(): array
    {
        return [
            'id' => 'logic.merge',
            'labelKey' => 'connectors.logic.merge.label',
            'descriptionKey' => 'connectors.logic.merge.description',
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
                'strategy' => [
                    'type' => 'string',
                    'titleKey' => 'connectors.logic.merge.fields.strategy.title',
                    'descriptionKey' => 'connectors.logic.merge.fields.strategy.description',
                    'enum' => ['shallow', 'deep'],
                    'enumLabelKeys' => [
                        'connectors.logic.merge.enum.shallow',
                        'connectors.logic.merge.enum.deep',
                    ],
                    'default' => 'shallow',
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
        return [
            ['id' => 'a', 'label' => 'A'],
            ['id' => 'b', 'label' => 'B'],
        ];
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
        $node = is_array($context['node'] ?? null) ? $context['node'] : [];
        $config = is_array($node['config'] ?? null) ? $node['config'] : [];
        $strategy = (string) ($config['strategy'] ?? 'shallow');

        $sources = is_array($context['nodes'] ?? null) ? $context['nodes'] : [];
        $payloads = [];
        foreach ($sources as $payload) {
            if (is_array($payload) && isset($payload['json']) && is_array($payload['json'])) {
                $payloads[] = $payload['json'];
            }
        }

        if ($payloads === []) {
            return [];
        }

        return $strategy === 'deep'
            ? array_replace_recursive(...$payloads)
            : array_replace(...$payloads);
    }

    public function test(array $config): array
    {
        return $this->validate($config) + ['success' => true];
    }
}
