<?php

declare(strict_types=1);

namespace Knot\Connectors\Logic;

use Knot\Connectors\Connector;
use Knot\Connectors\ConnectorInterface;
use Knot\Engine\ExpressionResolver;

/**
 * JSON utility node — parses, stringifies or extracts a path from JSON.
 *
 *  - operation = "parse"     : parse `input` (string) into an object
 *  - operation = "stringify" : encode `input` (any) to JSON string
 *  - operation = "extract"   : return value at `path` from current json
 */
#[Connector(id: 'logic.json', category: 'logic')]
final class JsonNode implements ConnectorInterface
{
    public function __construct(private readonly ?ExpressionResolver $resolver = null)
    {
    }

    public function getMetadata(): array
    {
        return [
            'id' => 'logic.json',
            'labelKey' => 'connectors.logic.json.label',
            'descriptionKey' => 'connectors.logic.json.description',
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
            'required' => ['operation'],
            'properties' => [
                'operation' => [
                    'type' => 'string',
                    'titleKey' => 'connectors.logic.json.fields.operation.title',
                    'enum' => ['parse', 'stringify', 'extract'],
                    'enumLabelKeys' => [
                        'connectors.logic.json.operation.parse',
                        'connectors.logic.json.operation.stringify',
                        'connectors.logic.json.operation.extract',
                    ],
                    'x-position' => 0,
                ],
                'input' => [
                    'type' => 'string',
                    'titleKey' => 'connectors.logic.json.fields.input.title',
                    'descriptionKey' => 'connectors.logic.json.fields.input.description',
                    'format' => 'textarea',
                    'x-position' => 1,
                ],
                'path' => [
                    'type' => 'string',
                    'titleKey' => 'connectors.logic.json.fields.path.title',
                    'descriptionKey' => 'connectors.logic.json.fields.path.description',
                    'x-position' => 2,
                ],
                'pretty' => [
                    'type' => 'boolean',
                    'titleKey' => 'connectors.logic.json.fields.pretty.title',
                    'descriptionKey' => 'connectors.logic.json.fields.pretty.description',
                    'default' => false,
                    'x-position' => 3,
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
        $op = (string) ($config['operation'] ?? '');
        return [
            'valid' => in_array($op, ['parse', 'stringify', 'extract'], true),
            'errors' => $op === '' ? ['operation is required'] : [],
        ];
    }

    public function execute(array $context): array
    {
        $node = is_array($context['node'] ?? null) ? $context['node'] : [];
        $config = is_array($node['config'] ?? null) ? $node['config'] : [];
        $resolver = $this->resolver ?? new ExpressionResolver();
        $operation = (string) ($config['operation'] ?? 'parse');

        switch ($operation) {
            case 'parse':
                $raw = (string) $resolver->resolve((string) ($config['input'] ?? ''), $context);
                $decoded = json_decode($raw, true);
                return [
                    'value' => is_array($decoded) ? $decoded : null,
                    'valid' => json_last_error() === JSON_ERROR_NONE,
                ];

            case 'stringify':
                $value = $resolver->resolve($config['input'] ?? ($context['json'] ?? []), $context);
                $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
                if (!empty($config['pretty'])) {
                    $flags |= JSON_PRETTY_PRINT;
                }
                return ['value' => json_encode($value, $flags) ?: '{}'];

            case 'extract':
                $path = (string) ($config['path'] ?? '');
                $resolved = $resolver->resolve('{{' . $path . '}}', $context);
                return ['value' => $resolved];
        }

        return ['value' => null];
    }

    public function test(array $config): array
    {
        return $this->validate($config) + ['success' => true];
    }
}
