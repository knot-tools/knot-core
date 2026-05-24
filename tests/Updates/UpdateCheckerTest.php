<?php

declare(strict_types=1);

namespace Knot\Tests\Updates;

use Knot\Repository\KnotConfigRepository;
use Knot\Updates\UpdateChecker;
use Knot\Updates\UpdateClient;
use Knot\Updates\UpdateLatestSource;
use Knot\Updates\UpdateStatusCache;
use PHPUnit\Framework\TestCase;

/**
 * Behavioural tests for the cache-aside semantics of {@see UpdateChecker}.
 *
 * The HTTP boundary is faked through a subclass of {@see UpdateClient}
 * that returns canned manifests without touching the network, and the
 * persistence layer is faked through an in-memory
 * {@see KnotConfigRepository} double.
 */
final class UpdateCheckerTest extends TestCase
{
    public function testReportsUpdateAvailableOnFreshFetch(): void
    {
        $repo = new InMemoryConfigRepo();
        $cache = new UpdateStatusCache($repo);
        $checker = new UpdateChecker(
            new StubUpdateLatestSource([
                'knot-migration' => $this->manifest('knot-migration', '0.7.0', '2026-05-16T12:00:00+00:00'),
            ]),
            $cache,
        );

        $result = $checker->check([
            ['slug' => 'knot-migration', 'version' => '0.6.0'],
        ]);

        self::assertTrue($result['hasAnyUpdate']);
        self::assertCount(1, $result['entries']);
        $entry = $result['entries'][0];
        self::assertSame('knot-migration', $entry['slug']);
        self::assertSame('0.6.0', $entry['installedVersion']);
        self::assertSame('0.7.0', $entry['latestVersion']);
        self::assertTrue($entry['hasUpdate']);
        self::assertSame('live', $entry['source']);
        self::assertNull($entry['error']);
    }

    public function testReportsNoUpdateWhenInstalledMatchesLatest(): void
    {
        $repo = new InMemoryConfigRepo();
        $checker = new UpdateChecker(
            new StubUpdateLatestSource(['knot' => $this->manifest('knot', '2.9.0')]),
            new UpdateStatusCache($repo),
        );

        $result = $checker->check([['slug' => 'knot', 'version' => '2.9.0']]);

        self::assertFalse($result['hasAnyUpdate']);
        self::assertFalse($result['entries'][0]['hasUpdate']);
    }

    public function testWarmCacheBypassesNetwork(): void
    {
        $repo = new InMemoryConfigRepo();
        $cache = new UpdateStatusCache($repo);
        $cache->write('knot-migration', ['version' => '0.7.0', 'channel' => 'beta', 'publishedAt' => '2026-05-16T12:00:00+00:00'], time());

        $stub = new StubUpdateLatestSource([], lastError: 'simulated_offline');
        $checker = new UpdateChecker($stub, $cache);

        $result = $checker->check([['slug' => 'knot-migration', 'version' => '0.6.0']]);

        self::assertSame('cache', $result['entries'][0]['source']);
        self::assertTrue($result['entries'][0]['hasUpdate']);
        self::assertSame(0, $stub->fetchCount, 'cache hit must not call the network');
    }

    public function testForceRefreshBypassesWarmCache(): void
    {
        $repo = new InMemoryConfigRepo();
        $cache = new UpdateStatusCache($repo);
        $cache->write('knot', ['version' => '2.12.0', 'channel' => 'beta', 'publishedAt' => '2026-05-16T12:00:00+00:00'], time());

        $stub = new StubUpdateLatestSource(['knot' => $this->manifest('knot', '2.13.0')]);
        $checker = new UpdateChecker($stub, $cache);

        $cached = $checker->check([['slug' => 'knot', 'version' => '2.12.0']]);
        self::assertSame('cache', $cached['entries'][0]['source']);
        self::assertSame(0, $stub->fetchCount);

        $forced = $checker->check([['slug' => 'knot', 'version' => '2.12.0']], forceRefresh: true);
        self::assertSame('live', $forced['entries'][0]['source']);
        self::assertSame('2.13.0', $forced['entries'][0]['latestVersion']);
        self::assertSame(1, $stub->fetchCount);
    }

    public function testStaleCacheIsServedWhenLiveFetchFails(): void
    {
        $repo = new InMemoryConfigRepo();
        // Inject an old cache entry manually.
        $repo->set('updates.cache.knot', (string) json_encode([
            'slug' => 'knot',
            'version' => '2.9.0',
            'channel' => 'beta',
            'publishedAt' => '2026-04-01T12:00:00+00:00',
            'fetchedAt' => time() - (UpdateStatusCache::TTL_SECONDS + 100),
        ]));

        $checker = new UpdateChecker(
            new StubUpdateLatestSource([], lastError: 'simulated_offline'),
            new UpdateStatusCache($repo),
        );

        $result = $checker->check([['slug' => 'knot', 'version' => '2.9.0']]);

        $entry = $result['entries'][0];
        self::assertSame('cache_stale', $entry['source']);
        self::assertSame('simulated_offline', $entry['error']);
        self::assertFalse($entry['hasUpdate']);
    }

    public function testUnavailableWhenNoCacheAndNetworkFails(): void
    {
        $repo = new InMemoryConfigRepo();
        $checker = new UpdateChecker(
            new StubUpdateLatestSource([], lastError: 'http_500'),
            new UpdateStatusCache($repo),
        );

        $result = $checker->check([['slug' => 'knot', 'version' => '2.9.0']]);

        $entry = $result['entries'][0];
        self::assertSame('unavailable', $entry['source']);
        self::assertNull($entry['latestVersion']);
        self::assertSame('http_500', $entry['error']);
        self::assertFalse($entry['hasUpdate']);
    }

    public function testIgnoresEntriesWithEmptySlugOrVersion(): void
    {
        $checker = new UpdateChecker(
            new StubUpdateLatestSource([]),
            new UpdateStatusCache(new InMemoryConfigRepo()),
        );

        $result = $checker->check([
            ['slug' => '', 'version' => '1.0.0'],
            ['slug' => 'knot', 'version' => ''],
        ]);

        self::assertSame([], $result['entries']);
        self::assertFalse($result['hasAnyUpdate']);
    }

    public function testCoreLicenseFallbackSurfacesLiveLicenseSource(): void
    {
        $checker = new UpdateChecker(
            new StubUpdateLatestSource(
                ['knot' => $this->manifest('knot', '2.13.0')],
                liveSource: 'live_license',
            ),
            new UpdateStatusCache(new InMemoryConfigRepo()),
        );

        $result = $checker->check([['slug' => 'knot', 'version' => '2.12.0']]);

        self::assertTrue($result['hasAnyUpdate']);
        self::assertSame('live_license', $result['entries'][0]['source']);
    }

    public function testCompareVersionsSemverAwareness(): void
    {
        self::assertTrue(UpdateChecker::compareVersions('0.6.0', '0.7.0'));
        self::assertTrue(UpdateChecker::compareVersions('2.9.0', '2.10.0'));
        self::assertFalse(UpdateChecker::compareVersions('2.9.0', '2.9.0'));
        self::assertFalse(UpdateChecker::compareVersions('2.10.0', '2.9.0'));
        self::assertTrue(UpdateChecker::compareVersions('1.0.0-beta1', '1.0.0'));
    }

    /**
     * @return array{slug: string, version: string, channel: string, publishedAt: string, zipSize: int, zipSha256: string, signatureKid: string}
     */
    private function manifest(string $slug, string $version, string $publishedAt = '2026-05-16T12:00:00+00:00'): array
    {
        return [
            'slug' => $slug,
            'version' => $version,
            'channel' => 'beta',
            'publishedAt' => $publishedAt,
            'zipSize' => 1024,
            'zipSha256' => str_repeat('a', 64),
            'signatureKid' => 'rel-2026-04',
        ];
    }
}

final class InMemoryConfigRepo extends KnotConfigRepository
{
    /** @var array<string, string> */
    private array $store = [];

    public function __construct()
    {
        // Skip parent constructor — no DoliDB needed for in-memory tests.
    }

    public function get(string $key, ?string $default = null): ?string
    {
        return $this->store[$key] ?? $default;
    }

    public function set(string $key, string $value): void
    {
        $this->store[$key] = $value;
    }

    public function delete(string $key): void
    {
        unset($this->store[$key]);
    }
}

final class StubUpdateLatestSource implements UpdateLatestSource
{
    public int $fetchCount = 0;

    /**
     * @param array<string, array<string, mixed>> $manifests
     */
    public function __construct(
        private readonly array $manifests,
        private readonly ?string $lastError = null,
        private readonly string $liveSource = 'live',
    ) {
    }

    public function fetchLatest(string $slug): array
    {
        $this->fetchCount++;
        if (!isset($this->manifests[$slug])) {
            return [
                'payload' => null,
                'source' => 'unavailable',
                'error' => $this->lastError ?? 'unknown_product',
            ];
        }
        /** @var array{slug: string, version: string, channel: string, publishedAt: string, zipSize: int, zipSha256: string, signatureKid: string} $m */
        $m = $this->manifests[$slug];

        return [
            'payload' => $m,
            'source' => $this->liveSource,
            'error' => null,
        ];
    }
}
