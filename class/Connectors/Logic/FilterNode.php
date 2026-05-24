<?php

declare(strict_types=1);

namespace Knot\Connectors\Logic;

use Knot\Connectors\Connector;
use Knot\Connectors\ConnectorInterface;
use Knot\Engine\ExpressionResolver;

/**
 * Pass/block node for simple single-condition filtering.
 */
#[Connector(id: 'logic.filter', category: 'logic')]
final class FilterNode implements ConnectorInterface, BranchAware
{
    public function __construct(private readonly ?ExpressionResolver $resolver = null)
    {
    }

    public function getMetadata(): array
    {
        return [
            'id' => 'logic.filter',
            'labelKey' => 'connectors.logic.filter.label',
            'descriptionKey' => 'connectors.logic.filter.description',
            'category' => 'logic',
            'riskLevel' => 'safe',
            'reversible' => true,
            'sideEffects' => [],
        ];
    }

    public function getConfigSchema(): array
    {
        $operators = [
            'equals',
            'not_equals',
            'contains',
            'not_contains',
            'greater',
            'greater_equal',
            'less',
            'less_equal',
            'is_empty',
            'is_not_empty',
            'regex',
        ];

        return [
            'type' => 'object',
            'required' => ['left', 'operator'],
            'properties' => [
                'left' => [
                    'type' => 'string',
                    'titleKey' => 'connectors.logic.filter.fields.left.title',
                    'descriptionKey' => 'connectors.logic.filter.fields.left.description',
                    'format' => 'textarea',
                    'x-position' => 0,
                ],
                'operator' => [
                    'type' => 'string',
                    'titleKey' => 'connectors.logic.filter.fields.operator.title',
                    'enum' => $operators,
                    'default' => 'equals',
                    'x-position' => 1,
                ],
                'right' => [
                    'type' => 'string',
                    'titleKey' => 'connectors.logic.filter.fields.right.title',
                    'format' => 'textarea',
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
        $errors = [];
        if (trim((string) ($config['left'] ?? '')) === '') {
            $errors[] = 'left is required';
        }
        return ['valid' => $errors === [], 'errors' => $errors];
    }

    public function execute(array $context): array
    {
        $node = is_array($context['node'] ?? null) ? $context['node'] : [];
        $config = is_array($node['config'] ?? null) ? $node['config'] : [];
        $resolver = $this->resolver ?? new ExpressionResolver();
        $left = $resolver->resolve($config['left'] ?? '', $context);
        $right = $resolver->resolve($config['right'] ?? '', $context);
        $passed = $this->compare($left, $right, (string) ($config['operator'] ?? 'equals'));

        $payload = is_array($context['json'] ?? null) ? $context['json'] : [];
        return array_replace($payload, [
            '_filter' => [
                'passed' => $passed,
                'blocked' => !$passed,
            ],
        ]);
    }

    public function branchesToSkip(array $context, array $output): array
    {
        return !empty($output['_filter']['passed']) ? [] : ['main'];
    }

    public function test(array $config): array
    {
        return $this->validate($config) + ['success' => true];
    }

    private function compare(mixed $left, mixed $right, string $operator): bool
    {
        return match ($operator) {
            'not_equals' => (string) $left !== (string) $right,
            'contains' => str_contains((string) $left, (string) $right),
            'not_contains' => !str_contains((string) $left, (string) $right),
            'greater' => (float) $left > (float) $right,
            'greater_equal' => (float) $left >= (float) $right,
            'less' => (float) $left < (float) $right,
            'less_equal' => (float) $left <= (float) $right,
            'is_empty' => trim((string) $left) === '',
            'is_not_empty' => trim((string) $left) !== '',
            default => (string) $left === (string) $right,
        };
    }
}
