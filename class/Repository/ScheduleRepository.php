<?php

declare(strict_types=1);

namespace Knot\Repository;

use Knot\Cron\CronEvaluator;

/**
 * Repository for workflow schedules.
 */
final class ScheduleRepository extends AbstractRepository
{
    /**
     * Fetch due schedules whose parent workflow is currently active.
     *
     * Joining on llx_knot_workflow lets us silently skip schedules whose
     * workflow was disabled/archived, instead of letting the cron worker
     * dequeue them, fail with "workflow inactive" and pollute the
     * Executions list with bogus error rows on every tick.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchDue(int $entity, int $limit = 10): array
    {
        $scheduleTable = $this->table('schedule');
        $workflowTable = $this->table('workflow');

        $sql = "SELECT s.rowid, s.fk_workflow, s.cron_expression, s.timezone ";
        $sql .= "FROM $scheduleTable s ";
        $sql .= "INNER JOIN $workflowTable w ON w.rowid = s.fk_workflow AND w.entity = s.entity ";
        $sql .= "WHERE s.is_active = 1 ";
        $sql .= "AND w.status = 'active' ";
        $sql .= "AND s.entity = " . (int) $entity . ' ';
        $sql .= "AND (s.next_run_at IS NULL OR s.next_run_at <= '" . $this->db->idate(time()) . "') ";
        $sql .= 'ORDER BY s.next_run_at ASC ';
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
                'cronExpression' => (string) $obj->cron_expression,
                'timezone' => (string) $obj->timezone,
            ];
        }

        return $rows;
    }

    /**
     * Mark a schedule as run and compute the real next run from its cron
     * expression in its declared timezone. Falls back to +60s only when the
     * row cannot be read or the expression cannot be parsed — that way a
     * malformed cron does not silently freeze the schedule.
     */
    public function markRun(int $scheduleId, int $entity): bool
    {
        $now = time();
        $next = $now + 60;

        $sel = $this->db->query(
            'SELECT cron_expression, timezone FROM ' . $this->table('schedule')
            . ' WHERE rowid = ' . (int) $scheduleId
            . ' AND entity = ' . (int) $entity
        );
        if ($sel) {
            $row = $this->db->fetch_object($sel);
            if ($row !== null && (string) $row->cron_expression !== '') {
                $next = CronEvaluator::nextRunAfter(
                    (string) $row->cron_expression,
                    (string) ($row->timezone ?: 'UTC'),
                    $now
                );
            }
        }

        $sql = 'UPDATE ' . $this->table('schedule') . ' SET ';
        $sql .= "last_run_at = '" . $this->db->idate($now) . "', ";
        $sql .= "next_run_at = '" . $this->db->idate($next) . "' ";
        $sql .= 'WHERE rowid = ' . (int) $scheduleId . ' AND entity = ' . (int) $entity;

        return (bool) $this->db->query($sql);
    }

    /**
     * Toggle the activation flag of every schedule attached to a workflow.
     *
     * Used when a workflow's status changes: disabling a workflow must
     * also disable its cron schedules so the worker stops trying to
     * dequeue them (which would otherwise pollute the executions list
     * with "Workflow not found or inactive" rows on every tick).
     */
    public function setActiveForWorkflow(int $workflowId, int $entity, bool $active): bool
    {
        $sql = 'UPDATE ' . $this->table('schedule')
            . ' SET is_active=' . ($active ? 1 : 0)
            . ' WHERE fk_workflow=' . (int) $workflowId
            . ' AND entity=' . (int) $entity;
        return (bool) $this->db->query($sql);
    }

    /**
     * Hard-delete every schedule attached to a workflow. Used when a
     * workflow itself is deleted to avoid orphan rows in the schedule table.
     */
    public function deleteForWorkflow(int $workflowId, int $entity): bool
    {
        $sql = 'DELETE FROM ' . $this->table('schedule')
            . ' WHERE fk_workflow=' . (int) $workflowId
            . ' AND entity=' . (int) $entity;
        return (bool) $this->db->query($sql);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForWorkflow(int $workflowId, int $entity): array
    {
        $sql = 'SELECT rowid, fk_workflow, cron_expression, timezone, is_active, last_run_at, next_run_at'
            . ' FROM ' . $this->table('schedule')
            . ' WHERE fk_workflow=' . (int) $workflowId . ' AND entity=' . (int) $entity
            . ' ORDER BY rowid DESC';
        $res = $this->db->query($sql);
        if (!$res) {
            return [];
        }
        $rows = [];
        while ($obj = $this->db->fetch_object($res)) {
            $rows[] = [
                'id' => (int) $obj->rowid,
                'workflowId' => (int) $obj->fk_workflow,
                'cronExpression' => (string) $obj->cron_expression,
                'timezone' => (string) $obj->timezone,
                'isActive' => (bool) $obj->is_active,
                'lastRunAt' => $obj->last_run_at,
                'nextRunAt' => $obj->next_run_at,
            ];
        }
        return $rows;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function save(?int $id, int $workflowId, array $data, int $entity): ?int
    {
        $cron = (string) ($data['cronExpression'] ?? '0 * * * *');
        $tz = (string) ($data['timezone'] ?? 'UTC');
        $active = !empty($data['isActive']) ? 1 : 0;
        $nextTs = CronEvaluator::nextRunAfter($cron, $tz !== '' ? $tz : 'UTC', time());
        $nextDate = $this->db->idate($nextTs);

        if ($id !== null && $id > 0) {
            $sql = 'UPDATE ' . $this->table('schedule')
                . " SET cron_expression='" . $this->db->escape($cron) . "',"
                . " timezone='" . $this->db->escape($tz) . "',"
                . " next_run_at='" . $nextDate . "',"
                . ' is_active=' . $active
                . ' WHERE rowid=' . (int) $id . ' AND entity=' . (int) $entity;
            return $this->db->query($sql) !== false ? $id : null;
        }

        $sql = 'INSERT INTO ' . $this->table('schedule')
            . ' (fk_workflow, cron_expression, timezone, next_run_at, is_active, entity)'
            . ' VALUES (' . (int) $workflowId
            . ", '" . $this->db->escape($cron) . "'"
            . ", '" . $this->db->escape($tz) . "'"
            . ", '" . $nextDate . "'"
            . ', ' . $active . ', ' . (int) $entity . ')';
        if ($this->db->query($sql) === false) {
            return null;
        }
        return (int) $this->db->last_insert_id($this->table('schedule'));
    }

    public function delete(int $id, int $entity): bool
    {
        $sql = 'DELETE FROM ' . $this->table('schedule') . ' WHERE rowid=' . (int) $id . ' AND entity=' . (int) $entity;
        return $this->db->query($sql) !== false;
    }
}
