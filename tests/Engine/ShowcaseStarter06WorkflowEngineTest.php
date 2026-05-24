<?php

declare(strict_types=1);

namespace Knot\Tests\Engine;

use Knot\Connectors\Logic\IfElseNode;
use Knot\Connectors\Logic\LoopNode;
use Knot\Connectors\Logic\SetNode;
use Knot\Connectors\Logic\SwitchNode;
use Knot\Engine\ExecutionContext;
use Knot\Engine\WorkflowEngine;
use Knot\Engine\WorkflowValidator;
use PHPUnit\Framework\TestCase;

/**
 * Executes {@see examples/starter/06-showcase-power-logic.knot.json}: deep branching,
 * Loop realIteration, Switch + nested If guards (uses $loop.item after Switch clears $json).
 */
final class ShowcaseStarter06WorkflowEngineTest extends TestCase
{
    /** @var array<string, string> keyed by SKU */
    private const EXPECT_ROUTE_BY_SKU = [
        'BULK-24' => 'BULK_FULL_PALLET',
        'BULK-08' => 'BULK_MIXED_CARTON',
        'RTL-002' => 'RTL_SAMPLE_TRACK',
        'RTL-015' => 'RTL_STANDARD_AUDIT',
        'CLR-042' => 'CLR_AGGRESSIVE_MARKDOWN',
    ];

    public function testStarter06ComputesExclusiveRoutesPerCartLineUsingLoopContexts(): void
    {
        $definition = $this->loadStarter06Definition();
        $engine = new WorkflowEngine(
            new WorkflowValidator(),
            new ExecutionContext(),
            [
                'logic.set' => new SetNode(),
                'logic.if' => new IfElseNode(),
                'logic.switch' => new SwitchNode(),
                'logic.loop' => new LoopNode(),
            ]
        );

        $result = $engine->execute($definition, [], []);

        $items = $result['nodes']['loop_lines']['json']['items'] ?? null;
        self::assertIsArray($items);
        self::assertCount(5, $items);

        $bySku = [];
        foreach ($items as $row) {
            self::assertIsArray($row);
            self::assertArrayHasKey('sku', $row);
            self::assertArrayHasKey('routeTag', $row);
            $bySku[(string) $row['sku']] = (string) $row['routeTag'];
        }
        self::assertSame(self::EXPECT_ROUTE_BY_SKU, $bySku);

        $statusStub = '';
        foreach ($engine->getLogs() as $log) {
            if (($log['nodeId'] ?? null) === 'stub_empty_cart') {
                $statusStub = (string) ($log['status'] ?? '');
                break;
            }
        }
        self::assertSame('skipped', $statusStub, 'Empty-cart stub must remain skipped when items exist.');
    }

    /** @return array<string, mixed> */
    private function loadStarter06Definition(): array
    {
        $root = dirname(__DIR__, 2);
        $path = $root . '/examples/starter/06-showcase-power-logic.knot.json';
        $json = file_get_contents($path);
        self::assertNotFalse($json, 'Starter 06 showcase file missing: ' . $path);
        /** @var array<string, mixed> $envelope */
        $envelope = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $definition = $envelope['workflow']['definition'] ?? null;
        self::assertIsArray($definition);

        return $definition;
    }
}
