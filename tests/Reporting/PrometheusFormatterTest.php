<?php

declare(strict_types=1);

namespace Knot\Tests\Reporting;

use Knot\Reporting\MetricsCollector;
use Knot\Reporting\PrometheusFormatter;
use PHPUnit\Framework\TestCase;

final class PrometheusFormatterTest extends TestCase
{
    public function testFormatsCountersTypeAndHelpHeaders(): void
    {
        $out = PrometheusFormatter::format(
            executions: [['workflow' => 1, 'status' => 'success', 'count' => 42]],
            quantiles: [],
            queue: ['waiting' => 0, 'running' => 0],
        );
        self::assertStringContainsString('# HELP knot_executions_total', $out);
        self::assertStringContainsString('# TYPE knot_executions_total counter', $out);
        self::assertStringContainsString('knot_executions_total{workflow="1",status="success"} 42', $out);
    }

    public function testFormatsDurationsAsSummaryWithSecondsConversion(): void
    {
        $out = PrometheusFormatter::format(
            executions: [],
            quantiles: [['workflow' => 7, 'p50' => 100, 'p95' => 500, 'p99' => 1000, 'count' => 12]],
            queue: ['waiting' => 0, 'running' => 0],
        );
        self::assertStringContainsString('# TYPE knot_execution_duration_seconds summary', $out);
        self::assertStringContainsString('knot_execution_duration_seconds{workflow="7",quantile="0.5"} 0.100', $out);
        self::assertStringContainsString('knot_execution_duration_seconds{workflow="7",quantile="0.99"} 1.000', $out);
        self::assertStringContainsString('knot_execution_duration_seconds_count{workflow="7"} 12', $out);
    }

    public function testEscapesQuotesInExtensionLabel(): void
    {
        $out = PrometheusFormatter::format(
            executions: [],
            quantiles: [],
            queue: ['waiting' => 0, 'running' => 0],
            licenses: [['extension' => 'evil"name', 'valid' => true]],
        );
        self::assertStringContainsString('extension="evil\\"name"', $out);
    }

    public function testQueueDepthGauges(): void
    {
        $out = PrometheusFormatter::format(
            executions: [],
            quantiles: [],
            queue: ['waiting' => 5, 'running' => 2],
        );
        self::assertStringContainsString('knot_queue_depth{status="waiting"} 5', $out);
        self::assertStringContainsString('knot_queue_depth{status="running"} 2', $out);
    }

    public function testQuantileHelperHandlesEdgeCases(): void
    {
        self::assertSame(0, MetricsCollector::quantile([], 0.5));
        self::assertSame(7, MetricsCollector::quantile([7], 0.99));
        $sorted = [10, 20, 30, 40, 50, 60, 70, 80, 90, 100];
        // floor(0.5 * 9) = 4 → sorted[4] = 50
        self::assertSame(50, MetricsCollector::quantile($sorted, 0.5));
        // floor(0.95 * 9) = 8 → sorted[8] = 90
        self::assertSame(90, MetricsCollector::quantile($sorted, 0.95));
        // floor(0.99 * 9) = 8 → sorted[8] = 90 (rank-floor approximation)
        self::assertSame(90, MetricsCollector::quantile($sorted, 0.99));
        // q=1.0 clamps to last element
        self::assertSame(100, MetricsCollector::quantile($sorted, 1.0));
    }
}
