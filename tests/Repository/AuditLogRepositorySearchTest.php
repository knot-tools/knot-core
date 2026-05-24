<?php

declare(strict_types=1);

namespace Knot\Tests\Repository;

use Knot\Repository\AuditLogRepository;
use PHPUnit\Framework\TestCase;

/**
 * SQL contract for {@see AuditLogRepository::listRecent} full-text `q` filter.
 */
final class AuditLogRepositorySearchTest extends TestCase
{
    public function testEmptyQDoesNotAddLikeClause(): void
    {
        $db = new AuditSearchSqlCapturingDb();
        $repo = new AuditLogRepository($db);

        $repo->listRecent(3, [], 50, 0);

        $sql = $db->capturedQueries[0];
        self::assertStringContainsString('WHERE entity=3', $sql);
        self::assertStringNotContainsString('LOWER(action_type) LIKE', $sql);
    }

    public function testQAddsLowerLikeAcrossColumns(): void
    {
        $db = new AuditSearchSqlCapturingDb();
        $repo = new AuditLogRepository($db);

        $repo->listRecent(1, ['q' => 'Test-LLM'], 25, 0);

        $sql = $db->capturedQueries[0];
        self::assertStringContainsString('WHERE entity=1', $sql);
        self::assertStringContainsString("LOWER(action_type) LIKE LOWER('%Test-LLM%')", $sql);
        self::assertStringContainsString("LOWER(payload) LIKE LOWER('%Test-LLM%')", $sql);
    }

    public function testQEscapesLikeWildcards(): void
    {
        $db = new AuditSearchSqlCapturingDb();
        $repo = new AuditLogRepository($db);

        $repo->listRecent(1, ['q' => '100%_spam'], 10, 0);

        $sql = $db->capturedQueries[0];
        self::assertStringContainsString('100\\%\\_spam', $sql);
        self::assertStringContainsString("ESCAPE '\\\\'", $sql);
    }

    public function testQIsClampedToTwoHundredCharacters(): void
    {
        $db = new AuditSearchSqlCapturingDb();
        $repo = new AuditLogRepository($db);

        $term = str_repeat('z', 500);
        $repo->listRecent(1, ['q' => $term], 10, 0);

        $sql = $db->capturedQueries[0];
        self::assertStringContainsString(str_repeat('z', 200), $sql);
        self::assertStringNotContainsString(str_repeat('z', 201), $sql);
    }

    public function testRecordPersistsAuditRow(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit';
        $db = new AuditMemoryDb();
        $repo = new AuditLogRepository($db);

        self::assertTrue($repo->record('workflow.activate', 'workflow', 12, 3, ['ref' => 'WF-1'], 1));
        self::assertStringContainsString('workflow.activate', $db->queries[0]);
    }

    public function testListRecentAppliesFiltersAndHydratesRows(): void
    {
        $db = new AuditMemoryDb();
        $db->rows = [
            (object) [
                'rowid' => 1,
                'action_type' => 'license.refresh',
                'entity_type' => 'license',
                'entity_id' => 5,
                'fk_user' => 2,
                'ip_address' => '10.0.0.1',
                'payload' => '{"ok":true}',
                'created_at' => '2026-05-19 12:00:00',
            ],
        ];
        $repo = new AuditLogRepository($db);

        $rows = $repo->listRecent(1, [
            'actionType' => 'license.refresh',
            'entityType' => 'license',
            'userId' => 2,
            'since' => '2026-05-01',
        ], 10, 0);

        self::assertCount(1, $rows);
        self::assertSame('license.refresh', $rows[0]['actionType']);
        self::assertSame(['ok' => true], $rows[0]['payload']);
        self::assertStringContainsString("action_type='license.refresh'", $db->queries[0]);
    }
}

final class AuditSearchSqlCapturingDb extends \DoliDB
{
    /** @var list<string> */
    public array $capturedQueries = [];

    public function query(string $sql)
    {
        $this->capturedQueries[] = $sql;

        return false;
    }

    public function fetch_object($resource): ?object
    {
        return null;
    }

    public function num_rows($resource): int
    {
        return 0;
    }

    public function escape(string $value): string
    {
        return addslashes($value);
    }
}

final class AuditMemoryDb extends \DoliDB
{
    /** @var list<string> */
    public array $queries = [];

    /** @var list<object> */
    public array $rows = [];

    /** @var array<int, array{__list: list<object>}> */
    private array $resultSets = [];

    private int $cursor = 0;

    public function query(string $sql)
    {
        $this->queries[] = $sql;

        if (str_starts_with($sql, 'INSERT INTO llx_knot_audit_log')) {
            return ++$this->cursor;
        }

        if (str_starts_with($sql, 'SELECT rowid, action_type')) {
            $this->resultSets[++$this->cursor] = ['__list' => $this->rows];

            return $this->cursor;
        }

        return false;
    }

    public function fetch_object($resource): ?object
    {
        $set = $this->resultSets[$resource] ?? null;
        if ($set === null) {
            return null;
        }
        $row = array_shift($this->resultSets[$resource]['__list']);

        return $row === null ? null : $row;
    }

    public function escape(string $value): string
    {
        return addslashes($value);
    }

    public function idate(int $timestamp): string
    {
        return date('Y-m-d H:i:s', $timestamp);
    }
}
