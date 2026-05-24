<?php

declare(strict_types=1);

namespace Knot\Connectors\Triggers;

use Knot\Connectors\Connector;
use Knot\Connectors\ConnectorInterface;

/**
 * Cron workflow trigger metadata.
 */
#[Connector(id: 'trigger.cron', category: 'trigger')]
final class CronTrigger implements ConnectorInterface
{
    public function getMetadata(): array
    {
        return [
            'id' => 'trigger.cron',
            'labelKey' => 'connectors.trigger.cron.label',
            'descriptionKey' => 'connectors.trigger.cron.description',
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
            'required' => ['cronExpression', 'timezone'],
            'properties' => [
                'cronExpression' => [
                    'type' => 'string',
                    'titleKey' => 'connectors.trigger.cron.fields.cronExpression.title',
                    'descriptionKey' => 'connectors.trigger.cron.fields.cronExpression.description',
                    'placeholder' => '0 * * * *',
                    'x-position' => 0,
                ],
                'timezone' => [
                    'type' => 'string',
                    'titleKey' => 'connectors.trigger.cron.fields.timezone.title',
                    'descriptionKey' => 'connectors.trigger.cron.fields.timezone.description',
                    'placeholder' => 'UTC',
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
        return ['valid' => !empty($config['cronExpression']), 'errors' => []];
    }

    public function execute(array $context): array
    {
        return is_array($context['json'] ?? null) ? $context['json'] : [];
    }

    public function test(array $config): array
    {
        return $this->validate($config) + ['success' => true];
    }
}
