<?php

declare(strict_types=1);

namespace Knot\Reporting;

/**
 * Formats Knot metrics in the Prometheus text exposition format
 * (`text/plain; version=0.0.4`).
 *
 * Reference:
 * https://prometheus.io/docs/instrumenting/exposition_formats/
 *
 * The formatter is a pure value transformer — no DB, no network. It
 * accepts already-aggregated arrays from {@see MetricsCollector} and
 * returns a string ready to be served by `api/metrics.php`.
 */
final class PrometheusFormatter
{
    /**
     * @param array<int, array{workflow: int, status: string, count: int}> $executions
     * @param array<int, array{workflow: int, p50: int, p95: int, p99: int, count: int}> $quantiles
     * @param array{waiting: int, running: int} $queue
     * @param array<int, array{extension: string, valid: bool}> $licenses
     */
    public static function format(array $executions, array $quantiles, array $queue, array $licenses = []): string
    {
        $lines = [];

        $lines[] = '# HELP knot_executions_total Total Knot workflow executions, by workflow and status.';
        $lines[] = '# TYPE knot_executions_total counter';
        foreach ($executions as $row) {
            $lines[] = sprintf(
                'knot_executions_total{workflow="%d",status="%s"} %d',
                $row['workflow'],
                self::escape((string) $row['status']),
                $row['count']
            );
        }

        $lines[] = '# HELP knot_execution_duration_seconds Workflow execution duration quantiles.';
        $lines[] = '# TYPE knot_execution_duration_seconds summary';
        foreach ($quantiles as $row) {
            $lines[] = sprintf(
                'knot_execution_duration_seconds{workflow="%d",quantile="0.5"} %.3f',
                $row['workflow'],
                $row['p50'] / 1000
            );
            $lines[] = sprintf(
                'knot_execution_duration_seconds{workflow="%d",quantile="0.95"} %.3f',
                $row['workflow'],
                $row['p95'] / 1000
            );
            $lines[] = sprintf(
                'knot_execution_duration_seconds{workflow="%d",quantile="0.99"} %.3f',
                $row['workflow'],
                $row['p99'] / 1000
            );
            $lines[] = sprintf(
                'knot_execution_duration_seconds_count{workflow="%d"} %d',
                $row['workflow'],
                $row['count']
            );
        }

        $lines[] = '# HELP knot_queue_depth Current Knot queue depth.';
        $lines[] = '# TYPE knot_queue_depth gauge';
        $lines[] = sprintf('knot_queue_depth{status="waiting"} %d', $queue['waiting']);
        $lines[] = sprintf('knot_queue_depth{status="running"} %d', $queue['running']);

        if ($licenses !== []) {
            $lines[] = '# HELP knot_extension_license_valid 1 if extension licence currently valid, 0 otherwise.';
            $lines[] = '# TYPE knot_extension_license_valid gauge';
            foreach ($licenses as $row) {
                $lines[] = sprintf(
                    'knot_extension_license_valid{extension="%s"} %d',
                    self::escape((string) $row['extension']),
                    (int) ($row['valid'] ? 1 : 0)
                );
            }
        }

        return implode("\n", $lines) . "\n";
    }

    private static function escape(string $value): string
    {
        return str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $value);
    }
}
