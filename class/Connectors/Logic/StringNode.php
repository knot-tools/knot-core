<?php

declare(strict_types=1);

namespace Knot\Connectors\Logic;

use Knot\Connectors\Connector;
use Knot\Connectors\ConnectorInterface;
use Knot\Engine\ExpressionResolver;

/**
 * String utilities — common transformations on a single string input.
 *
 * Operations:
 *  - upper, lower, trim
 *  - replace (find / replace)
 *  - split (separator → array)
 *  - substring (start, length)
 *  - length
 */
#[Connector(id: 'logic.string', category: 'logic')]
final class StringNode implements ConnectorInterface
{
    public function __construct(private readonly ?ExpressionResolver $resolver = null)
    {
    }

    public function getMetadata(): array
    {
        return [
            'id' => 'logic.string',
            'labelKey' => 'connectors.logic.string.label',
            'descriptionKey' => 'connectors.logic.string.description',
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
            'required' => ['operation', 'input'],
            'properties' => [
                'operation' => [
                    'type' => 'string',
                    'titleKey' => 'connectors.logic.string.fields.operation.title',
                    'enum' => ['upper', 'lower', 'trim', 'replace', 'split', 'substring', 'length'],
                    'x-position' => 0,
                ],
                'input' => [
                    'type' => 'string',
                    'titleKey' => 'connectors.logic.string.fields.input.title',
                    'descriptionKey' => 'connectors.logic.string.fields.input.description',
                    'x-position' => 1,
                ],
                'find' => [
                    'type' => 'string',
                    'titleKey' => 'connectors.logic.string.fields.find.title',
                    'descriptionKey' => 'connectors.logic.string.fields.find.description',
                    'x-position' => 2,
                ],
                'replace' => [
                    'type' => 'string',
                    'titleKey' => 'connectors.logic.string.fields.replace.title',
                    'x-position' => 3,
                ],
                'separator' => [
                    'type' => 'string',
                    'titleKey' => 'connectors.logic.string.fields.separator.title',
                    'default' => ',',
                    'descriptionKey' => 'connectors.logic.string.fields.separator.description',
                    'x-position' => 4,
                ],
                'start' => [
                    'type' => 'integer',
                    'titleKey' => 'connectors.logic.string.fields.start.title',
                    'default' => 0,
                    'x-position' => 5,
                ],
                'length' => [
                    'type' => 'integer',
                    'titleKey' => 'connectors.logic.string.fields.length.title',
                    'descriptionKey' => 'connectors.logic.string.fields.length.description',
                    'x-position' => 6,
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
        return ['valid' => isset($config['operation']) && isset($config['input']), 'errors' => []];
    }

    public function execute(array $context): array
    {
        $node = is_array($context['node'] ?? null) ? $context['node'] : [];
        $config = is_array($node['config'] ?? null) ? $node['config'] : [];
        $resolver = $this->resolver ?? new ExpressionResolver();
        $input = (string) $resolver->resolve((string) ($config['input'] ?? ''), $context);
        $operation = (string) ($config['operation'] ?? 'trim');

        $result = match ($operation) {
            'upper' => function_exists('mb_strtoupper') ? mb_strtoupper($input) : strtoupper($input),
            'lower' => function_exists('mb_strtolower') ? mb_strtolower($input) : strtolower($input),
            'trim' => trim($input),
            'replace' => str_replace(
                (string) $resolver->resolve((string) ($config['find'] ?? ''), $context),
                (string) $resolver->resolve((string) ($config['replace'] ?? ''), $context),
                $input
            ),
            'split' => explode((string) ($config['separator'] ?? ','), $input),
            'substring' => substr(
                $input,
                (int) ($config['start'] ?? 0),
                isset($config['length']) ? (int) $config['length'] : null
            ),
            'length' => function_exists('mb_strlen') ? mb_strlen($input) : strlen($input),
            default => $input,
        };

        return ['value' => $result];
    }

    public function test(array $config): array
    {
        return $this->validate($config) + ['success' => true];
    }
}
