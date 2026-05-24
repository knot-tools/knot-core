<?php

declare(strict_types=1);

namespace Knot\Tests\Engine\Support;

/**
 * Stateful DoliDB stub routing SQL to {@see CronWorkerScenario}.
 */
final class CronWorkerScenarioDb extends \DoliDB
{
    /** @var list<string> */
    public array $queries = [];

    private int $fetchCursor = 0;

    /** @var list<object> */
    private array $fetchQueue = [];

    private ?object $singleFetch = null;

    private int $lastAffectedRows = 0;

    public function __construct(private readonly CronWorkerScenario $scenario)
    {
    }

    public function query(string $sql)
    {
        $this->queries[] = $sql;
        $this->fetchQueue = [];
        $this->singleFetch = null;
        $this->lastAffectedRows = 0;

        if (str_contains($sql, 'DELETE FROM') && str_contains($sql, 'knot_idempotency')) {
            $this->lastAffectedRows = 0;
            return new \stdClass();
        }

        if (str_contains($sql, 'FROM llx_knot_schedule s') && str_contains($sql, 'INNER JOIN')) {
            foreach ($this->scenario->schedules as $schedule) {
                $this->fetchQueue[] = (object) [
                    'rowid' => $schedule['id'],
                    'fk_workflow' => $schedule['workflowId'],
                    'cron_expression' => $schedule['cronExpression'],
                    'timezone' => $schedule['timezone'],
                ];
            }
            return new \stdClass();
        }

        if (str_contains($sql, 'SELECT cron_expression, timezone FROM') && str_contains($sql, 'knot_schedule')) {
            $schedule = $this->scenario->schedules[0] ?? null;
            if ($schedule !== null) {
                $this->singleFetch = (object) [
                    'cron_expression' => $schedule['cronExpression'],
                    'timezone' => $schedule['timezone'],
                ];
            }
            return new \stdClass();
        }

        if (str_contains($sql, 'UPDATE') && str_contains($sql, 'knot_schedule')) {
            $this->lastAffectedRows = 1;
            return new \stdClass();
        }

        if (str_contains($sql, 'SELECT fk_execution FROM') && str_contains($sql, 'knot_idempotency')) {
            if (preg_match("/idempotency_key = '([^']+)'/", $sql, $m) === 1) {
                $key = $m[1];
                if (isset($this->scenario->idempotencyKeys[$key])) {
                    $this->singleFetch = (object) ['fk_execution' => $this->scenario->idempotencyKeys[$key]];
                }
            }
            return new \stdClass();
        }

        if (str_contains($sql, 'INSERT INTO') && str_contains($sql, 'knot_idempotency')) {
            return new \stdClass();
        }

        if (str_contains($sql, 'INSERT INTO') && str_contains($sql, 'knot_execution')) {
            $id = $this->scenario->nextInsertId++;
            $this->lastAffectedRows = 1;
            $triggerType = 'manual';
            if (preg_match("/,\s*'queued',\s*'([^']+)'/", $sql, $triggerMatch) === 1) {
                $triggerType = $triggerMatch[1];
            }
            $workflowId = 0;
            if (preg_match('/VALUES \((\d+),/', $sql, $wfMatch) === 1) {
                $workflowId = (int) $wfMatch[1];
            }
            $this->scenario->enqueued[] = [
                'workflowId' => $workflowId,
                'triggerType' => $triggerType,
                'triggerData' => [],
            ];
            $this->lastInsertIdValue = $id;
            return new \stdClass();
        }

        if (str_contains($sql, "status = 'queued'") && str_contains($sql, 'FROM llx_knot_execution')) {
            foreach ($this->scenario->queuedExecutions as $row) {
                $this->fetchQueue[] = $this->executionObject($row);
            }
            return new \stdClass();
        }

        if (str_contains($sql, 'FROM llx_knot_workflow') && str_contains($sql, 'WHERE rowid =')) {
            if (preg_match('/WHERE rowid = (\d+)/', $sql, $m) === 1) {
                $wfId = (int) $m[1];
                if (isset($this->scenario->workflows[$wfId])) {
                    $wf = $this->scenario->workflows[$wfId];
                    $this->singleFetch = (object) [
                        'rowid' => $wf['id'],
                        'ref' => $wf['ref'],
                        'label' => $wf['label'],
                        'description' => $wf['description'],
                        'status' => $wf['status'],
                        'json_definition' => json_encode($wf['definition'], JSON_UNESCAPED_SLASHES) ?: '{}',
                        'version_schema' => '1.0',
                        'entity' => 1,
                        'single_instance' => !empty($wf['singleInstance']) ? 1 : 0,
                        'activation_warning_dismissed' => 0,
                        'fk_user_creat' => null,
                        'fk_user_modif' => null,
                        'date_creation' => '2026-01-01 00:00:00',
                        'tms' => '2026-01-01 00:00:00',
                    ];
                }
            }
            return new \stdClass();
        }

        if (str_contains($sql, 'COUNT(*) AS n') && str_contains($sql, "status = 'running'")) {
            $workflowId = 0;
            $excludeId = 0;
            if (preg_match('/fk_workflow = (\d+)/', $sql, $wfMatch) === 1) {
                $workflowId = (int) $wfMatch[1];
            }
            if (preg_match('/rowid <> (\d+)/', $sql, $exMatch) === 1) {
                $excludeId = (int) $exMatch[1];
            }
            $n = 0;
            if ($workflowId > 0) {
                foreach ($this->scenario->runningByWorkflow[$workflowId] ?? [] as $runningId) {
                    if ($runningId !== $excludeId) {
                        $n++;
                    }
                }
            } else {
                $n = $this->scenario->runningCount;
            }
            $this->singleFetch = (object) ['n' => $n];
            return new \stdClass();
        }

        if (
            str_contains($sql, "status = 'running'")
            && str_contains($sql, 'UPDATE')
            && str_contains($sql, 'knot_execution')
            && str_contains($sql, "AND status = 'queued'")
        ) {
            $this->lastAffectedRows = $this->scenario->claimSuccess ? 1 : 0;
            if ($this->lastAffectedRows === 1 && preg_match('/WHERE rowid = (\d+)/', $sql, $idMatch) === 1) {
                $claimedId = (int) $idMatch[1];
                foreach ($this->scenario->queuedExecutions as $queued) {
                    $wfId = (int) ($queued['workflowId'] ?? 0);
                    if ((int) ($queued['id'] ?? 0) === $claimedId && $wfId > 0) {
                        $this->scenario->runningByWorkflow[$wfId] ??= [];
                        if (!in_array($claimedId, $this->scenario->runningByWorkflow[$wfId], true)) {
                            $this->scenario->runningByWorkflow[$wfId][] = $claimedId;
                        }
                        break;
                    }
                }
            }
            return new \stdClass();
        }

        if (str_contains($sql, "status = 'queued'") && str_contains($sql, 'started_at = NULL') && str_contains($sql, 'UPDATE')) {
            $this->lastAffectedRows = 1;
            if (preg_match('/WHERE rowid = (\d+)/', $sql, $idMatch) === 1) {
                $releasedId = (int) $idMatch[1];
                foreach ($this->scenario->runningByWorkflow as $wfId => $ids) {
                    $this->scenario->runningByWorkflow[$wfId] = array_values(
                        array_filter($ids, static fn (int $id): bool => $id !== $releasedId)
                    );
                }
            }
            return new \stdClass();
        }

        if (str_contains($sql, "status = 'running'") && str_contains($sql, 'UPDATE') && str_contains($sql, 'knot_execution')) {
            $this->lastAffectedRows = 1;
            return new \stdClass();
        }

        if (str_contains($sql, "status = 'success'") && str_contains($sql, 'UPDATE')) {
            $this->lastAffectedRows = 1;
            return new \stdClass();
        }

        if (str_contains($sql, "status = 'error'") && str_contains($sql, 'UPDATE')) {
            $this->lastAffectedRows = 1;
            return new \stdClass();
        }

        if (str_contains($sql, 'INSERT INTO') && str_contains($sql, 'knot_execution_log')) {
            if ($this->scenario->failExecutionLogInsert) {
                return false;
            }
            return new \stdClass();
        }

        if (str_contains($sql, 'FROM llx_knot_execution e') && str_contains($sql, 'LEFT JOIN')) {
            $executionId = 0;
            if (preg_match('/e.rowid = (\d+)/', $sql, $m) === 1) {
                $executionId = (int) $m[1];
            }
            $row = $this->scenario->fetchOneOverrides[$executionId] ?? null;
            if ($row === null) {
                foreach ($this->scenario->queuedExecutions as $queued) {
                    if ((int) ($queued['id'] ?? 0) === $executionId) {
                        $row = $queued;
                        break;
                    }
                }
            }
            if ($row !== null) {
                $this->singleFetch = $this->executionObject($row, true);
            }
            return new \stdClass();
        }

        if (str_contains($sql, 'UPDATE') && str_contains($sql, 'knot_execution')) {
            $this->lastAffectedRows = 1;
            return new \stdClass();
        }

        return new \stdClass();
    }

    public function fetch_object($resource): ?object
    {
        if ($this->singleFetch !== null) {
            $obj = $this->singleFetch;
            $this->singleFetch = null;
            return $obj;
        }
        if ($this->fetchCursor < count($this->fetchQueue)) {
            return $this->fetchQueue[$this->fetchCursor++];
        }
        $this->fetchCursor = 0;
        return null;
    }

    public function num_rows($resource): int
    {
        if ($this->singleFetch !== null) {
            return 1;
        }
        return count($this->fetchQueue) > 0 ? 1 : 0;
    }

    public function affected_rows(mixed $resultset = null): int
    {
        return $this->lastAffectedRows;
    }

    public function plimit(int $limit, int $offset = 0): string
    {
        return 'LIMIT ' . $limit;
    }

    public int $lastInsertIdValue = 0;

    public function last_insert_id(string $tableName): int
    {
        return $this->lastInsertIdValue;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function executionObject(array $row, bool $withLabels = false): object
    {
        $triggerData = $row['triggerData'] ?? [];
        $encoded = json_encode(is_array($triggerData) ? $triggerData : [], JSON_UNESCAPED_SLASHES) ?: '{}';

        $obj = (object) [
            'rowid' => (int) ($row['id'] ?? 1),
            'fk_workflow' => (int) ($row['workflowId'] ?? 1),
            'status' => (string) ($row['status'] ?? 'queued'),
            'trigger_type' => (string) ($row['triggerType'] ?? 'manual'),
            'trigger_data' => $encoded,
            'retry_count' => (int) ($row['retryCount'] ?? 0),
            'priority' => (int) ($row['priority'] ?? 5),
            'scheduled_at' => null,
            'next_retry_at' => null,
            'max_attempts' => (int) ($row['maxAttempts'] ?? 3),
            'backoff_strategy' => (string) ($row['backoffStrategy'] ?? 'exponential'),
            'worker_id' => null,
            'started_at' => null,
            'ended_at' => null,
            'error_message' => null,
            'error_payload' => null,
            'duration_ms' => null,
        ];

        if ($withLabels) {
            $obj->workflow_label = 'WF';
            $obj->workflow_ref = 'WF-REF';
        }

        return $obj;
    }
}
