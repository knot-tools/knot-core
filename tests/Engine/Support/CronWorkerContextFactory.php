<?php

declare(strict_types=1);

namespace Knot\Tests\Engine\Support;

use Knot\Connectors\ConnectorInterface;
use Knot\Engine\CronWorker;
use Knot\Engine\ExecutionContext;
use Knot\Engine\WorkflowEngine;
use Knot\Engine\WorkflowValidator;
use Knot\Repository\ExecutionLogRepository;
use Knot\Repository\ExecutionRepository;
use Knot\Repository\IdempotencyRepository;
use Knot\Repository\ScheduleRepository;
use Knot\Repository\WorkflowRepository;

final class CronWorkerContextFactory
{
    /**
     * @param array<string, ConnectorInterface> $connectors
     * @return array{
     *     entity: int,
     *     executions: ExecutionRepository,
     *     executionLogs: ExecutionLogRepository,
     *     idempotency: IdempotencyRepository,
     *     schedules: ScheduleRepository,
     *     workflows: WorkflowRepository,
     *     engine: WorkflowEngine
     * }
     */
    public static function build(CronWorkerScenario $scenario, array $connectors): array
    {
        $db = new CronWorkerScenarioDb($scenario);
        $entity = 1;

        return [
            'entity' => $entity,
            'executions' => new ExecutionRepository($db),
            'executionLogs' => new ExecutionLogRepository($db),
            'idempotency' => new IdempotencyRepository($db),
            'schedules' => new ScheduleRepository($db),
            'workflows' => new WorkflowRepository($db),
            'engine' => new WorkflowEngine(new WorkflowValidator(), new ExecutionContext(), $connectors),
        ];
    }

    /**
     * @param array<string, ConnectorInterface> $connectors
     * @return array<string, mixed>
     */
    public static function context(CronWorkerScenario $scenario, array $connectors): array
    {
        return self::build($scenario, $connectors);
    }
}
