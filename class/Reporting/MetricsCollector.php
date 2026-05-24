<?php

declare(strict_types=1);

namespace Knot\Reporting;

use DoliDB;

/**
 * Aggregates execution metrics for the V2.5 observability sprint.
 *
 * Provides families of data:
 *   - executions_total     : counters by workflow + status (success/error/timeout)
 *   - duration_quantiles   : p50, p95, p99 of execution duration_ms by workflow
 *   - queue_depth          : current waiting / running counts
 *   - failure_heatmap      : failures bucketed by weekday × hour (`llx_knot_execution`)
 *   - node_observability   : per-node-type runs/errors/avg duration (`llx_knot_execution_log`)
 *
 * Reads `llx_knot_execution` and `llx_knot_execution_log` with entity scoping.
 * Stateless — every call hits the DB. Cache one minute upstream when needed
 * (Prometheus scrape interval for exported counters).
 */
final class MetricsCollector
{
    public function __construct(
        private readonly DoliDB $db,
        private readonly string $tablePrefix = 'llx_knot_',
    ) {
    }

    /**
     * @return array<int, array{workflow: int, status: string, count: int}>
     */
    public function executionsTotal(int $entity, ?int $sinceUnixTs = null): array
    {
        $sql = 'SELECT fk_workflow, status, COUNT(*) AS c FROM ' . $this->tablePrefix . 'execution';
        $sql .= ' WHERE entity = ' . (int) $entity;
        if ($sinceUnixTs !== null) {
            $sql .= " AND date_creation >= '" . $this->db->idate($sinceUnixTs) . "'";
        }
        $sql .= ' GROUP BY fk_workflow, status';
        $res = $this->db->query($sql);
        if (!$res) {
            return [];
        }
        $out = [];
        while ($row = $this->db->fetch_object($res)) {
            $out[] = [
                'workflow' => (int) $row->fk_workflow,
                'status' => (string) $row->status,
                'count' => (int) $row->c,
            ];
        }
        return $out;
    }

    /**
     * @return array<int, array{workflow: int, p50: int, p95: int, p99: int, count: int}>
     */
    public function durationQuantiles(int $entity, ?int $sinceUnixTs = null): array
    {
        $sql = 'SELECT fk_workflow, duration_ms FROM ' . $this->tablePrefix . 'execution';
        $sql .= ' WHERE entity = ' . (int) $entity;
        $sql .= ' AND duration_ms IS NOT NULL';
        if ($sinceUnixTs !== null) {
            $sql .= " AND date_creation >= '" . $this->db->idate($sinceUnixTs) . "'";
        }
        $sql .= ' ORDER BY fk_workflow ASC, duration_ms ASC';
        $res = $this->db->query($sql);
        if (!$res) {
            return [];
        }
        $byWorkflow = [];
        while ($row = $this->db->fetch_object($res)) {
            $byWorkflow[(int) $row->fk_workflow][] = (int) $row->duration_ms;
        }
        $out = [];
        foreach ($byWorkflow as $wfId => $durations) {
            $count = count($durations);
            $out[] = [
                'workflow' => $wfId,
                'p50' => self::quantile($durations, 0.50),
                'p95' => self::quantile($durations, 0.95),
                'p99' => self::quantile($durations, 0.99),
                'count' => $count,
            ];
        }
        return $out;
    }

    /**
     * @return array{waiting: int, running: int}
     */
    public function queueDepth(int $entity): array
    {
        $sql = 'SELECT status, COUNT(*) AS c FROM ' . $this->tablePrefix . 'execution';
        $sql .= ' WHERE entity = ' . (int) $entity;
        $sql .= " AND status IN ('queued', 'running')";
        $sql .= ' GROUP BY status';
        $res = $this->db->query($sql);
        $out = ['waiting' => 0, 'running' => 0];
        if (!$res) {
            return $out;
        }
        while ($row = $this->db->fetch_object($res)) {
            if ($row->status === 'queued') {
                $out['waiting'] = (int) $row->c;
            } elseif ($row->status === 'running') {
                $out['running'] = (int) $row->c;
            }
        }
        return $out;
    }

    /**
     * Heatmap data: failures bucketed by ISO weekday × hour-of-day.
     *
     * @return array<int, array{weekday: int, hour: int, count: int}>
     */
    public function failureHeatmap(int $entity, int $sinceUnixTs): array
    {
        $sql = 'SELECT DAYOFWEEK(date_creation) AS dow, HOUR(date_creation) AS hh, COUNT(*) AS c';
        $sql .= ' FROM ' . $this->tablePrefix . 'execution';
        $sql .= ' WHERE entity = ' . (int) $entity;
        $sql .= " AND status IN ('error', 'timeout')";
        $sql .= " AND date_creation >= '" . $this->db->idate($sinceUnixTs) . "'";
        $sql .= ' GROUP BY DAYOFWEEK(date_creation), HOUR(date_creation)';
        $res = $this->db->query($sql);
        if (!$res) {
            return [];
        }
        $out = [];
        while ($row = $this->db->fetch_object($res)) {
            $out[] = [
                'weekday' => (int) $row->dow,
                'hour' => (int) $row->hh,
                'count' => (int) $row->c,
            ];
        }
        return $out;
    }

    /**
     * @param array<int, int> $sorted Pre-sorted ascending integers.
     */
    public static function quantile(array $sorted, float $q): int
    {
        $n = count($sorted);
        if ($n === 0) {
            return 0;
        }
        if ($n === 1) {
            return $sorted[0];
        }
        $rank = max(0, min($n - 1, (int) floor($q * ($n - 1))));
        return (int) $sorted[$rank];
    }

    /**
     * Node-level aggregates from `execution_log` (counts + avg duration, no payloads).
     *
     * @return array<int, array{node_type: string, runs: int, errors: int, avg_duration_ms: float|null}>
     */
    public function nodeObservabilityByType(int $entity, ?int $sinceUnixTs = null, int $limitTypes = 40): array
    {
        $errStatus = "'" . $this->db->escape('error') . "'";
        $sql = 'SELECT node_type, COUNT(*) AS runs,';
        $sql .= ' SUM(CASE WHEN status = ' . $errStatus . ' THEN 1 ELSE 0 END) AS err_count,';
        $sql .= ' AVG(duration_ms) AS avg_ms';
        $sql .= ' FROM ' . $this->tablePrefix . 'execution_log';
        $sql .= ' WHERE entity = ' . (int) $entity;
        if ($sinceUnixTs !== null) {
            $sql .= " AND executed_at >= '" . $this->db->idate($sinceUnixTs) . "'";
        }
        $sql .= ' GROUP BY node_type';
        $sql .= ' ORDER BY runs DESC';
        $sql .= ' LIMIT ' . max(1, min(200, $limitTypes));
        $res = $this->db->query($sql);
        if (!$res) {
            return [];
        }
        $out = [];
        while ($row = $this->db->fetch_object($res)) {
            $avg = $row->avg_ms !== null ? (float) $row->avg_ms : null;
            $out[] = [
                'node_type' => (string) $row->node_type,
                'runs' => (int) $row->runs,
                'errors' => (int) $row->err_count,
                'avg_duration_ms' => $avg !== null ? round($avg, 3) : null,
            ];
        }

        return $out;
    }
}
