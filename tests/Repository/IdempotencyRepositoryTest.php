<?php

declare(strict_types=1);

namespace Knot\Tests\Repository;

use Knot\Repository\IdempotencyRepository;
use PHPUnit\Framework\TestCase;

/**
 * Behavior of the idempotency repository at the SQL boundary.
 *
 * The in-memory DB stub parses the four SQL shapes the repository emits
 * (SELECT, INSERT, DELETE on expiration, escape) so we can assert TTL
 * defaults, multi-entity isolation, and key sanitization without booting
 * a real MariaDB.
 */
final class IdempotencyRepositoryTest extends TestCase
{
    public function testRememberAndFindRoundTripWithinSameEntity(): void
    {
        $db = new InMemoryIdempotencyDb();
        $repo = new IdempotencyRepository($db);

        self::assertNull($repo->findExecution('webhook:abc', 1));
        self::assertTrue($repo->remember('webhook:abc', 4242, 1));
        self::assertSame(4242, $repo->findExecution('webhook:abc', 1));
    }

    public function testEntityIsolationOnFindExecution(): void
    {
        $db = new InMemoryIdempotencyDb();
        $repo = new IdempotencyRepository($db);
        $repo->remember('shared-key', 1001, 1);
        $repo->remember('shared-key', 2002, 2);

        self::assertSame(1001, $repo->findExecution('shared-key', 1));
        self::assertSame(2002, $repo->findExecution('shared-key', 2));
        self::assertNull($repo->findExecution('shared-key', 3));
    }

    public function testKeyIsCleanedFromIllegalCharacters(): void
    {
        $db = new InMemoryIdempotencyDb();
        $repo = new IdempotencyRepository($db);
        $repo->remember("  bad/k=ey&with spaces  ", 7, 1);

        // After sanitization only [a-zA-Z0-9_.:-] remains, capped to 128 chars.
        self::assertSame(7, $repo->findExecution('badkeywithspaces', 1));
    }

    public function testEmptyKeyAfterSanitizationReturnsNoMatch(): void
    {
        $db = new InMemoryIdempotencyDb();
        $repo = new IdempotencyRepository($db);
        self::assertFalse($repo->remember('//&&==', 9, 1));
        self::assertNull($repo->findExecution('//&&==', 1));
    }

    public function testRememberRejectsZeroOrNegativeExecutionId(): void
    {
        $db = new InMemoryIdempotencyDb();
        $repo = new IdempotencyRepository($db);
        self::assertFalse($repo->remember('k', 0, 1));
        self::assertFalse($repo->remember('k', -42, 1));
    }

    public function testTtlIsClampedToMinimumSixtySeconds(): void
    {
        $db = new InMemoryIdempotencyDb();
        $repo = new IdempotencyRepository($db);
        $repo->remember('low-ttl', 11, 1, 5);
        self::assertSame(60, $db->lastTtlInsertedFor('low-ttl'));
    }

    public function testDefaultTtlIsTwentyFourHours(): void
    {
        $db = new InMemoryIdempotencyDb();
        $repo = new IdempotencyRepository($db);
        $repo->remember('default-ttl', 22, 1);
        self::assertSame(86400, $db->lastTtlInsertedFor('default-ttl'));
    }

    public function testKeyIsTruncatedToOneHundredTwentyEight(): void
    {
        $db = new InMemoryIdempotencyDb();
        $repo = new IdempotencyRepository($db);
        $longKey = str_repeat('a', 200);
        $repo->remember($longKey, 99, 1);

        $stored = str_repeat('a', 128);
        self::assertSame(99, $repo->findExecution($stored, 1));
    }

    public function testPurgeExpiredScopesByEntity(): void
    {
        $db = new InMemoryIdempotencyDb();
        $repo = new IdempotencyRepository($db);
        $repo->purgeExpired(1);
        self::assertNotEmpty($db->purgeQueries);
        self::assertStringContainsString('entity = 1', $db->purgeQueries[0]);
        self::assertStringNotContainsString('entity = 2', $db->purgeQueries[0]);
    }
}

/**
 * In-memory DoliDB stub that recognises the SQL shapes emitted by
 * IdempotencyRepository. Stores rows in a per-entity map.
 */
final class InMemoryIdempotencyDb extends \DoliDB
{
    /** @var array<int, array<string, array{exec:int,ttl:int}>> */
    public array $rows = [];
    /** @var array<int, array<string, mixed>> */
    private array $resultSets = [];
    private int $cursor = 0;

    /** @var list<string> */
    public array $purgeQueries = [];

    public function query(string $sql)
    {
        if (preg_match("/^SELECT fk_execution FROM llx_knot_idempotency WHERE idempotency_key = '([^']*)' AND entity = (\d+) LIMIT 1$/", $sql, $m)) {
            $key = $m[1];
            $entity = (int) $m[2];
            $row = $this->rows[$entity][$key] ?? null;
            $this->cursor++;
            $this->resultSets[$this->cursor] = $row === null ? [] : ['fk_execution' => $row['exec']];
            return $this->cursor;
        }
        if (preg_match("/^INSERT INTO llx_knot_idempotency \(idempotency_key, fk_execution, ttl_seconds, entity, date_creation\) VALUES \('([^']*)',(\d+),(\d+),(\d+),'[^']*'\)$/", $sql, $m)) {
            $key = $m[1];
            $exec = (int) $m[2];
            $ttl = (int) $m[3];
            $entity = (int) $m[4];
            $this->rows[$entity][$key] = ['exec' => $exec, 'ttl' => $ttl];
            return ++$this->cursor;
        }
        if (str_starts_with($sql, 'DELETE FROM llx_knot_idempotency')) {
            $this->purgeQueries[] = $sql;
            return ++$this->cursor;
        }

        return false;
    }

    public function fetch_object($resource): ?object
    {
        $row = $this->resultSets[$resource] ?? [];
        return $row === [] ? null : (object) $row;
    }

    public function affected_rows(mixed $resultset = null): int
    {
        return 0;
    }

    public function lastTtlInsertedFor(string $key): ?int
    {
        foreach ($this->rows as $perEntity) {
            if (isset($perEntity[$key])) {
                return $perEntity[$key]['ttl'];
            }
        }
        return null;
    }
}
