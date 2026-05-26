<?php

declare(strict_types=1);

namespace Knot\Tests\Marketplace;

use Knot\Marketplace\CatalogCache;
use Knot\Marketplace\SidebarBadge;
use Knot\Repository\KnotConfigRepository;
use PHPUnit\Framework\TestCase;

final class SidebarBadgeTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function minimalV2WithSidebar(array $sidebar): array
    {
        return [
            'version' => 2,
            'meta' => [
                'killSwitch' => false,
                'updatedAt' => '2026-05-25T10:00:00Z',
            ],
            'taxonomy' => [
                'categories' => [
                    [
                        'slug' => 'packs',
                        'label' => 'Packs',
                        'icon' => 'package',
                        'color' => '#6366f1',
                    ],
                ],
            ],
            'home' => [
                'spotlight' => [
                    'items' => [
                        [
                            'kind' => 'pack',
                            'slug' => 'knot-pro-pack',
                            'tagline' => 'Pro connectors.',
                            'accent' => 'pro',
                            'ctas' => [
                                ['label' => 'Buy', 'href' => 'https://knot.tools/pro-pack/'],
                            ],
                        ],
                    ],
                ],
                'collections' => [
                    [
                        'slug' => 'recent',
                        'label' => 'Recent',
                        'query' => ['sort' => 'recent'],
                        'limit' => 8,
                    ],
                ],
            ],
            'sidebar' => $sidebar,
        ];
    }

    public function testFromEditorialHappyPath(): void
    {
        $editorial = self::minimalV2WithSidebar([
            'badge' => [
                'label' => 'New',
                'variant' => 'info',
                'ariaLabel' => 'Fresh listings',
                'hasUnread' => true,
            ],
        ]);
        $b = SidebarBadge::fromEditorial($editorial);
        self::assertSame(
            ['label' => 'New', 'variant' => 'info', 'ariaLabel' => 'Fresh listings', 'hasUnread' => true],
            $b,
        );
    }

    public function testFromEditorialFailsWhenWholeDocInvalid(): void
    {
        $editorial = self::minimalV2WithSidebar([
            'badge' => [
                'label' => 'badges-too-long-string',
                'variant' => 'info',
                'ariaLabel' => 'x',
                'hasUnread' => false,
            ],
        ]);
        self::assertNull(SidebarBadge::fromEditorial($editorial));
    }

    public function testFromEditorialMissingBadgeReturnsNull(): void
    {
        $editorial = self::minimalV2WithSidebar([]);
        self::assertNull(SidebarBadge::fromEditorial($editorial));
    }

    public function testFromConfigReadsCatalogCacheEnvelope(): void
    {
        $repo = new SidebarBadgeMemoryConfigRepo();
        $payload = json_encode([
            'products' => [['slug' => 'knot-pro-pack', 'label' => 'Pack']],
            'editorial' => self::minimalV2WithSidebar([
                'badge' => [
                    'label' => 'Tip',
                    'variant' => 'success',
                    'ariaLabel' => 'Operator tips',
                    'hasUnread' => false,
                ],
            ]),
            'fetchedAt' => time(),
            'ttlSeconds' => 21600,
        ], JSON_THROW_ON_ERROR);
        $repo->set(CatalogCache::configKeyForLang('en'), $payload);

        self::assertSame(
            ['label' => 'Tip', 'variant' => 'success', 'ariaLabel' => 'Operator tips', 'hasUnread' => false],
            SidebarBadge::fromConfig($repo, 'en'),
        );
    }

    public function testFromConfigReturnsNullWithoutRow(): void
    {
        $repo = new SidebarBadgeMemoryConfigRepo();
        self::assertNull(SidebarBadge::fromConfig($repo, 'en'));
    }
}

final class SidebarBadgeMemoryConfigRepo extends KnotConfigRepository
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
