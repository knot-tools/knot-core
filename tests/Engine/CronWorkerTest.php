<?php

declare(strict_types=1);

namespace Knot\Tests\Engine;

use Knot\Connectors\ConnectorInterface;
use Knot\Connectors\DryRunAware;
use Knot\Engine\CronWorker;
use Knot\Tests\Engine\Support\CronWorkerContextFactory;
use Knot\Tests\Engine\Support\CronWorkerScenario;
use Knot\Tests\Engine\Support\CronWorkerScenarioDb;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CronWorkerTest extends TestCase
{
    public function testRunReturnsZeroWhenQueuesAreEmpty(): void
    {
        $scenario = new CronWorkerScenario();
        $worker = new CronWorker();
        self::assertSame(0, $worker->runWithContext($this->ctx($scenario)));
    }

    public function testRunEnqueuesCronExecutionForDueSchedule(): void
    {
        $scenario = new CronWorkerScenario();
        $scenario->schedules[] = [
            'id' => 11,
            'workflowId' => 5,
            'cronExpression' => '*/5 * * * *',
            'timezone' => 'UTC',
        ];
        $worker = new CronWorker();
        self::assertSame(0, $worker->runWithContext($this->ctx($scenario)));
        self::assertCount(1, $scenario->enqueued);
        self::assertSame(5, $scenario->enqueued[0]['workflowId']);
        self::assertSame('cron', $scenario->enqueued[0]['triggerType']);
    }

    public function testRunSkipsScheduleWhenIdempotencyKeyAlreadyExists(): void
    {
        $scenario = new CronWorkerScenario();
        $scenario->schedules[] = [
            'id' => 12,
            'workflowId' => 5,
            'cronExpression' => '*/5 * * * *',
            'timezone' => 'UTC',
        ];
        $key = 'cron:5:12:' . gmdate('YmdHi');
        $scenario->idempotencyKeys[$key] = 777;
        $worker = new CronWorker();
        $worker->runWithContext($this->ctx($scenario));
        self::assertSame([], $scenario->enqueued);
    }

    public function testRunMarksMissingWorkflowAsError(): void
    {
        $scenario = new CronWorkerScenario();
        $scenario->queuedExecutions[] = [
            'id' => 40,
            'workflowId' => 99,
            'status' => 'queued',
            'triggerType' => 'manual',
            'triggerData' => [],
        ];
        $worker = new CronWorker();
        self::assertSame(0, $worker->runWithContext($this->ctx($scenario)));
    }

    public function testRunDefersWhenSingleInstanceAlreadyRunning(): void
    {
        $scenario = new CronWorkerScenario();
        $def = $scenario->workflowDefinition();
        $scenario->workflows[7] = $scenario->activeWorkflow(7, $def, ['singleInstance' => true]);
        $scenario->queuedExecutions[] = [
            'id' => 41,
            'workflowId' => 7,
            'status' => 'queued',
            'triggerType' => 'manual',
            'triggerData' => [],
        ];
        $scenario->runningByWorkflow[7] = [40];
        $worker = new CronWorker();
        self::assertSame(0, $worker->runWithContext($this->ctx($scenario)));
    }

    public function testRunProcessesOnlyOneOfTwoQueuedSingleInstanceExecutions(): void
    {
        $scenario = new CronWorkerScenario();
        $def = $scenario->workflowDefinition();
        $scenario->workflows[10] = $scenario->activeWorkflow(10, $def, ['singleInstance' => true]);
        $scenario->queuedExecutions[] = [
            'id' => 70,
            'workflowId' => 10,
            'status' => 'queued',
            'triggerType' => 'manual',
            'triggerData' => [],
        ];
        $scenario->queuedExecutions[] = [
            'id' => 71,
            'workflowId' => 10,
            'status' => 'queued',
            'triggerType' => 'manual',
            'triggerData' => [],
        ];
        $worker = new CronWorker();
        self::assertSame(1, $worker->runWithContext($this->ctx($scenario)));
        self::assertSame([70], $scenario->runningByWorkflow[10] ?? []);
    }

    public function testRunSkipsWhenClaimFails(): void
    {
        $scenario = new CronWorkerScenario();
        $def = $scenario->workflowDefinition();
        $scenario->workflows[8] = $scenario->activeWorkflow(8, $def);
        $scenario->queuedExecutions[] = [
            'id' => 42,
            'workflowId' => 8,
            'status' => 'queued',
            'triggerType' => 'manual',
            'triggerData' => [],
        ];
        $scenario->claimSuccess = false;
        $worker = new CronWorker();
        self::assertSame(0, $worker->runWithContext($this->ctx($scenario)));
    }

    public function testRunProcessesSuccessfulExecution(): void
    {
        $scenario = new CronWorkerScenario();
        $def = $scenario->workflowDefinition();
        $scenario->workflows[1] = $scenario->activeWorkflow(1, $def);
        $scenario->queuedExecutions[] = [
            'id' => 50,
            'workflowId' => 1,
            'status' => 'queued',
            'triggerType' => 'manual',
            'triggerData' => [],
        ];
        $worker = new CronWorker();
        self::assertSame(1, $worker->runWithContext($this->ctx($scenario)));
    }

    public function testRunFinalizesNodeErrorsWithoutThrowing(): void
    {
        $scenario = new CronWorkerScenario();
        $def = $scenario->workflowDefinition([
            'nodes' => [
                ['id' => 't', 'type' => 'trigger.manual'],
                ['id' => 'b', 'type' => 'action.boom', 'config' => ['continueOnFail' => true]],
            ],
            'edges' => [['source' => 't', 'target' => 'b']],
        ]);
        $scenario->workflows[2] = $scenario->activeWorkflow(2, $def);
        $scenario->queuedExecutions[] = [
            'id' => 51,
            'workflowId' => 2,
            'status' => 'queued',
            'triggerType' => 'manual',
            'triggerData' => [],
        ];
        $worker = new CronWorker();
        self::assertSame(1, $worker->runWithContext($this->ctx($scenario, [
            'action.boom' => $this->boomConnector(),
        ])));
    }

    public function testRunEnqueuesGlobalErrorWorkflowOnTerminalFailure(): void
    {
        $scenario = new CronWorkerScenario();
        $def = $scenario->workflowDefinition([
            'workflow' => [
                'globalErrorWorkflowId' => 99,
                'executionMaxAttempts' => 1,
            ],
            'nodes' => [
                ['id' => 't', 'type' => 'trigger.manual'],
                ['id' => 'b', 'type' => 'action.boom'],
            ],
            'edges' => [['source' => 't', 'target' => 'b']],
        ]);
        $scenario->workflows[3] = $scenario->activeWorkflow(3, $def);
        $scenario->workflows[99] = $scenario->activeWorkflow(99, $scenario->workflowDefinition());
        $scenario->queuedExecutions[] = [
            'id' => 52,
            'workflowId' => 3,
            'status' => 'queued',
            'triggerType' => 'manual',
            'triggerData' => [],
        ];
        $scenario->fetchOneOverrides[52] = [
            'id' => 52,
            'workflowId' => 3,
            'status' => 'running',
            'triggerType' => 'manual',
            'triggerData' => [],
            'retryCount' => 0,
            'maxAttempts' => 1,
            'backoffStrategy' => 'exponential',
        ];
        $worker = new CronWorker();
        self::assertSame(0, $worker->runWithContext($this->ctx($scenario, ['action.boom' => $this->boomConnector()])));
        $foundGlobalError = false;
        foreach ($scenario->enqueued as $row) {
            if (($row['triggerType'] ?? '') === 'global_error' && ($row['workflowId'] ?? 0) === 99) {
                $foundGlobalError = true;
                break;
            }
        }
        self::assertTrue($foundGlobalError);
    }

    public function testRunOnceReturnsFalseWhenExecutionMissing(): void
    {
        $scenario = new CronWorkerScenario();
        $worker = new CronWorker();
        self::assertFalse($worker->runOnceWithContext(123, $this->ctx($scenario)));
    }

    public function testRunOnceReturnsFalseForNonQueuedStatus(): void
    {
        $scenario = new CronWorkerScenario();
        $scenario->fetchOneOverrides[60] = [
            'id' => 60,
            'workflowId' => 1,
            'status' => 'success',
            'triggerType' => 'manual',
            'triggerData' => [],
        ];
        $worker = new CronWorker();
        self::assertFalse($worker->runOnceWithContext(60, $this->ctx($scenario)));
    }

    public function testRunOnceProcessesQueuedExecution(): void
    {
        $scenario = new CronWorkerScenario();
        $def = $scenario->workflowDefinition();
        $scenario->workflows[1] = $scenario->activeWorkflow(1, $def);
        $scenario->fetchOneOverrides[61] = [
            'id' => 61,
            'workflowId' => 1,
            'status' => 'queued',
            'triggerType' => 'manual',
            'triggerData' => [],
        ];
        $worker = new CronWorker();
        self::assertTrue($worker->runOnceWithContext(61, $this->ctx($scenario)));
    }

    public function testRunOnceRejectsSingleInstanceConflict(): void
    {
        $scenario = new CronWorkerScenario();
        $def = $scenario->workflowDefinition();
        $scenario->workflows[9] = $scenario->activeWorkflow(9, $def, ['singleInstance' => true]);
        $scenario->runningByWorkflow[9] = [61];
        $scenario->fetchOneOverrides[62] = [
            'id' => 62,
            'workflowId' => 9,
            'status' => 'queued',
            'triggerType' => 'manual',
            'triggerData' => [],
        ];
        $worker = new CronWorker();
        self::assertFalse($worker->runOnceWithContext(62, $this->ctx($scenario)));
    }

    public function testRunOnceReturnsFalseAfterThrowable(): void
    {
        $scenario = new CronWorkerScenario();
        $def = $scenario->workflowDefinition([
            'nodes' => [
                ['id' => 't', 'type' => 'trigger.manual'],
                ['id' => 'b', 'type' => 'action.boom'],
            ],
            'edges' => [['source' => 't', 'target' => 'b']],
        ]);
        $scenario->workflows[4] = $scenario->activeWorkflow(4, $def);
        $scenario->fetchOneOverrides[63] = [
            'id' => 63,
            'workflowId' => 4,
            'status' => 'queued',
            'triggerType' => 'manual',
            'triggerData' => [],
            'retryCount' => 0,
            'maxAttempts' => 1,
        ];
        $worker = new CronWorker();
        self::assertFalse($worker->runOnceWithContext(63, $this->ctx($scenario, ['action.boom' => $this->boomConnector()])));
    }

    public function testPersistEngineLogsSwallowsRepositoryFailures(): void
    {
        $scenario = new CronWorkerScenario();
        $scenario->failExecutionLogInsert = true;
        $def = $scenario->workflowDefinition();
        $scenario->workflows[1] = $scenario->activeWorkflow(1, $def);
        $scenario->queuedExecutions[] = [
            'id' => 70,
            'workflowId' => 1,
            'status' => 'queued',
            'triggerType' => 'manual',
            'triggerData' => [],
        ];
        $worker = new CronWorker();
        self::assertSame(1, $worker->runWithContext($this->ctx($scenario)));
    }

    public function testRotateRuntimeLogWhenNotRotatedToday(): void
    {
        global $conf;
        $conf = (object) ['entity' => 1];
        $GLOBALS['knot_test_globals_string'] = ['KNOT_RUNTIME_LOG_LAST_ROTATION' => ''];
        try {
            $scenario = new CronWorkerScenario();
            $worker = new CronWorker();
            $worker->runWithContext($this->ctx($scenario));
            self::assertNotEmpty($GLOBALS['knot_test_const_writes'] ?? []);
        } finally {
            unset($GLOBALS['knot_test_globals_string'], $GLOBALS['knot_test_const_writes'], $conf);
        }
    }

    public function testBuildContextExposesRepositories(): void
    {
        global $db, $conf;
        $db = new CronWorkerScenarioDb(new CronWorkerScenario());
        $conf = (object) ['entity' => 1, 'db' => (object) ['name' => 'knot_test']];
        $worker = new CronWorker();
        $context = $worker->buildContext();
        self::assertSame(1, $context['entity']);
        self::assertInstanceOf(\Knot\Repository\ExecutionRepository::class, $context['executions']);
        self::assertInstanceOf(\Knot\Engine\WorkflowEngine::class, $context['engine']);
    }

    public function testRunExecutesWorkflowWithExtensionConnectorInAllowlist(): void
    {
        $scenario = new CronWorkerScenario();
        $def = $scenario->workflowDefinition([
            'nodes' => [
                ['id' => 't', 'type' => 'trigger.manual'],
                ['id' => 'p', 'type' => 'action.propack.stub', 'config' => ['marker' => 'pro']],
            ],
            'edges' => [['source' => 't', 'target' => 'p']],
        ]);
        $scenario->workflows[88] = $scenario->activeWorkflow(88, $def);
        $scenario->queuedExecutions[] = [
            'id' => 88,
            'workflowId' => 88,
            'status' => 'queued',
            'triggerType' => 'manual',
            'triggerData' => [],
        ];
        $worker = new CronWorker();
        $connectors = [
            'action.echo' => $this->echoConnector(),
            'action.propack.stub' => $this->proPackStubConnector(),
        ];
        self::assertSame(1, $worker->runWithContext($this->ctx($scenario, $connectors)));
    }

    public function testRunExecutesCoreOnlyWorkflowWithoutExtensionConnectors(): void
    {
        $scenario = new CronWorkerScenario();
        $def = $scenario->workflowDefinition();
        $scenario->workflows[1] = $scenario->activeWorkflow(1, $def);
        $scenario->queuedExecutions[] = [
            'id' => 71,
            'workflowId' => 1,
            'status' => 'queued',
            'triggerType' => 'manual',
            'triggerData' => [],
        ];
        $worker = new CronWorker();
        self::assertSame(1, $worker->runWithContext($this->ctx($scenario, ['action.echo' => $this->echoConnector()])));
    }

    public function testRunRecordsNodeErrorWhenExtensionConnectorNotRegistered(): void
    {
        $scenario = new CronWorkerScenario();
        $def = $scenario->workflowDefinition([
            'nodes' => [
                ['id' => 't', 'type' => 'trigger.manual'],
                ['id' => 'p', 'type' => 'action.propack.stub', 'config' => []],
            ],
            'edges' => [['source' => 't', 'target' => 'p']],
        ]);
        $scenario->workflows[89] = $scenario->activeWorkflow(89, $def);
        $scenario->queuedExecutions[] = [
            'id' => 89,
            'workflowId' => 89,
            'status' => 'queued',
            'triggerType' => 'manual',
            'triggerData' => [],
        ];
        $worker = new CronWorker();
        // WorkflowEngine soft-fails unknown connectors (node error, no throw).
        self::assertSame(1, $worker->runWithContext($this->ctx($scenario, ['action.echo' => $this->echoConnector()])));
    }

    /**
     * @param array<string, ConnectorInterface>|null $connectors
     * @return array<string, mixed>
     */
    private function ctx(CronWorkerScenario $scenario, ?array $connectors = null): array
    {
        return CronWorkerContextFactory::context(
            $scenario,
            $connectors ?? ['action.echo' => $this->echoConnector()],
        );
    }

    private function echoConnector(): ConnectorInterface
    {
        return new class implements ConnectorInterface, DryRunAware {
            public function getMetadata(): array
            {
                return ['id' => 'action.echo', 'label' => 'Echo', 'category' => 'test'];
            }
            public function getConfigSchema(): array
            {
                return ['type' => 'object'];
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
                return ['valid' => true, 'errors' => []];
            }
            public function execute(array $context): array
            {
                return ['echoed' => (string) ($context['node']['config']['payload'] ?? '')];
            }
            public function simulate(array $context): array
            {
                return ['_dryRun' => true];
            }
            public function test(array $config): array
            {
                return ['success' => true, 'errors' => []];
            }
        };
    }

    private function boomConnector(): ConnectorInterface
    {
        return new class implements ConnectorInterface {
            public function getMetadata(): array
            {
                return ['id' => 'action.boom', 'label' => 'Boom', 'category' => 'test'];
            }
            public function getConfigSchema(): array
            {
                return ['type' => 'object'];
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
                return ['valid' => true, 'errors' => []];
            }
            public function execute(array $context): array
            {
                throw new RuntimeException('boom');
            }
            public function test(array $config): array
            {
                return ['success' => true, 'errors' => []];
            }
        };
    }

    private function proPackStubConnector(): ConnectorInterface
    {
        return new class implements ConnectorInterface, DryRunAware {
            public function getMetadata(): array
            {
                return [
                    'id' => 'action.propack.stub',
                    'label' => 'Pro Pack stub',
                    'category' => 'other',
                    'description' => 'PHPUnit stand-in for a Pro Pack connector',
                ];
            }
            public function getConfigSchema(): array
            {
                return ['type' => 'object'];
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
                return ['valid' => true, 'errors' => []];
            }
            public function execute(array $context): array
            {
                return ['proPackMarker' => (string) ($context['node']['config']['marker'] ?? '')];
            }
            public function simulate(array $context): array
            {
                return ['_dryRun' => true];
            }
            public function test(array $config): array
            {
                return ['success' => true, 'errors' => []];
            }
        };
    }
}
