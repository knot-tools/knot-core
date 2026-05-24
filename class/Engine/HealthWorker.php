<?php

/* Copyright (C) 2026 Sébastien Audel (EXIATIS) — Knot Tools™ Core, GPL-3.0-or-later */

declare(strict_types=1);

namespace Knot\Engine;

/**
 * Health & stale-execution recovery worker.
 *
 * The CronWorker flips an execution to `running` while it boots the
 * engine. If the PHP worker is killed mid-tick (timeout, OOM, deploy)
 * the row is left dangling forever — the next worker tick refuses to
 * start a new instance because of the `single_instance` lock added in
 * V2.2, and the user sees a workflow that "is always running" yet
 * never produces output.
 *
 * HealthWorker fixes both halves of the problem on a 5-minute schedule:
 *
 * 1. Detect rows with `status = 'running'` whose `started_at` is older
 *    than `KNOT_HEALTH_STALE_AFTER_SEC` (default 1800 = 30 min) and
 *    transition them to `status = 'timeout'` with an explicit error
 *    message, so the operator sees what happened and the
 *    single_instance lock is released for the next tick.
 *
 * 2. Snapshot a small set of health metrics into Dolibarr globals
 *    (queue depth, running count, success/error rate over the last
 *    24h). The setup dashboard reads them to surface a "Knot is
 *    healthy" / "Knot has issues" badge without having to query the
 *    database on every page load.
 */
final class HealthWorker
{
    public string $error = '';

    /** @var array<int, string> */
    public array $errors = [];

    /** @var array<string, mixed> */
    public array $lastReport = [];

    public function run(): int
    {
        global $db, $conf;

        if (!isset($db) || !is_object($db)) {
            $this->error = 'No database connection available.';
            return 0;
        }
        $entity = isset($conf->entity) ? (int) $conf->entity : 1;
        return $this->process($db, $entity);
    }

    public function process(\DoliDB $db, int $entity): int
    {
        $this->error = '';
        $this->errors = [];

        $staleAfter = $this->config('KNOT_HEALTH_STALE_AFTER_SEC', 1800, 60);
        $cutoff = gmdate('Y-m-d H:i:s', time() - $staleAfter);

        $stale = $this->markStaleAsTimeout($db, $entity, $cutoff);
        $metrics = $this->snapshotMetrics($db, $entity);

        $this->persistMetrics($db, $entity, $metrics);

        $this->lastReport = [
            'staleMarkedAsTimeout' => $stale,
            'metrics' => $metrics,
            'staleCutoff' => $cutoff,
            'computedAt' => date(DATE_ATOM),
        ];

        return $stale;
    }

    /**
     * Reclaim executions that have been "running" longer than the
     * stale threshold. Returns the number of rows transitioned.
     */
    private function markStaleAsTimeout(\DoliDB $db, int $entity, string $cutoff): int
    {
        $sql = 'UPDATE ' . MAIN_DB_PREFIX . 'knot_execution SET '
            . "status = 'timeout', "
            . "ended_at = NOW(), "
            . "error_message = CONCAT('[health] stale execution reclaimed at ', NOW()) "
            . 'WHERE entity = ' . (int) $entity
            . " AND status = 'running' "
            . "AND started_at IS NOT NULL "
            . "AND started_at < '" . $cutoff . "'";

        $res = $db->query($sql);
        if ($res === false) {
            $this->errors[] = 'mark_stale: ' . (string) $db->lasterror();
            return 0;
        }
        try {
            return (int) $db->affected_rows($res);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Compute lightweight health metrics. We deliberately scope all
     * windows to 24 h so the SELECTs stay cheap even on installs with
     * tens of thousands of executions per day.
     *
     * @return array<string, int>
     */
    private function snapshotMetrics(\DoliDB $db, int $entity): array
    {
        $metrics = [
            'queued' => 0,
            'running' => 0,
            'success_24h' => 0,
            'error_24h' => 0,
            'timeout_24h' => 0,
            'cancelled_24h' => 0,
        ];

        $countSql = 'SELECT status, COUNT(*) AS n FROM ' . MAIN_DB_PREFIX . 'knot_execution '
            . 'WHERE entity = ' . (int) $entity
            . " AND status IN ('queued','running') GROUP BY status";
        $res = $db->query($countSql);
        if ($res !== false) {
            while ($row = $db->fetch_object($res)) {
                $metrics[(string) $row->status] = (int) $row->n;
            }
        }

        $cutoff = gmdate('Y-m-d H:i:s', time() - 86400);
        $aggSql = 'SELECT status, COUNT(*) AS n FROM ' . MAIN_DB_PREFIX . 'knot_execution '
            . 'WHERE entity = ' . (int) $entity
            . " AND status IN ('success','error','timeout','cancelled') "
            . "AND ended_at IS NOT NULL AND ended_at >= '" . $cutoff . "' "
            . 'GROUP BY status';
        $res = $db->query($aggSql);
        if ($res !== false) {
            while ($row = $db->fetch_object($res)) {
                $metrics[$row->status . '_24h'] = (int) $row->n;
            }
        }

        return $metrics;
    }

    /**
     * Persist the snapshot to `llx_const` so the setup page can read
     * it without re-querying. We use one row per metric — Dolibarr
     * already encodes `dolibarr_set_const` to be idempotent so this is
     * cheap. Keys are namespaced under `KNOT_HEALTH_*` so admins can
     * see them in the setup → constants page.
     *
     * @param array<string, int> $metrics
     */
    private function persistMetrics(\DoliDB $db, int $entity, array $metrics): void
    {
        if (!function_exists('dolibarr_set_const')) {
            return;
        }
        foreach ($metrics as $key => $value) {
            $constName = 'KNOT_HEALTH_' . strtoupper($key);
            try {
                \dolibarr_set_const($db, $constName, (string) $value, 'chaine', 0, '', $entity);
            } catch (\Throwable) {
                // Const persistence is best-effort; the snapshot still
                // appears in $this->lastReport for the cron UI.
            }
        }
        try {
            \dolibarr_set_const(
                $db,
                'KNOT_HEALTH_LAST_RUN_AT',
                date('Y-m-d H:i:s'),
                'chaine',
                0,
                '',
                $entity
            );
        } catch (\Throwable) {
            // ignored
        }
    }

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
}
