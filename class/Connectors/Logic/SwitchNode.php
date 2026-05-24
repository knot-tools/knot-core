<?php

declare(strict_types=1);

namespace Knot\Connectors\Logic;

use Knot\Connectors\Connector;
use Knot\Connectors\ConnectorInterface;
use Knot\Engine\ExpressionResolver;

/**
 * Switch routing node — picks one output handle out of N based on an
 * input expression and a list of cases. A `default` handle is always
 * exposed and used when no case matches.
 */
#[Connector(id: 'logic.switch', category: 'logic')]
final class SwitchNode implements ConnectorInterface, BranchAware
{
    public function __construct(private readonly ?ExpressionResolver $resolver = null)
    {
    }

    public function getMetadata(): array
    {
        return [
            'id' => 'logic.switch',
            'labelKey' => 'connectors.logic.switch.label',
            'descriptionKey' => 'connectors.logic.switch.description',
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
            'required' => ['expression'],
            'properties' => [
                'expression' => [
                    'type' => 'string',
                    'titleKey' => 'connectors.logic.switch.fields.expression.title',
                    'descriptionKey' => 'connectors.logic.switch.fields.expression.description',
                    'format' => 'textarea',
                    'x-position' => 0,
                ],
                'cases' => [
                    'type' => 'array',
                    'titleKey' => 'connectors.logic.switch.fields.cases.title',
                    'descriptionKey' => 'connectors.logic.switch.fields.cases.description',
                    'x-position' => 1,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'value' => [
                                'type' => 'string',
                                'titleKey' => 'connectors.logic.switch.caseRow.value.title',
                                'descriptionKey' => 'connectors.logic.switch.caseRow.value.description',
                                'x-position' => 0,
                            ],
                            'output' => [
                                'type' => 'string',
                                'titleKey' => 'connectors.logic.switch.caseRow.output.title',
                                'descriptionKey' => 'connectors.logic.switch.caseRow.output.description',
                                'x-position' => 1,
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
            ['id' => 'case_1', 'label' => 'Case 1'],
            ['id' => 'case_2', 'label' => 'Case 2'],
            ['id' => 'case_3', 'label' => 'Case 3'],
            ['id' => 'default', 'label' => 'Default'],
        ];
    }

    public function validate(array $config): array
    {
        $expression = $config['expression'] ?? '';
        return ['valid' => is_string($expression) && $expression !== '', 'errors' => []];
    }

    public function execute(array $context): array
    {
        $node = is_array($context['node'] ?? null) ? $context['node'] : [];
        $config = is_array($node['config'] ?? null) ? $node['config'] : [];
        $resolver = $this->resolver ?? new ExpressionResolver();
        $value = $resolver->resolve((string) ($config['expression'] ?? ''), $context);
        $cases = is_array($config['cases'] ?? null) ? $config['cases'] : [];

        $picked = 'default';
        foreach ($cases as $case) {
            if (!is_array($case)) {
                continue;
            }
            $caseValue = $resolver->resolve((string) ($case['value'] ?? ''), $context);
            if ((string) $caseValue === (string) $value) {
                $picked = (string) ($case['output'] ?? 'default');
                break;
            }
        }

        return ['value' => $value, 'branch' => $picked];
    }

    public function test(array $config): array
    {
        return $this->validate($config) + ['success' => true];
    }

    public function branchesToSkip(array $context, array $output): array
    {
        $taken = (string) ($output['branch'] ?? 'default');
        $all = ['case_1', 'case_2', 'case_3', 'default'];
        return array_values(array_filter($all, static fn (string $b): bool => $b !== $taken));
    }
}
