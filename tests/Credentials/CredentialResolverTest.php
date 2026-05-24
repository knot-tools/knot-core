<?php

declare(strict_types=1);

namespace Knot\Tests\Credentials;

use Knot\Credentials\CredentialCipher;
use Knot\Credentials\CredentialResolver;
use Knot\Repository\CredentialRepository;
use PHPUnit\Framework\TestCase;

final class CredentialResolverTest extends TestCase
{
    private CredentialCipher $cipher;

    protected function setUp(): void
    {
        $this->cipher = new CredentialCipher('resolver-test-secret', 'resolver-salt');
    }

    public function testResolveReturnsNullForInvalidId(): void
    {
        $resolver = new CredentialResolver(
            new CredentialRepository(new InMemoryCredentialDb()),
            $this->cipher,
            1,
        );
        self::assertNull($resolver->resolve(0));
        self::assertNull($resolver->resolve(-3));
    }

    public function testResolveDecryptsAndCachesCredential(): void
    {
        $blob = json_encode($this->cipher->encrypt(['secrets' => ['password' => 's3cret']]), JSON_THROW_ON_ERROR);
        $db = new InMemoryCredentialDb([
            5 => [
                'rowid' => 5,
                'ref' => 'cred-001',
                'label' => 'SMTP',
                'type' => 'smtp',
                'connector_type' => 'action.email',
                'encrypted_data' => $blob,
                'encryption_version' => '1',
                'expires_at' => null,
                'entity' => 1,
            ],
        ]);
        $resolver = new CredentialResolver(new CredentialRepository($db), $this->cipher, 1);

        $first = $resolver->resolve(5);
        self::assertIsArray($first);
        self::assertSame(5, $first['id']);
        self::assertSame('cred-001', $first['ref']);
        self::assertSame(['password' => 's3cret'], $first['secrets']);

        self::assertSame($first, $resolver->resolve(5), 'Second call must hit in-memory cache');
        self::assertSame(1, $db->selectCount, 'Repository fetch should run once');
    }

    public function testResolveReturnsNullWhenRowMissingOrWrongEntity(): void
    {
        $db = new InMemoryCredentialDb();
        $resolver = new CredentialResolver(new CredentialRepository($db), $this->cipher, 1);
        self::assertNull($resolver->resolve(99));

        $blob = json_encode($this->cipher->encrypt(['secrets' => []]), JSON_THROW_ON_ERROR);
        $db->rows[3] = [
            'rowid' => 3,
            'ref' => 'cred-003',
            'label' => 'Other entity',
            'type' => 'generic',
            'connector_type' => 'generic',
            'encrypted_data' => $blob,
            'encryption_version' => '1',
            'expires_at' => null,
            'entity' => 2,
        ];
        self::assertNull($resolver->resolve(3));
    }

    public function testResolveReturnsNullWhenEncryptedBlobInvalid(): void
    {
        $db = new InMemoryCredentialDb([
            7 => [
                'rowid' => 7,
                'ref' => 'cred-bad',
                'label' => 'Broken',
                'type' => 'generic',
                'connector_type' => 'generic',
                'encrypted_data' => 'not-json',
                'encryption_version' => '1',
                'expires_at' => null,
                'entity' => 1,
            ],
        ]);
        $resolver = new CredentialResolver(new CredentialRepository($db), $this->cipher, 1);
        self::assertNull($resolver->resolve(7));
        self::assertNull($resolver->resolve(7), 'Failed decrypt must be cached as null');
    }
}

/**
 * Minimal DoliDB stub for CredentialRepository::findEncrypted().
 *
 * @internal test helper
 */
final class InMemoryCredentialDb extends \DoliDB
{
    /** @var array<int, array<string, mixed>> */
    public array $rows = [];

    public int $selectCount = 0;

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    public function __construct(array $rows = [])
    {
        $this->rows = $rows;
    }

    public function query(string $sql)
    {
        if (!preg_match('/rowid = (\d+) AND entity = (\d+)/', $sql, $m)) {
            return false;
        }
        $id = (int) $m[1];
        $entity = (int) $m[2];
        ++$this->selectCount;
        $row = $this->rows[$id] ?? null;
        if (!is_array($row) || (int) ($row['entity'] ?? 0) !== $entity) {
            return new \stdClass();
        }

        return (object) $row;
    }

    public function fetch_object($resource): ?object
    {
        if (!$resource instanceof \stdClass || !isset($resource->rowid)) {
            return null;
        }

        return $resource;
    }
}
