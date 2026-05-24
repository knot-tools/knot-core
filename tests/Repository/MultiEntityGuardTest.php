<?php

declare(strict_types=1);

namespace Knot\Tests\Repository;

use Knot\Repository\IdempotencyRepository;
use Knot\Repository\WorkflowRepository;
use PHPUnit\Framework\TestCase;

/**
 * Multi-entity isolation guard.
 *
 * Knot must never expose data of entity A to entity B. This suite exercises
 * the SQL boundary of the most security-sensitive repositories using a
 * per-entity in-memory store. Adding a repository that lacks an entity
 * filter should make at least one of these assertions fail.
 */
final class MultiEntityGuardTest extends TestCase
{
    public function testWorkflowRepositoryFetchScopesByEntity(): void
    {
        $db = new EntityScopedDb();
        $db->seedWorkflow(['rowid' => 1, 'ref' => 'KW1', 'label' => 'A', 'status' => 'active', 'entity' => 1]);
        $db->seedWorkflow(['rowid' => 1, 'ref' => 'KW1', 'label' => 'B', 'status' => 'active', 'entity' => 2]);

        $repo = new WorkflowRepository($db);
        $entity1 = $repo->fetch(1, 1);
        $entity2 = $repo->fetch(1, 2);
        $crossover = $repo->fetch(1, 3);

        self::assertNotNull($entity1);
        self::assertSame('A', $entity1['label']);
        self::assertNotNull($entity2);
        self::assertSame('B', $entity2['label']);
        self::assertNull($crossover);
    }

    public function testWorkflowRepositoryListIsScopedByEntity(): void
    {
        $db = new EntityScopedDb();
        $db->seedWorkflow(['rowid' => 1, 'ref' => 'KW1', 'label' => 'E1', 'status' => 'active', 'entity' => 1]);
        $db->seedWorkflow(['rowid' => 2, 'ref' => 'KW2', 'label' => 'E2', 'status' => 'active', 'entity' => 2]);

        $repo = new WorkflowRepository($db);
        $list1 = $repo->list(1);
        $list2 = $repo->list(2);

        self::assertCount(1, $list1);
        self::assertSame('E1', $list1[0]['label']);
        self::assertCount(1, $list2);
        self::assertSame('E2', $list2[0]['label']);
    }

    public function testWorkflowRepositoryDeleteWithWrongEntityKeepsRow(): void
    {
        $db = new EntityScopedDb();
        $db->seedWorkflow(['rowid' => 7, 'ref' => 'KW7', 'label' => 'L', 'status' => 'active', 'entity' => 1]);

        $repo = new WorkflowRepository($db);
        $repo->delete(7, 2);

        self::assertNotNull($repo->fetch(7, 1), 'Row should still exist for the legitimate entity');
        self::assertNull($repo->fetch(7, 2));
    }

    public function testIdempotencyRepositoryRejectsCrossEntityReuse(): void
    {
        $db = new InMemoryIdempotencyDb();
        $repo = new IdempotencyRepository($db);
        $repo->remember('shared', 100, 1);
        $repo->remember('shared', 200, 2);

        self::assertSame(100, $repo->findExecution('shared', 1));
        self::assertSame(200, $repo->findExecution('shared', 2));
    }

    public function testIdempotencyPurgeAffectsOnlyTargetEntity(): void
    {
        $db = new InMemoryIdempotencyDb();
        $repo = new IdempotencyRepository($db);
        $repo->purgeExpired(7);

        foreach ($db->purgeQueries as $query) {
            self::assertStringContainsString('entity = 7', $query);
            self::assertDoesNotMatchRegularExpression('/entity\s*=\s*[^7\s]/', $query);
        }
    }
}

/**
 * In-memory DoliDB stub that filters seeded workflow rows by entity at
 * query time, mirroring how a real MySQL backend behaves under WHERE
 * entity = N clauses.
 */
final class EntityScopedDb extends \DoliDB
{
    /** @var list<array<string, mixed>> */
    private array $workflows = [];
    /** @var array<int, array<int, array<string, mixed>>> */
    private array $resultSets = [];
    /** @var array<int, int> */
    private array $cursors = [];
    private int $resultId = 0;

    public function seedWorkflow(array $row): void
    {
        $defaults = [
            'description' => '',
            'json_definition' => '{}',
            'version_schema' => '1.0',
            'single_instance' => 0,
            'fk_folder' => null,
            'fk_user_creat' => null,
            'fk_user_modif' => null,
            'date_creation' => '2026-05-01 00:00:00',
            'tms' => '2026-05-01 00:00:00',
        ];
        $this->workflows[] = array_merge($defaults, $row);
    }

    public function query(string $sql)
    {
        // Match fetchByIdInternal SELECT
        if (preg_match('/^SELECT rowid, ref, label, description, status, json_definition.*FROM llx_knot_workflow WHERE rowid = (\d+) AND entity = (\d+)/', $sql, $m)) {
            $matches = array_values(array_filter(
                $this->workflows,
                fn (array $w): bool => (int) $w['rowid'] === (int) $m[1] && (int) $w['entity'] === (int) $m[2]
            ));
            return $this->openResultSet($matches);
        }

        // Match list() SELECT
        if (preg_match('/^SELECT rowid.*FROM llx_knot_workflow WHERE entity = (\d+)/', $sql, $m)) {
            $matches = array_values(array_filter(
                $this->workflows,
                fn (array $w): bool => (int) $w['entity'] === (int) $m[1]
            ));
            return $this->openResultSet($matches);
        }

        // Match fetchExecutionStats COUNT/GROUP BY (return empty so list() builds zeros)
        if (str_contains($sql, 'FROM llx_knot_execution')) {
            return $this->openResultSet([]);
        }

        // Match DELETE
        if (preg_match('/^DELETE FROM llx_knot_workflow WHERE rowid = (\d+) AND entity = (\d+)$/', $sql, $m)) {
            $before = count($this->workflows);
            $this->workflows = array_values(array_filter(
                $this->workflows,
                fn (array $w): bool => !((int) $w['rowid'] === (int) $m[1] && (int) $w['entity'] === (int) $m[2])
            ));
            return ++$this->resultId;
        }

        return false;
    }

    public function fetch_object($resource): ?object
    {
        $rows = $this->resultSets[$resource] ?? [];
        $idx = $this->cursors[$resource] ?? 0;
        if (!isset($rows[$idx])) {
            return null;
        }
        $this->cursors[$resource] = $idx + 1;
        return (object) $rows[$idx];
    }

    public function num_rows($resource): int
    {
        return count($this->resultSets[$resource] ?? []);
    }

    public function plimit(int $limit, int $offset = 0): string
    {
        return ' LIMIT ' . $offset . ', ' . $limit;
    }

    public function escape(string $value): string
    {
        return addslashes($value);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function openResultSet(array $rows): int
    {
        $this->resultId++;
        $this->resultSets[$this->resultId] = $rows;
        $this->cursors[$this->resultId] = 0;
        return $this->resultId;
    }
}
