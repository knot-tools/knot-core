<?php

declare(strict_types=1);

namespace Knot\Connectors\Logic;

use Knot\Connectors\Connector;
use Knot\Connectors\ConnectorInterface;
use Knot\Engine\ExpressionResolver;

/**
 * Defines the HTTP response payload for webhook workflows.
 */
#[Connector(id: 'logic.respond_webhook', category: 'logic')]
final class RespondToWebhookNode implements ConnectorInterface
{
    public function __construct(private readonly ?ExpressionResolver $resolver = null)
    {
    }

    public function getMetadata(): array
    {
        return [
            'id' => 'logic.respond_webhook',
            'labelKey' => 'connectors.logic.respond_webhook.label',
            'descriptionKey' => 'connectors.logic.respond_webhook.description',
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
                'statusCode' => [
                    'type' => 'integer',
                    'titleKey' => 'connectors.logic.respond_webhook.fields.statusCode.title',
                    'descriptionKey' => 'connectors.logic.respond_webhook.fields.statusCode.description',
                    'default' => 200,
                    'x-position' => 0,
                ],
                'headers' => [
                    'type' => 'object',
                    'titleKey' => 'connectors.logic.respond_webhook.fields.headers.title',
                    'descriptionKey' => 'connectors.logic.respond_webhook.fields.headers.description',
                    'x-position' => 1,
                ],
                'body' => [
                    'type' => ['string', 'object', 'array'],
                    'titleKey' => 'connectors.logic.respond_webhook.fields.body.title',
                    'descriptionKey' => 'connectors.logic.respond_webhook.fields.body.description',
                    'x-position' => 2,
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
        $status = (int) ($config['statusCode'] ?? 200);
        return [
            'valid' => $status >= 100 && $status <= 599,
            'errors' => $status >= 100 && $status <= 599 ? [] : ['statusCode must be an HTTP status code'],
        ];
    }

    public function execute(array $context): array
    {
        $node = is_array($context['node'] ?? null) ? $context['node'] : [];
        $config = is_array($node['config'] ?? null) ? $node['config'] : [];
        $resolver = $this->resolver ?? new ExpressionResolver();

        return [
            '_webhookResponse' => [
                'statusCode' => max(100, min(599, (int) ($config['statusCode'] ?? 200))),
                'headers' => is_array($config['headers'] ?? null) ? $resolver->resolve($config['headers'], $context) : [],
                'body' => $resolver->resolve($config['body'] ?? ($context['json'] ?? []), $context),
            ],
        ];
    }

    public function test(array $config): array
    {
        return $this->validate($config) + ['success' => true];
    }
}
