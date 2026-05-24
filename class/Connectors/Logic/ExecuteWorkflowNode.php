<?php

declare(strict_types=1);

namespace Knot\Connectors\Logic;

use Knot\Connectors\Connector;
use Knot\Connectors\ConnectorInterface;
use Knot\Connectors\DryRunAware;
use Knot\Engine\EngineConnectorResolver;
use Knot\Engine\ExecutionContext;
use Knot\Engine\ExpressionResolver;
use Knot\Engine\WorkflowEngine;
use Knot\Engine\WorkflowValidator;
use Knot\Repository\WorkflowRepository;
use RuntimeException;

/**
 * Execute another active workflow and expose its output to the parent flow.
 */
#[Connector(id: 'logic.execute_workflow', category: 'logic')]
final class ExecuteWorkflowNode implements ConnectorInterface, DryRunAware
{
    private const MAX_DEPTH = 10;

    public function __construct(private readonly ?ExpressionResolver $resolver = null)
    {
    }

    public function getMetadata(): array
    {
        return [
            'id' => 'logic.execute_workflow',
            'labelKey' => 'connectors.logic.execute_workflow.label',
            'descriptionKey' => 'connectors.logic.execute_workflow.description',
            'category' => 'logic',
            'riskLevel' => 'caution',
            'reversible' => false,
            'sideEffects' => [],
        ];
    }

    public function getConfigSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['workflowId'],
            'properties' => [
                'workflowId' => [
                    'type' => 'integer',
                    'titleKey' => 'connectors.logic.execute_workflow.fields.workflowId.title',
                    'descriptionKey' => 'connectors.logic.execute_workflow.fields.workflowId.description',
                    'x-position' => 0,
                ],
                'input' => [
                    'type' => 'object',
                    'titleKey' => 'connectors.logic.execute_workflow.fields.input.title',
                    'descriptionKey' => 'connectors.logic.execute_workflow.fields.input.description',
                    'default' => [],
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
        return [['id' => 'main', 'label' => 'Main']];
    }

    public function getOutputs(): array
    {
        return [['id' => 'main', 'label' => 'Main']];
    }

    public function validate(array $config): array
    {
        $errors = [];
        if ((int) ($config['workflowId'] ?? 0) <= 0) {
            $errors[] = 'workflowId is required';
        }
        return ['valid' => $errors === [], 'errors' => $errors];
    }

    public function execute(array $context): array
    {
        global $db, $conf;

        $node = is_array($context['node'] ?? null) ? $context['node'] : [];
        $config = is_array($node['config'] ?? null) ? $node['config'] : [];
        $workflowId = (int) ($config['workflowId'] ?? 0);
        $currentWorkflowId = (int) (($context['workflow']['id'] ?? 0) ?: 0);
        $depth = (int) ($context['execution']['depth'] ?? 0);

        if ($workflowId <= 0) {
            throw new RuntimeException('ExecuteWorkflow requires a workflowId.');
        }
        if ($workflowId === $currentWorkflowId) {
            throw new RuntimeException('A workflow cannot execute itself as a sub-workflow.');
        }
        $workflowStack = is_array($context['workflowStack'] ?? null) ? $context['workflowStack'] : [];
        if (in_array($workflowId, $workflowStack, true)) {
            throw new RuntimeException('Sub-workflow cycle detected.');
        }
        if ($depth >= self::MAX_DEPTH) {
            throw new RuntimeException('Sub-workflow recursion limit exceeded.');
        }

        $entity = (int) $conf->entity;
        $workflow = (new WorkflowRepository($db))->fetchActive($workflowId, $entity);
        if ($workflow === null) {
            throw new RuntimeException('Sub-workflow not found or inactive: ' . $workflowId);
        }

        $resolver = $this->resolver ?? new ExpressionResolver();
        $inputConfig = is_array($config['input'] ?? null) ? $config['input'] : [];
        $input = $inputConfig !== [] ? $resolver->resolve($inputConfig, $context) : ($context['json'] ?? []);
        if (!is_array($input)) {
            $input = ['value' => $input];
        }

        /** @var array<string, mixed> $definition */
        $definition = $workflow['definition'];
        $definition['workflow'] = array_replace(
            is_array($definition['workflow'] ?? null) ? $definition['workflow'] : [],
            [
                'id' => $workflowId,
                'label' => (string) ($workflow['label'] ?? 'Sub-workflow'),
            ]
        );

        $connectorBundle = EngineConnectorResolver::resolve($db, $entity);

        $childContext = (new WorkflowEngine(
            new WorkflowValidator($connectorBundle['allowlist']),
            new ExecutionContext(),
            $connectorBundle['connectors']
        ))->execute($definition, $input, [
            'parentExecutionId' => $context['execution']['id'] ?? null,
            'depth' => $depth + 1,
            'workflowStack' => $workflowStack,
            'originChain' => array_merge(
                is_array($context['originChain'] ?? null) ? $context['originChain'] : [],
                [[
                    'type' => 'sub_workflow',
                    'workflowId' => $workflowId,
                    'parentWorkflowId' => $currentWorkflowId ?: null,
                    'nodeId' => (string) ($node['id'] ?? ''),
                    'at' => date(DATE_ATOM),
                ]]
            ),
        ]);

        return [
            'workflowId' => $workflowId,
            'workflowLabel' => (string) ($workflow['label'] ?? ''),
            'input' => $input,
            'output' => is_array($childContext['json'] ?? null) ? $childContext['json'] : [],
            'logs' => is_array($childContext['_logs'] ?? null) ? $childContext['_logs'] : [],
        ];
    }

    public function test(array $config): array
    {
        return $this->validate($config) + ['success' => true];
    }

    public function simulate(array $context): array
    {
        $node = is_array($context['node'] ?? null) ? $context['node'] : [];
        $config = is_array($node['config'] ?? null) ? $node['config'] : [];
        $workflowId = (int) ($config['workflowId'] ?? 0);
        $resolver = $this->resolver ?? new ExpressionResolver();
        $resolved = $resolver->resolve(is_array($config['input'] ?? null) ? $config['input'] : [], $context);
        return [
            '_dryRun' => true,
            'workflowId' => $workflowId,
            'input' => is_array($resolved) ? $resolved : [],
            'note' => sprintf('[DRY-RUN] Would invoke workflow #%d as a sub-workflow.', $workflowId),
        ];
    }
}
