<?php

declare(strict_types=1);

namespace Knot\Tests\Repository;

use Knot\Repository\CredentialRepository;
use PHPUnit\Framework\TestCase;

/**
 * SQL-boundary tests for {@see CredentialRepository}.
 */
final class CredentialRepositoryTest extends TestCase
{
    public function testCreateRejectsMissingRequiredFields(): void
    {
        $db = new InMemoryCredentialDb();
        $repo = new CredentialRepository($db);

        self::assertSame(0, $repo->create([], 1, 5));
        self::assertSame(0, $repo->create(['label' => 'x'], 1, 5));
    }

    public function testCreateFindUpdateDeleteRoundTrip(): void
    {
        $db = new InMemoryCredentialDb();
        $repo = new CredentialRepository($db);

        $id = $repo->create([
            'label' => '  SMTP prod  ',
            'type' => 'smtp',
            'connector_type' => 'email',
            'encrypted_data' => 'cipher-blob',
            'expires_at' => '2030-01-15',
        ], 1, 42);

        self::assertGreaterThan(0, $id);
        $row = $repo->find($id, 1);
        self::assertNotNull($row);
        self::assertSame('SMTP prod', $row['label']);
        self::assertSame('email', $row['connectorType']);
        self::assertSame(42, $row['createdBy']);

        self::assertTrue($repo->update($id, ['label' => 'Renamed', 'connectorType' => 'http'], 1, 99));
        $updated = $repo->find($id, 1);
        self::assertSame('Renamed', $updated['label']);
        self::assertSame('http', $updated['connectorType']);
        self::assertSame(99, $updated['modifiedBy']);

        self::assertTrue($repo->delete($id, 1));
        self::assertNull($repo->find($id, 1));
    }

    public function testEntityIsolationOnFindAndList(): void
    {
        $db = new InMemoryCredentialDb();
        $repo = new CredentialRepository($db);
        $id = $repo->create([
            'label' => 'Secret',
            'type' => 'api_key',
            'connector_type' => 'http',
            'encrypted_data' => 'blob',
        ], 1, null);

        self::assertNull($repo->find($id, 2));
        self::assertSame([], $repo->list(2));
        self::assertCount(1, $repo->list(1));
    }

    public function testListFiltersByConnectorType(): void
    {
        $db = new InMemoryCredentialDb();
        $repo = new CredentialRepository($db);
        $repo->create([
            'label' => 'A',
            'type' => 'generic',
            'connector_type' => 'http',
            'encrypted_data' => 'x',
        ], 1, null);
        $repo->create([
            'label' => 'B',
            'type' => 'generic',
            'connector_type' => 'email',
            'encrypted_data' => 'y',
        ], 1, null);

        self::assertCount(1, $repo->list(1, 'http'));
        self::assertSame('http', $repo->list(1, 'http')[0]['connectorType']);
    }

    public function testFindEncryptedReturnsPayload(): void
    {
        $db = new InMemoryCredentialDb();
        $repo = new CredentialRepository($db);
        $id = $repo->create([
            'label' => 'Key',
            'type' => 'token',
            'connector_type' => 'http',
            'encrypted_data' => 'super-secret-cipher',
        ], 1, null);

        $enc = $repo->findEncrypted($id, 1);
        self::assertNotNull($enc);
        self::assertSame('super-secret-cipher', $enc['encryptedData']);
        self::assertArrayNotHasKey('createdBy', $enc);
    }

    public function testCountByConnectorTypeAggregatesPerEntity(): void
    {
        $db = new InMemoryCredentialDb();
        $repo = new CredentialRepository($db);
        $repo->create([
            'label' => '1',
            'type' => 'generic',
            'connector_type' => 'http',
            'encrypted_data' => 'a',
        ], 1, null);
        $repo->create([
            'label' => '2',
            'type' => 'generic',
            'connector_type' => 'http',
            'encrypted_data' => 'b',
        ], 1, null);
        $repo->create([
            'label' => '3',
            'type' => 'generic',
            'connector_type' => 'email',
            'encrypted_data' => 'c',
        ], 2, null);

        self::assertSame(['http' => 2], $repo->countByConnectorType(1));
        self::assertSame(['email' => 1], $repo->countByConnectorType(2));
    }

    public function testUpdateReturnsFalseWhenRowMissing(): void
    {
        $db = new InMemoryCredentialDb();
        $repo = new CredentialRepository($db);
        self::assertFalse($repo->update(404, ['label' => 'nope'], 1, 1));
    }

    public function testInvalidExpiryDateStoredAsNull(): void
    {
        $db = new InMemoryCredentialDb();
        $repo = new CredentialRepository($db);
        $id = $repo->create([
            'label' => 'X',
            'type' => 'generic',
            'connector_type' => 'http',
            'encrypted_data' => 'z',
            'expires_at' => 'not-a-date',
        ], 1, null);

        self::assertNull($repo->find($id, 1)['expiresAt']);
    }
}

/**
 * In-memory credential store with enough SQL parsing for repository tests.
 */
final class InMemoryCredentialDb extends \DoliDB
{
    /** @var array<int, array<string, mixed>> */
    public array $rows = [];

    private int $nextId = 1;

    /** @var array<int, array<int, object>> */
    private array $resultSets = [];

    private int $cursor = 0;

    public function query(string $sql)
    {
        if (preg_match('/^INSERT INTO llx_knot_credential \(/', $sql)) {
            $row = $this->parseInsert($sql);
            if ($row === null) {
                return false;
            }
            $id = $this->nextId++;
            $row['rowid'] = $id;
            $row['tms'] = $row['date_creation'];
            $this->rows[$id] = $row;

            return ++$this->cursor;
        }

        if (preg_match('/^SELECT rowid, ref, label, type, connector_type, encrypted_data, encryption_version, expires_at FROM/', $sql)) {
            return $this->selectOne($sql, includeEncrypted: true);
        }

        if (preg_match('/^SELECT rowid, ref, label, type, connector_type, encryption_version, expires_at,/', $sql)) {
            if (str_contains($sql, ' ORDER BY tms DESC ')) {
                return $this->selectList($sql);
            }

            return $this->selectOne($sql, includeEncrypted: false);
        }

        if (preg_match('/^SELECT connector_type, COUNT\(\*\) AS total FROM llx_knot_credential/', $sql)) {
            return $this->selectGroupCount($sql);
        }

        if (preg_match('/^UPDATE llx_knot_credential SET /', $sql)) {
            return $this->applyUpdate($sql) ? ++$this->cursor : false;
        }

        if (preg_match('/^DELETE FROM llx_knot_credential WHERE rowid = (\d+) AND entity = (\d+)$/', $sql, $m)) {
            unset($this->rows[(int) $m[1]]);

            return ++$this->cursor;
        }

        return false;
    }

    public function fetch_object($resource): ?object
    {
        $set = $this->resultSets[$resource] ?? null;
        if ($set === null) {
            return null;
        }
        if ($set === []) {
            return null;
        }
        if (isset($set['__list'])) {
            $row = array_shift($this->resultSets[$resource]['__list']);
            return $row === null ? null : (object) $row;
        }

        return (object) $set;
    }

    public function plimit(int $limit, int $offset = 0): string
    {
        return ' LIMIT ' . $offset . ', ' . $limit;
    }

    public function last_insert_id(string $tableName): int
    {
        return $this->nextId - 1;
    }

    /** @return array<string, mixed>|null */
    private function parseInsert(string $sql): ?array
    {
        if (!preg_match(
            "/VALUES \('([^']*)','([^']*)','([^']*)','([^']*)','([^']*)','([^']*)',(NULL|'([^']*)'),(\d+),(\d+|NULL),(\d+|NULL),'([^']*)'\)$/",
            $sql,
            $m
        )) {
            return null;
        }

        return [
            'ref' => $m[1],
            'label' => $m[2],
            'type' => $m[3],
            'connector_type' => $m[4],
            'encrypted_data' => $m[5],
            'encryption_version' => $m[6],
            'expires_at' => $m[7] === 'NULL' ? null : ($m[8] !== '' ? $m[8] : null),
            'entity' => (int) $m[9],
            'fk_user_creat' => $m[10] === 'NULL' ? null : (int) $m[10],
            'fk_user_modif' => $m[11] === 'NULL' ? null : (int) $m[11],
            'date_creation' => $m[12],
        ];
    }

    private function selectOne(string $sql, bool $includeEncrypted): int
    {
        if (!preg_match('/WHERE rowid = (\d+) AND entity = (\d+)/', $sql, $m)) {
            return false;
        }
        $row = $this->rows[(int) $m[1]] ?? null;
        if ($row === null || (int) $row['entity'] !== (int) $m[2]) {
            $this->resultSets[++$this->cursor] = [];

            return $this->cursor;
        }
        $payload = $row;
        if (!$includeEncrypted) {
            unset($payload['encrypted_data']);
        }
        $this->resultSets[++$this->cursor] = $payload;

        return $this->cursor;
    }

    private function selectList(string $sql): int
    {
        if (!preg_match('/WHERE entity = (\d+)/', $sql, $m)) {
            return false;
        }
        $entity = (int) $m[1];
        $connectorFilter = null;
        if (preg_match("/AND connector_type = '([^']*)'/", $sql, $cf)) {
            $connectorFilter = $cf[1];
        }
        $list = [];
        foreach ($this->rows as $row) {
            if ((int) $row['entity'] !== $entity) {
                continue;
            }
            if ($connectorFilter !== null && (string) $row['connector_type'] !== $connectorFilter) {
                continue;
            }
            $copy = $row;
            unset($copy['encrypted_data']);
            $list[] = $copy;
        }
        $this->resultSets[++$this->cursor] = ['__list' => $list];

        return $this->cursor;
    }

    private function selectGroupCount(string $sql): int
    {
        if (!preg_match('/WHERE entity = (\d+) GROUP BY connector_type/', $sql, $m)) {
            return false;
        }
        $entity = (int) $m[1];
        $counts = [];
        foreach ($this->rows as $row) {
            if ((int) $row['entity'] !== $entity) {
                continue;
            }
            $type = (string) $row['connector_type'];
            $counts[$type] = ($counts[$type] ?? 0) + 1;
        }
        $list = [];
        foreach ($counts as $type => $total) {
            $list[] = ['connector_type' => $type, 'total' => $total];
        }
        $this->resultSets[++$this->cursor] = ['__list' => $list];

        return $this->cursor;
    }

    private function applyUpdate(string $sql): bool
    {
        if (!preg_match('/WHERE rowid = (\d+) AND entity = (\d+)$/', $sql, $m)) {
            return false;
        }
        $id = (int) $m[1];
        $entity = (int) $m[2];
        if (!isset($this->rows[$id]) || (int) $this->rows[$id]['entity'] !== $entity) {
            return false;
        }
        if (preg_match("/label = '([^']*)'/", $sql, $label)) {
            $this->rows[$id]['label'] = $label[1];
        }
        if (preg_match("/connector_type = '([^']*)'/", $sql, $connector)) {
            $this->rows[$id]['connector_type'] = $connector[1];
        }
        if (preg_match('/fk_user_modif = (\d+)/', $sql, $mod)) {
            $this->rows[$id]['fk_user_modif'] = (int) $mod[1];
        }

        return true;
    }
}
