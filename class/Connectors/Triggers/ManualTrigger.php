<?php

declare(strict_types=1);

namespace Knot\Connectors\Triggers;

use Knot\Connectors\Connector;
use Knot\Connectors\ConnectorInterface;

/**
 * Manual workflow trigger.
 */
#[Connector(id: 'trigger.manual', category: 'trigger')]
final class ManualTrigger implements ConnectorInterface
{
    public function getMetadata(): array
    {
        return [
            'id' => 'trigger.manual',
            'labelKey' => 'connectors.trigger.manual.label',
            'descriptionKey' => 'connectors.trigger.manual.description',
            'category' => 'trigger',
            'riskLevel' => 'safe',
            'reversible' => true,
            'sideEffects' => [],
        ];
    }

    public function getConfigSchema(): array
    {
        return ['type' => 'object', 'properties' => []];
    }

    public function getCredentialType(): ?string
    {
        return null;
    }

    public function getInputs(): array
    {
        return [];
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
        return is_array($context['json'] ?? null) ? $context['json'] : [];
    }

    public function test(array $config): array
    {
        return ['success' => true];
    }
}
