<?php

declare(strict_types=1);

namespace Knot\Tests\Connectors\Logic;

use Knot\Connectors\Logic\ApprovalWaitNode;
use PHPUnit\Framework\TestCase;

final class ApprovalWaitNodeTest extends TestCase
{
    public function testValidateRequiresMessage(): void
    {
        $node = new ApprovalWaitNode();
        $invalid = $node->validate([]);
        self::assertFalse($invalid['valid']);
        self::assertSame(['message is required'], $invalid['errors']);

        $valid = $node->validate(['message' => 'Please approve']);
        self::assertTrue($valid['valid']);
    }

    public function testTestReturnsSuccessWhenValid(): void
    {
        $node = new ApprovalWaitNode();
        $out = $node->test(['message' => 'OK']);
        self::assertTrue($out['valid']);
        self::assertTrue($out['success']);
    }

    public function testExecuteCreatesApprovalRecord(): void
    {
        $GLOBALS['conf'] = (object) ['entity' => 1];
        $GLOBALS['user'] = (object) ['id' => 42];
        $GLOBALS['db'] = new ApprovalMemoryDb();

        $node = new ApprovalWaitNode();
        $out = $node->execute([
            'node' => [
                'id' => 'approval-node',
                'config' => [
                    'message' => 'Sign off invoice {{$json.ref}}',
                    'approverRole' => 'manager',
                ],
            ],
            'execution' => ['id' => 100],
            'json' => ['ref' => 'INV-1'],
        ]);

        self::assertTrue($out['waitingApproval']);
        self::assertSame(1, $out['approvalId']);
        self::assertStringContainsString('INV-1', $out['message']);
    }

    public function testExecuteOmitsApproverRoleWhenBlank(): void
    {
        $GLOBALS['conf'] = (object) ['entity' => 2];
        $GLOBALS['user'] = (object) ['id' => 7];
        $GLOBALS['db'] = new ApprovalMemoryDb();

        $node = new ApprovalWaitNode();
        $out = $node->execute([
            'node' => [
                'id' => 'ap2',
                'config' => ['message' => 'Need approval', 'approverRole' => '   '],
            ],
            'json' => [],
        ]);

        self::assertSame(1, $out['approvalId']);
    }

    public function testSimulateReturnsDryRunPayload(): void
    {
        $node = new ApprovalWaitNode();
        $out = $node->simulate([
            'node' => ['id' => 'ap1', 'config' => ['message' => 'Approve?']],
        ]);

        self::assertTrue($out['_dryRun']);
        self::assertFalse($out['waitingApproval']);
        self::assertSame(-1, $out['approvalId']);
        self::assertSame('Approve?', $out['message']);
    }
}

final class ApprovalMemoryDb extends \DoliDB
{
    private int $nextId = 0;

    public function query(string $sql)
    {
        if (!str_starts_with($sql, 'INSERT INTO llx_knot_approval')) {
            return false;
        }
        $this->nextId++;

        return $this->nextId;
    }

    public function last_insert_id(string $tableName): int
    {
        return $this->nextId;
    }

    public function escape($stringvalue): string
    {
        return addslashes((string) $stringvalue);
    }

    public function idate($timestamp): string
    {
        return date('Y-m-d H:i:s', (int) $timestamp);
    }
}
