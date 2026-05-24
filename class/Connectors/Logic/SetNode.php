<?php

declare(strict_types=1);

namespace Knot\Connectors\Logic;

use Knot\Connectors\Connector;
use Knot\Connectors\ConnectorInterface;
use Knot\Engine\ExpressionResolver;

/**
 * Set node used to create or override JSON fields.
 */
#[Connector(id: 'logic.set', category: 'logic')]
final class SetNode implements ConnectorInterface
{
    public function __construct(private readonly ?ExpressionResolver $resolver = null)
    {
    }

    public function getMetadata(): array
    {
        return [
            'id' => 'logic.set',
            'labelKey' => 'connectors.logic.set.label',
            'descriptionKey' => 'connectors.logic.set.description',
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
                'values' => [
                    'type' => 'object',
                    'titleKey' => 'connectors.logic.set.fields.values.title',
                    'descriptionKey' => 'connectors.logic.set.fields.values.description',
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
        return ['valid' => isset($config['values']) && is_array($config['values']), 'errors' => []];
    }

    public function execute(array $context): array
    {
        $node = is_array($context['node'] ?? null) ? $context['node'] : [];
        $config = is_array($node['config'] ?? null) ? $node['config'] : [];
        $values = is_array($config['values'] ?? null) ? $config['values'] : [];
        $resolver = $this->resolver ?? new ExpressionResolver();

        /** @var array<string, mixed> $current */
        $current = is_array($context['json'] ?? null) ? $context['json'] : [];

        return array_replace($current, $resolver->resolve($values, $context));
    }

    public function test(array $config): array
    {
        return $this->validate($config) + ['success' => true];
    }
}
