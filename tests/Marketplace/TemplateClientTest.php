<?php

declare(strict_types=1);

namespace Knot\Tests\Marketplace;

use Knot\Marketplace\CatalogClient;
use Knot\Marketplace\TemplateClient;
use Knot\Repository\KnotConfigRepository;
use Knot\Repository\TemplateRepository;
use PHPUnit\Framework\TestCase;

/**
 * Marketplace v2 — exercises the cache-aside semantics of
 * {@see TemplateClient} without touching either Dolibarr's DB layer
 * or the network. Both the underlying TemplateRepository and the
 * CatalogClient are replaced by deterministic in-memory fakes so the
 * suite can run on any developer machine.
 */
final class TemplateClientTest extends TestCase
{
    public function testFreshRefreshPopulatesCacheAndStoresTimestamp(): void
    {
        $repo = new InMemoryTemplateRepo();
        $config = new MarketplaceInMemoryConfigRepo();
        $client = new TemplateClient($repo, $config, $this->fakeClient([
            ['slug' => 'tpl-1', 'label' => 'T1'],
        ]));

        $result = $client->all(1);

        self::assertCount(1, $result['templates']);
        self::assertSame('tpl-1', $result['templates'][0]['slug']);
        self::assertFalse($result['meta']['fromCache']);
        self::assertNotNull($config->get(TemplateClient::CONFIG_KEY_REFRESHED));
    }

    /** When marketplace passes reuse from a freshly completed CatalogClient::fetch(),
     *  skip separate fetch('template') for deployment / bandwidth reasons. */
    public function testReuseFullCatalogSkipsSeparateTemplateFetch(): void
    {
        $repo = new InMemoryTemplateRepo();
        $config = new MarketplaceInMemoryConfigRepo();
        $config->set(
            TemplateClient::CONFIG_KEY_REFRESHED,
            (string) (time() - TemplateClient::TTL_SECONDS - 3600),
        );

        $normalized = (new CatalogClient())->normalise([
            ['slug' => 'knot-pro-pack', 'label' => 'Pack', 'kind' => 'extension'],
            ['slug' => 'tpl-from-cat', 'label' => 'T', 'kind' => 'template'],
        ]);

        /** @see CatalogCacheTest\FakeCatalogClient */
        if (!class_exists(FakeCatalogClient::class, false)) {
            require_once __DIR__ . '/CatalogCacheTest.php';
        }

        $net = new FakeCatalogClient([], null, 'should_not_hit_network');
        $client = new TemplateClient($repo, $config, $net);

        $result = $client->all(1, $normalized);

        self::assertSame(0, $net->fetchCount, 'reuse path must skip CatalogClient fetch');
        self::assertCount(1, $result['templates']);
        self::assertSame('tpl-from-cat', $result['templates'][0]['slug']);
        self::assertFalse($result['meta']['fromCache']);
    }

    public function testWarmCacheBypassesNetworkCall(): void
    {
        $repo = new InMemoryTemplateRepo();
        $config = new MarketplaceInMemoryConfigRepo();
        $client = new TemplateClient($repo, $config, $this->fakeClient([
            ['slug' => 'tpl-1', 'label' => 'T1'],
        ]));
        $client->all(1);

        $offline = $this->fakeClient([], 'simulated_failure');
        $client2 = new TemplateClient($repo, $config, $offline);

        $result = $client2->all(1);

        self::assertCount(1, $result['templates']);
        self::assertTrue($result['meta']['fromCache']);
        self::assertSame(0, $offline->fetchCount, 'cache hit must not call the network');
    }

    public function testStaleCacheStillServedWhenLiveFetchFails(): void
    {
        $repo = new InMemoryTemplateRepo();
        $config = new MarketplaceInMemoryConfigRepo();
        $repo->cacheFromLicense([['slug' => 'old-tpl', 'label' => 'Old']], 1);
        // Simulate an old timestamp older than TTL.
        $config->set(TemplateClient::CONFIG_KEY_REFRESHED, (string) (time() - (TemplateClient::TTL_SECONDS + 100)));

        $offline = $this->fakeClient([], 'http_502');
        $client = new TemplateClient($repo, $config, $offline);

        $result = $client->all(1);

        self::assertCount(1, $result['templates']);
        self::assertTrue($result['meta']['fromCache']);
        self::assertTrue($result['meta']['stale']);
        self::assertSame('http_502', $result['meta']['error']);
    }

    public function testForceRefreshUpdatesCacheRegardlessOfTTL(): void
    {
        $repo = new InMemoryTemplateRepo();
        $config = new MarketplaceInMemoryConfigRepo();
        $config->set(TemplateClient::CONFIG_KEY_REFRESHED, (string) time());

        $client = new TemplateClient($repo, $config, $this->fakeClient([
            ['slug' => 'tpl-fresh', 'label' => 'Fresh'],
        ]));

        $result = $client->forceRefresh(1);

        self::assertSame(1, $result['count']);
        self::assertNull($result['error']);
        self::assertCount(1, $repo->listCached(1));
        self::assertSame('tpl-fresh', $repo->listCached(1)[0]['slug']);
    }

    public function testForceRefreshReportsErrorWhenUpstreamFails(): void
    {
        $repo = new InMemoryTemplateRepo();
        $config = new MarketplaceInMemoryConfigRepo();

        $client = new TemplateClient($repo, $config, $this->fakeClient([], 'unreachable'));

        $result = $client->forceRefresh(1);

        self::assertSame(0, $result['count']);
        self::assertSame('unreachable', $result['error']);
    }

    private function fakeClient(array $payload, ?string $error = null): FakeCatalogClient
    {
        if (!class_exists(FakeCatalogClient::class, false)) {
            require_once __DIR__ . '/CatalogCacheTest.php';
        }

        return new FakeCatalogClient($payload, null, $error);
    }
}

/**
 * In-memory replacement for {@see TemplateRepository} that bypasses the
 * Dolibarr DoliDB constructor (unavailable in unit tests).
 */
final class InMemoryTemplateRepo extends TemplateRepository
{
    /** @var array<int, array<int, array<string, mixed>>> */
    private array $byEntity = [];

    public function __construct()
    {
        // Skip DoliDB on purpose.
    }

    public function listCached(int $entity): array
    {
        return $this->byEntity[$entity] ?? [];
    }

    public function findBySlug(string $slug, int $entity): ?array
    {
        foreach ($this->byEntity[$entity] ?? [] as $row) {
            if ($row['slug'] === $slug) {
                return $row;
            }
        }
        return null;
    }

    public function find(int $templateId, int $entity): ?array
    {
        foreach ($this->byEntity[$entity] ?? [] as $row) {
            if ($row['id'] === $templateId) {
                return $row;
            }
        }
        return null;
    }

    public function cacheFromLicense(array $templates, int $entity): int
    {
        $rows = [];
        $i = 1;
        foreach ($templates as $tpl) {
            $slug = (string) ($tpl['slug'] ?? '');
            if ($slug === '') {
                continue;
            }
            $rows[] = [
                'id' => $i++,
                'ref' => strtoupper($slug),
                'slug' => $slug,
                'label' => (string) ($tpl['label'] ?? $slug),
                'description' => (string) ($tpl['description'] ?? ''),
                'category' => (string) ($tpl['templateCategory'] ?? $tpl['category'] ?? 'general'),
                'tier' => (string) ($tpl['tier'] ?? 'free'),
                'status' => 'published',
                'icon' => (string) ($tpl['icon'] ?? ''),
                'cachedAt' => gmdate('Y-m-d H:i:s'),
                'source' => 'license.knot.tools',
                'isSystem' => true,
                'definition' => $tpl['definition'] ?? [],
            ];
        }
        $this->byEntity[$entity] = $rows;
        return count($rows);
    }

    public function pruneMissing(array $slugsToKeep, int $entity): int
    {
        if ($slugsToKeep === []) {
            return 0;
        }
        $before = count($this->byEntity[$entity] ?? []);
        $this->byEntity[$entity] = array_values(array_filter(
            $this->byEntity[$entity] ?? [],
            static fn (array $row): bool => in_array($row['slug'], $slugsToKeep, true)
        ));
        return $before - count($this->byEntity[$entity]);
    }
}
