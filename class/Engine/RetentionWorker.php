<?php

/* Copyright (C) 2026 Sébastien Audel (EXIATIS) — Knot Tools™ Core, GPL-3.0-or-later */

declare(strict_types=1);

namespace Knot\Engine;

/**
 * Periodic retention worker.
 *
 * Knot accumulates rows fast in production:
 *  - `llx_knot_execution` + `llx_knot_execution_log` (every workflow run +
 *    every node fires multiple log lines),
 *  - `llx_knot_audit_log` (admin actions, security events),
 *  - `llx_knot_idempotency` (already self-purges on boot, but we kick it
 *    here too as a belt-and-suspenders measure).
 *
 * Without retention the database grows monotonically and SELECT queries
 * on the executions list slow down within a few weeks. This worker is
 * registered as a Dolibarr cron job and runs every 6 hours by default.
 *
 * Retention windows are configurable via Dolibarr global constants
 * (admins can tune them per tenant). Defaults are conservative and align
 * with `docs/security.md` (RGPD: 30 days for executions, 90 days for
 * audit log).
 *
 * - `KNOT_RETENTION_EXECUTION_DAYS` (default 30): age threshold for
 *   `llx_knot_execution` rows in finished states (success/error/cancelled/
 *   timeout). Queued and running rows are NEVER deleted.
 * - `KNOT_RETENTION_EXECUTION_LOG_DAYS` (default 30): same window for
 *   the per-node `llx_knot_execution_log` rows.
 * - `KNOT_RETENTION_AUDIT_DAYS` (default 90): minimum 30 enforced.
 * - `KNOT_RETENTION_BATCH_SIZE` (default 1000): max rows deleted per
 *   tick to avoid long-running DELETEs that hold table locks.
 *
 * Statistics from the last run are exposed via the public properties
 * `lastReport`, `error`, `errors` so Dolibarr's cron UI can surface
 * them and the admin dashboard can show the most recent purge.
 */
final class RetentionWorker
{
    public string $error = '';

    /** @var array<int, string> */
    public array $errors = [];

    /** @var array<string, int|string> */
    public array $lastReport = [];

    /**
     * Cron entry point. Returns the number of rows deleted across all
     * targets so the Dolibarr cron UI shows progress, and 0 on a noop tick.
     */
    public function run(): int
    {
        global $db, $conf;

        if (!isset($db) || !is_object($db)) {
            $this->error = 'No database connection available.';
            return 0;
        }
        $entity = isset($conf->entity) ? (int) $conf->entity : 1;
        return $this->purge($db, $entity);
    }

    /**
     * Perform the actual purge work — extracted so unit tests can call it
     * directly with a stubbed DoliDB without booting the full Dolibarr stack.
     */
    public function purge(\DoliDB $db, int $entity): int
    {
        $this->error = '';
        $this->errors = [];

        $execDays = $this->config('KNOT_RETENTION_EXECUTION_DAYS', 30, 1);
        $logDays = $this->config('KNOT_RETENTION_EXECUTION_LOG_DAYS', $execDays, 1);
        $auditDays = $this->config('KNOT_RETENTION_AUDIT_DAYS', 90, 30);
        $batch = $this->config('KNOT_RETENTION_BATCH_SIZE', 1000, 100);

        $deleted = 0;
        $report = [
            'executionsDeleted' => 0,
            'executionLogsDeleted' => 0,
            'auditLogsDeleted' => 0,
            'idempotencyDeleted' => 0,
            'execDaysWindow' => $execDays,
            'auditDaysWindow' => $auditDays,
            'startedAt' => date(DATE_ATOM),
        ];

        $report['executionLogsDeleted'] = $this->purgeExecutionLogs($db, $entity, $logDays, $batch);
        $deleted += $report['executionLogsDeleted'];

        $report['executionsDeleted'] = $this->purgeExecutions($db, $entity, $execDays, $batch);
        $deleted += $report['executionsDeleted'];

        $report['auditLogsDeleted'] = $this->purgeAuditLogs($db, $entity, $auditDays, $batch);
        $deleted += $report['auditLogsDeleted'];

        $report['idempotencyDeleted'] = $this->purgeIdempotency($db, $entity);
        $deleted += $report['idempotencyDeleted'];

        $report['endedAt'] = date(DATE_ATOM);
        $report['totalDeleted'] = $deleted;
        $this->lastReport = $report;

        return $deleted;
    }

    /**
     * Delete finished execution rows older than the retention window.
     * Queued / running rows are explicitly preserved so a stuck job is
     * never silently dropped — KnotHealthWorker handles those instead.
     */
    private function purgeExecutions(\DoliDB $db, int $entity, int $days, int $batch): int
    {
        $cutoff = $this->cutoffDate($days);
        $sql = 'DELETE FROM ' . MAIN_DB_PREFIX . 'knot_execution '
            . 'WHERE entity = ' . (int) $entity
            . " AND status IN ('success', 'error', 'cancelled', 'timeout') "
            . "AND ((ended_at IS NOT NULL AND ended_at < '" . $cutoff . "')"
            . " OR (ended_at IS NULL AND started_at < '" . $cutoff . "'))"
            . ' LIMIT ' . (int) $batch;

        $res = $db->query($sql);
        if ($res === false) {
            $this->errors[] = 'executions: ' . (string) $db->lasterror();
            return 0;
        }
        return $this->countAffected($db, $res);
    }

    /**
     * Delete execution log lines whose parent execution is gone OR
     * older than the per-log window. The orphan branch is critical
     * because purgeExecutions could have removed the parent row before
     * us if Dolibarr's cron crashes mid-tick.
     */
    private function purgeExecutionLogs(\DoliDB $db, int $entity, int $days, int $batch): int
    {
        $cutoff = $this->cutoffDate($days);

        // Orphan logs (parent execution row already deleted). Use a
        // subquery rather than DELETE…JOIN to keep the SQL portable
        // across MySQL/MariaDB versions that disagree on multi-table
        // DELETE syntax in batched contexts.
        $orphan = 'DELETE FROM ' . MAIN_DB_PREFIX . 'knot_execution_log '
            . 'WHERE fk_execution NOT IN (SELECT rowid FROM ' . MAIN_DB_PREFIX . 'knot_execution) '
            . 'LIMIT ' . (int) $batch;
        $orphanRes = $db->query($orphan);
        $orphanCount = $orphanRes === false ? 0 : $this->countAffected($db, $orphanRes);

        // Old logs whose parent execution has finished and is past retention.
        $sql = 'DELETE FROM ' . MAIN_DB_PREFIX . 'knot_execution_log '
            . 'WHERE fk_execution IN ('
            . 'SELECT rowid FROM ' . MAIN_DB_PREFIX . 'knot_execution '
            . 'WHERE entity = ' . (int) $entity
            . " AND status IN ('success', 'error', 'cancelled', 'timeout')"
            . ') '
            . "AND created_at < '" . $cutoff . "' "
            . 'LIMIT ' . (int) $batch;
        $res = $db->query($sql);
        if ($res === false) {
            $this->errors[] = 'execution_log: ' . (string) $db->lasterror();
            return $orphanCount;
        }
        return $orphanCount + $this->countAffected($db, $res);
    }

    /**
     * Delete audit log entries past their retention window. The window
     * is enforced to a minimum of 30 days even if the admin tries to
     * shorten it — audit must outlive the noisiest rotation.
     */
    private function purgeAuditLogs(\DoliDB $db, int $entity, int $days, int $batch): int
    {
        $cutoff = $this->cutoffDate($days);
        $sql = 'DELETE FROM ' . MAIN_DB_PREFIX . 'knot_audit_log '
            . 'WHERE entity = ' . (int) $entity
            . " AND created_at < '" . $cutoff . "'"
            . ' LIMIT ' . (int) $batch;

        $res = $db->query($sql);
        if ($res === false) {
            $this->errors[] = 'audit_log: ' . (string) $db->lasterror();
            return 0;
        }
        return $this->countAffected($db, $res);
    }

    /**
     * Best-effort idempotency purge — IdempotencyRepository already does
     * this on every boot, but firing it again from the retention worker
     * keeps the table tidy even if no workflow runs for a long time.
     */
    private function purgeIdempotency(\DoliDB $db, int $entity): int
    {
        $sql = 'DELETE FROM ' . MAIN_DB_PREFIX . 'knot_idempotency '
            . 'WHERE entity = ' . (int) $entity
            . ' AND expires_at < NOW() LIMIT 1000';
        $res = $db->query($sql);
        if ($res === false) {
            return 0;
        }
        return $this->countAffected($db, $res);
    }

    private function cutoffDate(int $days): string
    {
        return gmdate('Y-m-d H:i:s', time() - ($days * 86400));
    }

    /**
     * Read a Dolibarr global constant with a numeric default and a hard
     * minimum (so an admin who types "0" does not accidentally disable
     * audit retention).
     */
    private function config(string $name, int $default, int $min): int
    {
        $value = $default;
        if (function_exists('getDolGlobalInt')) {
            $candidate = (int) \getDolGlobalInt($name);
            if ($candidate > 0) {
                $value = $candidate;
            }
        }
        return max($min, $value);
    }

    /**
     * DoliDB::affected_rows() requires a $resultset parameter on every
     * supported Dolibarr version, but the implementation falls back to
     * the connection's last result when it is not a usable handle. We
     * pass the actual handle returned by the DELETE so the count is
     * reliable across MariaDB, MySQL and PostgreSQL drivers.
     */
    private function countAffected(\DoliDB $db, mixed $resultset): int
    {
        try {
            return (int) $db->affected_rows($resultset);
        } catch (\Throwable) {
            return 0;
        }
    }
}
