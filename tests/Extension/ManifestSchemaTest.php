<?php

declare(strict_types=1);

namespace Knot\Tests\Extension;

use Knot\Extension\ManifestSchema;
use PHPUnit\Framework\TestCase;

final class ManifestSchemaTest extends TestCase
{
    public function testValidMinimalManifestPasses(): void
    {
        $manifest = [
            'id' => 'knot-stripe-pro',
            'label' => 'Knot Stripe Pro',
            'version' => '1.0.0',
            'author' => 'Knot Team',
            'category' => 'pro',
            'license' => ['type' => 'commercial', 'validation' => 'local', 'productId' => '12345'],
            'requires' => ['knot' => '>=2.3.5', 'dolibarr' => '>=17.0'],
            'connectors' => ['Knot\\Extension\\StripePro\\SubscriptionAction'],
            'namespace' => 'Knot\\Extension\\StripePro\\',
        ];

        $result = ManifestSchema::validate($manifest);

        self::assertTrue($result['valid'], implode('; ', $result['errors']));
        self::assertSame([], $result['errors']);
        self::assertNotNull($result['normalised']);
        self::assertSame('knot-stripe-pro', $result['normalised']['id']);
        self::assertSame('local', $result['normalised']['license']['validation']);
        self::assertSame('12345', $result['normalised']['license']['productId']);
    }

    public function testFreeExtensionWithoutLicenseBlockPasses(): void
    {
        $result = ManifestSchema::validate([
            'id' => 'knot-utility',
            'label' => 'Knot Utility',
            'version' => '0.1.0',
            'author' => 'Acme',
            'connectors' => [],
        ]);
        self::assertTrue($result['valid'], implode('; ', $result['errors']));
        self::assertSame('free', $result['normalised']['license']['type']);
        self::assertSame('none', $result['normalised']['license']['validation']);
    }

    public function testInvalidIdRejected(): void
    {
        $result = ManifestSchema::validate([
            'id' => 'KnotProPack', // uppercase = invalid
            'label' => 'x',
            'version' => '1.0.0',
            'author' => 'a',
        ]);
        self::assertFalse($result['valid']);
        self::assertNotEmpty($result['errors']);
    }

    public function testInvalidVersionRejected(): void
    {
        $result = ManifestSchema::validate([
            'id' => 'knot-x',
            'label' => 'X',
            'version' => 'not-semver',
            'author' => 'a',
        ]);
        self::assertFalse($result['valid']);
        self::assertContains('version must be valid semver (e.g. 1.0.0 or 1.2.3-beta1)', $result['errors']);
    }

    public function testCommercialWithoutValidationRejected(): void
    {
        $result = ManifestSchema::validate([
            'id' => 'knot-paid',
            'label' => 'Paid',
            'version' => '1.0.0',
            'author' => 'a',
            'license' => ['type' => 'commercial', 'validation' => 'none'],
        ]);
        self::assertFalse($result['valid']);
        self::assertNotEmpty(array_filter(
            $result['errors'],
            static fn (string $e) => str_contains($e, 'license.validation cannot be "none"')
        ));
    }

    public function testDolistoreWithoutProductIdRejected(): void
    {
        $result = ManifestSchema::validate([
            'id' => 'knot-paid',
            'label' => 'Paid',
            'version' => '1.0.0',
            'author' => 'a',
            'license' => ['type' => 'commercial', 'validation' => 'dolistore'],
        ]);
        self::assertFalse($result['valid']);
        self::assertNotEmpty(array_filter(
            $result['errors'],
            static fn (string $e) => str_contains($e, 'productId is required')
        ));
    }

    public function testLicenseValidationAliasNormalizesToDolistore(): void
    {
        $result = ManifestSchema::validate([
            'id' => 'knot-paid',
            'label' => 'Paid',
            'version' => '1.0.0',
            'author' => 'a',
            'license' => ['type' => 'commercial', 'validation' => 'license', 'productId' => 'knot-pro-pack'],
        ]);
        self::assertTrue($result['valid'], implode('; ', $result['errors']));
        self::assertSame('dolistore', $result['normalised']['license']['validation']);
    }

    public function testInvalidConnectorFqcnRejected(): void
    {
        $result = ManifestSchema::validate([
            'id' => 'knot-x',
            'label' => 'X',
            'version' => '1.0.0',
            'author' => 'a',
            'connectors' => ['NotAFQCN'],
        ]);
        self::assertFalse($result['valid']);
    }

    public function testNonArrayManifestRejected(): void
    {
        $result = ManifestSchema::validate('not-an-array');
        self::assertFalse($result['valid']);
        self::assertSame(['manifest must be a JSON object'], $result['errors']);
    }

    public function testManifestSignaturePassesWhenHex128(): void
    {
        $signature = strtolower(str_repeat('ab', 64));
        $manifest = [
            'id' => 'knot-stripe-pro',
            'label' => 'Stripe',
            'version' => '1.0.0',
            'author' => 'Knot',
            'license' => [
                'type' => 'commercial',
                'validation' => 'dolistore',
                'productId' => 'p1',
                'manifestSignature' => $signature,
            ],
            'connectors' => [],
        ];

        $result = ManifestSchema::validate($manifest);
        self::assertTrue($result['valid'], implode('; ', $result['errors']));
        self::assertSame($signature, $result['normalised']['license']['manifestSignature']);
    }

    public function testInvalidManifestSignatureLengthRejected(): void
    {
        $manifest = [
            'id' => 'knot-stripe-pro',
            'label' => 'Stripe',
            'version' => '1.0.0',
            'author' => 'Knot',
            'license' => [
                'type' => 'commercial',
                'validation' => 'dolistore',
                'productId' => 'p1',
                'manifestSignature' => str_repeat('a', 127),
            ],
            'connectors' => [],
        ];

        self::assertFalse(ManifestSchema::validate($manifest)['valid']);
    }

    public function testSatisfies(): void
    {
        self::assertTrue(ManifestSchema::satisfies('2.3.5', '*'));
        self::assertTrue(ManifestSchema::satisfies('2.3.5', '>=2.3.5'));
        self::assertTrue(ManifestSchema::satisfies('2.3.5', '>=2.0.0'));
        self::assertFalse(ManifestSchema::satisfies('2.3.5', '>=2.4.0'));
        self::assertTrue(ManifestSchema::satisfies('2.3.5', '^2.0.0'));
        self::assertFalse(ManifestSchema::satisfies('3.0.0', '^2.0.0'));
        self::assertTrue(ManifestSchema::satisfies('2.3.5', '~2.3.0'));
        self::assertFalse(ManifestSchema::satisfies('2.4.0', '~2.3.0'));
        self::assertTrue(ManifestSchema::satisfies('17.0.0', '>=17.0'));
        self::assertTrue(ManifestSchema::satisfies('21.0.4', '>=17.0'));
    }

    public function testInvalidVersionRangeRejected(): void
    {
        $result = ManifestSchema::validate([
            'id' => 'knot-x',
            'label' => 'X',
            'version' => '1.0.0',
            'author' => 'a',
            'requires' => ['knot' => '!!gibberish', 'dolibarr' => '*'],
        ]);
        self::assertFalse($result['valid']);
    }

    // -----------------------------------------------------------------
    // ADR-20: optional `ui` section (sidebar menu + runtime bundle +
    // onboarding hooks). Absent = no UI surface, extension still loads
    // its connectors normally.
    // -----------------------------------------------------------------

    public function testUiSectionAbsentIsValidAndNormalisedToNull(): void
    {
        $result = ManifestSchema::validate($this->baseManifest());
        self::assertTrue($result['valid'], implode('; ', $result['errors']));
        self::assertArrayHasKey('ui', $result['normalised']);
        self::assertNull($result['normalised']['ui']);
    }

    public function testUiSectionFullyPopulatedPasses(): void
    {
        $manifest = $this->baseManifest();
        $manifest['ui'] = [
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
            'ctaIfMissing' => [
                'label' => 'Contact admin',
                'url' => 'https://knot.tools/support',
            ],
            'onboarding' => [
                'adminSetupRequired' => true,
                'adminSetupUrl' => 'custom/knotmigration/admin/setup.php',
                'ctaIfPermissionMissingForAdmin' => 'KnotMigrationFirstSetupCTA@knotmigration',
            ],
        ];

        $result = ManifestSchema::validate($manifest);

        self::assertTrue($result['valid'], implode('; ', $result['errors']));
        $ui = $result['normalised']['ui'];
        self::assertIsArray($ui);
        self::assertSame('migration', $ui['menu']['mode']);
        self::assertSame('operations', $ui['menu']['section']);
        self::assertSame('package', $ui['menu']['icon']);
        self::assertSame(50, $ui['menu']['position']);
        self::assertSame('dist/knot-extension.js', $ui['bundle']['js']);
        self::assertSame('dist/knot-extension.css', $ui['bundle']['css']);
        self::assertSame('KnotMigrationExtension', $ui['bundle']['globalEntry']);
        self::assertSame('knotmigration.use', $ui['requiredPermission']);
        self::assertSame(['label' => 'Contact admin', 'url' => 'https://knot.tools/support'], $ui['ctaIfMissing']);
        self::assertTrue($ui['onboarding']['adminSetupRequired']);
        self::assertSame('custom/knotmigration/admin/setup.php', $ui['onboarding']['adminSetupUrl']);
        self::assertSame('KnotMigrationFirstSetupCTA@knotmigration', $ui['onboarding']['ctaIfPermissionMissingForAdmin']);
    }

    public function testUiSectionAppliesDefaults(): void
    {
        $manifest = $this->baseManifest();
        $manifest['ui'] = [
            'menu' => [
                'label' => 'My Tool',
                'labelLang' => 'MyToolLabel@mytool',
                'mode' => 'my-tool',
            ],
            'bundle' => [
                'js' => 'dist/knot-extension.js',
                'globalEntry' => 'MyToolExtension',
            ],
        ];

        $result = ManifestSchema::validate($manifest);

        self::assertTrue($result['valid'], implode('; ', $result['errors']));
        $ui = $result['normalised']['ui'];
        self::assertSame('operations', $ui['menu']['section']);
        self::assertSame('puzzle', $ui['menu']['icon']);
        self::assertSame(1000, $ui['menu']['position']);
        self::assertNull($ui['bundle']['css']);
        self::assertNull($ui['requiredPermission']);
        self::assertNull($ui['ctaIfMissing']);
        self::assertFalse($ui['onboarding']['adminSetupRequired']);
        self::assertNull($ui['onboarding']['adminSetupUrl']);
        self::assertNull($ui['onboarding']['ctaIfPermissionMissingForAdmin']);
    }

    public function testUiSectionRejectsMissingMenu(): void
    {
        $manifest = $this->baseManifest();
        $manifest['ui'] = [
            'bundle' => [
                'js' => 'dist/knot-extension.js',
                'globalEntry' => 'X',
            ],
        ];

        $result = ManifestSchema::validate($manifest);

        self::assertFalse($result['valid']);
        self::assertContains('ui.menu is required when ui is present', $result['errors']);
    }

    public function testUiSectionRejectsMissingBundle(): void
    {
        $manifest = $this->baseManifest();
        $manifest['ui'] = [
            'menu' => [
                'label' => 'X',
                'labelLang' => 'X@x',
                'mode' => 'x',
            ],
        ];

        $result = ManifestSchema::validate($manifest);

        self::assertFalse($result['valid']);
        self::assertContains('ui.bundle is required when ui is present', $result['errors']);
    }

    public function testUiMenuRejectsUppercaseMode(): void
    {
        $manifest = $this->baseManifest();
        $manifest['ui'] = [
            'menu' => [
                'label' => 'X',
                'labelLang' => 'X@x',
                'mode' => 'NotKebab',
            ],
            'bundle' => [
                'js' => 'dist/knot-extension.js',
                'globalEntry' => 'X',
            ],
        ];

        $result = ManifestSchema::validate($manifest);

        self::assertFalse($result['valid']);
        self::assertNotEmpty(array_filter(
            $result['errors'],
            static fn (string $e) => str_contains($e, 'ui.menu.mode must be kebab-case')
        ));
    }

    public function testUiMenuAcceptsMarketplaceSection(): void
    {
        $manifest = $this->baseManifest();
        $manifest['ui'] = [
            'menu' => [
                'label' => 'Knot Migration',
                'labelLang' => 'KnotMigration@knotmigration',
                'mode' => 'migration',
                'section' => 'marketplace',
                'placement' => 'end',
            ],
            'bundle' => [
                'js' => 'dist/knot-extension.js',
                'globalEntry' => 'KnotMigrationExtension',
            ],
        ];

        $result = ManifestSchema::validate($manifest);

        self::assertTrue($result['valid'], implode('; ', $result['errors']));
    }

    public function testUiMenuRejectsUnknownSection(): void
    {
        $manifest = $this->baseManifest();
        $manifest['ui'] = [
            'menu' => [
                'label' => 'X',
                'labelLang' => 'X@x',
                'mode' => 'x',
                'section' => 'random',
            ],
            'bundle' => [
                'js' => 'dist/knot-extension.js',
                'globalEntry' => 'X',
            ],
        ];

        $result = ManifestSchema::validate($manifest);

        self::assertFalse($result['valid']);
        self::assertNotEmpty(array_filter(
            $result['errors'],
            static fn (string $e) => str_contains($e, 'ui.menu.section must be one of')
        ));
    }

    public function testUiBundleRejectsBadGlobalEntry(): void
    {
        $manifest = $this->baseManifest();
        $manifest['ui'] = [
            'menu' => [
                'label' => 'X',
                'labelLang' => 'X@x',
                'mode' => 'x',
            ],
            'bundle' => [
                'js' => 'dist/knot-extension.js',
                'globalEntry' => '99-bad-identifier',
            ],
        ];

        $result = ManifestSchema::validate($manifest);

        self::assertFalse($result['valid']);
        self::assertNotEmpty(array_filter(
            $result['errors'],
            static fn (string $e) => str_contains($e, 'ui.bundle.globalEntry must be a valid JS identifier')
        ));
    }

    public function testUiRequiredPermissionMustFollowDolibarrShape(): void
    {
        $manifest = $this->baseManifest();
        $manifest['ui'] = [
            'menu' => [
                'label' => 'X',
                'labelLang' => 'X@x',
                'mode' => 'x',
            ],
            'bundle' => [
                'js' => 'dist/knot-extension.js',
                'globalEntry' => 'X',
            ],
            'requiredPermission' => 'NotDolibarrShape',
        ];

        $result = ManifestSchema::validate($manifest);

        self::assertFalse($result['valid']);
        self::assertNotEmpty(array_filter(
            $result['errors'],
            static fn (string $e) => str_contains($e, 'ui.requiredPermission must follow')
        ));
    }

    public function testUiCtaIfMissingRequiresHttpsUrl(): void
    {
        $manifest = $this->baseManifest();
        $manifest['ui'] = [
            'menu' => [
                'label' => 'X',
                'labelLang' => 'X@x',
                'mode' => 'x',
            ],
            'bundle' => [
                'js' => 'dist/knot-extension.js',
                'globalEntry' => 'X',
            ],
            'ctaIfMissing' => [
                'label' => 'OK',
                'url' => 'not-a-url',
            ],
        ];

        $result = ManifestSchema::validate($manifest);

        self::assertFalse($result['valid']);
        self::assertNotEmpty(array_filter(
            $result['errors'],
            static fn (string $e) => str_contains($e, 'ui.ctaIfMissing.url must be an http(s) URL')
        ));
    }

    public function testUiOnboardingAdminSetupRequiredMustBeBoolean(): void
    {
        $manifest = $this->baseManifest();
        $manifest['ui'] = [
            'menu' => [
                'label' => 'X',
                'labelLang' => 'X@x',
                'mode' => 'x',
            ],
            'bundle' => [
                'js' => 'dist/knot-extension.js',
                'globalEntry' => 'X',
            ],
            'onboarding' => [
                'adminSetupRequired' => 'yes',
            ],
        ];

        $result = ManifestSchema::validate($manifest);

        self::assertFalse($result['valid']);
        self::assertContains('ui.onboarding.adminSetupRequired must be a boolean', $result['errors']);
    }

    // ADR-20 Phase 6g §L2 — `ui.navigation` validation tests.

    public function testUiNavigationAbsentIsValidAndNormalisedToNull(): void
    {
        $manifest = $this->baseManifest();
        $manifest['ui'] = [
            'menu' => ['label' => 'X', 'labelLang' => 'X@x', 'mode' => 'demo'],
            'bundle' => ['js' => 'dist/knot-extension.js', 'globalEntry' => 'X'],
        ];

        $result = ManifestSchema::validate($manifest);

        self::assertTrue($result['valid'], implode('; ', $result['errors']));
        self::assertNull($result['normalised']['ui']['navigation']);
    }

    public function testUiNavigationHierarchicalShapeIsNormalised(): void
    {
        $manifest = $this->baseManifest();
        $manifest['ui'] = [
            'menu' => ['label' => 'X', 'labelLang' => 'X@x', 'mode' => 'demo'],
            'bundle' => ['js' => 'dist/knot-extension.js', 'globalEntry' => 'X'],
            'navigation' => [
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
                [
                    'key' => 'system',
                    'labelKey' => 'nav.section.system',
                    'items' => [
                        [
                            'key' => 'settings',
                            'labelKey' => 'nav.settings',
                            'icon' => 'settings',
                            'hash' => '#/settings',
                            'badge' => 'nav.settings.badge',
                        ],
                    ],
                ],
            ],
        ];

        $result = ManifestSchema::validate($manifest);

        self::assertTrue($result['valid'], implode('; ', $result['errors']));
        $nav = $result['normalised']['ui']['navigation'];
        self::assertIsArray($nav);
        self::assertCount(2, $nav);
        self::assertSame('overview', $nav[0]['key']);
        self::assertSame('mission-control', $nav[0]['items'][0]['key']);
        self::assertNull($nav[0]['items'][0]['badge']);
        self::assertSame('nav.settings.badge', $nav[1]['items'][0]['badge']);
    }

    public function testUiNavigationRejectsNonListInput(): void
    {
        $manifest = $this->baseManifest();
        $manifest['ui'] = [
            'menu' => ['label' => 'X', 'labelLang' => 'X@x', 'mode' => 'demo'],
            'bundle' => ['js' => 'dist/knot-extension.js', 'globalEntry' => 'X'],
            'navigation' => ['some' => 'object'],
        ];

        $result = ManifestSchema::validate($manifest);

        self::assertFalse($result['valid']);
        self::assertContains('ui.navigation must be a JSON array of sections', $result['errors']);
    }

    public function testUiNavigationRejectsDuplicateSectionKeys(): void
    {
        $manifest = $this->baseManifest();
        $manifest['ui'] = [
            'menu' => ['label' => 'X', 'labelLang' => 'X@x', 'mode' => 'demo'],
            'bundle' => ['js' => 'dist/knot-extension.js', 'globalEntry' => 'X'],
            'navigation' => [
                [
                    'key' => 'overview',
                    'labelKey' => 'nav.section.overview',
                    'items' => [
                        ['key' => 'aa', 'labelKey' => 'l', 'icon' => 'i', 'hash' => '#/a'],
                    ],
                ],
                [
                    'key' => 'overview',
                    'labelKey' => 'nav.section.overview',
                    'items' => [
                        ['key' => 'bb', 'labelKey' => 'l', 'icon' => 'i', 'hash' => '#/b'],
                    ],
                ],
            ],
        ];

        $result = ManifestSchema::validate($manifest);

        self::assertFalse($result['valid']);
        self::assertNotEmpty(array_filter(
            $result['errors'],
            static fn (string $e) => str_contains($e, 'is duplicated'),
        ));
    }

    public function testUiNavigationRejectsHashWithoutLeadingSymbol(): void
    {
        $manifest = $this->baseManifest();
        $manifest['ui'] = [
            'menu' => ['label' => 'X', 'labelLang' => 'X@x', 'mode' => 'demo'],
            'bundle' => ['js' => 'dist/knot-extension.js', 'globalEntry' => 'X'],
            'navigation' => [
                [
                    'key' => 'overview',
                    'labelKey' => 'nav.section.overview',
                    'items' => [
                        ['key' => 'aa', 'labelKey' => 'l', 'icon' => 'i', 'hash' => '/no-leading-hash'],
                    ],
                ],
            ],
        ];

        $result = ManifestSchema::validate($manifest);

        self::assertFalse($result['valid']);
        self::assertNotEmpty(array_filter(
            $result['errors'],
            static fn (string $e) => str_contains($e, 'hash must be a string starting with "#"'),
        ));
    }

    public function testUiNavigationRejectsEmptyItems(): void
    {
        $manifest = $this->baseManifest();
        $manifest['ui'] = [
            'menu' => ['label' => 'X', 'labelLang' => 'X@x', 'mode' => 'demo'],
            'bundle' => ['js' => 'dist/knot-extension.js', 'globalEntry' => 'X'],
            'navigation' => [
                ['key' => 'overview', 'labelKey' => 'l', 'items' => []],
            ],
        ];

        $result = ManifestSchema::validate($manifest);

        self::assertFalse($result['valid']);
        self::assertNotEmpty(array_filter(
            $result['errors'],
            static fn (string $e) => str_contains($e, 'items must be a non-empty array'),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function baseManifest(): array
    {
        return [
            'id' => 'knot-x',
            'label' => 'X',
            'version' => '1.0.0',
            'author' => 'a',
        ];
    }
}
