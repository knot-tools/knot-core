<?php

declare(strict_types=1);

namespace Knot\Tests\Engine;

use Knot\Engine\HealthWorker;
use PHPUnit\Framework\TestCase;

final class HealthWorkerTest extends TestCase
{
    public function testProcessTransitionsStaleRunningExecutions(): void
    {
        $db = $this->makeSpyDb(staleAffected: 4, statusCounts: ['queued' => 2, 'running' => 1], aggregates: ['success' => 50, 'error' => 3]);
        $worker = new HealthWorker();
        $stale = $worker->process($db, 1);

        self::assertSame(4, $stale);
        self::assertSame(4, $worker->lastReport['staleMarkedAsTimeout']);
        self::assertSame(2, $worker->lastReport['metrics']['queued']);
        self::assertSame(1, $worker->lastReport['metrics']['running']);
        self::assertSame(50, $worker->lastReport['metrics']['success_24h']);
        self::assertSame(3, $worker->lastReport['metrics']['error_24h']);
        self::assertSame([], $worker->errors);

        // The first query is the UPDATE marking stale rows as timeout.
        self::assertStringContainsString("status = 'timeout'", $db->queries[0]);
        self::assertStringContainsString("status = 'running'", $db->queries[0]);
    }

    public function testStaleThresholdHonoursMinimumOfSixtySeconds(): void
    {
        $GLOBALS['knot_test_globals_int'] = ['KNOT_HEALTH_STALE_AFTER_SEC' => 1];
        try {
            $db = $this->makeSpyDb(staleAffected: 0, statusCounts: [], aggregates: []);
            $worker = new HealthWorker();
            $worker->process($db, 1);

            self::assertNotEmpty($worker->lastReport['staleCutoff']);
            $cutoffTs = strtotime($worker->lastReport['staleCutoff']);
            self::assertGreaterThan(0, $cutoffTs);
            // floor=60s; cutoff must be at most ~60s in the past.
            self::assertGreaterThan(time() - 120, $cutoffTs);
        } finally {
            unset($GLOBALS['knot_test_globals_int']);
        }
    }

    public function testMetricsArePersistedAsConstants(): void
    {
        $GLOBALS['knot_test_const_writes'] = [];
        try {
            $db = $this->makeSpyDb(staleAffected: 0, statusCounts: ['queued' => 7], aggregates: ['success' => 9]);
            $worker = new HealthWorker();
            $worker->process($db, 1);

            $written = array_column($GLOBALS['knot_test_const_writes'], 'name');
            self::assertContains('KNOT_HEALTH_QUEUED', $written);
            self::assertContains('KNOT_HEALTH_SUCCESS_24H', $written);
            self::assertContains('KNOT_HEALTH_LAST_RUN_AT', $written);
        } finally {
            unset($GLOBALS['knot_test_const_writes']);
        }
    }

    /**
     * @param array<string, int> $statusCounts
     * @param array<string, int> $aggregates
     */
    private function makeSpyDb(int $staleAffected, array $statusCounts, array $aggregates): \DoliDB
    {
        return new class($staleAffected, $statusCounts, $aggregates) extends \DoliDB {
            /** @var array<int, string> */
            public array $queries = [];

            /** @var array<string, int> */
            private array $cursorRows = [];
            private int $cursorIdx = 0;

            public function __construct(
                private int $staleAffected,
                private array $statusCounts,
                private array $aggregates
            ) {}

            public function query(string $sql)
            {
                $this->queries[] = $sql;
                if (str_contains($sql, "status = 'timeout'")) {
                    return new \stdClass();
                }
                if (str_contains($sql, "status IN ('queued','running')")) {
                    $this->cursorRows = $this->statusCounts;
                    $this->cursorIdx = 0;
                    return new \stdClass();
                }
                if (str_contains($sql, "status IN ('success','error','timeout','cancelled')")) {
                    $this->cursorRows = $this->aggregates;
                    $this->cursorIdx = 0;
                    return new \stdClass();
                }
                return new \stdClass();
            }

            public function affected_rows(mixed $resultset = null): int
            {
                return $this->staleAffected;
            }

            public function fetch_object($resource): ?object
            {
                $keys = array_keys($this->cursorRows);
                if ($this->cursorIdx >= count($keys)) {
                    return null;
                }
                $key = $keys[$this->cursorIdx];
                $value = $this->cursorRows[$key];
                $this->cursorIdx++;
                return (object) ['status' => $key, 'n' => $value];
            }

            public function lasterror(): string
            {
                return 'simulated';
            }
        };
    }
}
