<?php

declare(strict_types=1);

namespace Knot\Tests\Compatibility\Versioning;

use Knot\Compatibility\Versioning\BundledSnapshotCatalog;
use PHPUnit\Framework\TestCase;

final class BundledSnapshotCatalogTest extends TestCase
{
    public function testExcludesSamplePrefixedFiles(): void
    {
        $dir = sys_get_temp_dir() . '/knot-snap-catalog-' . bin2hex(random_bytes(4));
        self::assertTrue(mkdir($dir, 0755, true));

        try {
            file_put_contents(
                $dir . '/sample-test.json',
                json_encode([
                    'schema_version' => 'knot.snapshot.v1',
                    'dolibarr_version' => '9.9.9',
                    'generated_at' => '2026-01-01T00:00:00Z',
                    'objects' => [],
                ]),
            );
            file_put_contents(
                $dir . '/dolibarr-21.json',
                json_encode([
                    'schema_version' => 'knot.snapshot.v1',
                    'dolibarr_version' => '21.0.0',
                    'generated_at' => '2026-01-02T00:00:00Z',
                    'objects' => [],
                ]),
            );

            $catalog = new BundledSnapshotCatalog($dir);
            $rows = $catalog->listReferenceSnapshots();
            self::assertCount(1, $rows);
            self::assertSame('dolibarr-21.json', $rows[0]['filename']);
        } finally {
            foreach (glob($dir . '/*.json') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
    }

    public function testResolveReadablePathRejectsTraversal(): void
    {
        $dir = sys_get_temp_dir() . '/knot-snap-safe-' . bin2hex(random_bytes(4));
        self::assertTrue(mkdir($dir, 0755, true));

        try {
            file_put_contents(
                $dir . '/ok.json',
                json_encode([
                    'schema_version' => 'knot.snapshot.v1',
                    'dolibarr_version' => '1.0',
                    'objects' => [],
                ]),
            );

            $catalog = new BundledSnapshotCatalog($dir);
            self::assertNotNull($catalog->resolveReadablePath('ok.json'));
            self::assertNull($catalog->resolveReadablePath('../ok.json'));
            self::assertNull($catalog->resolveReadablePath('sample-x.json'));
            self::assertNull($catalog->resolveReadablePath('bad;.json'));
        } finally {
            @unlink($dir . '/ok.json');
            @rmdir($dir);
        }
    }
}
