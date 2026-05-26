<?php

declare(strict_types=1);

namespace Knot\Tests\Marketplace;

use Knot\Marketplace\EditorialMerger;
use PHPUnit\Framework\TestCase;

final class EditorialMergerTest extends TestCase
{
    public function testNullOverlayReturnsFallback(): void
    {
        $fb = ['version' => 1, 'home' => ['layout' => [['id' => 'a', 'title' => 'T']]]];
        self::assertSame($fb, EditorialMerger::merge($fb, null));
    }

    public function testOverridesBlockFieldsBySameId(): void
    {
        $fb = [
            'version' => 1,
            'home' => [
                'layout' => [
                    ['id' => 'hero-main', 'type' => 'hero', 'title' => 'Old'],
                ],
            ],
        ];
        $patch = [
            'home' => [
                'layout' => [
                    ['id' => 'hero-main', 'type' => 'hero', 'title' => 'New'],
                ],
            ],
        ];
        $out = EditorialMerger::merge($fb, $patch);
        self::assertSame('New', $out['home']['layout'][0]['title']);
    }

    public function testAppendsNewBlocks(): void
    {
        $fb = [
            'version' => 1,
            'home' => [
                'layout' => [['id' => 'a', 'type' => 'banner']],
            ],
        ];
        $patch = [
            'home' => [
                'layout' => [
                    ['id' => 'b', 'type' => 'banner'],
                ],
            ],
        ];
        $out = EditorialMerger::merge($fb, $patch);
        self::assertCount(2, $out['home']['layout']);
        self::assertSame('b', $out['home']['layout'][1]['id']);
    }

    public function testProductPageMergeKeepsSlugOrderBaseline(): void
    {
        $fb = [
            'version' => 1,
            'home' => ['layout' => [['id' => 'h', 'type' => 'hero']]],
            'productPages' => [
                'knot-pro-pack' => [
                    'layout' => [
                        ['id' => 'pp1', 'type' => 'highlight', 'title' => 'Legacy'],
                    ],
                ],
            ],
        ];
        $patch = [
            'productPages' => [
                'knot-pro-pack' => [
                    'layout' => [
                        ['id' => 'pp1', 'type' => 'highlight', 'title' => 'Patched'],
                    ],
                ],
            ],
        ];
        $out = EditorialMerger::merge($fb, $patch);
        self::assertSame('Patched', $out['productPages']['knot-pro-pack']['layout'][0]['title']);
    }

    public function testTemplatePageMergeMatchesProductPageSemantics(): void
    {
        $fb = [
            'version' => 1,
            'home' => ['layout' => [['id' => 'h', 'type' => 'hero']]],
            'templatePages' => [
                'invoice-reminder' => [
                    'layout' => [
                        ['id' => 't1', 'type' => 'hero', 'title' => 'Bundled'],
                    ],
                ],
            ],
        ];
        $patch = [
            'templatePages' => [
                'invoice-reminder' => [
                    'layout' => [
                        ['id' => 't1', 'type' => 'hero', 'title' => 'Remote overlay'],
                    ],
                ],
            ],
        ];
        $out = EditorialMerger::merge($fb, $patch);
        self::assertSame(
            'Remote overlay',
            $out['templatePages']['invoice-reminder']['layout'][0]['title'],
        );
    }

    public function testNewsPageMergeMatchesProductPageSemantics(): void
    {
        $fb = [
            'version' => 1,
            'home' => ['layout' => [['id' => 'h', 'type' => 'hero']]],
            'newsPages' => [
                'marketplace-refresh' => [
                    'layout' => [
                        ['id' => 'n1', 'type' => 'banner', 'title' => 'Stale copy'],
                    ],
                ],
            ],
        ];
        $patch = [
            'newsPages' => [
                'marketplace-refresh' => [
                    'layout' => [
                        ['id' => 'n1', 'type' => 'banner', 'title' => 'Fresh copy'],
                    ],
                ],
            ],
        ];
        $out = EditorialMerger::merge($fb, $patch);
        self::assertSame('Fresh copy', $out['newsPages']['marketplace-refresh']['layout'][0]['title']);
    }

    public function testSidebarBadgeShallowMerged(): void
    {
        $fb = ['version' => 1, 'home' => ['layout' => []], 'sidebar' => ['badge' => ['label' => 'A']]];
        $patch = ['sidebar' => ['badge' => ['variant' => 'warning']]];
        $out = EditorialMerger::merge($fb, $patch);
        self::assertSame('warning', $out['sidebar']['badge']['variant']);
        self::assertSame('A', $out['sidebar']['badge']['label']);
    }

    public function testKillSwitchSignalsRemoteShouldBeDropped(): void
    {
        self::assertFalse(EditorialMerger::remoteBlockedByKillSwitch(null));
        self::assertFalse(EditorialMerger::remoteBlockedByKillSwitch([]));
        self::assertFalse(EditorialMerger::remoteBlockedByKillSwitch(['meta' => []]));
        self::assertFalse(EditorialMerger::remoteBlockedByKillSwitch(['meta' => ['killSwitch' => false]]));
        self::assertTrue(EditorialMerger::remoteBlockedByKillSwitch(['meta' => ['killSwitch' => true]]));
    }

    public function testForwardCompatibleOverlayIgnoresUnknownBlockTypes(): void
    {
        $fb = [
            'version' => 1,
            'home' => ['layout' => [['id' => 'story', 'type' => 'banner', 'title' => 'Stable']]],
        ];
        $patch = [
            'home' => [
                'layout' => [
                    ['id' => 'future-chip', 'type' => 'totally_future_block', 'title' => 'Should drop'],
                ],
            ],
        ];
        $out = EditorialMerger::merge($fb, $patch);
        self::assertCount(1, $out['home']['layout']);
        self::assertSame('banner', $out['home']['layout'][0]['type']);
    }

    public function testForwardCompatiblePatchWithUnknownBlockTypeLeavesFallbackFields(): void
    {
        $fb = [
            'version' => 1,
            'home' => ['layout' => [['id' => 'hero-main', 'type' => 'hero', 'title' => 'Old']]],
        ];
        $patch = [
            'home' => [
                'layout' => [
                    ['id' => 'hero-main', 'type' => 'unknown_future', 'title' => 'New'],
                ],
            ],
        ];
        $out = EditorialMerger::merge($fb, $patch);
        self::assertSame('hero', $out['home']['layout'][0]['type']);
        self::assertSame('Old', $out['home']['layout'][0]['title']);
    }

    public function testBackwardUsesFallbackWhenOverlayMissing(): void
    {
        $fb = ['version' => 1, 'home' => ['layout' => [['id' => 'solo', 'type' => 'hero', 'title' => 'Local']]]];
        self::assertSame($fb, EditorialMerger::merge($fb, null));
    }

    public function testHomeV2DiscoveryStripsLegacyLayoutAfterMerge(): void
    {
        $fb = [
            'version' => 2,
            'home' => [
                'layout' => [['id' => 'fb-hero', 'type' => 'hero', 'title' => 'Fallback']],
                'spotlight' => ['items' => [['slug' => 'knot-pro-pack', 'kind' => 'pack']]],
            ],
        ];
        $overlay = [
            'home' => [
                'layout' => [['id' => 'remote-banner', 'type' => 'banner', 'title' => 'Teaser']],
            ],
        ];

        $out = EditorialMerger::merge($fb, $overlay);

        self::assertArrayNotHasKey('layout', $out['home']);
        self::assertSame('knot-pro-pack', $out['home']['spotlight']['items'][0]['slug']);
    }
}
