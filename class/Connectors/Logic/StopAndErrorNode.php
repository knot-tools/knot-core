<?php

declare(strict_types=1);

namespace Knot\Connectors\Logic;

use Knot\Connectors\Connector;
use Knot\Connectors\ConnectorInterface;
use Knot\Engine\ExpressionResolver;
use Knot\Errors\ExecutionError;

/**
 * Explicitly stop a workflow with a business error.
 */
#[Connector(id: 'logic.stop_error', category: 'logic')]
final class StopAndErrorNode implements ConnectorInterface
{
    public function __construct(private readonly ?ExpressionResolver $resolver = null)
    {
    }

    public function getMetadata(): array
    {
        return [
            'id' => 'logic.stop_error',
            'labelKey' => 'connectors.logic.stop_error.label',
            'descriptionKey' => 'connectors.logic.stop_error.description',
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
            'required' => ['message'],
            'properties' => [
                'message' => [
                    'type' => 'string',
                    'titleKey' => 'connectors.logic.stop_error.fields.message.title',
                    'descriptionKey' => 'connectors.logic.stop_error.fields.message.description',
                    'format' => 'textarea',
                    'default' => 'Workflow stopped by Stop & Error node.',
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
        return [
            'valid' => trim((string) ($config['message'] ?? '')) !== '',
            'errors' => trim((string) ($config['message'] ?? '')) !== '' ? [] : ['message is required'],
        ];
    }

    public function execute(array $context): array
    {
        $node = is_array($context['node'] ?? null) ? $context['node'] : [];
        $config = is_array($node['config'] ?? null) ? $node['config'] : [];
        $resolver = $this->resolver ?? new ExpressionResolver();
        $message = (string) $resolver->resolve(
            (string) ($config['message'] ?? 'Workflow stopped by Stop & Error node.'),
            $context
        );
        $trimmed = trim($message);
        $user = $trimmed !== '' ? $trimmed : 'Workflow stopped by Stop & Error node.';

        throw new ExecutionError(
            'KNOT_EXECUTION_FAILED',
            $user,
            $user,
            'https://knot.tools/docs/errors/catalog#knot-execution-failed',
            [],
            'Adjust upstream branches or remove this node when the workflow should complete.',
            'error'
        );
    }

    public function test(array $config): array
    {
        return $this->validate($config) + ['success' => true];
    }
}
