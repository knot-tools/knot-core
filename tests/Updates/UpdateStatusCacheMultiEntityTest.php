<?php

declare(strict_types=1);

namespace Knot\Tests\Updates;

use Knot\Repository\KnotConfigRepository;
use Knot\Tests\Repository\InMemoryConfigDb;
use Knot\Updates\UpdateChecker;
use Knot\Updates\UpdateLatestSource;
use Knot\Updates\UpdateStatusCache;
use PHPUnit\Framework\TestCase;

/**
 * Update notify cache must not leak latest-version hints across Dolibarr entities.
 */
final class UpdateStatusCacheMultiEntityTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['conf']);
    }

    public function testUpdateCacheIsIsolatedPerEntity(): void
    {
        $db = new InMemoryConfigDb();
        $now = time();

        $GLOBALS['conf'] = (object) ['entity' => 1];
        $cacheEntity1 = new UpdateStatusCache(new KnotConfigRepository($db));
        $cacheEntity1->write('knot', [
            'version' => '2.12.0',
            'channel' => 'stable',
            'publishedAt' => '2026-05-01T00:00:00+00:00',
        ], $now);

        $GLOBALS['conf'] = (object) ['entity' => 2];
        $cacheEntity2 = new UpdateStatusCache(new KnotConfigRepository($db));
        $cacheEntity2->write('knot', [
            'version' => '2.11.0',
            'channel' => 'beta',
            'publishedAt' => '2026-04-01T00:00:00+00:00',
        ], $now);

        $GLOBALS['conf'] = (object) ['entity' => 1];
        $read1 = $cacheEntity1->read('knot');
        self::assertNotNull($read1);
        self::assertSame('2.12.0', $read1['version']);

        $GLOBALS['conf'] = (object) ['entity' => 2];
        $read2 = $cacheEntity2->read('knot');
        self::assertNotNull($read2);
        self::assertSame('2.11.0', $read2['version']);
    }

    public function testCheckerUsesEntityScopedCacheWithoutCrossPollution(): void
    {
        $db = new InMemoryConfigDb();
        $now = time();

        $GLOBALS['conf'] = (object) ['entity' => 1];
        $cache1 = new UpdateStatusCache(new KnotConfigRepository($db));
        $cache1->write('knot-pro-pack', [
            'version' => '1.0.0',
            'channel' => 'stable',
            'publishedAt' => '2026-05-01T00:00:00+00:00',
        ], $now);

        $GLOBALS['conf'] = (object) ['entity' => 2];
        $source = new MultiEntityStubUpdateLatestSource([
            'knot-pro-pack' => [
                'slug' => 'knot-pro-pack',
                'version' => '1.1.0',
                'channel' => 'stable',
                'publishedAt' => '2026-05-20T00:00:00+00:00',
                'zipSize' => 0,
                'zipSha256' => str_repeat('a', 64),
                'signatureKid' => '',
            ],
        ]);
        $checker = new UpdateChecker($source, new UpdateStatusCache(new KnotConfigRepository($db)));

        $result = $checker->check([['slug' => 'knot-pro-pack', 'version' => '0.9.0']]);

        self::assertTrue($result['entries'][0]['hasUpdate']);
        self::assertSame('1.1.0', $result['entries'][0]['latestVersion']);
        self::assertSame('live', $result['entries'][0]['source']);

        $GLOBALS['conf'] = (object) ['entity' => 1];
        $checkerEntity1 = new UpdateChecker(
            new MultiEntityStubUpdateLatestSource([], lastError: 'must_not_be_called'),
            new UpdateStatusCache(new KnotConfigRepository($db)),
        );
        $cachedOnly = $checkerEntity1->check([['slug' => 'knot-pro-pack', 'version' => '0.9.0']]);

        self::assertSame('cache', $cachedOnly['entries'][0]['source']);
        self::assertSame('1.0.0', $cachedOnly['entries'][0]['latestVersion']);
    }
}

final class MultiEntityStubUpdateLatestSource implements UpdateLatestSource
{
    public int $fetchCount = 0;

    /**
     * @param array<string, array<string, mixed>> $manifests
     */
    public function __construct(
        private readonly array $manifests,
        private readonly ?string $lastError = null,
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
            'source' => 'live',
            'error' => null,
        ];
    }
}
