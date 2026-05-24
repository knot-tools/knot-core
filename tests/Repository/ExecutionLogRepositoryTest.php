<?php

declare(strict_types=1);

namespace Knot\Tests\Repository;

use Knot\Repository\ExecutionLogRepository;
use PHPUnit\Framework\TestCase;

final class ExecutionLogRepositoryTest extends TestCase
{
    public function testAddAndFetchByExecutionRoundTrip(): void
    {
        $db = new InMemoryExecutionLogDb();
        $repo = new ExecutionLogRepository($db);

        self::assertTrue($repo->add(
            100,
            'node-1',
            'logic.if',
            'success',
            ['a' => 1],
            ['b' => 2],
            1,
            1,
            null,
            42
        ));

        $rows = $repo->fetchByExecution(100, 1);
        self::assertCount(1, $rows);
        self::assertSame('node-1', $rows[0]['nodeId']);
        self::assertSame(['a' => 1], $rows[0]['input']);
        self::assertSame(['b' => 2], $rows[0]['output']);
        self::assertSame(42, $rows[0]['durationMs']);
    }

    public function testCountByExecutionScopesEntity(): void
    {
        $db = new InMemoryExecutionLogDb();
        $repo = new ExecutionLogRepository($db);
        $repo->add(5, 'n', 't', 'success', [], [], 1, 1);
        $repo->add(5, 'n2', 't', 'error', [], [], 2, 1, 'boom');

        self::assertSame(2, $repo->countByExecution(5, 1));
        self::assertSame(0, $repo->countByExecution(5, 2));
    }

    public function testFetchByExecutionReturnsEmptyOnQueryFailure(): void
    {
        $db = new InMemoryExecutionLogDb();
        $db->failSelect = true;
        $repo = new ExecutionLogRepository($db);
        self::assertSame([], $repo->fetchByExecution(1, 1));
        self::assertSame(0, $repo->countByExecution(1, 1));
    }
}

final class InMemoryExecutionLogDb extends \DoliDB
{
    /** @var list<array<string, mixed>> */
    public array $logs = [];

    public bool $failSelect = false;

    /** @var array<int, mixed> */
    private array $resultSets = [];

    private int $cursor = 0;

    private int $nextId = 1;

    public function query(string $sql)
    {
        if (preg_match('/^INSERT INTO llx_knot_execution_log /', $sql)) {
            if (preg_match(
                "/VALUES \((\d+),'([^']*)','([^']*)','([^']*)','([^']*)','([^']*)',(\d+|NULL),(NULL|'([^']*)'),'([^']*)',(\d+),(\d+)\)$/",
                $sql,
                $m
            )) {
                $this->logs[] = [
                    'rowid' => $this->nextId++,
                    'fk_execution' => (int) $m[1],
                    'node_id' => $m[2],
                    'node_type' => $m[3],
                    'status' => $m[4],
                    'input_data' => stripcslashes($m[5]),
                    'output_data' => stripcslashes($m[6]),
                    'duration_ms' => $m[7] === 'NULL' ? null : (int) $m[7],
                    'error_message' => $m[8] === 'NULL' ? null : ($m[9] ?? null),
                    'executed_at' => $m[10],
                    'sequence_order' => (int) $m[11],
                    'entity' => (int) $m[12],
                ];
            }

            return ++$this->cursor;
        }

        if (preg_match('/^SELECT COUNT\(\*\) AS cnt FROM llx_knot_execution_log/', $sql)) {
            if ($this->failSelect) {
                return false;
            }
            if (!preg_match('/fk_execution = (\d+) .* entity = (\d+)/', $sql, $m)) {
                return false;
            }
            $exec = (int) $m[1];
            $entity = (int) $m[2];
            $cnt = 0;
            foreach ($this->logs as $idx => $log) {
                if (($log['fk_execution'] ?? null) === $exec && ($log['entity'] ?? null) === $entity) {
                    $cnt++;
                }
            }
            $this->resultSets[++$this->cursor] = ['cnt' => $cnt];

            return $this->cursor;
        }

        if (preg_match('/^SELECT rowid, node_id, node_type, status, input_data, output_data,/', $sql)) {
            if ($this->failSelect) {
                return false;
            }
            if (!preg_match('/fk_execution = (\d+) .* entity = (\d+)/', $sql, $m)) {
                return false;
            }
            $exec = (int) $m[1];
            $entity = (int) $m[2];
            $list = [];
            foreach ($this->logs as $log) {
                if (($log['fk_execution'] ?? null) === $exec && ($log['entity'] ?? null) === $entity) {
                    $list[] = $log;
                }
            }
            usort($list, static fn (array $a, array $b): int => ($a['sequence_order'] ?? 0) <=> ($b['sequence_order'] ?? 0));
            $this->resultSets[++$this->cursor] = ['__list' => $list];

            return $this->cursor;
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

            return $row === null ? null : (object) $row;
        }

        return (object) $set;
    }

    public function idate(int $timestamp): string
    {
        return date('Y-m-d H:i:s', $timestamp);
    }
}
