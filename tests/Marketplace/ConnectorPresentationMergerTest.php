<?php

declare(strict_types=1);

namespace Knot\Tests\Marketplace;

use Knot\Marketplace\ConnectorPresentationMerger;
use PHPUnit\Framework\TestCase;

final class ConnectorPresentationMergerTest extends TestCase
{
    public function testMergeAppliesLocalizedLabelBlurbSortOrder(): void
    {
        $rows = [[
            'extensionManifestId' => 'tenant-demo',
            'fqcn' => 'Demo\\Actions\\Ping',
            'label' => 'Core label',
            'category' => 'logic',
            'description' => 'Core description',
        ]];

        $snippet = [[
            'extensionId' => 'tenant-demo',
            'fqcn' => 'Demo\\Actions\\Ping',
            'paletteCategory' => 'ai',
            'displayLabel' => ['fr' => 'Libellé FR', 'en' => 'Label EN'],
            'blurb' => ['fr' => 'Accroche', 'en' => 'Blurb EN'],
            'sortOrder' => 42,
        ]];

        $merged = ConnectorPresentationMerger::mergePaletteRows($rows, $snippet, 'en');

        self::assertSame('Label EN', $merged[0]['label']);
        self::assertSame('Blurb EN', $merged[0]['description']);
        self::assertSame('ai', $merged[0]['category']);
        self::assertSame(42, $merged[0]['snippetSortOrder']);
    }

    public function testUnknownPaletteCategoryIsIgnored(): void
    {
        $rows = [[
            'extensionManifestId' => 'tenant-demo',
            'fqcn' => 'Demo\\Actions\\Ping',
            'label' => 'Core label',
            'category' => 'logic',
            'description' => 'Core description',
        ]];

        $snippet = [[
            'extensionId' => 'tenant-demo',
            'fqcn' => 'Demo\\Actions\\Ping',
            'paletteCategory' => 'not-a-catalog-category',
            'displayLabel' => ['en' => 'Snippet label'],
            'blurb' => ['en' => 'Snippet body'],
            'sortOrder' => 1,
        ]];

        $merged = ConnectorPresentationMerger::mergePaletteRows($rows, $snippet, 'en');

        self::assertSame('logic', $merged[0]['category']);
        self::assertSame('Snippet label', $merged[0]['label']);
    }

    public function testBuildSnippetLookupIsCaseInsensitiveOnExtensionId(): void
    {
        $snippet = [[
            'extensionId' => 'Tenant-DEMO',
            'fqcn' => 'Demo\\Actions\\Ping',
            'displayLabel' => ['en' => 'X'],
        ]];
        $lookup = ConnectorPresentationMerger::buildSnippetLookup($snippet);
        self::assertArrayHasKey('tenant-demo|Demo\\Actions\\Ping', $lookup);
    }
}
