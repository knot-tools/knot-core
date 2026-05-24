<?php

declare(strict_types=1);

namespace Knot\Tests\Repository;

use Knot\Repository\ExecutionRepository;
use Knot\Tests\Support\InMemoryExecutionDb;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Knot\Repository\ExecutionRepository
 */
final class ExecutionRepositoryLifecycleTest extends TestCase
{
    public function testEnqueueFetchClaimAndRetryFlow(): void
    {
        $db = new InMemoryExecutionDb();
        $db->workflows[7] = ['label' => 'Demo flow', 'ref' => 'WF-DEMO'];
        $repo = new ExecutionRepository($db);

        $id = $repo->enqueue(7, 'manual', ['foo' => 'bar'], 1, 9, null, 2, 'linear');
        self::assertSame(1, $id);

        $row = $repo->fetchOne($id, 1);
        self::assertNotNull($row);
        self::assertSame('queued', $row['status']);
        self::assertSame(9, $row['priority']);
        self::assertSame(['foo' => 'bar'], $row['triggerData']);
        self::assertSame('Demo flow', $row['workflowLabel']);

        self::assertTrue($repo->tryClaimExecution($id, 1, 'worker-a'));
        self::assertFalse($repo->tryClaimExecution($id, 1, 'worker-b'));
        self::assertSame('running', $repo->fetchOne($id, 1)['status'] ?? null);

        self::assertSame('requeued', $repo->recordFailureAndScheduleRetry(
            $id,
            1,
            'temporary',
            2,
            'linear',
            ['code' => 'KNOT_TEST']
        ));
        $afterRetry = $repo->fetchOne($id, 1);
        self::assertSame('queued', $afterRetry['status'] ?? null);
        self::assertSame(1, $afterRetry['retryCount'] ?? null);

        self::assertSame('terminal_error', $repo->recordFailureAndScheduleRetry(
            $id,
            1,
            'final',
            2,
            'linear'
        ));
        self::assertSame('error', $repo->fetchOne($id, 1)['status'] ?? null);

        $newId = $repo->retry($id, 1);
        self::assertSame(2, $newId);
        $clone = $repo->fetchOne($newId, 1);
        self::assertSame('queued', $clone['status'] ?? null);
        self::assertSame(1, $clone['triggerData']['_retriedFrom'] ?? null);
    }

    public function testListQueuedCandidatesReturnsEligibleRows(): void
    {
        $db = new InMemoryExecutionDb();
        $db->executions[1] = [
            'rowid' => 1,
            'fk_workflow' => 2,
            'status' => 'queued',
            'trigger_type' => 'manual',
            'trigger_data' => '{}',
            'retry_count' => 0,
            'entity' => 3,
            'priority' => 5,
            'scheduled_at' => null,
            'max_attempts' => 3,
            'backoff_strategy' => 'exponential',
            'worker_id' => null,
            'started_at' => null,
            'ended_at' => null,
            'error_message' => null,
            'error_payload' => null,
            'next_retry_at' => null,
            'duration_ms' => null,
        ];
        $repo = new ExecutionRepository($db);

        $rows = $repo->listQueuedCandidates(3, 10);
        self::assertCount(1, $rows);
        self::assertSame(1, $rows[0]['id']);
    }

    public function testCancelMarksQueuedExecutionCancelled(): void
    {
        $db = new InMemoryExecutionDb();
        $db->executions[4] = [
            'rowid' => 4,
            'fk_workflow' => 1,
            'status' => 'queued',
            'trigger_type' => 'manual',
            'trigger_data' => '{}',
            'retry_count' => 0,
            'entity' => 1,
            'priority' => 5,
            'scheduled_at' => null,
            'max_attempts' => 3,
            'backoff_strategy' => 'exponential',
            'worker_id' => null,
            'started_at' => null,
            'ended_at' => null,
            'error_message' => null,
            'error_payload' => null,
            'next_retry_at' => null,
            'duration_ms' => null,
        ];
        $repo = new ExecutionRepository($db);

        self::assertTrue($repo->cancel(4, 1));
        self::assertSame('cancelled', $db->executions[4]['status']);
    }

    public function testStatusCountsAddsDerivedFailedKey(): void
    {
        $db = new InMemoryExecutionDb();
        $db->executions = [
            1 => ['rowid' => 1, 'status' => 'error', 'entity' => 1],
            2 => ['rowid' => 2, 'status' => 'timeout', 'entity' => 1],
            3 => ['rowid' => 3, 'status' => 'success', 'entity' => 1],
        ];
        $repo = new ExecutionRepository($db);

        $counts = $repo->statusCounts(1);
        self::assertSame(2, $counts['failed']);
        self::assertSame(1, $counts['success']);
    }

    public function testCountSinceAndPurgeFailuresOlderThan(): void
    {
        $db = new InMemoryExecutionDb();
        $db->executions = [
            1 => [
                'rowid' => 1,
                'entity' => 2,
                'status' => 'success',
                'started_at' => '2026-05-01 10:00:00',
            ],
        ];
        $repo = new ExecutionRepository($db);

        self::assertSame(1, $repo->countSince(2, strtotime('2026-05-01 00:00:00')));
        self::assertSame(2, $repo->purgeFailuresOlderThan(2, 30));
    }
}
