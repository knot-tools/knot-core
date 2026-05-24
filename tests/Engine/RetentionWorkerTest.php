<?php

declare(strict_types=1);

namespace Knot\Tests\Engine;

use Knot\Engine\RetentionWorker;
use PHPUnit\Framework\TestCase;

final class RetentionWorkerTest extends TestCase
{
    public function testPurgeRecordsAffectedRowsAndIssuesExpectedDeletes(): void
    {
        // Order: execution_log orphan, execution_log retention,
        // executions, audit, idempotency.
        $db = $this->makeSpyDb([3, 7, 12, 4, 2]);
        $worker = new RetentionWorker();
        $deleted = $worker->purge($db, 1);

        self::assertSame(3 + 7 + 12 + 4 + 2, $deleted);
        self::assertSame([], $worker->errors);
        self::assertSame(3 + 7, $worker->lastReport['executionLogsDeleted']);
        self::assertSame(12, $worker->lastReport['executionsDeleted']);
        self::assertSame(4, $worker->lastReport['auditLogsDeleted']);
        self::assertSame(2, $worker->lastReport['idempotencyDeleted']);
        self::assertSame(30, $worker->lastReport['execDaysWindow']);
        self::assertSame(90, $worker->lastReport['auditDaysWindow']);

        $sqls = $db->queries;
        self::assertCount(5, $sqls);
        self::assertStringContainsString('DELETE FROM llx_knot_execution_log', $sqls[0]);
        self::assertStringContainsString('NOT IN (SELECT rowid', $sqls[0]);
        self::assertStringContainsString('DELETE FROM llx_knot_execution_log', $sqls[1]);
        self::assertStringContainsString("status IN ('success', 'error', 'cancelled', 'timeout')", $sqls[1]);
        self::assertStringContainsString('DELETE FROM llx_knot_execution', $sqls[2]);
        self::assertStringContainsString("status IN ('success', 'error', 'cancelled', 'timeout')", $sqls[2]);
        self::assertStringContainsString('DELETE FROM llx_knot_audit_log', $sqls[3]);
        self::assertStringContainsString('DELETE FROM llx_knot_idempotency', $sqls[4]);
    }

    public function testPurgeContinuesEvenWhenSomeQueriesFail(): void
    {
        $db = $this->makeSpyDb([0, 0, 0, 0, 0], failAll: true);
        $worker = new RetentionWorker();
        $deleted = $worker->purge($db, 1);

        self::assertSame(0, $deleted);
        self::assertNotEmpty($worker->errors);
    }

    public function testAuditMinimumRetentionIsEnforcedToThirtyDays(): void
    {
        $GLOBALS['knot_test_globals_int'] = ['KNOT_RETENTION_AUDIT_DAYS' => 1];
        try {
            $db = $this->makeSpyDb([0, 0, 0, 0, 0]);
            $worker = new RetentionWorker();
            $worker->purge($db, 1);

            self::assertSame(30, $worker->lastReport['auditDaysWindow']);
        } finally {
            unset($GLOBALS['knot_test_globals_int']);
        }
    }

    public function testCustomRetentionDaysAreRespectedAboveMinimum(): void
    {
        $GLOBALS['knot_test_globals_int'] = [
            'KNOT_RETENTION_EXECUTION_DAYS' => 7,
            'KNOT_RETENTION_AUDIT_DAYS' => 365,
        ];
        try {
            $db = $this->makeSpyDb([0, 0, 0, 0, 0]);
            $worker = new RetentionWorker();
            $worker->purge($db, 1);

            self::assertSame(7, $worker->lastReport['execDaysWindow']);
            self::assertSame(365, $worker->lastReport['auditDaysWindow']);
        } finally {
            unset($GLOBALS['knot_test_globals_int']);
        }
    }

    /**
     * @param array<int, int> $rowsByQuery
     */
    private function makeSpyDb(array $rowsByQuery, bool $failAll = false): \DoliDB
    {
        return new class($rowsByQuery, $failAll) extends \DoliDB {
            /** @var array<int, string> */
            public array $queries = [];

            public function __construct(
                private array $rowsByQuery,
                private bool $failAll
            ) {}

            public function query(string $sql)
            {
                $this->queries[] = $sql;
                if ($this->failAll) {
                    return false;
                }
                return new \stdClass();
            }

            public function affected_rows(mixed $resultset = null): int
            {
                $idx = max(0, count($this->queries) - 1);
                return (int) ($this->rowsByQuery[$idx] ?? 0);
            }

            public function lasterror(): string
            {
                return 'simulated failure';
            }
        };
    }
}
