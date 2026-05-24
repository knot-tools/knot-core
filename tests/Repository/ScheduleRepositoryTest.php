<?php

declare(strict_types=1);

namespace Knot\Tests\Repository;

use Knot\Repository\ScheduleRepository;
use PHPUnit\Framework\TestCase;

final class ScheduleRepositoryTest extends TestCase
{
    public function testSaveInsertAndListForWorkflow(): void
    {
        $db = new InMemoryScheduleDb();
        $repo = new ScheduleRepository($db);

        $id = $repo->save(null, 10, [
            'cronExpression' => '0 * * * *',
            'timezone' => 'Europe/Paris',
            'isActive' => true,
        ], 1);

        self::assertNotNull($id);
        $rows = $repo->listForWorkflow(10, 1);
        self::assertCount(1, $rows);
        self::assertSame('0 * * * *', $rows[0]['cronExpression']);
        self::assertTrue($rows[0]['isActive']);
    }

    public function testSaveUpdateExistingSchedule(): void
    {
        $db = new InMemoryScheduleDb();
        $repo = new ScheduleRepository($db);
        $id = $repo->save(null, 5, ['cronExpression' => '*/5 * * * *', 'isActive' => true], 1);

        $updated = $repo->save($id, 5, ['cronExpression' => '0 0 * * *', 'isActive' => false], 1);
        self::assertSame($id, $updated);
        $row = $repo->listForWorkflow(5, 1)[0];
        self::assertSame('0 0 * * *', $row['cronExpression']);
        self::assertFalse($row['isActive']);
    }

    public function testFetchDueReturnsOnlyMatchingRows(): void
    {
        $db = new InMemoryScheduleDb();
        $db->dueRows = [
            (object) [
                'rowid' => 1,
                'fk_workflow' => 7,
                'cron_expression' => '0 * * * *',
                'timezone' => 'UTC',
            ],
        ];
        $repo = new ScheduleRepository($db);

        $due = $repo->fetchDue(1, 5);
        self::assertCount(1, $due);
        self::assertSame(7, $due[0]['workflowId']);
    }

    public function testMarkRunUpdatesTimestamps(): void
    {
        $db = new InMemoryScheduleDb();
        $db->scheduleRows[3] = [
            'cron_expression' => '0 * * * *',
            'timezone' => 'UTC',
            'entity' => 1,
        ];
        $repo = new ScheduleRepository($db);

        self::assertTrue($repo->markRun(3, 1));
        self::assertNotEmpty($db->updateQueries);
        self::assertStringContainsString('last_run_at', $db->updateQueries[0]);
        self::assertStringContainsString('next_run_at', $db->updateQueries[0]);
    }

    public function testSetActiveForWorkflowAndDeleteForWorkflow(): void
    {
        $db = new InMemoryScheduleDb();
        $repo = new ScheduleRepository($db);

        self::assertTrue($repo->setActiveForWorkflow(12, 1, false));
        self::assertStringContainsString('is_active=0', $db->lastMutationSql ?? '');
        self::assertTrue($repo->deleteForWorkflow(12, 1));
        self::assertStringContainsString('DELETE FROM llx_knot_schedule', $db->lastMutationSql ?? '');
    }

    public function testDeleteSingleSchedule(): void
    {
        $db = new InMemoryScheduleDb();
        $repo = new ScheduleRepository($db);
        self::assertTrue($repo->delete(9, 2));
        self::assertStringContainsString('rowid=9', $db->lastMutationSql ?? '');
        self::assertStringContainsString('entity=2', $db->lastMutationSql ?? '');
    }

    public function testEntityIsolationOnListForWorkflow(): void
    {
        $db = new InMemoryScheduleDb();
        $repo = new ScheduleRepository($db);
        $repo->save(null, 1, ['cronExpression' => '0 * * * *'], 1);

        self::assertSame([], $repo->listForWorkflow(1, 2));
    }
}

final class InMemoryScheduleDb extends \DoliDB
{
    /** @var list<object> */
    public array $dueRows = [];

    /** @var array<int, array<string, mixed>> */
    public array $scheduleRows = [];

    /** @var list<string> */
    public array $updateQueries = [];

    public ?string $lastMutationSql = null;

    /** @var array<int, array<string, mixed>> */
    private array $rows = [];

    private int $nextId = 1;

    /** @var array<int, array<int, mixed>> */
    private array $resultSets = [];

    private int $cursor = 0;

    public function query(string $sql)
    {
        if (str_contains($sql, 'INNER JOIN llx_knot_workflow')) {
            $this->resultSets[++$this->cursor] = ['__list' => $this->dueRows];

            return $this->cursor;
        }

        if (preg_match('/^SELECT cron_expression, timezone FROM llx_knot_schedule WHERE rowid = (\d+) AND entity = (\d+)/', $sql, $m)) {
            $row = $this->scheduleRows[(int) $m[1]] ?? null;
            $this->resultSets[++$this->cursor] = $row === null ? [] : [
                'cron_expression' => $row['cron_expression'],
                'timezone' => $row['timezone'],
            ];

            return $this->cursor;
        }

        if (preg_match('/^UPDATE llx_knot_schedule SET last_run_at/', $sql)) {
            $this->updateQueries[] = $sql;

            return ++$this->cursor;
        }

        if (preg_match('/^UPDATE llx_knot_schedule SET is_active=/', $sql)) {
            $this->lastMutationSql = $sql;

            return ++$this->cursor;
        }

        if (preg_match('/^DELETE FROM llx_knot_schedule WHERE fk_workflow=/', $sql)) {
            $this->lastMutationSql = $sql;

            return ++$this->cursor;
        }

        if (preg_match('/^DELETE FROM llx_knot_schedule WHERE rowid=/', $sql)) {
            $this->lastMutationSql = $sql;

            return ++$this->cursor;
        }

        if (preg_match('/^SELECT rowid, fk_workflow, cron_expression, timezone, is_active/', $sql)) {
            if (!preg_match('/WHERE fk_workflow=(\d+) AND entity=(\d+)/', $sql, $m)) {
                return false;
            }
            $wf = (int) $m[1];
            $entity = (int) $m[2];
            $list = [];
            foreach ($this->rows as $row) {
                if ((int) $row['fk_workflow'] === $wf && (int) $row['entity'] === $entity) {
                    $list[] = $row;
                }
            }
            $this->resultSets[++$this->cursor] = ['__list' => $list];

            return $this->cursor;
        }

        if (preg_match('/^UPDATE llx_knot_schedule SET cron_expression=/', $sql, $m)) {
            if (!preg_match('/WHERE rowid=(\d+) AND entity=(\d+)/', $sql, $where)) {
                return false;
            }
            $id = (int) $where[1];
            if (!isset($this->rows[$id])) {
                return false;
            }
            if (preg_match("/cron_expression='([^']*)'/", $sql, $cron)) {
                $this->rows[$id]['cron_expression'] = $cron[1];
            }
            if (preg_match('/is_active=(\d)/', $sql, $active)) {
                $this->rows[$id]['is_active'] = (int) $active[1];
            }

            return ++$this->cursor;
        }

        if (preg_match('/^INSERT INTO llx_knot_schedule/', $sql)) {
            $id = $this->nextId++;
            preg_match('/fk_workflow, cron_expression, timezone, next_run_at, is_active, entity\)/', $sql);
            preg_match('/VALUES \((\d+), \'([^\']*)\', \'([^\']*)\', \'([^\']*)\', (\d), (\d+)\)/', $sql, $m);
            $this->rows[$id] = [
                'rowid' => $id,
                'fk_workflow' => (int) ($m[1] ?? 0),
                'cron_expression' => $m[2] ?? '0 * * * *',
                'timezone' => $m[3] ?? 'UTC',
                'is_active' => (int) ($m[5] ?? 1),
                'last_run_at' => null,
                'next_run_at' => $m[4] ?? null,
                'entity' => (int) ($m[6] ?? 1),
            ];

            return ++$this->cursor;
        }

        return false;
    }

    public function fetch_object($resource): ?object
    {
        $set = $this->resultSets[$resource] ?? null;
        if ($set === null || $set === []) {
            return null;
        }
        if (isset($set['__list'])) {
            $row = array_shift($this->resultSets[$resource]['__list']);

            return $row === null ? null : (is_object($row) ? $row : (object) $row);
        }

        return (object) $set;
    }

    public function last_insert_id(string $tableName): int
    {
        return $this->nextId - 1;
    }
}
