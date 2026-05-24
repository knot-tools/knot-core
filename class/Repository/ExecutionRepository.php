<?php

declare(strict_types=1);

namespace Knot\Repository;

use Knot\Engine\ExecutionBackoff;
use Knot\Errors\ExecutionErrorPayloadCodec;

/**
 * Repository for workflow executions.
 */
final class ExecutionRepository extends AbstractRepository
{
    /**
     * Queue a workflow execution.
     *
     * @param array<string, mixed> $triggerData Trigger payload
     */
    public function enqueue(
        int $workflowId,
        string $triggerType,
        array $triggerData,
        int $entity,
        int $priority = 5,
        ?string $scheduledAt = null,
        int $maxAttempts = 3,
        string $backoffStrategy = 'exponential'
    ): int {
        $priority = max(0, min(127, $priority));
        $maxAttempts = max(1, min(127, $maxAttempts));
        $backoffStrategy = $this->normaliseBackoffStrategy($backoffStrategy);
        $scheduledSql = $scheduledAt !== null && $scheduledAt !== ''
            ? "'" . $this->db->escape($scheduledAt) . "'"
            : 'NULL';

        $sql = 'INSERT INTO ' . $this->table('execution') . ' (';
        $sql .= 'fk_workflow, status, trigger_type, trigger_data, retry_count, entity';
        $sql .= ', priority, scheduled_at, max_attempts, backoff_strategy';
        $sql .= ') VALUES (';
        $sql .= (int) $workflowId . ',';
        $sql .= "'queued',";
        $sql .= "'" . $this->db->escape($triggerType) . "',";
        $sql .= "'" . $this->db->escape(json_encode($triggerData, JSON_UNESCAPED_SLASHES) ?: '{}') . "',";
        $sql .= '0,';
        $sql .= (int) $entity . ',';
        $sql .= (int) $priority . ',';
        $sql .= $scheduledSql . ',';
        $sql .= (int) $maxAttempts . ',';
        $sql .= "'" . $this->db->escape($backoffStrategy) . "'";
        $sql .= ')';

        if (!$this->db->query($sql)) {
            return 0;
        }

        return (int) $this->db->last_insert_id($this->table('execution'));
    }

    /**
     * List queued executions eligible for workers (read-only snapshot).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listQueuedCandidates(int $entity, int $limit = 10): array
    {
        $nowLit = "'" . $this->db->idate(time()) . "'";
        $sql = 'SELECT rowid, fk_workflow, status, trigger_type, trigger_data, retry_count, ';
        $sql .= 'priority, scheduled_at, next_retry_at, max_attempts, backoff_strategy, worker_id, ';
        $sql .= 'started_at, ended_at, error_message, duration_ms ';
        $sql .= 'FROM ' . $this->table('execution') . ' ';
        $sql .= 'WHERE entity = ' . (int) $entity . " AND status = 'queued' ";
        $sql .= ' AND (scheduled_at IS NULL OR scheduled_at <= ' . $nowLit . ') ';
        $sql .= ' AND (next_retry_at IS NULL OR next_retry_at <= ' . $nowLit . ') ';
        $sql .= ' ORDER BY priority DESC, scheduled_at IS NULL DESC, scheduled_at ASC, rowid ASC ';
        $sql .= $this->db->plimit($limit);

        $res = $this->db->query($sql);
        if (!$res) {
            return [];
        }

        $rows = [];
        while ($obj = $this->db->fetch_object($res)) {
            $rows[] = $this->mapExecutionObject($obj);
        }

        return $rows;
    }

    /**
     * Move a queued execution to running if still queued (optimistic lock).
     *
     * Only one worker wins per execution row (`affected_rows === 1`).
     * For `single_instance` workflows, {@see CronWorker} also runs a
     * post-claim check so two different execution ids cannot stay `running`.
     */
    public function tryClaimExecution(int $executionId, int $entity, string $workerId): bool
    {
        $workerId = substr(preg_replace('/[^a-zA-Z0-9._:-]/', '', $workerId) ?? '', 0, 36);
        if ($workerId === '') {
            $workerId = 'w';
        }
        $nowLit = "'" . $this->db->idate(time()) . "'";
        $upd = 'UPDATE ' . $this->table('execution') . ' SET ';
        $upd .= "status = 'running', ";
        $upd .= "worker_id = '" . $this->db->escape($workerId) . "', ";
        $upd .= 'started_at = ' . $nowLit . ' ';
        $upd .= 'WHERE rowid = ' . (int) $executionId . ' AND entity = ' . (int) $entity . " AND status = 'queued'";

        $updRes = $this->db->query($upd);
        if (!$updRes) {
            return false;
        }

        return (int) $this->db->affected_rows($updRes) === 1;
    }

    /**
     * Increment failure count and either re-queue with backoff or mark terminal error.
     *
     * @param array<string, mixed>|null $errorPayload
     *
     * @return 'requeued'|'terminal_error'
     */
    public function recordFailureAndScheduleRetry(
        int $executionId,
        int $entity,
        string $errorMessage,
        int $maxAttempts,
        string $backoffStrategy,
        ?array $errorPayload = null,
    ): string {
        $row = $this->fetchOne($executionId, $entity);
        if ($row === null) {
            return 'terminal_error';
        }

        $maxAttempts = max(1, min(50, $maxAttempts));
        $backoffStrategy = $this->normaliseBackoffStrategy($backoffStrategy);
        $failures = (int) ($row['retryCount'] ?? 0) + 1;
        $nowLit = "'" . $this->db->idate(time()) . "'";

        $payloadSql = $this->sqlErrorPayloadLiteral($errorPayload);

        if ($failures >= $maxAttempts) {
            $sql = 'UPDATE ' . $this->table('execution') . ' SET ';
            $sql .= "status = 'error', ";
            $sql .= 'retry_count = ' . $failures . ', ';
            $sql .= "error_message = '" . $this->db->escape($errorMessage) . "', ";
            $sql .= 'error_payload = ' . $payloadSql . ', ';
            $sql .= 'ended_at = ' . $nowLit . ', ';
            $sql .= 'worker_id = NULL ';
            $sql .= 'WHERE rowid = ' . (int) $executionId . ' AND entity = ' . (int) $entity;

            $this->db->query($sql);

            return 'terminal_error';
        }

        $delay = ExecutionBackoff::delaySecondsBeforeNextAttempt($failures, $backoffStrategy);
        $nextTs = time() + $delay;
        $nextLit = "'" . $this->db->idate($nextTs) . "'";

        $sql = 'UPDATE ' . $this->table('execution') . ' SET ';
        $sql .= "status = 'queued', ";
        $sql .= 'retry_count = ' . $failures . ', ';
        $sql .= 'next_retry_at = ' . $nextLit . ', ';
        $sql .= "error_message = '" . $this->db->escape($errorMessage) . "', ";
        $sql .= 'error_payload = ' . $payloadSql . ', ';
        $sql .= 'worker_id = NULL, ';
        $sql .= 'started_at = NULL, ';
        $sql .= 'ended_at = NULL ';
        $sql .= 'WHERE rowid = ' . (int) $executionId . ' AND entity = ' . (int) $entity;

        $this->db->query($sql);

        return 'requeued';
    }

    /**
     * Delete terminal error rows older than N days (manual purge).
     */
    public function purgeFailuresOlderThan(int $entity, int $daysOld): int
    {
        $daysOld = max(1, min(3650, $daysOld));
        $sql = 'DELETE FROM ' . $this->table('execution') . ' ';
        $sql .= 'WHERE entity = ' . (int) $entity . " AND status = 'error' ";
        $sql .= " AND ended_at IS NOT NULL AND ended_at < DATE_SUB(NOW(), INTERVAL " . (int) $daysOld . ' DAY)';

        $res = $this->db->query($sql);
        if (!$res) {
            return 0;
        }

        return (int) $this->db->affected_rows($res);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function topRetryRows(int $entity, int $limit = 15): array
    {
        $limit = max(1, min(100, $limit));
        $ex = $this->table('execution');
        $wf = $this->table('workflow');
        $sql = 'SELECT e.rowid, e.fk_workflow, e.status, e.retry_count, e.error_message, e.ended_at, e.next_retry_at, ';
        $sql .= 'w.label AS workflow_label, w.ref AS workflow_ref ';
        $sql .= 'FROM ' . $ex . ' e ';
        $sql .= 'LEFT JOIN ' . $wf . ' w ON w.rowid = e.fk_workflow AND w.entity = e.entity ';
        $sql .= 'WHERE e.entity = ' . (int) $entity . ' AND e.retry_count > 0 ';
        $sql .= 'ORDER BY e.retry_count DESC, e.ended_at DESC ';
        $sql .= $this->db->plimit($limit);

        $res = $this->db->query($sql);
        if (!$res) {
            return [];
        }

        $rows = [];
        while ($obj = $this->db->fetch_object($res)) {
            $rows[] = [
                'id' => (int) $obj->rowid,
                'workflowId' => (int) $obj->fk_workflow,
                'workflowLabel' => isset($obj->workflow_label) && $obj->workflow_label !== ''
                    ? (string) $obj->workflow_label : null,
                'workflowRef' => isset($obj->workflow_ref) && $obj->workflow_ref !== ''
                    ? (string) $obj->workflow_ref : null,
                'status' => (string) $obj->status,
                'retryCount' => (int) $obj->retry_count,
                'errorMessage' => $obj->error_message !== null ? (string) $obj->error_message : null,
                'endedAt' => $obj->ended_at !== null ? (string) $obj->ended_at : null,
                'nextRetryAt' => $obj->next_retry_at !== null ? (string) $obj->next_retry_at : null,
            ];
        }

        return $rows;
    }

    /**
     * Queued executions grouped by workflow (MySQL-native queue), with labels.
     *
     * @return array<int, array{workflowId: int, queuedCount: int, workflowLabel: string|null, workflowRef: string|null}>
     */
    public function queuedAggregatedByWorkflow(int $entity): array
    {
        $ex = $this->table('execution');
        $wf = $this->table('workflow');
        $sql = 'SELECT e.fk_workflow AS fk_workflow, COUNT(*) AS queued_count, ';
        $sql .= 'MAX(w.label) AS workflow_label, MAX(w.ref) AS workflow_ref ';
        $sql .= 'FROM ' . $ex . ' e ';
        $sql .= 'LEFT JOIN ' . $wf . ' w ON w.rowid = e.fk_workflow AND w.entity = e.entity ';
        $sql .= 'WHERE e.entity = ' . (int) $entity . " AND e.status = 'queued' ";
        $sql .= 'GROUP BY e.fk_workflow ';
        $sql .= 'ORDER BY queued_count DESC, workflow_label ASC';

        $res = $this->db->query($sql);
        if (!$res) {
            return [];
        }

        $rows = [];
        while ($obj = $this->db->fetch_object($res)) {
            $label = isset($obj->workflow_label) && $obj->workflow_label !== ''
                ? (string) $obj->workflow_label : null;
            $ref = isset($obj->workflow_ref) && $obj->workflow_ref !== ''
                ? (string) $obj->workflow_ref : null;
            $rows[] = [
                'workflowId' => (int) $obj->fk_workflow,
                'queuedCount' => (int) $obj->queued_count,
                'workflowLabel' => $label,
                'workflowRef' => $ref,
            ];
        }

        return $rows;
    }

    /**
     * Allowed GET `status` values for {@see list()} (includes aggregate `failed`).
     *
     * @var list<string>
     */
    private const EXECUTION_LIST_STATUS_WHITELIST = [
        'queued', 'running', 'success', 'error', 'timeout', 'cancelled', 'failed',
    ];

    /**
     * @return list<string>
     */
    public static function executionListStatusWhitelist(): array
    {
        return self::EXECUTION_LIST_STATUS_WHITELIST;
    }

    /**
     * List executions for an entity, optionally filtered by workflow id and status.
     *
     * `$statusFilter` must be null or one of {@see executionListStatusWhitelist()}.
     * The aggregate value `failed` matches rows with status `error` or `timeout`.
     *
     * @return array<int, array<string, mixed>>
     */
    public function list(
        int $entity,
        ?int $workflowId = null,
        int $limit = 50,
        int $offset = 0,
        ?string $statusFilter = null,
    ): array {
        $ex = $this->table('execution');
        $wf = $this->table('workflow');
        $sql = 'SELECT e.rowid, e.fk_workflow, e.status, e.trigger_type, e.trigger_data, e.retry_count, ';
        $sql .= 'e.priority, e.scheduled_at, e.next_retry_at, e.max_attempts, e.backoff_strategy, e.worker_id, ';
        $sql .= 'e.started_at, e.ended_at, e.error_message, e.error_payload, e.duration_ms, ';
        $sql .= 'w.label AS workflow_label, w.ref AS workflow_ref ';
        $sql .= 'FROM ' . $ex . ' e ';
        $sql .= 'LEFT JOIN ' . $wf . ' w ON w.rowid = e.fk_workflow AND w.entity = e.entity ';
        $sql .= 'WHERE e.entity = ' . (int) $entity;
        if ($workflowId !== null) {
            $sql .= ' AND e.fk_workflow = ' . (int) $workflowId;
        }
        $normStatus = $statusFilter !== null ? strtolower(trim($statusFilter)) : '';
        if ($normStatus !== '') {
            if ($normStatus === 'failed') {
                $sql .= " AND e.status IN ('error','timeout')";
            } elseif (in_array($normStatus, ['queued', 'running', 'success', 'error', 'timeout', 'cancelled'], true)) {
                $sql .= " AND e.status = '" . $this->db->escape($normStatus) . "'";
            }
        }
        $sql .= ' ORDER BY e.rowid DESC ';
        $sql .= $this->db->plimit($limit, $offset);

        $res = $this->db->query($sql);
        if (!$res) {
            return [];
        }

        $rows = [];
        while ($obj = $this->db->fetch_object($res)) {
            $rows[] = $this->mapExecutionObject($obj);
        }

        return $rows;
    }

    /**
     * Count executions started since a given Unix timestamp. Used by
     * the healthcheck to surface a 24 h activity gauge without scanning
     * the entire history.
     */
    public function countSince(int $entity, int $sinceTimestamp): int
    {
        $sql = 'SELECT COUNT(*) AS total FROM ' . $this->table('execution');
        $sql .= ' WHERE entity = ' . (int) $entity;
        $sql .= " AND started_at >= '" . $this->db->idate($sinceTimestamp) . "'";
        $res = $this->db->query($sql);
        if (!$res) {
            return 0;
        }
        $obj = $this->db->fetch_object($res);
        return $obj ? (int) $obj->total : 0;
    }

    /**
     * Aggregate counts by status for an entity (all time, grouped by DB status).
     *
     * Includes a derived **`failed`** key (error + timeout) so dashboards that
     * label the card "Failed" stay aligned with rows stored as `status = error`
     * or `timeout` (there is no `failed` value in the database).
     *
     * @return array<string, int>
     */
    public function statusCounts(int $entity): array
    {
        $sql = 'SELECT status, COUNT(*) AS total ';
        $sql .= 'FROM ' . $this->table('execution') . ' ';
        $sql .= 'WHERE entity = ' . (int) $entity . ' GROUP BY status';
        $res = $this->db->query($sql);
        $counts = ['queued' => 0, 'running' => 0, 'success' => 0, 'error' => 0, 'timeout' => 0, 'cancelled' => 0];
        if (!$res) {
            $counts['failed'] = 0;

            return $counts;
        }
        while ($obj = $this->db->fetch_object($res)) {
            $counts[(string) $obj->status] = (int) $obj->total;
        }
        $counts['failed'] = (int) ($counts['error'] ?? 0) + (int) ($counts['timeout'] ?? 0);

        return $counts;
    }

    /**
     * Mark an execution status.
     *
     * @param array<string, mixed>|null $errorPayload
     */
    public function markStatus(int $executionId, string $status, int $entity, ?string $errorMessage = null, ?array $errorPayload = null): bool
    {
        $sql = 'UPDATE ' . $this->table('execution') . ' SET ';
        $sql .= "status = '" . $this->db->escape($status) . "'";
        if ($status === 'running') {
            $sql .= ", started_at = '" . $this->db->idate(time()) . "'";
        }
        if ($status === 'success') {
            $sql .= ', error_payload = NULL';
        }
        if (in_array($status, ['success', 'error', 'timeout', 'cancelled'], true)) {
            $sql .= ", ended_at = '" . $this->db->idate(time()) . "'";
            $sql .= ', worker_id = NULL';
        }
        if ($errorMessage !== null) {
            $sql .= ", error_message = '" . $this->db->escape($errorMessage) . "'";
            if ($errorPayload !== null) {
                $sql .= ', error_payload = ' . $this->sqlErrorPayloadLiteral($errorPayload);
            } else {
                $sql .= ', error_payload = NULL';
            }
        }
        $sql .= ' WHERE rowid = ' . (int) $executionId . ' AND entity = ' . (int) $entity;

        return (bool) $this->db->query($sql);
    }

    /**
     * Fetch a single execution row.
     *
     * @return array<string, mixed>|null
     */
    public function fetchOne(int $executionId, int $entity): ?array
    {
        $ex = $this->table('execution');
        $wf = $this->table('workflow');
        $sql = 'SELECT e.rowid, e.fk_workflow, e.status, e.trigger_type, e.trigger_data, e.retry_count, ';
        $sql .= 'e.priority, e.scheduled_at, e.next_retry_at, e.max_attempts, e.backoff_strategy, e.worker_id, ';
        $sql .= 'e.started_at, e.ended_at, e.error_message, e.error_payload, e.duration_ms, ';
        $sql .= 'w.label AS workflow_label, w.ref AS workflow_ref ';
        $sql .= 'FROM ' . $ex . ' e ';
        $sql .= 'LEFT JOIN ' . $wf . ' w ON w.rowid = e.fk_workflow AND w.entity = e.entity ';
        $sql .= 'WHERE e.rowid = ' . (int) $executionId . ' AND e.entity = ' . (int) $entity;

        $res = $this->db->query($sql);
        if (!$res || $this->db->num_rows($res) === 0) {
            return null;
        }
        $obj = $this->db->fetch_object($res);

        return $this->mapExecutionObject($obj);
    }

    /**
     * Count the executions currently flagged as `running` for a workflow,
     * scoped to an entity. Used by the cron worker to enforce
     * `single_instance` workflows: while one run is still alive, queued
     * runs are deferred to the next tick instead of overlapping.
     *
     * @param int $excludeExecutionId Optional execution id to ignore (typically
     *                                 the one we're about to start).
     */
    public function countRunningForWorkflow(int $workflowId, int $entity, int $excludeExecutionId = 0): int
    {
        $sql = 'SELECT COUNT(*) AS n FROM ' . $this->table('execution') . ' '
            . 'WHERE fk_workflow = ' . (int) $workflowId
            . ' AND entity = ' . (int) $entity
            . " AND status = 'running'";
        if ($excludeExecutionId > 0) {
            $sql .= ' AND rowid <> ' . (int) $excludeExecutionId;
        }
        $res = $this->db->query($sql);
        if (!$res) {
            return 0;
        }
        $row = $this->db->fetch_object($res);
        return $row ? (int) $row->n : 0;
    }

    /**
     * Cancel a queued or running execution.
     */
    public function cancel(int $executionId, int $entity): bool
    {
        $sql = 'UPDATE ' . $this->table('execution') . ' SET ';
        $sql .= "status = 'cancelled', ";
        $sql .= "ended_at = '" . $this->db->idate(time()) . "', ";
        $sql .= "error_message = 'Cancelled by user', ";
        $sql .= 'error_payload = NULL, ';
        $sql .= 'worker_id = NULL ';
        $sql .= 'WHERE rowid = ' . (int) $executionId . ' AND entity = ' . (int) $entity . ' ';
        $sql .= "AND status IN ('queued','running')";

        return (bool) $this->db->query($sql);
    }

    /**
     * Re-enqueue a finished execution by cloning its trigger data.
     * Returns the new execution id, or 0 on failure.
     */
    public function retry(int $executionId, int $entity): int
    {
        $existing = $this->fetchOne($executionId, $entity);
        if ($existing === null) {
            return 0;
        }
        $triggerData = is_array($existing['triggerData'] ?? null) ? $existing['triggerData'] : [];
        unset($triggerData['_idempotencyKey']);
        $triggerData['_retriedFrom'] = $executionId;
        $triggerData['_retriedAt'] = date(DATE_ATOM);

        $prio = (int) ($existing['priority'] ?? 5);
        $maxA = (int) ($existing['maxAttempts'] ?? 3);
        $bos = (string) ($existing['backoffStrategy'] ?? 'exponential');

        return $this->enqueue(
            (int) $existing['workflowId'],
            (string) ($existing['triggerType'] ?? 'manual'),
            $triggerData,
            $entity,
            $prio,
            null,
            $maxA,
            $bos
        );
    }

    /**
     * Reset a queued execution back to queued status (used by run-now to bypass started_at).
     */
    public function resetToQueued(int $executionId, int $entity): bool
    {
        $sql = 'UPDATE ' . $this->table('execution') . ' SET ';
        $sql .= "status = 'queued', started_at = NULL, ended_at = NULL, error_message = NULL, ";
        $sql .= 'error_payload = NULL, ';
        $sql .= 'worker_id = NULL, next_retry_at = NULL ';
        $sql .= 'WHERE rowid = ' . (int) $executionId . ' AND entity = ' . (int) $entity;

        return (bool) $this->db->query($sql);
    }

    /**
     * @return array<string, mixed>
     */
    private function mapExecutionObject(object $obj): array
    {
        return [
            'id' => (int) $obj->rowid,
            'workflowId' => (int) $obj->fk_workflow,
            'status' => (string) $obj->status,
            'triggerType' => (string) $obj->trigger_type,
            'triggerData' => json_decode((string) $obj->trigger_data, true) ?: [],
            'retryCount' => (int) $obj->retry_count,
            'priority' => isset($obj->priority) ? (int) $obj->priority : 5,
            'scheduledAt' => isset($obj->scheduled_at) ? (string) $obj->scheduled_at : null,
            'nextRetryAt' => isset($obj->next_retry_at) ? (string) $obj->next_retry_at : null,
            'maxAttempts' => isset($obj->max_attempts) ? (int) $obj->max_attempts : 3,
            'backoffStrategy' => isset($obj->backoff_strategy) ? (string) $obj->backoff_strategy : 'exponential',
            'workerId' => isset($obj->worker_id) ? (string) $obj->worker_id : null,
            'startedAt' => $obj->started_at !== null ? (string) $obj->started_at : null,
            'endedAt' => $obj->ended_at !== null ? (string) $obj->ended_at : null,
            'errorMessage' => $obj->error_message !== null ? (string) $obj->error_message : null,
            'errorPayload' => $this->decodeErrorPayloadColumn($obj),
            'durationMs' => $obj->duration_ms !== null ? (int) $obj->duration_ms : null,
            'workflowLabel' => isset($obj->workflow_label) && $obj->workflow_label !== ''
                ? (string) $obj->workflow_label : null,
            'workflowRef' => isset($obj->workflow_ref) && $obj->workflow_ref !== ''
                ? (string) $obj->workflow_ref : null,
        ];
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    private function sqlErrorPayloadLiteral(?array $payload): string
    {
        $encoded = ExecutionErrorPayloadCodec::encode($payload);
        if ($encoded === null || $encoded === '') {
            return 'NULL';
        }

        return "'" . $this->db->escape($encoded) . "'";
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeErrorPayloadColumn(object $obj): ?array
    {
        if (!isset($obj->error_payload) || $obj->error_payload === '') {
            return null;
        }
        $decoded = json_decode((string) $obj->error_payload, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function normaliseBackoffStrategy(string $strategy): string
    {
        $s = strtolower(trim($strategy));

        return in_array($s, ['exponential', 'linear', 'fixed'], true) ? $s : 'exponential';
    }
}
