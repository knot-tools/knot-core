<?php

declare(strict_types=1);

namespace Knot\Tests\Extension;

use Knot\Extension\ExtensionStateRepository;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the ExtensionStateRepository CRUD surface and its
 * input-validation contract (extension id, key shape, value size).
 *
 * Uses an in-memory fake DoliDB so the tests run without a real database
 * (mirrors the pattern of MigratorTest and other Knot Repository tests).
 */
final class ExtensionStateRepositoryTest extends TestCase
{
    public function testAllReturnsEverySoredKeyOrderedAscending(): void
    {
        $db = $this->newFakeDb([
            ['rowid' => 1, 'extension_id' => 'knot-migration', 'fk_user' => 7, 'state_key' => 'lastSnapshotId', 'state_value' => '"abc"', 'entity' => 1],
            ['rowid' => 2, 'extension_id' => 'knot-migration', 'fk_user' => 7, 'state_key' => 'onboardingStep', 'state_value' => '3', 'entity' => 1],
            // Different user: must NOT leak
            ['rowid' => 3, 'extension_id' => 'knot-migration', 'fk_user' => 99, 'state_key' => 'lastSnapshotId', 'state_value' => '"other"', 'entity' => 1],
            // Different entity: must NOT leak
            ['rowid' => 4, 'extension_id' => 'knot-migration', 'fk_user' => 7, 'state_key' => 'lastSnapshotId', 'state_value' => '"e2"', 'entity' => 2],
        ]);

        $repo = new ExtensionStateRepository($db);
        $state = $repo->all(7, 'knot-migration', 1);

        self::assertSame([
            'lastSnapshotId' => '"abc"',
            'onboardingStep' => '3',
        ], $state);
    }

    public function testAllReturnsEmptyArrayWhenUserOrExtensionInvalid(): void
    {
        $db = $this->newFakeDb([]);
        $repo = new ExtensionStateRepository($db);

        self::assertSame([], $repo->all(0, 'knot-migration', 1));
        self::assertSame([], $repo->all(7, '', 1));
        self::assertSame([], $repo->all(7, '!!invalid!!', 1));
    }

    public function testCountKeysCountsOnlyMatchingRows(): void
    {
        $db = $this->newFakeDb([
            ['extension_id' => 'knot-migration', 'fk_user' => 7, 'state_key' => 'a', 'state_value' => '1', 'entity' => 1],
            ['extension_id' => 'knot-migration', 'fk_user' => 7, 'state_key' => 'b', 'state_value' => '2', 'entity' => 1],
            ['extension_id' => 'other-ext', 'fk_user' => 7, 'state_key' => 'c', 'state_value' => '3', 'entity' => 1],
        ]);
        $repo = new ExtensionStateRepository($db);

        self::assertSame(2, $repo->countKeys(7, 'knot-migration', 1));
        self::assertSame(1, $repo->countKeys(7, 'other-ext', 1));
        self::assertSame(0, $repo->countKeys(7, 'no-such', 1));
    }

    public function testSetInsertsNewRow(): void
    {
        $db = $this->newFakeDb([]);
        $repo = new ExtensionStateRepository($db);

        self::assertTrue($repo->set(7, 'knot-migration', 'lastSnapshotId', '"abc"', 1));
        self::assertSame([
            'lastSnapshotId' => '"abc"',
        ], $repo->all(7, 'knot-migration', 1));
    }

    public function testSetUpdatesExistingRow(): void
    {
        $db = $this->newFakeDb([
            ['extension_id' => 'knot-migration', 'fk_user' => 7, 'state_key' => 'lastSnapshotId', 'state_value' => '"old"', 'entity' => 1],
        ]);
        $repo = new ExtensionStateRepository($db);

        self::assertTrue($repo->set(7, 'knot-migration', 'lastSnapshotId', '"new"', 1));
        self::assertSame(['lastSnapshotId' => '"new"'], $repo->all(7, 'knot-migration', 1));
    }

    public function testSetRejectsTooLargeValue(): void
    {
        $db = $this->newFakeDb([]);
        $repo = new ExtensionStateRepository($db);
        $tooBig = str_repeat('x', ExtensionStateRepository::MAX_VALUE_BYTES + 1);

        self::assertFalse($repo->set(7, 'knot-migration', 'k', $tooBig, 1));
    }

    public function testSetRejectsBadExtensionId(): void
    {
        $db = $this->newFakeDb([]);
        $repo = new ExtensionStateRepository($db);

        self::assertFalse($repo->set(7, 'INVALID', 'k', 'v', 1));
        self::assertFalse($repo->set(7, '-leading-dash', 'k', 'v', 1));
        self::assertFalse($repo->set(7, '', 'k', 'v', 1));
    }

    public function testSetRejectsBadKey(): void
    {
        $db = $this->newFakeDb([]);
        $repo = new ExtensionStateRepository($db);

        self::assertFalse($repo->set(7, 'knot-migration', '', 'v', 1));
        self::assertFalse($repo->set(7, 'knot-migration', 'has space', 'v', 1));
        self::assertFalse($repo->set(7, 'knot-migration', "tab\tinside", 'v', 1));
        self::assertFalse($repo->set(7, 'knot-migration', str_repeat('k', ExtensionStateRepository::MAX_KEY_LENGTH + 1), 'v', 1));
    }

    public function testSetRejectsNonPositiveUserId(): void
    {
        $db = $this->newFakeDb([]);
        $repo = new ExtensionStateRepository($db);

        self::assertFalse($repo->set(0, 'knot-migration', 'k', 'v', 1));
        self::assertFalse($repo->set(-1, 'knot-migration', 'k', 'v', 1));
    }

    public function testRemoveDropsTargetedKeyOnly(): void
    {
        $db = $this->newFakeDb([
            ['extension_id' => 'knot-migration', 'fk_user' => 7, 'state_key' => 'a', 'state_value' => '1', 'entity' => 1],
            ['extension_id' => 'knot-migration', 'fk_user' => 7, 'state_key' => 'b', 'state_value' => '2', 'entity' => 1],
        ]);
        $repo = new ExtensionStateRepository($db);

        self::assertTrue($repo->remove(7, 'knot-migration', 'a', 1));
        self::assertSame(['b' => '2'], $repo->all(7, 'knot-migration', 1));
    }

    public function testRemoveIsIdempotentForMissingKeys(): void
    {
        $db = $this->newFakeDb([]);
        $repo = new ExtensionStateRepository($db);

        self::assertTrue($repo->remove(7, 'knot-migration', 'missing', 1));
    }

    public function testClearWipesEveryKeyForPair(): void
    {
        $db = $this->newFakeDb([
            ['extension_id' => 'knot-migration', 'fk_user' => 7, 'state_key' => 'a', 'state_value' => '1', 'entity' => 1],
            ['extension_id' => 'knot-migration', 'fk_user' => 7, 'state_key' => 'b', 'state_value' => '2', 'entity' => 1],
            ['extension_id' => 'other', 'fk_user' => 7, 'state_key' => 'c', 'state_value' => '3', 'entity' => 1],
        ]);
        $repo = new ExtensionStateRepository($db);

        self::assertTrue($repo->clear(7, 'knot-migration', 1));
        self::assertSame([], $repo->all(7, 'knot-migration', 1));
        self::assertSame(['c' => '3'], $repo->all(7, 'other', 1));
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function newFakeDb(array $rows): \DoliDB
    {
        return new class($rows) extends \DoliDB {
            /** @var array<int, array<string, mixed>> */
            private array $rows;
            /** @var array<int, array<string, mixed>> */
            private array $lastResult = [];
            private int $cursor = 0;

            public function __construct(array $rows)
            {
                $this->rows = $rows;
            }

            public function escape(string $value): string
            {
                return str_replace("'", "''", $value);
            }

            public function idate(int $timestamp): string
            {
                return gmdate('Y-m-d H:i:s', $timestamp);
            }

            public function query(string $sql)
            {
                if (str_starts_with($sql, 'SELECT state_key, state_value')) {
                    $this->lastResult = $this->matchSelect($sql);
                    $this->cursor = 0;
                    return new \stdClass();
                }
                if (str_starts_with($sql, 'SELECT COUNT(*) AS nb')) {
                    $matched = $this->matchSelect($sql);
                    $this->lastResult = [['nb' => count($matched)]];
                    $this->cursor = 0;
                    return new \stdClass();
                }
                if (str_starts_with($sql, 'INSERT INTO')) {
                    $row = $this->parseInsert($sql);
                    if ($row === null) {
                        return false;
                    }
                    // Upsert on (entity, extension_id, fk_user, state_key)
                    foreach ($this->rows as $i => $r) {
                        if ($r['entity'] === $row['entity']
                            && $r['extension_id'] === $row['extension_id']
                            && $r['fk_user'] === $row['fk_user']
                            && $r['state_key'] === $row['state_key']) {
                            $this->rows[$i]['state_value'] = $row['state_value'];
                            return new \stdClass();
                        }
                    }
                    $this->rows[] = $row;
                    return new \stdClass();
                }
                if (str_starts_with($sql, 'DELETE FROM')) {
                    $this->rows = array_values(array_filter($this->rows, fn (array $r) => !$this->deleteMatches($sql, $r)));
                    return new \stdClass();
                }
                return false;
            }

            public function fetch_object($res): ?object
            {
                if (!isset($this->lastResult[$this->cursor])) {
                    return null;
                }
                $row = $this->lastResult[$this->cursor++];
                return (object) $row;
            }

            /**
             * @return array<int, array<string, mixed>>
             */
            private function matchSelect(string $sql): array
            {
                $entity = $this->extractInt($sql, '/entity\s*=\s*(\d+)/');
                $userId = $this->extractInt($sql, '/fk_user\s*=\s*(\d+)/');
                $extensionId = $this->extractStr($sql, "/extension_id\s*=\s*'([^']*)'/");

                $out = [];
                foreach ($this->rows as $r) {
                    if ($r['entity'] === $entity
                        && $r['fk_user'] === $userId
                        && $r['extension_id'] === $extensionId) {
                        $out[] = $r;
                    }
                }
                usort($out, fn (array $a, array $b) => strcmp((string) $a['state_key'], (string) $b['state_key']));
                return $out;
            }

            /**
             * @return array<string, mixed>|null
             */
            private function parseInsert(string $sql): ?array
            {
                if (!preg_match("/VALUES\s*\(\s*'([^']*)',\s*(\d+),\s*'([^']*)',\s*'([^']*)',\s*(\d+),\s*'([^']*)'\s*\)/", $sql, $m)) {
                    return null;
                }
                return [
                    'extension_id' => $m[1],
                    'fk_user' => (int) $m[2],
                    'state_key' => $m[3],
                    'state_value' => $m[4],
                    'entity' => (int) $m[5],
                ];
            }

            private function deleteMatches(string $sql, array $row): bool
            {
                $entity = $this->extractInt($sql, '/entity\s*=\s*(\d+)/');
                $userId = $this->extractInt($sql, '/fk_user\s*=\s*(\d+)/');
                $extensionId = $this->extractStr($sql, "/extension_id\s*=\s*'([^']*)'/");
                if ($row['entity'] !== $entity
                    || $row['fk_user'] !== $userId
                    || $row['extension_id'] !== $extensionId) {
                    return false;
                }
                if (preg_match("/state_key\s*=\s*'([^']*)'/", $sql, $m)) {
                    return $row['state_key'] === $m[1];
                }
                return true;
            }

            private function extractInt(string $sql, string $pattern): int
            {
                return preg_match($pattern, $sql, $m) === 1 ? (int) $m[1] : 0;
            }

            private function extractStr(string $sql, string $pattern): string
            {
                return preg_match($pattern, $sql, $m) === 1 ? (string) $m[1] : '';
            }
        };
    }
}
