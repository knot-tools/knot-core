<?php

declare(strict_types=1);

namespace Knot\Tests\Connectors;

use Knot\Connectors\ConnectorRegistry;
use Knot\Extension\ExtensionRegistry;
use PHPUnit\Framework\TestCase;

final class ConnectorRegistryMethodsTest extends TestCase
{
    public function testPaletteSectionsGroupsCoreConnectorsByCategory(): void
    {
        $sections = (new ConnectorRegistry())->paletteSections();
        self::assertNotSame([], $sections);

        $categories = array_column($sections, 'category');
        self::assertContains('trigger', $categories);
        self::assertContains('logic', $categories);
        self::assertContains('dolibarr', $categories);

        foreach ($sections as $section) {
            self::assertIsArray($section['ids'] ?? null);
            self::assertNotSame([], $section['ids']);
        }
    }

    public function testDescribeAllForPaletteReturnsCoreDescriptors(): void
    {
        $rows = (new ConnectorRegistry())->describeAllForPalette(new ExtensionRegistry(), [], 'en');
        self::assertGreaterThanOrEqual(31, count($rows));

        $ids = array_column($rows, 'id');
        self::assertContains('logic.set', $ids);
        self::assertContains('trigger.manual', $ids);

        foreach ($rows as $row) {
            if (($row['source'] ?? '') !== ConnectorRegistry::SOURCE_CORE) {
                continue;
            }
            self::assertTrue($row['available']);
            self::assertNotSame('', $row['fqcn']);
        }
    }

    public function testAllWithExtensionsKeepsCoreOnIdCollision(): void
    {
        $registry = new ConnectorRegistry();
        $coreIds = array_keys($registry->all());
        $merged = $registry->allWithExtensions(new ExtensionRegistry());
        foreach ($coreIds as $id) {
            self::assertArrayHasKey($id, $merged);
            self::assertSame($registry->all()[$id]::class, $merged[$id]::class);
        }
    }
}
