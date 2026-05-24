<?php

declare(strict_types=1);

namespace Knot\Tests\Repository;

use Knot\Repository\ExecutionRepository;
use PHPUnit\Framework\TestCase;

final class ExecutionRepositoryQueueAggregationTest extends TestCase
{
    public function testTopRetryRowsIncludesWorkflowLabels(): void
    {
        $obj = (object) [
            'rowid' => 99,
            'fk_workflow' => 12,
            'status' => 'error',
            'retry_count' => 3,
            'error_message' => 'boom',
            'ended_at' => '2026-01-02 10:00:00',
            'next_retry_at' => '2026-01-02 11:00:00',
            'workflow_label' => 'Invoice sync',
            'workflow_ref' => 'WF-INV',
        ];

        $db = new class ($obj) extends \DoliDB {
            public function __construct(private object $row)
            {
            }

            public function query(string $sql)
            {
                return new \stdClass();
            }

            public function fetch_object($resource): ?object
            {
                static $done = false;
                if ($done) {
                    return null;
                }
                $done = true;

                return $this->row;
            }

            public function plimit(int $limit, int $offset = 0): string
            {
                return 'LIMIT ' . $limit;
            }
        };

        $repo = new ExecutionRepository($db);
        $rows = $repo->topRetryRows(1, 10);

        self::assertCount(1, $rows);
        self::assertSame('Invoice sync', $rows[0]['workflowLabel']);
        self::assertSame('WF-INV', $rows[0]['workflowRef']);
        self::assertSame(99, $rows[0]['id']);
    }

    public function testQueuedAggregatedByWorkflowMapsRows(): void
    {
        $obj = (object) [
            'fk_workflow' => 44,
            'queued_count' => 7,
            'workflow_label' => 'Cron batch',
            'workflow_ref' => 'WF-CRON',
        ];

        $db = new class ($obj) extends \DoliDB {
            public function __construct(private object $row)
            {
            }

            public function query(string $sql)
            {
                return new \stdClass();
            }

            public function fetch_object($resource): ?object
            {
                static $done = false;
                if ($done) {
                    return null;
                }
                $done = true;

                return $this->row;
            }
        };

        $repo = new ExecutionRepository($db);
        $rows = $repo->queuedAggregatedByWorkflow(1);

        self::assertCount(1, $rows);
        self::assertSame(44, $rows[0]['workflowId']);
        self::assertSame(7, $rows[0]['queuedCount']);
        self::assertSame('Cron batch', $rows[0]['workflowLabel']);
        self::assertSame('WF-CRON', $rows[0]['workflowRef']);
    }
}
