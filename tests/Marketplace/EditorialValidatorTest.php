<?php

declare(strict_types=1);

namespace Knot\Tests\Marketplace;

use Knot\Marketplace\CatalogCache;
use Knot\Marketplace\EditorialValidator;
use PHPUnit\Framework\TestCase;

final class EditorialValidatorTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function minimalV2Payload(): array
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
                    'title' => 'Spotlight',
                    'items' => [
                        [
                            'kind' => 'pack',
                            'slug' => 'knot-pro-pack',
                            'tagline' => 'Advanced connectors for Dolibarr.',
                            'accent' => 'pro',
                            'ctas' => [
                                ['label' => 'Buy', 'href' => 'https://knot.tools/pro-pack/'],
                            ],
                        ],
                    ],
                ],
                'collections' => [
                    [
                        'slug' => 'recent-templates',
                        'label' => 'Recent',
                        'query' => ['sort' => 'recent'],
                        'limit' => 12,
                    ],
                ],
            ],
            'productPages' => [
                'knot-pro-pack' => [
                    'hero' => [
                        'tagline' => 'Pro Pack for Dolibarr automation.',
                        'version' => '2.13.7',
                        'author' => 'Knot Tools',
                        'tier' => 'pro',
                    ],
                    'tabs' => [
                        [
                            'id' => 'overview',
                            'label' => 'Overview',
                            'kind' => 'richtext',
                            'visible' => true,
                            'props' => ['body' => 'Overview copy.'],
                        ],
                    ],
                ],
            ],
        ];
    }

    public function testVersionOneRejectedVersionThreeRejected(): void
    {
        $base = self::minimalV2Payload();
        unset($base['version']);
        $v1 = $base + ['version' => 1];
        self::assertContains('version_invalid', EditorialValidator::validate($v1)->errors());
        $v3 = $base + ['version' => 3];
        self::assertContains('version_invalid', EditorialValidator::validate($v3)->errors());
    }

    public function testMinimalV2PayloadPasses(): void
    {
        self::assertTrue(EditorialValidator::validate(self::minimalV2Payload())->isValid());
    }

    public function testSidebarBadgeBounds(): void
    {
        $data = self::minimalV2Payload();
        $data['sidebar'] = [
            'badge' => [
                'label' => 'abcdefghijklm',
                'variant' => 'info',
                'ariaLabel' => 'ok',
                'hasUnread' => false,
            ],
        ];
        $r = EditorialValidator::validate($data);
        self::assertFalse($r->isValid());
        self::assertContains('sidebar.badge.label_length', $r->errors());
    }

    public function testForbiddenSpotlightCtaRejected(): void
    {
        $data = self::minimalV2Payload();
        $data['home']['spotlight']['items'][0]['ctas'] = [
            ['label' => 'Go', 'href' => 'https://evil.example/x'],
        ];
        $r = EditorialValidator::validate($data);
        self::assertFalse($r->isValid());
        self::assertContains('home.spotlight.items.0.ctas.0.href_host_not_allowed', $r->errors());
    }

    public function testHttpsRequired(): void
    {
        $data = self::minimalV2Payload();
        $data['home']['spotlight']['items'][0]['ctas'] = [
            ['label' => 'Go', 'href' => 'http://knot.tools/pricing'],
        ];
        self::assertFalse(EditorialValidator::validate($data)->isValid());
    }

    public function testSvgHrefRejected(): void
    {
        $data = self::minimalV2Payload();
        $data['productPages']['knot-pro-pack']['tabs'][] = [
            'id' => 'shots',
            'label' => 'Shots',
            'kind' => 'screenshots',
            'visible' => true,
            'props' => [
                'images' => [
                    ['src' => 'https://cdn.knot.tools/asset.svg', 'alt' => 'Bad'],
                ],
            ],
        ];
        self::assertFalse(EditorialValidator::validate($data)->isValid());
    }

    public function testScreenshotRequiresAlt(): void
    {
        $data = self::minimalV2Payload();
        $data['productPages']['knot-pro-pack']['tabs'][] = [
            'id' => 'shots',
            'label' => 'Shots',
            'kind' => 'screenshots',
            'visible' => true,
            'props' => [
                'images' => [
                    ['src' => 'https://cdn.knot.tools/foo.png'],
                ],
            ],
        ];
        self::assertFalse(EditorialValidator::validate($data)->isValid());
    }

    public function testScriptSnippetRejectedInTabBody(): void
    {
        $data = self::minimalV2Payload();
        $data['productPages']['knot-pro-pack']['tabs'][0]['props']['body'] = 'Hi<script>Bad';
        self::assertFalse(EditorialValidator::validate($data)->isValid());
    }

    public function testAllowedKnotHostsPass(): void
    {
        $data = self::minimalV2Payload();
        $data['home']['spotlight']['items'][0]['ctas'] = [
            ['label' => 'Go', 'href' => 'https://license.knot.tools/catalog'],
        ];
        $data['sidebar'] = [
            'badge' => [
                'label' => 'Beta',
                'variant' => 'neutral',
                'ariaLabel' => 'Marketplace teasers',
                'hasUnread' => true,
            ],
        ];
        self::assertTrue(EditorialValidator::validate($data)->isValid());
    }

    public function testUnknownLucideIconRejected(): void
    {
        $data = self::minimalV2Payload();
        $data['taxonomy']['categories'][0]['icon'] = 'definitely-not-on-the-whitelist';
        $r = EditorialValidator::validate($data);
        self::assertFalse($r->isValid());
        self::assertContains('taxonomy.categories.0.icon_not_whitelisted', $r->errors());
    }

    public function testUnknownRootKeyRejected(): void
    {
        $data = self::minimalV2Payload();
        $data['futureSurface'] = ['beta' => true];
        self::assertContains('root_unknown_key', EditorialValidator::validate($data)->errors());
    }

    public function testMetaUpdatedAtInvalid(): void
    {
        $data = self::minimalV2Payload();
        $data['meta']['updatedAt'] = 'not-a-date';
        self::assertContains('meta.updatedAt_invalid', EditorialValidator::validate($data)->errors());
    }

    public function testSpotlightItemsCountBounds(): void
    {
        $data = self::minimalV2Payload();
        $data['home']['spotlight']['items'] = [];
        self::assertContains('home.spotlight.items_count', EditorialValidator::validate($data)->errors());
    }

    public function testCollectionLimitBounds(): void
    {
        $data = self::minimalV2Payload();
        $data['home']['collections'][0]['limit'] = 30;
        self::assertContains('home.collections.0.limit_invalid', EditorialValidator::validate($data)->errors());
    }

    public function testTemplatePagePrerequisitesAndUseCases(): void
    {
        $data = self::minimalV2Payload();
        $data['templatePages'] = [
            'invoice-reminder' => [
                'hero' => [
                    'tagline' => 'Invoice reminders',
                    'version' => '1.0.0',
                    'author' => 'Knot Tools',
                    'tier' => 'core',
                ],
                'tabs' => [
                    [
                        'id' => 'overview',
                        'label' => 'Overview',
                        'kind' => 'richtext',
                        'visible' => true,
                        'props' => ['body' => 'Plain copy.'],
                    ],
                ],
                'prerequisites' => [
                    'items' => [
                        ['title' => 'Knot Core', 'description' => 'Required module.'],
                    ],
                ],
                'useCases' => [
                    'items' => [
                        ['title' => 'AR team', 'description' => 'Reduce overdue invoices.'],
                    ],
                ],
            ],
        ];
        self::assertTrue(EditorialValidator::validate($data)->isValid());
    }

    public function testDeprecatedHomeLayoutStillValidatedWhenPresent(): void
    {
        $data = self::minimalV2Payload();
        $data['home']['layout'] = [
            ['id' => 'x', 'type' => 'unknown'],
        ];
        self::assertContains('home.layout.0.type_invalid', EditorialValidator::validate($data)->errors());
    }

    public function testRichTextPropsRejectJavascriptHrefInDeprecatedLayout(): void
    {
        $data = self::minimalV2Payload();
        $data['home']['layout'] = [
            [
                'id' => 'rtx',
                'type' => 'richText',
                'props' => ['note' => 'click javascript:alert(1) please'],
            ],
        ];
        $r = EditorialValidator::validate($data);
        self::assertFalse($r->isValid());
        self::assertContains('home.layout.0.props.note_unsafe', $r->errors());
    }

    public function testCatalogNormalizeLangChainsLocale(): void
    {
        self::assertSame('fr', CatalogCache::normalizeCatalogLang('fr_FR'));
        self::assertSame('zh', CatalogCache::normalizeCatalogLang('zh_CN'));
        self::assertSame('en', CatalogCache::normalizeCatalogLang(''));
    }

    public function testBundledFallbackJsonValidForAllLocales(): void
    {
        $path = dirname(__DIR__, 2) . '/data/marketplace/editorial-fallback.json';
        self::assertFileExists($path);
        $decoded = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($decoded);
        foreach (['fr', 'en', 'es', 'it', 'de', 'pt'] as $lang) {
            self::assertArrayHasKey($lang, $decoded, 'Missing locale ' . $lang);
            self::assertTrue(
                EditorialValidator::validate($decoded[$lang])->isValid(),
                'Invalid fallback for ' . $lang . ': ' . implode(', ', EditorialValidator::validate($decoded[$lang])->errors()),
            );
        }
    }
}
