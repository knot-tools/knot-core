<?php

declare(strict_types=1);

namespace Knot\Tests\Updates;

use Knot\Updates\UpdateStatusCache;
use PHPUnit\Framework\TestCase;

/**
 * Freshness rules for notify cache (nominal TTL vs empty-notes TTL).
 */
final class UpdateStatusCacheFreshnessTest extends TestCase
{
    public function testFreshWithinNominalTtlWhenNotesPresent(): void
    {
        $cache = new UpdateStatusCache(new InMemoryConfigRepo());
        $now = 1_700_000_000;
        $row = [
            'slug' => 'knot',
            'version' => '2.13.24',
            'channel' => 'beta',
            'publishedAt' => '2026-10-12T00:00:00+00:00',
            'notes' => "## [2.13.24]\n\n- notes",
            'fetchedAt' => $now - 60,
        ];

        self::assertTrue($cache->isFresh($row, $now));
        self::assertTrue($cache->isFresh($row, (int) $row['fetchedAt'] + UpdateStatusCache::TTL_SECONDS - 1));
        self::assertFalse($cache->isFresh($row, (int) $row['fetchedAt'] + UpdateStatusCache::TTL_SECONDS));
    }

    public function testEmptyNotesUsesShorterTtl(): void
    {
        $cache = new UpdateStatusCache(new InMemoryConfigRepo());
        $now = 1_700_000_000;
        $row = [
            'slug' => 'knot',
            'version' => '2.13.24',
            'channel' => 'beta',
            'publishedAt' => '2026-10-12T00:00:00+00:00',
            'notes' => '',
            'fetchedAt' => $now - 60,
        ];

        self::assertTrue($cache->isFresh($row, $now));
        self::assertFalse(
            $cache->isFresh($row, $now + UpdateStatusCache::EMPTY_NOTES_TTL_SECONDS),
            'empty notes must expire before the nominal 6h TTL',
        );
        self::assertLessThan(UpdateStatusCache::TTL_SECONDS, UpdateStatusCache::EMPTY_NOTES_TTL_SECONDS);
        self::assertSame(21_600, UpdateStatusCache::TTL_SECONDS);
        self::assertSame(900, UpdateStatusCache::EMPTY_NOTES_TTL_SECONDS);
    }
}
