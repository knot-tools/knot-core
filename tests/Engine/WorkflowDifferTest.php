<?php

declare(strict_types=1);

namespace Knot\Tests\Engine;

use Knot\Engine\WorkflowDiffer;
use PHPUnit\Framework\TestCase;

final class WorkflowDifferTest extends TestCase
{
    public function testDetectsAddedRemovedAndChangedNodes(): void
    {
        $left = [
            'workflow' => ['label' => 'Old name', 'description' => 'desc'],
            'nodes' => [
                ['id' => 'a', 'type' => 'trigger.manual'],
                ['id' => 'b', 'type' => 'logic.set', 'config' => ['foo' => 1]],
                ['id' => 'c', 'type' => 'logic.if'],
            ],
            'edges' => [
                ['source' => 'a', 'target' => 'b'],
                ['source' => 'b', 'target' => 'c'],
            ],
            'schemaVersion' => '1.0',
        ];
        $right = [
            'workflow' => ['label' => 'New name', 'description' => 'desc'],
            'nodes' => [
                ['id' => 'a', 'type' => 'trigger.manual'],
                ['id' => 'b', 'type' => 'logic.set', 'config' => ['foo' => 2, 'bar' => 'x']],
                ['id' => 'd', 'type' => 'action.email'],
            ],
            'edges' => [
                ['source' => 'a', 'target' => 'b'],
                ['source' => 'b', 'target' => 'd'],
            ],
            'schemaVersion' => '1.0',
        ];

        $diff = (new WorkflowDiffer())->diff($left, $right);

        self::assertSame(1, $diff['summary']['nodesAdded']);
        self::assertSame(1, $diff['summary']['nodesRemoved']);
        self::assertSame(1, $diff['summary']['nodesChanged']);
        self::assertSame(1, $diff['summary']['edgesAdded']);
        self::assertSame(1, $diff['summary']['edgesRemoved']);

        self::assertSame('d', $diff['nodes']['added'][0]['id']);
        self::assertSame('c', $diff['nodes']['removed'][0]['id']);
        self::assertSame('b', $diff['nodes']['changed'][0]['id']);
        self::assertArrayHasKey('config', $diff['nodes']['changed'][0]['patch']);

        self::assertArrayHasKey('label', $diff['meta']['changed']);
        self::assertSame('replace', $diff['meta']['changed']['label']['op']);
    }

    public function testEmptyDiffWhenIdentical(): void
    {
        $def = [
            'workflow' => ['label' => 'Same'],
            'nodes' => [['id' => 'a', 'type' => 'trigger.manual']],
            'edges' => [],
            'schemaVersion' => '1.0',
        ];
        $diff = (new WorkflowDiffer())->diff($def, $def);
        self::assertSame(0, $diff['summary']['nodesAdded']);
        self::assertSame(0, $diff['summary']['nodesRemoved']);
        self::assertSame(0, $diff['summary']['nodesChanged']);
        self::assertSame([], $diff['meta']['changed']);
    }
}
