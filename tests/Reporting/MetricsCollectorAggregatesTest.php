<?php

declare(strict_types=1);

namespace Knot\Tests\Reporting;

use DoliDB;
use Knot\Reporting\MetricsCollector;
use PHPUnit\Framework\TestCase;

final class MetricsCollectorAggregatesTest extends TestCase
{
    public function testExecutionsTotalAggregatesByWorkflowAndStatus(): void
    {
        $db = $this->makeDb([
            (object) ['fk_workflow' => 1, 'status' => 'success', 'c' => 5],
            (object) ['fk_workflow' => 1, 'status' => 'error', 'c' => 1],
        ]);
        $collector = new MetricsCollector($db);

        $rows = $collector->executionsTotal(2, strtotime('-1 day'));
        self::assertStringContainsString('entity = 2', $db->lastSql);
        self::assertStringContainsString('date_creation >=', $db->lastSql);
        self::assertSame([
            ['workflow' => 1, 'status' => 'success', 'count' => 5],
            ['workflow' => 1, 'status' => 'error', 'count' => 1],
        ], $rows);
    }

    public function testExecutionsTotalReturnsEmptyWhenQueryFails(): void
    {
        $db = $this->makeDb([], failQuery: true);
        $collector = new MetricsCollector($db);
        self::assertSame([], $collector->executionsTotal(1));
    }

    public function testDurationQuantilesGroupsPerWorkflow(): void
    {
        $db = $this->makeDb([
            (object) ['fk_workflow' => 3, 'duration_ms' => 10],
            (object) ['fk_workflow' => 3, 'duration_ms' => 20],
            (object) ['fk_workflow' => 3, 'duration_ms' => 100],
            (object) ['fk_workflow' => 4, 'duration_ms' => 50],
        ]);
        $collector = new MetricsCollector($db);

        $rows = $collector->durationQuantiles(1);
        self::assertCount(2, $rows);
        $wf3 = array_values(array_filter($rows, static fn (array $r): bool => $r['workflow'] === 3))[0];
        self::assertSame(20, $wf3['p50']);
        self::assertSame(20, $wf3['p95']);
        self::assertSame(20, $wf3['p99']);
        self::assertSame(3, $wf3['count']);
    }

    public function testQueueDepthMapsQueuedAndRunning(): void
    {
        $db = $this->makeDb([
            (object) ['status' => 'queued', 'c' => 4],
            (object) ['status' => 'running', 'c' => 2],
        ]);
        $collector = new MetricsCollector($db);

        self::assertSame(['waiting' => 4, 'running' => 2], $collector->queueDepth(9));
    }

    public function testQueueDepthReturnsZeroesWhenQueryFails(): void
    {
        $db = $this->makeDb([], failQuery: true);
        $collector = new MetricsCollector($db);
        self::assertSame(['waiting' => 0, 'running' => 0], $collector->queueDepth(1));
    }

    public function testFailureHeatmapBucketsByWeekdayAndHour(): void
    {
        $db = $this->makeDb([
            (object) ['dow' => 2, 'hh' => 14, 'c' => 3],
        ]);
        $collector = new MetricsCollector($db);
        $since = strtotime('-7 days');

        $rows = $collector->failureHeatmap(1, $since);
        self::assertStringContainsString("status IN ('error', 'timeout')", $db->lastSql);
        self::assertSame([['weekday' => 2, 'hour' => 14, 'count' => 3]], $rows);
    }

    /**
     * @param list<object> $rows
     */
    private function makeDb(array $rows, bool $failQuery = false): DoliDB
    {
        return new class ($rows, $failQuery) extends DoliDB {
            public string $lastSql = '';

            /** @param list<object> $rows */
            public function __construct(
                private array $rows,
                private bool $failQuery,
            ) {
            }

            public function query(string $sql)
            {
                $this->lastSql = $sql;

                return $this->failQuery ? false : true;
            }

            public function fetch_object($resource): ?object
            {
                return array_shift($this->rows);
            }

            public function idate(int $timestamp): string
            {
                return date('Y-m-d H:i:s', $timestamp);
            }
        };
    }
}
