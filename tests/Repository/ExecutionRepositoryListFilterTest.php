<?php

declare(strict_types=1);

namespace Knot\Tests\Repository;

use Knot\Repository\ExecutionRepository;
use PHPUnit\Framework\TestCase;

final class ExecutionRepositoryListFilterTest extends TestCase
{
    public function testListAddsFailedAggregateClause(): void
    {
        $db = new ExecutionListSqlCaptureDb();
        $repo = new ExecutionRepository($db);
        $repo->list(3, null, 10, 0, 'failed');

        self::assertNotEmpty($db->queries);
        $sql = $db->queries[count($db->queries) - 1];
        self::assertStringContainsString('entity = 3', $sql);
        self::assertStringContainsString("status IN ('error','timeout')", $sql);
    }

    public function testListScopesEntityWhenFilteringByStatus(): void
    {
        $db = new ExecutionListSqlCaptureDb();
        $repo = new ExecutionRepository($db);
        $repo->list(99, null, 5, 0, 'queued');

        $sql = $db->queries[count($db->queries) - 1];
        self::assertStringContainsString('entity = 99', $sql);
        self::assertStringContainsString("status = 'queued'", $sql);
        self::assertStringNotContainsString('entity = 1', $sql);
    }
}

/**
 * Minimal DoliDB stub that records SELECTs issued by ExecutionRepository::list().
 */
final class ExecutionListSqlCaptureDb extends \DoliDB
{
    /** @var list<string> */
    public array $queries = [];

    public function query(string $sql)
    {
        if (str_contains($sql, 'FROM ') && str_contains($sql, 'knot_execution')) {
            $this->queries[] = $sql;

            return new \stdClass();
        }

        return false;
    }

    public function fetch_object($resource): ?object
    {
        return null;
    }

    public function num_rows($resource): int
    {
        return 0;
    }

    public function plimit(int $limit, int $offset = 0): string
    {
        return ' LIMIT ' . $offset . ', ' . $limit;
    }

    public function escape(string $value): string
    {
        return addslashes($value);
    }
}
