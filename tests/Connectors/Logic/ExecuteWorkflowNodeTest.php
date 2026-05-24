<?php

declare(strict_types=1);

namespace Knot\Tests\Connectors\Logic;

use Knot\Connectors\Logic\ExecuteWorkflowNode;
use Knot\Tests\Support\InMemoryWorkflowDb;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ExecuteWorkflowNodeTest extends TestCase
{
    private function bootGlobals(): void
    {
        $GLOBALS['conf'] = (object) ['entity' => 1];
        $GLOBALS['db'] = new class extends \DoliDB {
            public function query(string $sql)
            {
                return false;
            }
        };
    }

    public function testValidateRequiresWorkflowId(): void
    {
        $node = new ExecuteWorkflowNode();
        self::assertFalse($node->validate([])['valid']);
        self::assertTrue($node->validate(['workflowId' => 3])['valid']);
    }

    public function testExecuteRejectsSelfReference(): void
    {
        $this->bootGlobals();
        $node = new ExecuteWorkflowNode();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cannot execute itself');
        $node->execute([
            'node' => ['config' => ['workflowId' => 5]],
            'workflow' => ['id' => 5],
        ]);
    }

    public function testExecuteRejectsCycleInWorkflowStack(): void
    {
        $this->bootGlobals();
        $node = new ExecuteWorkflowNode();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cycle detected');
        $node->execute([
            'node' => ['config' => ['workflowId' => 9]],
            'workflow' => ['id' => 1],
            'workflowStack' => [9],
        ]);
    }

    public function testExecuteRejectsRecursionDepthLimit(): void
    {
        $this->bootGlobals();
        $node = new ExecuteWorkflowNode();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('recursion limit');
        $node->execute([
            'node' => ['config' => ['workflowId' => 2]],
            'workflow' => ['id' => 1],
            'execution' => ['depth' => 10],
        ]);
    }

    public function testExecuteRejectsMissingSubWorkflow(): void
    {
        $this->bootGlobals();
        $node = new ExecuteWorkflowNode();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not found or inactive');
        $node->execute([
            'node' => ['config' => ['workflowId' => 404]],
            'workflow' => ['id' => 1],
        ]);
    }

    public function testSimulateReturnsDryRunNote(): void
    {
        $node = new ExecuteWorkflowNode();
        $out = $node->simulate([
            'node' => ['config' => ['workflowId' => 7, 'input' => ['k' => 'v']]],
        ]);

        self::assertTrue($out['_dryRun']);
        self::assertSame(7, $out['workflowId']);
        self::assertSame(['k' => 'v'], $out['input']);
    }

    public function testTestReturnsValidatedSuccess(): void
    {
        $node = new ExecuteWorkflowNode();
        $out = $node->test(['workflowId' => 4]);
        self::assertTrue($out['valid']);
        self::assertTrue($out['success']);
    }

    public function testExecuteRunsActiveSubWorkflow(): void
    {
        $GLOBALS['conf'] = (object) ['entity' => 1];
        $db = new InMemoryWorkflowDb();
        $GLOBALS['db'] = $db;

        $childDefinition = [
            'schemaVersion' => '1.0',
            'workflow' => ['id' => 2, 'label' => 'Child flow'],
            'nodes' => [
                ['id' => 't1', 'type' => 'trigger.manual', 'config' => []],
                [
                    'id' => 's1',
                    'type' => 'logic.set',
                    'config' => ['values' => ['childFlag' => true]],
                ],
            ],
            'edges' => [
                ['source' => 't1', 'target' => 's1'],
            ],
        ];

        $db->workflows[2] = [
            'rowid' => 2,
            'ref' => 'WF-CHILD',
            'label' => 'Child flow',
            'description' => '',
            'status' => 'active',
            'json_definition' => json_encode($childDefinition, JSON_THROW_ON_ERROR),
            'version_schema' => '1.0',
            'entity' => 1,
            'single_instance' => 0,
            'activation_warning_dismissed' => 0,
            'fk_user_creat' => 1,
            'fk_user_modif' => null,
            'date_creation' => '2026-01-01 00:00:00',
            'tms' => '2026-01-01 00:00:00',
        ];

        $node = new ExecuteWorkflowNode();
        $out = $node->execute([
            'node' => [
                'id' => 'exec1',
                'config' => ['workflowId' => 2, 'input' => ['seed' => 1]],
            ],
            'workflow' => ['id' => 1],
            'json' => ['parent' => true],
            'execution' => ['id' => 99, 'depth' => 0],
        ]);

        self::assertSame(2, $out['workflowId']);
        self::assertSame('Child flow', $out['workflowLabel']);
        self::assertSame(['seed' => 1], $out['input']);
        self::assertTrue($out['output']['childFlag'] ?? false);
        self::assertIsArray($out['logs']);
    }

    public function testExecuteUsesParentJsonWhenInputConfigEmpty(): void
    {
        $GLOBALS['conf'] = (object) ['entity' => 1];
        $db = new InMemoryWorkflowDb();
        $GLOBALS['db'] = $db;

        $childDefinition = [
            'schemaVersion' => '1.0',
            'workflow' => ['id' => 3, 'label' => 'Passthrough'],
            'nodes' => [
                ['id' => 't1', 'type' => 'trigger.manual', 'config' => []],
                ['id' => 's1', 'type' => 'logic.set', 'config' => ['values' => ['seen' => true]]],
            ],
            'edges' => [
                ['source' => 't1', 'target' => 's1'],
            ],
        ];

        $db->workflows[3] = [
            'rowid' => 3,
            'ref' => 'WF-PASS',
            'label' => 'Passthrough',
            'description' => '',
            'status' => 'active',
            'json_definition' => json_encode($childDefinition, JSON_THROW_ON_ERROR),
            'version_schema' => '1.0',
            'entity' => 1,
            'single_instance' => 0,
            'activation_warning_dismissed' => 0,
            'fk_user_creat' => 1,
            'fk_user_modif' => null,
            'date_creation' => '2026-01-01 00:00:00',
            'tms' => '2026-01-01 00:00:00',
        ];

        $node = new ExecuteWorkflowNode();
        $out = $node->execute([
            'node' => ['id' => 'exec2', 'config' => ['workflowId' => 3]],
            'workflow' => ['id' => 1],
            'json' => ['inherited' => 'payload'],
            'execution' => ['depth' => 0],
        ]);

        self::assertSame(['inherited' => 'payload'], $out['input']);
        self::assertTrue($out['output']['seen'] ?? false);
    }
}
