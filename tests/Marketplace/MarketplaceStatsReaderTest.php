<?php

declare(strict_types=1);

namespace Knot\Tests\Marketplace;

use Knot\Marketplace\MarketplaceStatsReader;
use PHPUnit\Framework\TestCase;

final class MarketplaceStatsReaderTest extends TestCase
{
    public function testNormalizeSlugKeyWins(): void
    {
        self::assertSame(
            'slug:pro-pack',
            MarketplaceStatsReader::normalizeCtaAggregationKey([
                'cta_slug' => 'pro-pack',
                'href' => 'https://knot.tools/pro-pack/',
            ]),
        );
    }

    public function testNormalizeCombinesIdAndRoute(): void
    {
        self::assertSame(
            'id|sticky|home',
            MarketplaceStatsReader::normalizeCtaAggregationKey([
                'cta_id' => 'sticky',
                'route' => 'home',
                'href' => 'ignored',
            ]),
        );
    }

    public function testNormalizeHrefFallbackUsesPrefix(): void
    {
        self::assertSame(
            'href:https://knot.tools/docs',
            MarketplaceStatsReader::normalizeCtaAggregationKey(['href' => 'https://knot.tools/docs']),
        );
    }

    public function testPopularSlugsOrderingUsesDescendingCounts(): void
    {
        /** @var array<string, positive-int> $counts */
        $counts = ['z-slug' => 9, 'a-slug' => 1];
        arsort($counts);
        self::assertSame(['z-slug', 'a-slug'], array_keys($counts));
    }
}
