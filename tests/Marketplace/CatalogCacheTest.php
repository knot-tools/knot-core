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
        $repo = new MarketplaceInMemoryConfigRepo();
        $cache = new CatalogCache($repo);
        $client = new FakeCatalogClient([['slug' => 'pack-a', 'label' => 'A']]);

        $first = $cache->get($client, 'en');

        self::assertSame('pack-a', $first['products'][0]['slug']);
        self::assertFalse($first['fromCache']);
        self::assertFalse($first['stale']);
        self::assertNull($first['error']);
        self::assertTrue($first['live_catalog_fetched']);
        self::assertNotNull($repo->get(CatalogCache::configKeyForLang('en')));
    }

    public function testWarmCacheBypassesNetworkCall(): void
    {
        $repo = new MarketplaceInMemoryConfigRepo();
        $cache = new CatalogCache($repo);
        $cache->get(new FakeCatalogClient([['slug' => 'pack-a', 'label' => 'A']]), 'en');

        $offlineClient = new FakeCatalogClient([], null, 'simulated_failure');
        $second = $cache->get($offlineClient, 'en');

        self::assertSame('pack-a', $second['products'][0]['slug']);
        self::assertTrue($second['fromCache']);
        self::assertFalse($second['stale']);
        self::assertNull($second['error']);
        self::assertFalse($second['live_catalog_fetched']);
        self::assertSame(0, $offlineClient->fetchCount, 'cache hit must not call the network');
    }

    public function testStaleCacheIsServedWhenLiveFetchFails(): void
    {
        $repo = new MarketplaceInMemoryConfigRepo();
        $repo->set(CatalogCache::configKeyForLang('en'), json_encode([
            'products' => [['slug' => 'old-pack', 'label' => 'Old']],
            'editorial' => null,
            'fetchedAt' => time() - (int) (CatalogCache::TTL_SECONDS * 1.2) - 3600,
            'ttlSeconds' => CatalogCache::TTL_SECONDS,
        ], JSON_UNESCAPED_SLASHES));

        $cache = new CatalogCache($repo);
        $offline = new FakeCatalogClient([], null, 'http_502');

        $result = $cache->get($offline, 'en');

        self::assertTrue($result['stale']);
        self::assertTrue($result['fromCache']);
        self::assertSame('old-pack', $result['products'][0]['slug']);
        self::assertSame('http_502', $result['error']);
        self::assertFalse($result['live_catalog_fetched']);
    }

    public function testEmptyResponseWithoutCacheReturnsEmptyAndError(): void
    {
        $repo = new MarketplaceInMemoryConfigRepo();
        $cache = new CatalogCache($repo);
        $offline = new FakeCatalogClient([], null, 'unreachable');

        $result = $cache->get($offline, 'en');

        self::assertSame([], $result['products']);
        self::assertFalse($result['fromCache']);
        self::assertSame('unreachable', $result['error']);
        self::assertFalse($result['live_catalog_fetched']);
    }

    public function testCachesEditorialAlongsideProducts(): void
    {
        $repo = new MarketplaceInMemoryConfigRepo();
        $cache = new CatalogCache($repo);
        $editorial = ['version' => 1, 'home' => ['layout' => [['id' => 'x', 'type' => 'banner']]]];
        $client = new FakeCatalogClient(
            [['slug' => 'alpha', 'label' => 'A']],
            $editorial,
        );

        $first = $cache->get($client, 'fr');
        self::assertSame($editorial, $first['editorial']);

        $raw = $repo->get(CatalogCache::configKeyForLang('fr'));
        self::assertIsString($raw);
        $decoded = json_decode($raw, true);
        self::assertIsArray($decoded);
        self::assertSame($editorial, $decoded['editorial']);
    }

    public function testPersistsJitterAwareTtl(): void
    {
        $repo = new MarketplaceInMemoryConfigRepo();
        $cache = new CatalogCache($repo);
        $cache->get(new FakeCatalogClient([['slug' => 'solo', 'label' => 'S']]), 'en');

        $raw = $repo->get(CatalogCache::configKeyForLang('en'));
        self::assertIsString($raw);
        $decoded = json_decode($raw, true);
        self::assertIsArray($decoded);
        $storedTtl = (int) ($decoded['ttlSeconds'] ?? 0);
        $span = (int) floor(CatalogCache::TTL_SECONDS * (CatalogCache::TTL_JITTER_PERCENT / 100));
        self::assertGreaterThanOrEqual(CatalogCache::TTL_SECONDS - $span, $storedTtl);
        self::assertLessThanOrEqual(CatalogCache::TTL_SECONDS + $span, $storedTtl);
    }

    public function testFrenchCacheUsesDistinctKey(): void
    {
        $repo = new MarketplaceInMemoryConfigRepo();
        $cache = new CatalogCache($repo);
        $cache->get(new FakeCatalogClient([['slug' => 'p1', 'label' => 'P1']]), 'en');
        $cache->get(new FakeCatalogClient([['slug' => 'p2', 'label' => 'P2']]), 'fr');

        self::assertTrue($repo->get(CatalogCache::configKeyForLang('en')) !== null);
        self::assertTrue($repo->get(CatalogCache::configKeyForLang('fr')) !== null);
    }

    public function testNormalizeCatalogLang(): void
    {
        self::assertSame('fr', CatalogCache::normalizeCatalogLang('fr_FR'));
        self::assertSame('de', CatalogCache::normalizeCatalogLang('de'));
        self::assertSame('en', CatalogCache::normalizeCatalogLang(''));
    }
}

final class MarketplaceInMemoryConfigRepo extends KnotConfigRepository
{
    /** @var array<string, string> */
    private array $store = [];

    public function __construct()
    {
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

final class FakeCatalogClient extends CatalogClient
{
    public int $fetchCount = 0;

    /**
     * @param array<int, array<string, mixed>> $payload
     * @param array<string, mixed>|null $editorial
     */
    public function __construct(
        private readonly array $payload,
        private readonly ?array $editorial = null,
        private readonly ?string $error = null,
    ) {
        parent::__construct();
    }

    public function fetchCatalog(?string $kind = null, ?string $lang = null): array
    {
        $this->fetchCount++;
        if ($this->payload === []) {
            $ref = new \ReflectionProperty(CatalogClient::class, 'lastError');
            $ref->setValue($this, $this->error);

            return ['products' => [], 'editorial' => null];
        }

        return [
            'products' => $this->normalise($this->payload),
            'editorial' => $this->editorial,
        ];
    }
}
