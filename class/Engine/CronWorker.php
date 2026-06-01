<?php

/* Copyright (C) 2026 Sébastien Audel (EXIATIS) — Knot Tools™ Core, GPL-3.0-or-later */

declare(strict_types=1);

namespace Knot\Engine;

use Knot\Connectors\ConnectorRegistry;
use Knot\Credentials\CredentialCipher;
use Knot\Credentials\CredentialResolver;
use Knot\Errors\ExecutionErrorPayloadCodec;
use Knot\Repository\CredentialRepository;
use Knot\Repository\ExecutionLogRepository;
use Knot\Repository\ExecutionRepository;
use Knot\Repository\IdempotencyRepository;
use Knot\Repository\ScheduleRepository;
use Knot\Repository\WorkflowRepository;
use Throwable;

/**
 * Cron worker placeholder for queued executions.
 */
final class CronWorker
{
    /**
     * Last single-line error from the most recent run(). Dolibarr's cron
     * framework reads this on the runner instance after invoking `run()` to
     * decide whether the scheduled job is healthy and what to surface in
     * the cron job UI; without these fields it logs PHP "Undefined
     * property" warnings on every tick.
     *
     * @var string
     */
    public string $error = '';

    /**
     * @var array<int, string>
     */
    public array $errors = [];

    /**
     * Process queued executions.
     *
     * @return int Number of processed jobs
     */
    public function run(): int
    {
        return $this->runWithContext($this->bootContext());
    }

    /**
     * Process queued executions with a pre-built context (used by PHPUnit).
     *
     * @param array{
     *     entity: int,
     *     executions: ExecutionRepository,
     *     executionLogs: ExecutionLogRepository,
     *     idempotency: IdempotencyRepository,
     *     schedules: ScheduleRepository,
     *     workflows: WorkflowRepository,
     *     engine: WorkflowEngine
     * } $context
     */
    public function runWithContext(array $context): int
    {
        $context['idempotency']->purgeExpired($context['entity']);
        $this->rotateRuntimeLogIfNeeded();
        $entity = $context['entity'];
        $executions = $context['executions'];
        $executionLogs = $context['executionLogs'];
        $idempotency = $context['idempotency'];
        $schedules = $context['schedules'];
        $workflows = $context['workflows'];
        $engine = $context['engine'];

        foreach ($schedules->fetchDue($entity, 10) as $schedule) {
            $idempotencyKey = 'cron:' . (int) $schedule['workflowId'] . ':' . (int) $schedule['id'] . ':' . gmdate('YmdHi');
            if ($idempotency->findExecution($idempotencyKey, $entity) !== null) {
                $schedules->markRun((int) $schedule['id'], $entity);
                continue;
            }
            $executionId = $executions->enqueue(
                (int) $schedule['workflowId'],
                'cron',
                [
                    'scheduleId' => (int) $schedule['id'],
                    'cronExpression' => (string) $schedule['cronExpression'],
                    'timezone' => (string) $schedule['timezone'],
                    '_idempotencyKey' => $idempotencyKey,
                ],
                $entity
            );
            if ($executionId > 0) {
                $idempotency->remember($idempotencyKey, $executionId, $entity, 3600);
            }
            $schedules->markRun((int) $schedule['id'], $entity);
        }

        $processed = 0;
        $workerTag = 'cron-' . gmdate('YmdHis');
        foreach ($executions->listQueuedCandidates($entity, 10) as $execution) {
            $executionId = (int) $execution['id'];
            $workflow = null;

            try {
                $workflow = $workflows->fetchActive((int) $execution['workflowId'], $entity);
                if ($workflow === null) {
                    $executions->markStatus($executionId, 'error', $entity, 'Workflow not found or inactive.');
                    continue;
                }

                if (
                    !empty($workflow['singleInstance'])
                    && $executions->countRunningForWorkflow((int) $execution['workflowId'], $entity, 0) > 0
                ) {
                    continue;
                }

                if (!$executions->tryClaimExecution($executionId, $entity, $workerTag . '-' . $executionId)) {
                    continue;
                }

                if (
                    !$this->retainClaimOrReleaseSingleInstance(
                        $executions,
                        $workflow,
                        (int) $execution['workflowId'],
                        $executionId,
                        $entity
                    )
                ) {
                    continue;
                }

                /** @var array<string, mixed> $definition */
                $definition = $workflow['definition'];
                $triggerData = is_array($execution['triggerData'] ?? null) ? $execution['triggerData'] : [];
                $engine->execute($definition, $triggerData, [
                    'executionId' => $executionId,
                    'entity' => $entity,
                    'workflowId' => (int) $execution['workflowId'],
                    'idempotencyKey' => $triggerData['_idempotencyKey'] ?? null,
                    'startNodeId' => $triggerData['_startNodeId'] ?? null,
                    'inputData' => is_array($triggerData['_inputData'] ?? null) ? $triggerData['_inputData'] : null,
                    'dryRun' => (bool) ($triggerData['_dryRun'] ?? false),
                ]);
                $this->finalizeExecution(
                    $executionLogs,
                    $engine,
                    $executions,
                    $executionId,
                    $entity,
                    $workflow,
                    $execution
                );
                $processed++;
            } catch (Throwable $throwable) {
                // Persist whatever logs the engine captured before
                // throwing so the waterfall reflects the real progress
                // up to the failure point.
                $this->persistEngineLogs($executionLogs, $engine, $executionId, $entity);
                if (is_array($workflow)) {
                    $retryCfg = $this->executionRetrySettings($workflow);
                    $failurePayload = ExecutionErrorPayloadCodec::fromThrowable($throwable);
                    $outcome = $executions->recordFailureAndScheduleRetry(
                        $executionId,
                        $entity,
                        $throwable->getMessage(),
                        $retryCfg['maxAttempts'],
                        $retryCfg['backoffStrategy'],
                        $failurePayload
                    );
                    if ($outcome === 'terminal_error') {
                        $this->enqueueGlobalErrorWorkflow(
                            $workflow,
                            $execution,
                            $executionId,
                            $throwable->getMessage(),
                            $entity,
                            $executions
                        );
                    }
                } else {
                    $executions->markStatus(
                        $executionId,
                        'error',
                        $entity,
                        $throwable->getMessage(),
                        ExecutionErrorPayloadCodec::fromThrowable($throwable)
                    );
                }
            }
        }

        return $processed;
    }

    /**
     * Process a single queued execution synchronously.
     * Useful for the "Run pending now" UI button.
     */
    public function runOnce(int $executionId): bool
    {
        return $this->runOnceWithContext($executionId, $this->bootContext());
    }

    /**
     * @param array{
     *     entity: int,
     *     executions: ExecutionRepository,
     *     executionLogs: ExecutionLogRepository,
     *     idempotency: IdempotencyRepository,
     *     schedules: ScheduleRepository,
     *     workflows: WorkflowRepository,
     *     engine: WorkflowEngine
     * } $context
     */
    public function runOnceWithContext(int $executionId, array $context): bool
    {
        /** @var ExecutionRepository $executions */
        $executions = $context['executions'];
        /** @var ExecutionLogRepository $executionLogs */
        $executionLogs = $context['executionLogs'];
        /** @var WorkflowRepository $workflows */
        $workflows = $context['workflows'];
        /** @var WorkflowEngine $engine */
        $engine = $context['engine'];
        $entity = (int) $context['entity'];

        $execution = $executions->fetchOne($executionId, $entity);
        if ($execution === null) {
            return false;
        }
        if (!in_array((string) $execution['status'], ['queued', 'error', 'cancelled', 'timeout'], true)) {
            return false;
        }
        $executions->resetToQueued($executionId, $entity);

        $workflow = $workflows->fetchActive((int) $execution['workflowId'], $entity);
        if ($workflow === null) {
            $executions->markStatus($executionId, 'error', $entity, 'Workflow not found or inactive.');
            return false;
        }

        // Same single-instance guard as run(): refuse to start a second
        // parallel execution if the workflow opted in.
        if (
            !empty($workflow['singleInstance'])
            && $executions->countRunningForWorkflow((int) $execution['workflowId'], $entity, $executionId) > 0
        ) {
            $executions->markStatus($executionId, 'error', $entity, 'Workflow is single-instance and already running.');
            return false;
        }

        if (!$executions->tryClaimExecution($executionId, $entity, 'runonce-' . $executionId)) {
            return false;
        }

        if (
            !$this->retainClaimOrReleaseSingleInstance(
                $executions,
                $workflow,
                (int) $execution['workflowId'],
                $executionId,
                $entity
            )
        ) {
            return false;
        }

        try {
            // Workflow already fetched above; no need to re-fetch.
            $definition = $workflow['definition'];
            $triggerData = is_array($execution['triggerData'] ?? null) ? $execution['triggerData'] : [];
            $engine->execute($definition, $triggerData, [
                'executionId' => $executionId,
                'idempotencyKey' => $triggerData['_idempotencyKey'] ?? null,
                'startNodeId' => $triggerData['_startNodeId'] ?? null,
                'inputData' => is_array($triggerData['_inputData'] ?? null) ? $triggerData['_inputData'] : null,
                'dryRun' => (bool) ($triggerData['_dryRun'] ?? false),
            ]);
            $this->finalizeExecution(
                $executionLogs,
                $engine,
                $executions,
                $executionId,
                $entity,
                $workflow,
                $execution
            );

            return !$engine->hasNodeErrors();
        } catch (Throwable $t) {
            $this->persistEngineLogs($executionLogs, $engine, $executionId, $entity);
            $retryCfg = $this->executionRetrySettings($workflow);
            $failurePayload = ExecutionErrorPayloadCodec::fromThrowable($t);
            $outcome = $executions->recordFailureAndScheduleRetry(
                $executionId,
                $entity,
                $t->getMessage(),
                $retryCfg['maxAttempts'],
                $retryCfg['backoffStrategy'],
                $failurePayload
            );
            if ($outcome === 'terminal_error') {
                $this->enqueueGlobalErrorWorkflow($workflow, $execution, $executionId, $t->getMessage(), $entity, $executions);
            }
            return false;
        }
    }

    /**
     * Post-claim guard for `single_instance` workflows.
     *
     * Two queued executions of the same workflow can both pass the pre-claim
     * `countRunningForWorkflow` check; only one may stay `running`. Losers are
     * moved back to `queued` for the next cron tick.
     *
     * @param array<string, mixed> $workflow
     */
    private function retainClaimOrReleaseSingleInstance(
        ExecutionRepository $executions,
        array $workflow,
        int $workflowId,
        int $executionId,
        int $entity,
    ): bool {
        if (empty($workflow['singleInstance'])) {
            return true;
        }

        if ($executions->countRunningForWorkflow($workflowId, $entity, $executionId) > 0) {
            $executions->resetToQueued($executionId, $entity);

            return false;
        }

        return true;
    }

    /**
     * Workflow-level execution retry policy (definition.workflow.*).
     *
     * @param array<string, mixed> $workflow
     *
     * @return array{maxAttempts: int, backoffStrategy: string}
     */
    private function executionRetrySettings(array $workflow): array
    {
        $definition = $workflow['definition'] ?? [];
        $wf = is_array($definition['workflow'] ?? null) ? $definition['workflow'] : [];
        $maxAttempts = max(1, min(50, (int) ($wf['executionMaxAttempts'] ?? 3)));
        $backoffStrategy = strtolower(trim((string) ($wf['executionBackoffStrategy'] ?? 'exponential')));
        if (!in_array($backoffStrategy, ['exponential', 'linear', 'fixed'], true)) {
            $backoffStrategy = 'exponential';
        }

        return ['maxAttempts' => $maxAttempts, 'backoffStrategy' => $backoffStrategy];
    }

    /**
     * Boot the shared engine + repository context once.
     *
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
    public function buildContext(): array
    {
        return $this->bootContext();
    }

    /**
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
    protected function bootContext(): array
    {
        global $db, $conf;

        require_once dirname(__DIR__) . '/autoload.php';

        $entity = (int) $conf->entity;
        $executions = new ExecutionRepository($db);
        $executionLogs = new ExecutionLogRepository($db);
        $idempotency = new IdempotencyRepository($db);
        $schedules = new ScheduleRepository($db);
        $workflows = new WorkflowRepository($db);
        $credentials = new CredentialRepository($db);
        $cipher = new CredentialCipher($this->instanceSecret($conf), 'knot-credential-v1');
        $resolver = new CredentialResolver($credentials, $cipher, $entity);
        $connectorBundle = EngineConnectorResolver::resolve($db, $entity);
        $engine = new WorkflowEngine(
            new WorkflowValidator($connectorBundle['allowlist']),
            new ExecutionContext(),
            $connectorBundle['connectors'],
            null,
            $resolver,
            null,
            new \Knot\Observability\RuntimeLogger(\Knot\Observability\RuntimeLogger::defaultDirectory())
        );

        return [
            'entity' => $entity,
            'executions' => $executions,
            'executionLogs' => $executionLogs,
            'idempotency' => $idempotency,
            'schedules' => $schedules,
            'workflows' => $workflows,
            'engine' => $engine,
        ];
    }

    /**
     * @param array<string, mixed> $workflow
     * @param array<string, mixed> $execution
     */
    private function enqueueGlobalErrorWorkflow(
        array $workflow,
        array $execution,
        int $executionId,
        string $errorMessage,
        int $entity,
        ExecutionRepository $executions
    ): void {
        $definition = is_array($workflow['definition'] ?? null) ? $workflow['definition'] : [];
        $settings = is_array($definition['workflow'] ?? null) ? $definition['workflow'] : [];
        $errorWorkflowId = (int) ($settings['globalErrorWorkflowId'] ?? $settings['errorWorkflowId'] ?? 0);
        if ($errorWorkflowId <= 0 || $errorWorkflowId === (int) ($execution['workflowId'] ?? 0)) {
            return;
        }

        $executions->enqueue($errorWorkflowId, 'global_error', [
            'failedExecutionId' => $executionId,
            'failedWorkflowId' => (int) ($execution['workflowId'] ?? 0),
            'failingTriggerType' => (string) ($execution['triggerType'] ?? ''),
            'error' => $errorMessage,
            'triggerData' => is_array($execution['triggerData'] ?? null) ? $execution['triggerData'] : [],
            'queuedAt' => date(DATE_ATOM),
        ], $entity);
    }

    private function instanceSecret(object $conf): string
    {
        global $dolibarr_main_instance_unique_id;
        $parts = [];
        if (isset($dolibarr_main_instance_unique_id) && (string) $dolibarr_main_instance_unique_id !== '') {
            $parts[] = (string) $dolibarr_main_instance_unique_id;
        }
        if (function_exists('getDolGlobalString')) {
            $parts[] = (string) getDolGlobalString('MAIN_INFO_SOCIETE_NOM');
            $parts[] = (string) getDolGlobalString('MAIN_MAIL_EMAIL_FROM');
        }
        $parts[] = defined('DOL_DOCUMENT_ROOT') ? DOL_DOCUMENT_ROOT : '';
        $parts[] = (string) ($conf->db->name ?? '');

        return hash('sha256', implode('|', array_filter($parts)));
    }

    /**
     * Persist node logs and set the execution row to success or error.
     *
     * @param array<string, mixed> $workflow
     * @param array<string, mixed> $execution
     */
    private function finalizeExecution(
        ExecutionLogRepository $executionLogs,
        WorkflowEngine $engine,
        ExecutionRepository $executions,
        int $executionId,
        int $entity,
        array $workflow,
        array $execution
    ): void {
        $this->persistEngineLogs($executionLogs, $engine, $executionId, $entity);

        if (!$engine->hasNodeErrors()) {
            $executions->markStatus($executionId, 'success', $entity);

            return;
        }

        $message = $engine->firstNodeErrorMessage() ?? 'One or more workflow nodes failed.';
        $knotPayload = $engine->firstNodeKnotError();
        $executions->markStatus($executionId, 'error', $entity, $message, $knotPayload);
        $this->enqueueGlobalErrorWorkflow(
            $workflow,
            $execution,
            $executionId,
            $message,
            $entity,
            $executions
        );
    }

    /**
     * Persist the in-memory node logs captured by `WorkflowEngine` to
     * `llx_knot_execution_log`, including `duration_ms` so the
     * V2.5.0d execution detail / waterfall view has real data to
     * render. Best-effort: a DB write failure must not break the
     * overall execution status.
     */
    private function persistEngineLogs(
        ExecutionLogRepository $logs,
        WorkflowEngine $engine,
        int $executionId,
        int $entity
    ): void {
        try {
            foreach ($engine->getLogs() as $log) {
                $logs->add(
                    $executionId,
                    (string) ($log['nodeId'] ?? ''),
                    (string) ($log['nodeType'] ?? ''),
                    (string) ($log['status'] ?? ''),
                    is_array($log['input'] ?? null) ? $log['input'] : [],
                    is_array($log['output'] ?? null) ? $log['output'] : [],
                    (int) ($log['sequenceOrder'] ?? 0),
                    $entity,
                    isset($log['errorMessage']) ? (string) $log['errorMessage'] : null,
                    isset($log['durationMs']) ? (int) $log['durationMs'] : null
                );
            }
        } catch (Throwable $e) {
            // Best-effort; the runtime JSON log + Dolibarr error log
            // remain the operator's recourse if DB persistence fails.
        }
    }

    /**
     * Trigger RuntimeLogger rotation once per UTC day. Cheap idempotent
     * lock via `KNOT_RUNTIME_LOG_LAST_ROTATION` so the cron tick (every
     * 5 min) does not re-scan the directory each time.
     */
    private function rotateRuntimeLogIfNeeded(): void
    {
        global $db, $conf;

        if (!function_exists('getDolGlobalString') || !function_exists('dolibarr_set_const')) {
            return;
        }

        $today = gmdate('Y-m-d');
        $last = (string) getDolGlobalString('KNOT_RUNTIME_LOG_LAST_ROTATION', '');
        if ($last === $today) {
            return;
        }
        if (!isset($conf) || !is_object($conf)) {
            return;
        }

        try {
            $logger = new \Knot\Observability\RuntimeLogger(\Knot\Observability\RuntimeLogger::defaultDirectory());
            $logger->rotate();
            dolibarr_set_const($db, 'KNOT_RUNTIME_LOG_LAST_ROTATION', $today, 'chaine', 0, '', (int) $conf->entity);
        } catch (\Throwable $e) {
            // Rotation failure must never break the cron run.
        }
    }
}
