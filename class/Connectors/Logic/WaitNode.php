<?php

declare(strict_types=1);

namespace Knot\Connectors\Logic;

use Knot\Connectors\Connector;
use Knot\Connectors\ConnectorInterface;

/**
 * Wait node — sleeps for a configurable duration, capped at 60 seconds
 * to avoid blocking PHP-FPM workers indefinitely. Larger waits should
 * be implemented later via persistent queue resume.
 */
#[Connector(id: 'logic.wait', category: 'logic')]
final class WaitNode implements ConnectorInterface
{
    public function getMetadata(): array
    {
        return [
            'id' => 'logic.wait',
            'labelKey' => 'connectors.logic.wait.label',
            'descriptionKey' => 'connectors.logic.wait.description',
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
                'seconds' => [
                    'type' => 'number',
                    'titleKey' => 'connectors.logic.wait.fields.seconds.title',
                    'descriptionKey' => 'connectors.logic.wait.fields.seconds.description',
                    'minimum' => 0,
                    'maximum' => 60,
                    'default' => 1,
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
        $node = is_array($context['node'] ?? null) ? $context['node'] : [];
        $config = is_array($node['config'] ?? null) ? $node['config'] : [];
        $seconds = max(0.0, min(60.0, (float) ($config['seconds'] ?? 1)));

        if ($seconds > 0) {
            usleep((int) ($seconds * 1_000_000));
        }

        $current = is_array($context['json'] ?? null) ? $context['json'] : [];
        return $current + ['waitedSeconds' => $seconds];
    }

    public function test(array $config): array
    {
        return $this->validate($config) + ['success' => true];
    }
}
