<?php

declare(strict_types=1);

namespace Knot\Connectors\Triggers;

use Knot\Connectors\Connector;
use Knot\Connectors\ConnectorInterface;

/**
 * Webhook workflow trigger metadata.
 */
#[Connector(id: 'trigger.webhook', category: 'trigger')]
final class WebhookTrigger implements ConnectorInterface
{
    public function getMetadata(): array
    {
        return [
            'id' => 'trigger.webhook',
            'labelKey' => 'connectors.trigger.webhook.label',
            'descriptionKey' => 'connectors.trigger.webhook.description',
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
                'method' => [
                    'type' => 'string',
                    'default' => 'POST',
                    'titleKey' => 'connectors.trigger.webhook.fields.method.title',
                    'descriptionKey' => 'connectors.trigger.webhook.fields.method.description',
                ],
                'hmacRequired' => [
                    'type' => 'boolean',
                    'default' => false,
                    'titleKey' => 'connectors.trigger.webhook.fields.hmacRequired.title',
                    'descriptionKey' => 'connectors.trigger.webhook.fields.hmacRequired.description',
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
        return ['success' => true];
    }
}
