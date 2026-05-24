<?php

declare(strict_types=1);

namespace Knot\Connectors\Triggers;

use Knot\Connectors\Connector;
use Knot\Connectors\ConnectorInterface;

#[Connector(id: 'trigger.dolibarr_event', category: 'trigger')]
final class DolibarrEventTrigger implements ConnectorInterface
{
    public function getMetadata(): array
    {
        return [
            'id' => 'trigger.dolibarr_event',
            'labelKey' => 'connectors.trigger.dolibarr_event.label',
            'descriptionKey' => 'connectors.trigger.dolibarr_event.description',
            'category' => 'trigger',
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
                'events' => [
                    'type' => 'array',
                    'titleKey' => 'connectors.trigger.dolibarr_event.fields.events.title',
                    'descriptionKey' => 'connectors.trigger.dolibarr_event.fields.events.description',
                    'items' => ['type' => 'string'],
                    'x-position' => 0,
                ],
                'objectTypes' => [
                    'type' => 'array',
                    'titleKey' => 'connectors.trigger.dolibarr_event.fields.objectTypes.title',
                    'descriptionKey' => 'connectors.trigger.dolibarr_event.fields.objectTypes.description',
                    'items' => ['type' => 'string'],
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
        return ['valid' => true, 'errors' => [], 'success' => true];
    }
}
