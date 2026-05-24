<?php

declare(strict_types=1);

namespace Knot\Tests\Engine\Support;

/**
 * In-memory scenario for {@see CronWorkerScenarioDb}.
 */
final class CronWorkerScenario
{
    /** @var list<array{id: int, workflowId: int, cronExpression: string, timezone: string}> */
    public array $schedules = [];

    /** @var array<string, int> */
    public array $idempotencyKeys = [];

    /**
     * Queued executions returned by listQueuedCandidates.
     *
     * @var list<array<string, mixed>>
     */
    public array $queuedExecutions = [];

    /** @var array<int, array<string, mixed>> */
    public array $workflows = [];

    public bool $claimSuccess = true;

    public int $runningCount = 0;

    /**
     * Running execution ids per workflow (simulates DB state for COUNT queries).
     *
     * @var array<int, list<int>>
     */
    public array $runningByWorkflow = [];

    public int $nextInsertId = 900;

    /** @var array<int, array<string, mixed>> */
    public array $fetchOneOverrides = [];

    public bool $failExecutionLogInsert = false;

    /** @var list<array{workflowId: int, triggerType: string, triggerData: array<string, mixed>}> */
    public array $enqueued = [];

    public function workflowDefinition(array $extra = []): array
    {
        return array_merge([
            'schemaVersion' => '1.0',
            'workflow' => [],
            'nodes' => [
                ['id' => 't', 'type' => 'trigger.manual'],
                ['id' => 'a', 'type' => 'action.echo', 'config' => ['payload' => 'ok']],
            ],
            'edges' => [['source' => 't', 'target' => 'a']],
        ], $extra);
    }

    /**
     * @return array<string, mixed>
     */
    public function activeWorkflow(int $id, array $definition, array $extra = []): array
    {
        return array_merge([
            'id' => $id,
            'ref' => 'WF-' . $id,
            'label' => 'Workflow ' . $id,
            'description' => '',
            'status' => 'active',
            'definition' => $definition,
            'schemaVersion' => '1.0',
            'entity' => 1,
            'singleInstance' => false,
            'activationWarningDismissed' => false,
        ], $extra);
    }
}
