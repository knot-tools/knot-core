<?php

declare(strict_types=1);

namespace Knot\Tests\Reporting;

use DoliDB;
use Knot\Reporting\MetricsCollector;
use PHPUnit\Framework\TestCase;

final class MetricsCollectorNodeObservabilityTest extends TestCase
{
    public function testNodeObservabilityByTypeBuildsAggregates(): void
    {
        $db = new class extends DoliDB {
            public string $lastSql = '';

            /** @var list<object> */
            public array $rowQueue = [];

            public function query(string $sql)
            {
                $this->lastSql = $sql;

                return true;
            }

            public function fetch_object($resource): ?object
            {
                return array_shift($this->rowQueue);
            }
        };

        $db->rowQueue = [
            (object) ['node_type' => 'http', 'runs' => 10, 'err_count' => 2, 'avg_ms' => 150.25],
            (object) ['node_type' => 'logic.if', 'runs' => 4, 'err_count' => 0, 'avg_ms' => null],
        ];

        $collector = new MetricsCollector($db);
        $rows = $collector->nodeObservabilityByType(3, strtotime('-2 days'), 40);

        self::assertStringContainsString('llx_knot_execution_log', $db->lastSql);
        self::assertStringContainsString('entity = 3', $db->lastSql);
        self::assertStringContainsString('executed_at >=', $db->lastSql);
        self::assertSame(
            [
                [
                    'node_type' => 'http',
                    'runs' => 10,
                    'errors' => 2,
                    'avg_duration_ms' => 150.25,
                ],
                [
                    'node_type' => 'logic.if',
                    'runs' => 4,
                    'errors' => 0,
                    'avg_duration_ms' => null,
                ],
            ],
            $rows,
        );
    }
}
