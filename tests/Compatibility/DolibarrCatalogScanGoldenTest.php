<?php

declare(strict_types=1);

namespace Knot\Tests\Compatibility;

use PHPUnit\Framework\TestCase;

/**
 * Portable dolibarr-catalog.json must stay aligned after upstream Dolibarr bumps.
 *
 * Regenerate via `docker exec … export_dolibarr_audit_tables.php --dol-root=/var/www/html --write-catalog=/tmp/…`.
 */
final class DolibarrCatalogScanGoldenTest extends TestCase
{
    public function testPortableCatalogBaselineContainsCriticalBusinessSlugs(): void
    {
        $path = dirname(__DIR__, 2) . '/data/compatibility/dolibarr-catalog.json';
        if (!is_readable($path)) {
            self::markTestSkipped('Portable catalog artifact missing.');
        }

        $raw = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($raw);
        self::assertSame('knot.dolibarr-catalog', $raw['format'] ?? null);

        /** @var list<array<string, mixed>> $scan */
        $scan = $raw['scan'] ?? [];
        self::assertGreaterThanOrEqual(100, count($scan), 'Regenerate dolibarr-catalog.json after Dolibarr or scanner changes.');
        $slugs = array_column($scan, 'slug');

        foreach (['thirdparty', 'facture', 'commande', 'propal', 'product', 'actioncomm', 'expedition'] as $required) {
            self::assertContains($required, $slugs, 'Missing slug ' . $required . '; regenerate dolibarr-catalog.json.');
        }
    }
}
