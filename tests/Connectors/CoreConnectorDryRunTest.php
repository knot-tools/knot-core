<?php

declare(strict_types=1);

namespace Knot\Tests\Connectors;

use Knot\Connectors\Actions\DolibarrReadObject;
use Knot\Connectors\Communication\EmailAction;
use Knot\Connectors\Dolibarr\SpecializedAction;
use Knot\Connectors\Dolibarr\SqlQuery;
use Knot\Connectors\Logic\ExecuteWorkflowNode;
use Knot\Connectors\Triggers\CronTrigger;
use Knot\Connectors\Triggers\DolibarrEventTrigger;
use Knot\Connectors\Triggers\ManualTrigger;
use Knot\Connectors\Triggers\WebhookTrigger;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CoreConnectorDryRunTest extends TestCase
{
    public function testManualTriggerPassesJsonThrough(): void
    {
        $out = (new ManualTrigger())->execute(['json' => ['hello' => 'world']]);
        self::assertSame(['hello' => 'world'], $out);
    }

    public function testWebhookTriggerPassesJsonPayload(): void
    {
        $out = (new WebhookTrigger())->execute([
            'json' => ['method' => 'POST', 'body' => ['id' => 9]],
        ]);
        self::assertSame('POST', $out['method']);
    }

    public function testCronTriggerPassesJsonPayload(): void
    {
        $out = (new CronTrigger())->execute([
            'json' => ['cronExpression' => '0 * * * *'],
        ]);
        self::assertSame('0 * * * *', $out['cronExpression']);
    }

    public function testDolibarrEventTriggerPassesJsonPayload(): void
    {
        $out = (new DolibarrEventTrigger())->execute([
            'json' => ['event' => 'BILL_VALIDATE', 'object' => ['id' => 1]],
        ]);
        self::assertSame('BILL_VALIDATE', $out['event']);
    }

    public function testEmailValidateAndSimulate(): void
    {
        $action = new EmailAction();
        self::assertFalse($action->validate(['subject' => 'Hi'])['valid']);

        $sim = $action->simulate([
            'node' => [
                'config' => [
                    'to' => 'ops@example.com',
                    'subject' => 'Hello',
                    'body' => '<p>Test</p>',
                ],
            ],
            'json' => [],
        ]);
        self::assertTrue($sim['_dryRun']);
        self::assertSame('ops@example.com', $sim['to']);
    }

    public function testEmailExecuteUsesStubMailer(): void
    {
        $out = (new EmailAction())->execute([
            'node' => [
                'config' => [
                    'to' => 'ops@example.com',
                    'subject' => 'Subject',
                    'body' => '<p>Body</p>',
                ],
            ],
            'json' => [],
        ]);
        self::assertTrue($out['sent']);
        self::assertSame('Subject', $out['subject']);
    }

    public function testSqlQueryValidateAndSimulate(): void
    {
        $query = new SqlQuery();
        self::assertFalse($query->validate(['query' => 'DELETE FROM llx_societe'])['valid']);

        $sim = $query->simulate([
            'node' => ['config' => ['query' => 'SELECT rowid FROM llx_societe']],
            'json' => [],
        ]);
        self::assertTrue($sim['_dryRun']);
        self::assertTrue($sim['safetyValid']);
    }

    public function testSqlQueryExecuteRequiresAdmin(): void
    {
        $GLOBALS['user'] = (object) ['id' => 1, 'admin' => 0];
        $GLOBALS['conf'] = (object) ['entity' => 1];
        $GLOBALS['db'] = new SqlQueryScenarioDb();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('admin-only');
        (new SqlQuery())->execute([
            'node' => ['config' => ['query' => 'SELECT rowid FROM llx_societe']],
            'json' => [],
        ]);
    }

    public function testSqlQueryExecuteReturnsRowsForAdmin(): void
    {
        $GLOBALS['user'] = (object) ['id' => 7, 'admin' => 1];
        $GLOBALS['conf'] = (object) ['entity' => 1];
        $GLOBALS['db'] = new SqlQueryScenarioDb();

        $out = (new SqlQuery())->execute([
            'node' => ['config' => ['query' => 'SELECT rowid FROM llx_societe']],
            'json' => [],
        ]);
        self::assertSame(1, $out['count']);
        self::assertSame(42, $out['rows'][0]['rowid'] ?? null);
    }

    public function testExecuteWorkflowValidateSimulateAndGuards(): void
    {
        $node = new ExecuteWorkflowNode();
        self::assertFalse($node->validate([])['valid']);

        $sim = $node->simulate([
            'node' => ['config' => ['workflowId' => 12, 'input' => ['k' => 'v']]],
            'json' => [],
        ]);
        self::assertTrue($sim['_dryRun']);
        self::assertSame(12, $sim['workflowId']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cannot execute itself');
        $node->execute([
            'node' => ['config' => ['workflowId' => 5]],
            'workflow' => ['id' => 5],
            'json' => [],
        ]);
    }

    public function testExecuteWorkflowRejectsCyclesAndDepth(): void
    {
        $node = new ExecuteWorkflowNode();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cycle detected');
        $node->execute([
            'node' => ['config' => ['workflowId' => 8]],
            'workflow' => ['id' => 1],
            'workflowStack' => [8],
            'json' => [],
        ]);
    }

    public function testSpecializedActionSimulateAndWarningPaths(): void
    {
        $action = new SpecializedAction();
        self::assertFalse($action->validate(['operation' => 'ship_order'])['valid']);

        $sim = $action->simulate([
            'node' => ['config' => ['operation' => 'ship_order', 'id' => 3]],
            'json' => [],
        ]);
        self::assertTrue($sim['_dryRun']);

        $stock = $action->execute([
            'node' => ['config' => ['operation' => 'stock_adjust', 'id' => 4]],
            'json' => [],
        ]);
        self::assertSame('stock_adjust', $stock['operation']);
        self::assertArrayHasKey('warning', $stock);
    }

    public function testDolibarrReadObjectFetchStub(): void
    {
        $GLOBALS['db'] = new class() extends \DoliDB {
        };
        $reader = new DolibarrReadObject();
        self::assertFalse($reader->validate(['objectType' => 'thirdparty', 'objectId' => 0])['valid']);

        $out = $reader->execute([
            'node' => ['config' => ['objectType' => 'thirdparty', 'objectId' => 15]],
            'json' => [],
        ]);
        self::assertSame('thirdparty', $out['objectType']);
        self::assertSame(15, $out['id']);
    }
}

/**
 * @internal test helper
 */
final class SqlQueryScenarioDb extends \DoliDB
{
    public function query(string $sql)
    {
        return new \stdClass();
    }

    public function fetch_object($resource): ?object
    {
        static $done = false;
        if ($done) {
            return null;
        }
        $done = true;

        return (object) ['rowid' => 42];
    }

    public function lasterror(): string
    {
        return '';
    }

    public function escape(string $string): string
    {
        return addslashes($string);
    }

    public function idate(int $timestamp): string
    {
        return gmdate('Y-m-d H:i:s', $timestamp);
    }
}
