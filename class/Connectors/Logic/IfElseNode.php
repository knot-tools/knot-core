<?php

declare(strict_types=1);

namespace Knot\Connectors\Logic;

use Knot\Connectors\Connector;
use Knot\Connectors\ConnectorInterface;
use Knot\Engine\ExpressionResolver;

/**
 * If/Else routing node. Output handle "true" or "false" is taken
 * depending on the boolean evaluation of all configured conditions.
 *
 * Configuration:
 *  - `mode`: "all" (AND) or "any" (OR), default "all".
 *  - `conditions`: list of `{ left, operator, right }` items.
 *      Operators: equals, not_equals, contains, not_contains,
 *                 greater, greater_equal, less, less_equal,
 *                 is_empty, is_not_empty, regex.
 *
 * Both `left` and `right` support Knot expressions.
 */
#[Connector(id: 'logic.if', category: 'logic')]
final class IfElseNode implements ConnectorInterface, BranchAware
{
    public function __construct(private readonly ?ExpressionResolver $resolver = null)
    {
    }

    public function getMetadata(): array
    {
        return [
            'id' => 'logic.if',
            'labelKey' => 'connectors.logic.if.label',
            'descriptionKey' => 'connectors.logic.if.description',
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
            'properties' => [
                'mode' => [
                    'type' => 'string',
                    'titleKey' => 'connectors.logic.if.fields.mode.title',
                    'descriptionKey' => 'connectors.logic.if.fields.mode.description',
                    'enum' => ['all', 'any'],
                    'enumLabelKeys' => [
                        'connectors.logic.if.mode.all',
                        'connectors.logic.if.mode.any',
                    ],
                    'default' => 'all',
                    'x-position' => 0,
                ],
                'conditions' => [
                    'type' => 'array',
                    'titleKey' => 'connectors.logic.if.fields.conditions.title',
                    'descriptionKey' => 'connectors.logic.if.fields.conditions.description',
                    'x-position' => 1,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'left' => [
                                'type' => 'string',
                                'titleKey' => 'connectors.logic.if.condition.left.title',
                                'descriptionKey' => 'connectors.logic.if.condition.left.description',
                                'format' => 'textarea',
                                'x-position' => 0,
                            ],
                            'operator' => [
                                'type' => 'string',
                                'titleKey' => 'connectors.logic.if.condition.operator.title',
                                'enum' => $operators,
                                'default' => 'equals',
                                'x-position' => 1,
                            ],
                            'right' => [
                                'type' => 'string',
                                'titleKey' => 'connectors.logic.if.condition.right.title',
                                'descriptionKey' => 'connectors.logic.if.condition.right.description',
                                'format' => 'textarea',
                                'x-position' => 2,
                            ],
                        ],
                    ],
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
        return [
            ['id' => 'true', 'label' => 'True'],
            ['id' => 'false', 'label' => 'False'],
        ];
    }

    public function validate(array $config): array
    {
        $conditions = $config['conditions'] ?? [];
        return [
            'valid' => is_array($conditions) && $conditions !== [],
            'errors' => is_array($conditions) && $conditions !== [] ? [] : ['conditions cannot be empty'],
        ];
    }

    public function execute(array $context): array
    {
        $node = is_array($context['node'] ?? null) ? $context['node'] : [];
        $config = is_array($node['config'] ?? null) ? $node['config'] : [];
        $mode = (string) ($config['mode'] ?? 'all');
        $conditions = is_array($config['conditions'] ?? null) ? $config['conditions'] : [];
        $resolver = $this->resolver ?? new ExpressionResolver();

        if ($conditions === []) {
            return ['result' => false, 'branch' => 'false', 'evaluated' => []];
        }

        $results = [];
        foreach ($conditions as $cond) {
            if (!is_array($cond)) {
                $results[] = false;
                continue;
            }
            $left = $resolver->resolve($cond['left'] ?? '', $context);
            $right = $resolver->resolve($cond['right'] ?? '', $context);
            $results[] = $this->compare($left, $right, (string) ($cond['operator'] ?? 'equals'));
        }

        $passed = $mode === 'any'
            ? in_array(true, $results, true)
            : !in_array(false, $results, true);

        return [
            'result' => $passed,
            'branch' => $passed ? 'true' : 'false',
            'evaluated' => $results,
            '_passthrough' => $context['json'] ?? [],
        ];
    }

    public function test(array $config): array
    {
        return $this->validate($config) + ['success' => true];
    }

    public function branchesToSkip(array $context, array $output): array
    {
        return ((string) ($output['branch'] ?? 'false')) === 'true' ? ['false'] : ['true'];
    }

    private function compare(mixed $left, mixed $right, string $operator): bool
    {
        $operator = strtolower(trim($operator));
        $leftStr = is_scalar($left) || $left === null ? (string) $left : json_encode($left, JSON_UNESCAPED_UNICODE);
        $rightStr = is_scalar($right) || $right === null ? (string) $right : json_encode($right, JSON_UNESCAPED_UNICODE);

        return match ($operator) {
            'equals' => $leftStr === $rightStr,
            'not_equals' => $leftStr !== $rightStr,
            'contains' => str_contains($leftStr, $rightStr),
            'not_contains' => !str_contains($leftStr, $rightStr),
            'greater' => is_numeric($leftStr) && is_numeric($rightStr) && (float) $leftStr > (float) $rightStr,
            'greater_equal' => is_numeric($leftStr) && is_numeric($rightStr) && (float) $leftStr >= (float) $rightStr,
            'less' => is_numeric($leftStr) && is_numeric($rightStr) && (float) $leftStr < (float) $rightStr,
            'less_equal' => is_numeric($leftStr) && is_numeric($rightStr) && (float) $leftStr <= (float) $rightStr,
            'is_empty' => $leftStr === '' || $left === null || $left === [] || $left === false,
            'is_not_empty' => !($leftStr === '' || $left === null || $left === [] || $left === false),
            'regex' => @preg_match('/' . str_replace('/', '\\/', $rightStr) . '/u', $leftStr) === 1,
            default => false,
        };
    }
}
