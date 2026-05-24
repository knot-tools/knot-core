<?php

declare(strict_types=1);

namespace Knot\Tests\Repository;

use Knot\Repository\ExecutionRepository;
use PHPUnit\Framework\TestCase;

final class ExecutionRepositoryStatusCountsTest extends TestCase
{
    public function testStatusCountsAddsFailedAsErrorPlusTimeout(): void
    {
        $db = new class extends \DoliDB {
            private int $row = 0;

            /** @var list<array{status: string, total: int}> */
            private array $rows = [
                ['status' => 'success', 'total' => 512],
                ['status' => 'error', 'total' => 3],
                ['status' => 'timeout', 'total' => 2],
            ];

            public function query(string $sql)
            {
                return new \stdClass();
            }

            public function fetch_object($resource): ?object
            {
                if ($this->row >= count($this->rows)) {
                    return null;
                }
                $r = $this->rows[$this->row];
                $this->row++;

                return (object) ['status' => $r['status'], 'total' => $r['total']];
            }
        };

        $repo = new ExecutionRepository($db);
        $counts = $repo->statusCounts(1);

        self::assertSame(512, $counts['success']);
        self::assertSame(3, $counts['error']);
        self::assertSame(2, $counts['timeout']);
        self::assertSame(5, $counts['failed']);
    }
}
