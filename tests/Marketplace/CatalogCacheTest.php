<?php

declare(strict_types=1);

namespace Knot\Tests\Marketplace;

use Knot\Marketplace\CatalogCache;
use Knot\Marketplace\CatalogClient;
use Knot\Repository\KnotConfigRepository;
use PHPUnit\Framework\TestCase;

/**
 * Behavioural tests for the cache-aside semantics of {@see CatalogCache}.
 * The PHP DB layer is mocked via an in-memory {@see KnotConfigRepository}
 * stub and {@see CatalogClient} is replaced with a deterministic fake.
 */
final class CatalogCacheTest extends TestCase
{
    public function testFreshFetchPopulatesCache(): void
    {
        $repo = new InMemoryConfigRepo();
        $cache = new CatalogCache($repo);
        $client = new FakeClient([['slug' => 'pack-a', 'label' => 'A']]);

        $first = $cache->get($client);

        self::assertSame('pack-a', $first['products'][0]['slug']);
        self::assertFalse($first['fromCache']);
        self::assertFalse($first['stale']);
        self::assertNull($first['error']);
        self::assertTrue($first['live_catalog_fetched']);
        self::assertNotNull($repo->get(CatalogCache::CONFIG_KEY));
    }

    public function testWarmCacheBypassesNetworkCall(): void
    {
        $repo = new InMemoryConfigRepo();
        $cache = new CatalogCache($repo);
        // Seed with fresh data
        $cache->get(new FakeClient([['slug' => 'pack-a', 'label' => 'A']]));

        $offlineClient = new FakeClient([], 'simulated_failure');
        $second = $cache->get($offlineClient);

        self::assertSame('pack-a', $second['products'][0]['slug']);
        self::assertTrue($second['fromCache']);
        self::assertFalse($second['stale']);
        self::assertNull($second['error']);
        self::assertFalse($second['live_catalog_fetched']);
        self::assertSame(0, $offlineClient->fetchCount, 'cache hit must not call the network');
    }

    public function testStaleCacheIsServedWhenLiveFetchFails(): void
    {
        $repo = new InMemoryConfigRepo();
        // Manually inject an old cache entry (older than TTL)
        $repo->set(CatalogCache::CONFIG_KEY, json_encode([
            'products' => [['slug' => 'old-pack', 'label' => 'Old']],
            'fetchedAt' => time() - (CatalogCache::TTL_SECONDS + 100),
        ], JSON_UNESCAPED_SLASHES));

        $cache = new CatalogCache($repo);
        $offline = new FakeClient([], 'http_502');

        $result = $cache->get($offline);

        self::assertTrue($result['stale']);
        self::assertTrue($result['fromCache']);
        self::assertSame('old-pack', $result['products'][0]['slug']);
        self::assertSame('http_502', $result['error']);
        self::assertFalse($result['live_catalog_fetched']);
    }

    public function testEmptyResponseWithoutCacheReturnsEmptyAndError(): void
    {
        $repo = new InMemoryConfigRepo();
        $cache = new CatalogCache($repo);
        $offline = new FakeClient([], 'unreachable');

        $result = $cache->get($offline);

        self::assertSame([], $result['products']);
        self::assertFalse($result['fromCache']);
        self::assertSame('unreachable', $result['error']);
        self::assertFalse($result['live_catalog_fetched']);
    }
}

/**
 * Minimal in-memory replacement for KnotConfigRepository — bypasses the
 * Dolibarr DB layer that is unavailable in the unit test environment.
 */
final class InMemoryConfigRepo extends KnotConfigRepository
{
    /** @var array<string, string> */
    private array $store = [];

    public function __construct()
    {
        // Deliberately skip the parent constructor (no DoliDB needed).
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

/**
 * Fake CatalogClient: returns canned data without touching the network.
 */
final class FakeClient extends CatalogClient
{
    public int $fetchCount = 0;

    public function __construct(
        /** @var array<int, array<string, mixed>> */
        private readonly array $payload,
        private readonly ?string $error = null,
    ) {
        parent::__construct();
    }

    public function fetch(?string $kind = null): array
    {
        $this->fetchCount++;
        if ($this->payload === []) {
            $ref = new \ReflectionProperty(CatalogClient::class, 'lastError');
            $ref->setValue($this, $this->error);
            return [];
        }
        return $this->normalise($this->payload);
    }
}
