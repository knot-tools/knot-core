<?php

declare(strict_types=1);

namespace Knot\Connectors\Logic;

use Knot\Connectors\Connector;
use Knot\Connectors\ConnectorInterface;
use Knot\Engine\ExpressionResolver;

/**
 * Number utilities — basic arithmetic and rounding on resolved numeric inputs.
 *
 * Operations:
 *  - add, subtract, multiply, divide
 *  - round, floor, ceil
 *  - format (decimals, thousands separator, decimal separator)
 */
#[Connector(id: 'logic.number', category: 'logic')]
final class NumberNode implements ConnectorInterface
{
    public function __construct(private readonly ?ExpressionResolver $resolver = null)
    {
    }

    public function getMetadata(): array
    {
        return [
            'id' => 'logic.number',
            'labelKey' => 'connectors.logic.number.label',
            'descriptionKey' => 'connectors.logic.number.description',
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
            'required' => ['operation', 'a'],
            'properties' => [
                'operation' => [
                    'type' => 'string',
                    'titleKey' => 'connectors.logic.number.fields.operation.title',
                    'enum' => ['add', 'subtract', 'multiply', 'divide', 'round', 'floor', 'ceil', 'format'],
                    'x-position' => 0,
                ],
                'a' => [
                    'type' => 'string',
                    'titleKey' => 'connectors.logic.number.fields.a.title',
                    'descriptionKey' => 'connectors.logic.number.fields.a.description',
                    'x-position' => 1,
                ],
                'b' => [
                    'type' => 'string',
                    'titleKey' => 'connectors.logic.number.fields.b.title',
                    'descriptionKey' => 'connectors.logic.number.fields.b.description',
                    'x-position' => 2,
                ],
                'decimals' => [
                    'type' => 'integer',
                    'titleKey' => 'connectors.logic.number.fields.decimals.title',
                    'default' => 2,
                    'descriptionKey' => 'connectors.logic.number.fields.decimals.description',
                    'x-position' => 3,
                ],
                'thousands' => [
                    'type' => 'string',
                    'titleKey' => 'connectors.logic.number.fields.thousands.title',
                    'default' => ' ',
                    'x-position' => 4,
                ],
                'decimal' => [
                    'type' => 'string',
                    'titleKey' => 'connectors.logic.number.fields.decimal.title',
                    'default' => '.',
                    'x-position' => 5,
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
        return ['valid' => isset($config['operation']), 'errors' => []];
    }

    public function execute(array $context): array
    {
        $node = is_array($context['node'] ?? null) ? $context['node'] : [];
        $config = is_array($node['config'] ?? null) ? $node['config'] : [];
        $resolver = $this->resolver ?? new ExpressionResolver();
        $a = (float) $resolver->resolve((string) ($config['a'] ?? '0'), $context);
        $b = (float) $resolver->resolve((string) ($config['b'] ?? '0'), $context);
        $decimals = (int) ($config['decimals'] ?? 2);

        $value = match ((string) ($config['operation'] ?? 'add')) {
            'add' => $a + $b,
            'subtract' => $a - $b,
            'multiply' => $a * $b,
            'divide' => abs($b) < 1e-12 ? null : $a / $b,
            'round' => round($a, $decimals),
            'floor' => floor($a),
            'ceil' => ceil($a),
            'format' => number_format($a, $decimals, (string) ($config['decimal'] ?? '.'), (string) ($config['thousands'] ?? ' ')),
            default => $a,
        };

        return ['value' => $value];
    }

    public function test(array $config): array
    {
        return $this->validate($config) + ['success' => true];
    }
}
