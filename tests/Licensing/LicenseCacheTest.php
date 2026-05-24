<?php

declare(strict_types=1);

namespace Knot\Tests\Licensing;

use Knot\Licensing\LicenseCache;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class LicenseCacheTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/knot-license-cache-' . uniqid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            foreach (glob($this->tmpDir . '/**/*') ?: [] as $f) {
                if (is_file($f)) {
                    @unlink($f);
                }
            }
            foreach (glob($this->tmpDir . '/*') ?: [] as $f) {
                if (is_file($f)) {
                    @unlink($f);
                } elseif (is_dir($f)) {
                    @rmdir($f);
                }
            }
            @rmdir($this->tmpDir);
        }
    }

    public function testReadReturnsNullWhenCacheFileMissing(): void
    {
        $cache = new LicenseCache($this->tmpDir);
        self::assertNull($cache->read('knot-pro-pack'));
    }

    public function testWriteThenReadRoundTrip(): void
    {
        $cache = new LicenseCache($this->tmpDir);
        $entry = $this->makeEntry('knot-pro-pack');
        $cache->write($entry);
        $loaded = $cache->read('knot-pro-pack');
        self::assertSame($entry['signature'], $loaded['signature'] ?? null);
        self::assertSame($entry['signedPayload'], $loaded['signedPayload'] ?? null);
    }

    public function testWriteCreatesDirectoryIfMissing(): void
    {
        $cache = new LicenseCache($this->tmpDir);
        self::assertDirectoryDoesNotExist($this->tmpDir);
        $cache->write($this->makeEntry('knot-pro-pack'));
        self::assertDirectoryExists($this->tmpDir);
    }

    public function testWriteSetsWorldReadableCacheFileForPhpFpm(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('Unix file modes are not enforced on Windows.');
        }

        $cache = new LicenseCache($this->tmpDir);
        $cache->write($this->makeEntry('knot-migration'));

        $cacheFile = $this->tmpDir . '/knot-migration.cache.json';
        self::assertFileExists($cacheFile);

        $dirMode = fileperms($this->tmpDir) & 0777;
        $fileMode = fileperms($cacheFile) & 0777;
        self::assertSame(0755, $dirMode);
        self::assertSame(0644, $fileMode);
    }

    public function testRecordFailedAttemptUpdatesError(): void
    {
        $cache = new LicenseCache($this->tmpDir);
        $cache->write($this->makeEntry('knot-pro-pack'));
        $cache->recordFailedAttempt('knot-pro-pack', 'Network unreachable');
        $loaded = $cache->read('knot-pro-pack');
        self::assertSame('Network unreachable', $loaded['lastError'] ?? null);
    }

    public function testRecordFailedAttemptIsNoopWhenNoCache(): void
    {
        $cache = new LicenseCache($this->tmpDir);
        $cache->recordFailedAttempt('knot-pro-pack', 'whatever');
        self::assertNull($cache->read('knot-pro-pack'));
    }

    public function testDeleteRemovesEntry(): void
    {
        $cache = new LicenseCache($this->tmpDir);
        $cache->write($this->makeEntry('knot-pro-pack'));
        $cache->delete('knot-pro-pack');
        self::assertNull($cache->read('knot-pro-pack'));
    }

    public function testInvalidExtensionIdIsRejected(): void
    {
        $cache = new LicenseCache($this->tmpDir);
        $this->expectException(RuntimeException::class);
        $cache->write($this->makeEntry('Invalid Id With Spaces'));
    }

    public function testStandaloneRefreshThrottleRoundTrip(): void
    {
        $cache = new LicenseCache($this->tmpDir);
        $cache->writeStandaloneRefreshThrottle('knot-pro-pack', 'network_error');
        $state = $cache->readStandaloneRefreshThrottle('knot-pro-pack');

        self::assertSame('network_error', $state['refreshFailedClass'] ?? null);
        self::assertNotEmpty($state['refreshFailedAt'] ?? null);

        $cache->write($this->makeEntry('knot-pro-pack'));
        self::assertNull($cache->readStandaloneRefreshThrottle('knot-pro-pack'));
    }

    public function testMergeAuditThrottlePersistsPartialState(): void
    {
        $cache = new LicenseCache($this->tmpDir);
        $cache->write($this->makeEntry('knot-pro-pack'));
        $cache->mergeAuditThrottle('knot-pro-pack', ['graceEnteredAt' => '2026-05-01T00:00:00+00:00']);

        $loaded = $cache->read('knot-pro-pack');
        self::assertSame('2026-05-01T00:00:00+00:00', $loaded['auditThrottle']['graceEnteredAt'] ?? null);
    }

    public function testReadReturnsNullForMalformedCacheFile(): void
    {
        if (!is_dir($this->tmpDir)) {
            mkdir($this->tmpDir, 0755, true);
        }
        file_put_contents($this->tmpDir . '/knot-pro-pack.cache.json', '{"extensionId":"knot-pro-pack"}');

        $cache = new LicenseCache($this->tmpDir);
        self::assertNull($cache->read('knot-pro-pack'));
    }

    /**
     * @return array{
     *     extensionId: string,
     *     instanceId: string,
     *     signedPayload: array<string, mixed>,
     *     signature: string,
     *     signedAt: string,
     *     expiresAt: ?string,
     *     plan: ?string,
     *     issuedTo: ?string,
     *     lastSuccessfulRefresh: string,
     *     lastAttempt: string,
     *     lastError: ?string
     * }
     */
    private function makeEntry(string $id): array
    {
        return [
            'extensionId' => $id,
            'instanceId' => 'aaaa',
            'signedPayload' => ['valid' => true, 'extensionId' => $id],
            'signature' => 'fake-sig-base64',
            'signedAt' => '2026-04-29T00:00:00+00:00',
            'expiresAt' => '2027-04-29T00:00:00+00:00',
            'plan' => 'standard',
            'issuedTo' => 'Acme',
            'lastSuccessfulRefresh' => '2026-04-29T00:00:00+00:00',
            'lastAttempt' => '2026-04-29T00:00:00+00:00',
            'lastError' => null,
        ];
    }
}
