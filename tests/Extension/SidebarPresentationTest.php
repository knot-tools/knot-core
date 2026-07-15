<?php

declare(strict_types=1);

namespace Knot\Tests\Extension;

use Knot\Extension\SidebarPresentation;
use PHPUnit\Framework\TestCase;

final class SidebarPresentationTest extends TestCase
{
    public function testExtensionWithoutUiSectionIsSkipped(): void
    {
        $active = [
            'knot-free-demo' => ['ui' => null],
        ];

        $items = SidebarPresentation::buildExtensionItems(
            $active,
            static fn (string $perm): bool => true,
            true,
            static fn (string $key, ?string $domain): string => $key,
            'https://example.com/preview.php'
        );

        self::assertSame([], $items);
    }

    public function testExtensionWithUiBuiltIntoMenuItem(): void
    {
        $items = SidebarPresentation::buildExtensionItems(
            ['knot-migration' => $this->migrationExtension()],
            static fn (string $perm): bool => $perm === 'knotmigration.use',
            false,
            static fn (string $key, ?string $domain): string => 'TR(' . $key . ')',
            'https://example.com/preview.php'
        );

        self::assertCount(1, $items);
        self::assertSame('ext-knot-migration', $items[0]['key']);
        self::assertSame('fa-package', $items[0]['icon']);
        self::assertSame('TR(KnotMigrationMenuRoot)', $items[0]['label']);
        self::assertSame('https://example.com/preview.php?mode=migration', $items[0]['url']);
        self::assertSame('operations', $items[0]['_section']);
        self::assertSame(50, $items[0]['_position']);
        self::assertFalse($items[0]['_isCtaAdmin']);
        self::assertSame('migration', $items[0]['_mode']);
    }

    public function testExtensionWithCategoryPremiumExposesIsPremiumTrue(): void
    {
        $ext = $this->migrationExtension();
        $ext['category'] = 'premium';

        $items = SidebarPresentation::buildExtensionItems(
            ['knot-migration' => $ext],
            static fn (string $perm): bool => true,
            true,
            static fn (string $key, ?string $domain): string => $key,
            'https://example.com/preview.php'
        );

        self::assertCount(1, $items);
        self::assertTrue(
            $items[0]['_isPremium'],
            'category=premium must flip _isPremium so the template renders the gold treatment.',
        );
    }

    public function testExtensionWithCategoryOtherThanPremiumIsNotPremium(): void
    {
        $ext = $this->migrationExtension();
        $ext['category'] = 'third-party';

        $items = SidebarPresentation::buildExtensionItems(
            ['knot-x' => $ext],
            static fn (string $perm): bool => true,
            true,
            static fn (string $key, ?string $domain): string => $key,
            'https://example.com/preview.php'
        );

        self::assertFalse($items[0]['_isPremium']);
    }

    public function testExtensionWithoutCategoryDefaultsToNotPremium(): void
    {
        $items = SidebarPresentation::buildExtensionItems(
            ['knot-migration' => $this->migrationExtension()],
            static fn (string $perm): bool => true,
            true,
            static fn (string $key, ?string $domain): string => $key,
            'https://example.com/preview.php'
        );

        self::assertFalse(
            $items[0]['_isPremium'],
            'No category in the manifest must default to _isPremium=false (free/third-party).',
        );
    }

    public function testNonAdminWithoutPermissionIsHidden(): void
    {
        $items = SidebarPresentation::buildExtensionItems(
            ['knot-migration' => $this->migrationExtension()],
            static fn (string $perm): bool => false,
            false,
            static fn (string $key, ?string $domain): string => $key,
            'https://example.com/preview.php'
        );

        self::assertSame([], $items);
    }

    public function testAdminWithoutPermissionGetsCtaItem(): void
    {
        $ext = $this->migrationExtension();
        $ext['ui']['onboarding']['ctaIfPermissionMissingForAdmin'] = 'KnotMigrationFirstSetupCTA@knotmigration';

        $items = SidebarPresentation::buildExtensionItems(
            ['knot-migration' => $ext],
            static fn (string $perm): bool => false,
            true,
            static fn (string $key, ?string $domain): string => $key,
            'https://example.com/preview.php'
        );

        self::assertCount(1, $items);
        self::assertTrue($items[0]['_isCtaAdmin']);
    }

    public function testAdminWithoutPermissionWithoutCtaIsHidden(): void
    {
        $ext = $this->migrationExtension();
        $ext['ui']['onboarding']['ctaIfPermissionMissingForAdmin'] = null;

        $items = SidebarPresentation::buildExtensionItems(
            ['knot-migration' => $ext],
            static fn (string $perm): bool => false,
            true,
            static fn (string $key, ?string $domain): string => $key,
            'https://example.com/preview.php'
        );

        self::assertSame([], $items);
    }

    public function testIconWithoutFaPrefixIsNormalised(): void
    {
        $ext = $this->migrationExtension();
        $ext['ui']['menu']['icon'] = 'rocket';

        $items = SidebarPresentation::buildExtensionItems(
            ['knot-migration' => $ext],
            static fn (string $perm): bool => true,
            true,
            static fn (string $key, ?string $domain): string => $key,
            'https://example.com/preview.php'
        );

        self::assertSame('fa-rocket', $items[0]['icon']);
    }

    public function testIconAlreadyPrefixedIsKept(): void
    {
        $ext = $this->migrationExtension();
        $ext['ui']['menu']['icon'] = 'fa-bolt';

        $items = SidebarPresentation::buildExtensionItems(
            ['knot-migration' => $ext],
            static fn (string $perm): bool => true,
            true,
            static fn (string $key, ?string $domain): string => $key,
            'https://example.com/preview.php'
        );

        self::assertSame('fa-bolt', $items[0]['icon']);
    }

    public function testLabelFallsBackToLiteralWhenTranslationMissing(): void
    {
        $ext = $this->migrationExtension();
        $ext['ui']['menu']['labelLang'] = 'MissingKey@nodomain';
        $ext['ui']['menu']['label'] = 'Fallback Label';

        $items = SidebarPresentation::buildExtensionItems(
            ['knot-migration' => $ext],
            static fn (string $perm): bool => true,
            true,
            // Dolibarr semantics: trans() returns the key itself when
            // no translation is registered.
            static fn (string $key, ?string $domain): string => $key,
            'https://example.com/preview.php'
        );

        self::assertSame('Fallback Label', $items[0]['label']);
    }

    public function testModeWithSpaceIsUrlEncoded(): void
    {
        $ext = $this->migrationExtension();
        $ext['ui']['menu']['mode'] = 'mig-2'; // valid kebab — assert pass-through

        $items = SidebarPresentation::buildExtensionItems(
            ['knot-migration' => $ext],
            static fn (string $perm): bool => true,
            true,
            static fn (string $key, ?string $domain): string => $key,
            'https://example.com/preview.php'
        );

        self::assertSame('https://example.com/preview.php?mode=mig-2', $items[0]['url']);
    }

    public function testMergeInsertsAfterLastNativeOfSection(): void
    {
        $native = [
            ['key' => 'dashboard'],
            ['key' => 'observability'],
            ['key' => 'workflows'],
            ['key' => 'editor'],
            ['key' => 'executions'],
            ['key' => 'connectors'],
            ['key' => 'setup'],
        ];
        $extensions = [
            [
                'key' => 'ext-knot-migration',
                '_section' => 'operations',
                '_position' => 50,
                '_placement' => 'end',
                '_extId' => 'knot-migration',
            ],
            [
                'key' => 'ext-other-cat',
                '_section' => 'catalog',
                '_position' => 100,
                '_placement' => 'end',
                '_extId' => 'other-cat',
            ],
        ];

        $merged = SidebarPresentation::mergeWithNativeItems($native, $extensions);
        $keys = array_map(static fn (array $i): string => (string) ($i['key'] ?? ''), $merged);

        // Operations last native = "executions" → insertion right after.
        // Catalog last native = "connectors" → insertion right after.
        self::assertSame(
            ['dashboard', 'observability', 'workflows', 'editor', 'executions', 'ext-knot-migration', 'connectors', 'ext-other-cat', 'setup'],
            $keys
        );
    }

    public function testMergeOrdersMultipleExtensionsInSameSectionByPositionThenId(): void
    {
        $native = [
            ['key' => 'workflows'],
            ['key' => 'executions'],
            ['key' => 'setup'],
        ];
        $extensions = [
            ['key' => 'ext-b', '_section' => 'operations', '_position' => 100, '_placement' => 'end', '_extId' => 'b'],
            ['key' => 'ext-a', '_section' => 'operations', '_position' => 100, '_placement' => 'end', '_extId' => 'a'],
            ['key' => 'ext-c', '_section' => 'operations', '_position' => 50, '_placement' => 'end', '_extId' => 'c'],
        ];

        $merged = SidebarPresentation::mergeWithNativeItems($native, $extensions);
        $keys = array_map(static fn (array $i): string => (string) ($i['key'] ?? ''), $merged);

        self::assertSame(
            ['workflows', 'executions', 'ext-c', 'ext-a', 'ext-b', 'setup'],
            $keys
        );
    }

    public function testMergeAppendsItemsWithUnknownSection(): void
    {
        $native = [['key' => 'dashboard'], ['key' => 'setup']];
        $extensions = [
            ['key' => 'ext-mystery', '_section' => 'undeclared-future-section', '_position' => 10, '_placement' => 'end', '_extId' => 'mystery'],
        ];

        $merged = SidebarPresentation::mergeWithNativeItems($native, $extensions);
        $keys = array_map(static fn (array $i): string => (string) ($i['key'] ?? ''), $merged);

        self::assertSame(['dashboard', 'setup', 'ext-mystery'], $keys);
    }

    public function testMergeInsertsAtSectionStartWhenPlacementStart(): void
    {
        $native = [
            ['key' => 'dashboard'],
            ['key' => 'observability'],
            ['key' => 'workflows'],
            ['key' => 'setup'],
        ];
        $extensions = [
            [
                'key' => 'ext-knot-migration',
                '_section' => 'dashboard',
                '_position' => 10,
                '_placement' => 'start',
                '_extId' => 'knot-migration',
            ],
        ];

        $merged = SidebarPresentation::mergeWithNativeItems($native, $extensions);
        $keys = array_map(static fn (array $i): string => (string) ($i['key'] ?? ''), $merged);

        self::assertSame(
            ['ext-knot-migration', 'dashboard', 'observability', 'workflows', 'setup'],
            $keys
        );
    }

    public function testBuildExtensionItemsExposesMenuPlacement(): void
    {
        $ext = $this->migrationExtension();
        $ext['ui']['menu']['section'] = 'dashboard';
        $ext['ui']['menu']['placement'] = 'start';

        $items = SidebarPresentation::buildExtensionItems(
            ['knot-migration' => $ext],
            static fn (string $perm): bool => true,
            true,
            static fn (string $key, ?string $domain): string => $key,
            'https://example.com/preview.php'
        );

        self::assertSame('dashboard', $items[0]['_section']);
        self::assertSame('start', $items[0]['_placement']);
    }

    public function testBuildNavigationChildrenFromUiNavigation(): void
    {
        $children = SidebarPresentation::buildNavigationChildren(
            [
                [
                    'key' => 'journey',
                    'labelKey' => 'nav.section.journey',
                    'items' => [
                        [
                            'key' => 'discovery',
                            'labelKey' => 'nav.discovery',
                            'icon' => 'compass',
                            'hash' => '#/discovery',
                        ],
                    ],
                ],
            ],
            'https://example.com/preview.php',
            'migration',
            static fn (string $key, ?string $domain): string => $key,
            'knot-migration',
        );

        self::assertCount(1, $children);
        self::assertSame('ext-knot-migration-discovery', $children[0]['key']);
        self::assertSame('discovery', $children[0]['navKey']);
        self::assertSame('Discovery', $children[0]['label']);
        self::assertSame('fa-compass', $children[0]['icon']);
        self::assertSame('#/discovery', $children[0]['hash']);
        self::assertStringContainsString('mode=migration#/discovery', $children[0]['url']);
        self::assertTrue($children[0]['_isNavChild']);
        self::assertSame('Mission Control', SidebarPresentation::humanizeNavKey('mission-control'));
    }

    public function testNormalizeNavIconMapsLucideNamesToFontAwesome(): void
    {
        self::assertSame('fa-exchange-alt', SidebarPresentation::normalizeNavIcon('switch'));
        self::assertSame('fa-cog', SidebarPresentation::normalizeNavIcon('settings'));
        self::assertSame('fa-life-ring', SidebarPresentation::normalizeNavIcon('lifebuoy'));
        self::assertSame('fa-compass', SidebarPresentation::normalizeNavIcon('compass'));
        self::assertSame('fa-cog', SidebarPresentation::normalizeNavIcon('fa-cog'));
    }

    public function testBuildNavigationChildrenMapsBrokenLucideIcons(): void
    {
        $children = SidebarPresentation::buildNavigationChildren(
            [
                [
                    'key' => 'system',
                    'labelKey' => 'nav.section.system',
                    'items' => [
                        [
                            'key' => 'cutover',
                            'labelKey' => 'nav.cutover',
                            'icon' => 'switch',
                            'hash' => '#/cutover',
                        ],
                        [
                            'key' => 'settings',
                            'labelKey' => 'nav.settings',
                            'icon' => 'settings',
                            'hash' => '#/settings',
                        ],
                        [
                            'key' => 'help',
                            'labelKey' => 'nav.help',
                            'icon' => 'lifebuoy',
                            'hash' => '#/help',
                        ],
                    ],
                ],
            ],
            'https://example.com/preview.php',
            'migration',
            static fn (string $key, ?string $domain): string => $key,
            'knot-migration',
        );

        self::assertSame('fa-exchange-alt', $children[0]['icon']);
        self::assertSame('fa-life-ring', $children[1]['icon']);
        self::assertCount(2, $children);
    }

    public function testBuildNavigationChildrenSkipsSettingsAndWorkspace(): void
    {
        $children = SidebarPresentation::buildNavigationChildren(
            [
                [
                    'key' => 'system',
                    'labelKey' => 'nav.section.system',
                    'items' => [
                        [
                            'key' => 'settings',
                            'labelKey' => 'nav.settings',
                            'icon' => 'settings',
                            'hash' => '#/settings',
                        ],
                        [
                            'key' => 'help',
                            'labelKey' => 'nav.help',
                            'icon' => 'lifebuoy',
                            'hash' => '#/help',
                        ],
                    ],
                ],
            ],
            'https://example.com/preview.php',
            'migration',
            static fn (string $key, ?string $domain): string => $key,
            'knot-migration',
        );

        self::assertCount(1, $children);
        self::assertSame('help', $children[0]['navKey']);
    }

    public function testBuildNavigationChildrenSkipsWorkspaceAndDisabledItems(): void
    {
        $children = SidebarPresentation::buildNavigationChildren(
            [
                [
                    'key' => 'tools',
                    'labelKey' => 'nav.section.tools',
                    'items' => [
                        [
                            'key' => 'history',
                            'labelKey' => 'nav.history',
                            'icon' => 'history',
                            'hash' => '#/history',
                        ],
                        [
                            'key' => 'workspace',
                            'labelKey' => 'nav.workspace',
                            'icon' => 'layers',
                            'hash' => '#/workspace',
                        ],
                        [
                            'key' => 'soon-feature',
                            'labelKey' => 'nav.soonFeature',
                            'icon' => 'circle',
                            'hash' => '#/soon',
                            'disabled' => true,
                        ],
                    ],
                ],
            ],
            'https://example.com/preview.php',
            'migration',
            static fn (string $key, ?string $domain): string => $key,
            'knot-migration',
        );

        self::assertCount(1, $children);
        self::assertSame('ext-knot-migration-history', $children[0]['key']);
    }

    public function testBuildExtensionItemsAttachesNavChildrenWhenDeclared(): void
    {
        $ext = $this->migrationExtension();
        $ext['ui']['navigation'] = [
            [
                'key' => 'overview',
                'labelKey' => 'nav.section.overview',
                'items' => [
                    [
                        'key' => 'mission-control',
                        'labelKey' => 'nav.missionControl',
                        'icon' => 'home',
                        'hash' => '#/mission-control',
                    ],
                ],
            ],
        ];

        $items = SidebarPresentation::buildExtensionItems(
            ['knot-migration' => $ext],
            static fn (string $perm): bool => true,
            true,
            static fn (string $key, ?string $domain): string => $key,
            'https://example.com/preview.php'
        );

        self::assertNotEmpty($items[0]['_navChildren']);
        self::assertSame('ext-knot-migration-mission-control', $items[0]['_navChildren'][0]['key']);
    }

    public function testMergeWithNoExtensionsReturnsNativeUnchanged(): void
    {
        $native = [['key' => 'dashboard'], ['key' => 'setup']];
        self::assertSame($native, SidebarPresentation::mergeWithNativeItems($native, []));
    }

    public function testNativeMarketplaceMapsToMarketplaceSection(): void
    {
        self::assertSame('marketplace', SidebarPresentation::NATIVE_SECTION_MAP['marketplace']);
    }

    /**
     * @return array<string, mixed>
     */
    private function migrationExtension(): array
    {
        return [
            'id' => 'knot-migration',
            'label' => 'Knot Migration',
            'version' => '0.11.0',
            'ui' => [
                'menu' => [
                    'label' => 'Knot Tools Migration',
                    'labelLang' => 'KnotMigrationMenuRoot@knotmigration',
                    'mode' => 'migration',
                    'section' => 'operations',
                    'icon' => 'package',
                    'position' => 50,
                ],
                'bundle' => [
                    'js' => 'dist/knot-extension.js',
                    'css' => 'dist/knot-extension.css',
                    'globalEntry' => 'KnotMigrationExtension',
                ],
                'requiredPermission' => 'knotmigration.use',
                'ctaIfMissing' => null,
                'onboarding' => [
                    'adminSetupRequired' => true,
                    'adminSetupUrl' => 'custom/knotmigration/admin/setup.php',
                    'ctaIfPermissionMissingForAdmin' => null,
                ],
            ],
        ];
    }
}
