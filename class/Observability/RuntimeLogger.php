<?php

/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

namespace Knot\Observability;

use Knot\Security\SecretMasker;

/**
 * V2.5.0d — Structured runtime logger for workflow node executions.
 *
 * Writes one JSON object per line to `runtime-YYYY-MM-DD.jsonl` so the
 * file stream is directly compatible with Loki / Promtail, Vector,
 * Filebeat or `jq` ad-hoc queries. The format is intentionally narrow
 * (workflowId, executionId, nodeId, nodeType, status, durationMs,
 * tenant, error) so it stays cheap on disk and predictable across
 * rotations.
 *
 * Robustness invariants:
 *  - the logger MUST NEVER raise from `logNodeExecution()`. Every I/O
 *    error is silently counted in `runtime_log.write_failures` so the
 *    healthcheck can surface the issue without breaking workflow
 *    execution.
 *  - the directory is created idempotently on the first write to keep
 *    the `php -S` / fresh-install paths working without manual setup.
 *  - rotation is opt-in (`rotate()`); the cron worker calls it once per
 *    day. Files older than `RETENTION_DAYS` are unlinked.
 *  - error messages are masked through `SecretMasker` before being
 *    written so credentials never end up in disk logs.
 */
final class RuntimeLogger
{
    public const RETENTION_DAYS = 7;

    private const FAILURE_COUNTER_PATH = '/.runtime-write-failures';

    private readonly SecretMasker $masker;

    public function __construct(
        private readonly string $directory,
        ?SecretMasker $masker = null
    ) {
        $this->masker = $masker ?? new SecretMasker();
    }

    /**
     * Resolve the directory used by Dolibarr / cron entry points to
     * keep the path consistent across CronWorker, healthcheck and
     * test fixtures. Falls back to the documents/knot/logs convention
     * when `KNOT_RUNTIME_LOG_DIR` is not set.
     */
    public static function defaultDirectory(): string
    {
        $configured = function_exists('getDolGlobalString')
            ? (string) getDolGlobalString('KNOT_RUNTIME_LOG_DIR', '')
            : '';
        if ($configured !== '') {
            return $configured;
        }
        $base = defined('DOL_DATA_ROOT') ? (string) constant('DOL_DATA_ROOT') : sys_get_temp_dir();
        return rtrim($base, '/') . '/knot/logs';
    }

    /**
     * Append one event to the daily file. Never throws.
     *
     * @param array{
     *     workflowId?: int|string|null,
     *     executionId?: int|string|null,
     *     nodeId?: string|null,
     *     nodeType?: string|null,
     *     status?: string|null,
     *     durationMs?: int|null,
     *     tenant?: int|string|null,
     *     error?: string|null
     * } $event
     */
    public function logNodeExecution(array $event): void
    {
        try {
            if (!$this->ensureDirectory()) {
                $this->incrementFailureCounter();
                return;
            }

            $payload = [
                'ts' => gmdate('c'),
                'event' => 'node.execution',
                'workflowId' => $this->stringOrNull($event['workflowId'] ?? null),
                'executionId' => $this->stringOrNull($event['executionId'] ?? null),
                'nodeId' => $this->stringOrNull($event['nodeId'] ?? null),
                'nodeType' => $this->stringOrNull($event['nodeType'] ?? null),
                'status' => $this->stringOrNull($event['status'] ?? null),
                'durationMs' => isset($event['durationMs']) ? (int) $event['durationMs'] : null,
                'tenant' => $this->stringOrNull($event['tenant'] ?? null),
                'error' => $this->maskError($event['error'] ?? null),
            ];

            $line = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($line === false) {
                $this->incrementFailureCounter();
                return;
            }

            $file = $this->currentFilePath();
            $written = @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
            if ($written === false) {
                $this->incrementFailureCounter();
            }
        } catch (\Throwable $e) {
            // Never let observability break the workflow.
            $this->incrementFailureCounter();
        }
    }

    /**
     * Delete `runtime-*.jsonl` files older than RETENTION_DAYS. Called
     * once per day by the cron worker. Returns the number of files
     * removed for diagnostics.
     */
    public function rotate(): int
    {
        if (!is_dir($this->directory)) {
            return 0;
        }
        $cutoff = time() - (self::RETENTION_DAYS * 86400);
        $removed = 0;
        $glob = @glob(rtrim($this->directory, '/') . '/runtime-*.jsonl');
        if ($glob === false) {
            return 0;
        }
        foreach ($glob as $path) {
            $mtime = @filemtime($path);
            if ($mtime !== false && $mtime < $cutoff) {
                if (@unlink($path)) {
                    $removed++;
                }
            }
        }
        return $removed;
    }

    /**
     * Number of times `logNodeExecution()` failed to persist since the
     * last reset. Surfaced by the healthcheck endpoint.
     */
    public function failureCount(): int
    {
        $path = rtrim($this->directory, '/') . self::FAILURE_COUNTER_PATH;
        if (!is_file($path)) {
            return 0;
        }
        $raw = @file_get_contents($path);
        return $raw === false ? 0 : (int) trim((string) $raw);
    }

    /**
     * `true` when the directory is writable and we can append. Used by
     * the healthcheck to flag an operator misconfiguration before logs
     * silently disappear in production.
     */
    public function isWritable(): bool
    {
        if (!is_dir($this->directory)) {
            // Try to create on the fly — same path the first write
            // would take. Returns false if the parent is read-only.
            return $this->ensureDirectory() && is_writable($this->directory);
        }
        return is_writable($this->directory);
    }

    public function directory(): string
    {
        return $this->directory;
    }

    /**
     * Disk usage stats for the runtime log directory.
     *
     * Used by `api/health.php` (V2.5.0b-ux-ops, plan chantier 7.I) to
     * surface a warning when JSONL files start to fill up the disk
     * before they hit the rotation horizon. The values are deliberately
     * cheap to compute: a single `glob()` plus per-file `filesize()`.
     *
     * @return array{
     *     totalBytes: int,
     *     bytesToday: int,
     *     fileCount: int,
     *     diskFreeBytes: int|null,
     *     diskTotalBytes: int|null,
     *     diskFreeRatio: float|null
     * }
     */
    public function diskUsageStats(): array
    {
        $stats = [
            'totalBytes' => 0,
            'bytesToday' => 0,
            'fileCount' => 0,
            'diskFreeBytes' => null,
            'diskTotalBytes' => null,
            'diskFreeRatio' => null,
        ];
        if (!is_dir($this->directory)) {
            return $stats;
        }

        $today = gmdate('Y-m-d');
        $glob = @glob(rtrim($this->directory, '/') . '/runtime-*.jsonl');
        if (is_array($glob)) {
            $stats['fileCount'] = count($glob);
            foreach ($glob as $path) {
                $size = @filesize($path);
                if ($size === false) {
                    continue;
                }
                $stats['totalBytes'] += (int) $size;
                if (str_contains(basename($path), $today)) {
                    $stats['bytesToday'] += (int) $size;
                }
            }
        }

        $free = @disk_free_space($this->directory);
        $total = @disk_total_space($this->directory);
        if ($free !== false) {
            $stats['diskFreeBytes'] = (int) $free;
        }
        if ($total !== false && $total > 0) {
            $stats['diskTotalBytes'] = (int) $total;
            if ($free !== false) {
                $stats['diskFreeRatio'] = (float) $free / (float) $total;
            }
        }

        return $stats;
    }

    private function ensureDirectory(): bool
    {
        if (is_dir($this->directory)) {
            return true;
        }
        // Idempotent: dol_mkdir behaves the same way but we don't want
        // a hard dependency on the Dolibarr boot graph for tests.
        return @mkdir($this->directory, 0775, true) || is_dir($this->directory);
    }

    private function currentFilePath(): string
    {
        return rtrim($this->directory, '/') . '/runtime-' . gmdate('Y-m-d') . '.jsonl';
    }

    private function incrementFailureCounter(): void
    {
        $path = rtrim($this->directory, '/') . self::FAILURE_COUNTER_PATH;
        $current = $this->failureCount();
        @file_put_contents($path, (string) ($current + 1), LOCK_EX);
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (string) $value;
    }

    private function maskError(mixed $error): ?string
    {
        if ($error === null) {
            return null;
        }
        $raw = is_string($error) ? $error : (string) $error;
        if ($raw === '') {
            return null;
        }
        $masked = $this->masker->maskString($raw);
        // Truncate aggressive payloads so a single rogue stack trace
        // does not blow up the log line. Loki dislikes lines >256 KB.
        return mb_strlen($masked) > 4096 ? mb_substr($masked, 0, 4096) . '…' : $masked;
    }
}
