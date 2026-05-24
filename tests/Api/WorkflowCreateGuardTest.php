<?php

declare(strict_types=1);

namespace Knot\Tests\Api;

use Knot\Api\WorkflowCreateGuard;
use PHPUnit\Framework\TestCase;

final class WorkflowCreateGuardTest extends TestCase
{
    public function testRejectsEmptyImportWithoutLabelOrNodes(): void
    {
        self::assertTrue(WorkflowCreateGuard::rejectsEmptyImport([], null));
        self::assertTrue(WorkflowCreateGuard::rejectsEmptyImport(['status' => 'draft'], ['nodes' => []]));
    }

    public function testAllowsExplicitLabelWithEmptyGraph(): void
    {
        self::assertFalse(WorkflowCreateGuard::rejectsEmptyImport(
            ['label' => 'Untitled workflow'],
            ['nodes' => [], 'edges' => []],
        ));
    }

    public function testAllowsDefinitionWithNodes(): void
    {
        self::assertFalse(WorkflowCreateGuard::rejectsEmptyImport(
            [],
            ['nodes' => [['id' => 'n1', 'type' => 'trigger.manual']], 'edges' => []],
        ));
    }
}
