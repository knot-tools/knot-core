<?php

declare(strict_types=1);

namespace Knot\Tests\Reporting;

use Knot\Reporting\MetricsCollector;
use Knot\Tests\Support\SqliteInstallSchemaDb;
use PHPUnit\Framework\TestCase;

/**
 * Regression: MetricsCollector must run against the real install schema
 * (`sql/llx_knot_execution.sql` / `execution_log`), not a mocked column set.
 *
 * The 2.13.23 empty observability dashboard was caused by querying
 * `date_creation` on `llx_knot_execution`, which only has started_at /
 * ended_at / scheduled_at. Mocked DoliDB unit tests could not catch that.
 */
final class MetricsCollectorInstallSchemaTest extends TestCase
{
    private SqliteInstallSchemaDb $db;

    private MetricsCollector $collector;

    protected function setUp(): void
    {
        $sqlDir = dirname(__DIR__, 2) . '/sql';
        $this->db = SqliteInstallSchemaDb::fromInstallSql($sqlDir, 'execution', 'execution_log');
        $this->seed();
        $this->collector = new MetricsCollector($this->db);
    }

    public function testExecutionsTotalUsesStartedAtAndSucceedsOnInstallSchema(): void
    {
        $since = strtotime('2026-01-01 00:00:00');
        $rows = $this->collector->executionsTotal(1, $since);

        self::assertTrue($this->db->lastQueryOk, $this->db->lastError);
        self::assertStringContainsString('started_at >=', $this->db->lastSql);
        self::assertStringNotContainsString('date_creation', $this->db->lastSql);

        $byStatus = [];
        foreach ($rows as $row) {
            $byStatus[$row['status']] = ($byStatus[$row['status']] ?? 0) + $row['count'];
        }
        // Queued row has NULL started_at → excluded from the time window.
        self::assertArrayNotHasKey('queued', $byStatus);
        self::assertSame(1, $byStatus['success'] ?? 0);
        self::assertSame(1, $byStatus['error'] ?? 0);
    }

    public function testDurationQuantilesSucceedsOnInstallSchema(): void
    {
        $rows = $this->collector->durationQuantiles(1, strtotime('2026-01-01 00:00:00'));
        self::assertTrue($this->db->lastQueryOk, $this->db->lastError);
        self::assertCount(1, $rows);
        self::assertSame(10, $rows[0]['workflow']);
        // success (150) + error (200) both have duration_ms
        self::assertSame(2, $rows[0]['count']);
        self::assertSame(150, $rows[0]['p50']);
        // rank = floor(0.99 * (n-1)) → index 0 for n=2 with this quantile helper
        self::assertSame(150, $rows[0]['p99']);
    }

    public function testQueueDepthIncludesQueuedWithNullStartedAt(): void
    {
        $depth = $this->collector->queueDepth(1);
        self::assertTrue($this->db->lastQueryOk, $this->db->lastError);
        self::assertSame(['waiting' => 1, 'running' => 0], $depth);
    }

    public function testFailureHeatmapBucketsByStartedAt(): void
    {
        $rows = $this->collector->failureHeatmap(1, strtotime('2026-01-01 00:00:00'));
        self::assertTrue($this->db->lastQueryOk, $this->db->lastError);
        self::assertStringContainsString('DAYOFWEEK(started_at)', $this->db->lastSql);
        self::assertCount(1, $rows);
        self::assertSame(1, $rows[0]['count']);
        self::assertSame(14, $rows[0]['hour']);
    }

    public function testNodeObservabilityUsesExecutedAtOnExecutionLog(): void
    {
        $rows = $this->collector->nodeObservabilityByType(1, strtotime('2026-01-01 00:00:00'));
        self::assertTrue($this->db->lastQueryOk, $this->db->lastError);
        self::assertSame('http.request', $rows[0]['node_type']);
        self::assertSame(1, $rows[0]['runs']);
        self::assertSame(0, $rows[0]['errors']);
    }

    private function seed(): void
    {
        // Success — inside window.
        $this->db->exec(
            "INSERT INTO llx_knot_execution
            (fk_workflow, status, started_at, ended_at, duration_ms, entity)
            VALUES (10, 'success', '2026-06-01 10:00:00', '2026-06-01 10:00:01', 150, 1)"
        );
        // Error — inside window (heatmap).
        $this->db->exec(
            "INSERT INTO llx_knot_execution
            (fk_workflow, status, started_at, ended_at, duration_ms, entity)
            VALUES (10, 'error', '2026-06-02 14:30:00', '2026-06-02 14:30:02', 200, 1)"
        );
        // Queued — NULL started_at (must not break filters; excluded from window).
        $this->db->exec(
            "INSERT INTO llx_knot_execution
            (fk_workflow, status, started_at, scheduled_at, entity)
            VALUES (10, 'queued', NULL, '2026-06-03 09:00:00', 1)"
        );
        $this->db->exec(
            "INSERT INTO llx_knot_execution_log
            (fk_execution, node_id, node_type, status, duration_ms, executed_at, entity)
            VALUES (1, 'n1', 'http.request', 'success', 40, '2026-06-01 10:00:00', 1)"
        );
    }
}
