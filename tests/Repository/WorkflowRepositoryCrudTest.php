<?php

declare(strict_types=1);

namespace Knot\Tests\Repository;

use Knot\Repository\WorkflowRepository;
use Knot\Tests\Support\InMemoryWorkflowDb;
use PHPUnit\Framework\TestCase;

final class WorkflowRepositoryCrudTest extends TestCase
{
    public function testCreateFetchUpdateDeleteRoundTrip(): void
    {
        $db = new InMemoryWorkflowDb();
        $repo = new WorkflowRepository($db);

        $definition = [
            'schemaVersion' => '1.0',
            'workflow' => [],
            'nodes' => [['type' => 'trigger.manual', 'id' => 't1']],
            'edges' => [],
        ];
        $id = $repo->create([
            'label' => '  My   flow  ',
            'description' => 'desc',
            'status' => 'draft',
            'definition' => $definition,
        ], 1, 7);

        self::assertGreaterThan(0, $id);
        $row = $repo->fetch($id, 1);
        self::assertNotNull($row);
        self::assertSame('My flow', $row['label']);
        self::assertSame('draft', $row['status']);
        self::assertSame('trigger.manual', $row['definition']['nodes'][0]['type']);

        self::assertNull($repo->fetchActive($id, 1));

        self::assertTrue($repo->update($id, ['status' => 'active', 'label' => 'Live'], 1, 8));
        $active = $repo->fetchActive($id, 1);
        self::assertNotNull($active);
        self::assertSame('Live', $active['label']);

        self::assertTrue($repo->delete($id, 1));
        self::assertNull($repo->fetch($id, 1));
    }

    public function testCountByStatusInitialisesAllBuckets(): void
    {
        $db = new InMemoryWorkflowDb();
        $db->statusCounts = ['active' => 2, 'draft' => 1];
        $repo = new WorkflowRepository($db);

        $counts = $repo->countByStatus(1);
        self::assertSame(2, $counts['active']);
        self::assertSame(1, $counts['draft']);
        self::assertSame(0, $counts['archived']);
    }

    public function testFetchDefinitionsByIdsSkipsInvalidIds(): void
    {
        $db = new InMemoryWorkflowDb();
        $repo = new WorkflowRepository($db);
        self::assertSame([], $repo->fetchDefinitionsByIds([0, -1], 1));
    }

    public function testFetchDefinitionsByIdsReturnsDecodedJson(): void
    {
        $db = new InMemoryWorkflowDb();
        $db->definitions[4] = ['schemaVersion' => '1.0', 'workflow' => [], 'nodes' => [], 'edges' => []];
        $repo = new WorkflowRepository($db);

        $defs = $repo->fetchDefinitionsByIds([4], 1);
        self::assertArrayHasKey(4, $defs);
        self::assertSame('1.0', $defs[4]['schemaVersion']);
    }

    public function testListEnrichesExecutionStatsAndTriggerType(): void
    {
        $db = new InMemoryWorkflowDb();
        $db->listRows = [[
            'rowid' => 11,
            'ref' => 'KW20260101-001-0001',
            'label' => 'Cron job',
            'description' => '',
            'status' => 'active',
            'version_schema' => '1.0',
            'fk_user_creat' => 1,
            'fk_user_modif' => 1,
            'date_creation' => '2026-01-01 00:00:00',
            'tms' => '2026-01-02 00:00:00',
            'fk_folder' => null,
            'json_definition' => json_encode([
                'nodes' => [['type' => 'trigger.cron']],
            ]),
        ]];
        $db->executionStats = [
            11 => ['success' => 3, 'error' => 1, 'waiting' => 0, 'running' => 0, 'queued' => 0, 'total' => 4],
        ];
        $repo = new WorkflowRepository($db);

        $rows = $repo->list(1, ['active']);
        self::assertCount(1, $rows);
        self::assertSame('trigger.cron', $rows[0]['triggerType']);
        self::assertSame(3, $rows[0]['successCount']);
        self::assertSame(1, $rows[0]['errorCount']);
        self::assertSame(4, $rows[0]['runsTotal']);
        self::assertNull($rows[0]['folderId']);
    }

    public function testListMapsFolderIdWhenPresent(): void
    {
        $db = new InMemoryWorkflowDb();
        $db->listRows = [[
            'rowid' => 12,
            'ref' => 'KW20260101-001-0012',
            'label' => 'Foldered',
            'description' => '',
            'status' => 'draft',
            'version_schema' => '1.0',
            'fk_user_creat' => 1,
            'fk_user_modif' => 1,
            'date_creation' => '2026-01-01 00:00:00',
            'tms' => '2026-01-02 00:00:00',
            'fk_folder' => 5,
            'json_definition' => '{"nodes":[],"edges":[]}',
        ]];
        $repo = new WorkflowRepository($db);

        $rows = $repo->list(1);
        self::assertSame(5, $rows[0]['folderId']);
    }

    public function testUpdateReturnsFalseWhenWorkflowMissing(): void
    {
        $db = new InMemoryWorkflowDb();
        $repo = new WorkflowRepository($db);
        self::assertFalse($repo->update(999, ['label' => 'x'], 1, 1));
    }

    public function testInvalidStatusNormalisedToDraftOnCreate(): void
    {
        $db = new InMemoryWorkflowDb();
        $repo = new WorkflowRepository($db);
        $id = $repo->create(['label' => 'x', 'status' => 'bogus'], 1, null);
        self::assertSame('draft', $repo->fetch($id, 1)['status']);
    }
}
