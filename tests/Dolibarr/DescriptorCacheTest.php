<?php

declare(strict_types=1);

namespace Knot\Tests\Dolibarr;

use Knot\Dolibarr\DescriptorCache;
use PHPUnit\Framework\TestCase;

final class DescriptorCacheTest extends TestCase
{
    /** @var list<string> */
    private array $tmpDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpDirs as $tmpDir) {
            $this->removeDirectory($tmpDir);
        }
        $this->tmpDirs = [];
        parent::tearDown();
    }

    public function testReadReturnsNullWhenFileMissing(): void
    {
        $tmpDir = $this->makeTempDir();
        $cache = new DescriptorCache($tmpDir);
        self::assertNull($cache->read());
    }

    public function testReadReturnsNullForMalformedPayload(): void
    {
        $tmpDir = $this->makeTempDir();
        file_put_contents(
            $tmpDir . '/' . DescriptorCache::FILENAME,
            '{"hash":"x","generatedAt":"2026-01-01T00:00:00+00:00"}'
        );

        $cache = new DescriptorCache($tmpDir);
        self::assertNull($cache->read());
    }

    public function testWriteReadAndClearRoundTrip(): void
    {
        $tmpDir = $this->makeTempDir();
        $cache = new DescriptorCache($tmpDir);
        $descriptors = [
            ['slug' => 'facture', 'class' => 'Facture', 'module' => 'facture'],
        ];

        $cache->write($descriptors, 'abc123');
        $read = $cache->read();
        self::assertIsArray($read);
        self::assertSame('abc123', $read['hash']);
        self::assertCount(1, $read['descriptors']);

        $cache->clear();
        self::assertNull($cache->read());
        self::assertFileDoesNotExist($cache->getPath());
    }

    public function testReadUsesMemoryCacheWithoutHittingDiskTwice(): void
    {
        $tmpDir = $this->makeTempDir();
        $cache = new DescriptorCache($tmpDir);
        $cache->write([['slug' => 'a']], 'mem');

        $first = $cache->read();
        unlink($tmpDir . '/' . DescriptorCache::FILENAME);
        $second = $cache->read();

        self::assertSame($first, $second);
    }

    public function testHashForRootsIncludesClassFileMtimes(): void
    {
        $root = $this->makeTempDir();
        $moduleDir = $root . '/societe/class';
        self::assertTrue(mkdir($moduleDir, 0755, true));
        file_put_contents($moduleDir . '/societe.class.php', '<?php class Societe {}');

        $hashA = DescriptorCache::hashForRoots($root);
        file_put_contents($moduleDir . '/societe_extra.class.php', '<?php class SocieteExtra {}');
        $hashB = DescriptorCache::hashForRoots($root);

        self::assertNotSame('', $hashA);
        self::assertNotSame($hashA, $hashB);
    }

    public function testHashForRootsAcceptsExtraRoots(): void
    {
        $root = $this->makeTempDir();
        $extra = $this->makeTempDir();
        $extraClassDir = $extra . '/class';
        self::assertTrue(mkdir($extraClassDir, 0755, true));
        file_put_contents($extraClassDir . '/extra.class.php', '<?php class Extra {}');

        $without = DescriptorCache::hashForRoots($root);
        $with = DescriptorCache::hashForRoots($root, [$extra]);

        self::assertNotSame($without, $with);
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/knot-desc-cache-' . uniqid('', true);
        self::assertTrue(mkdir($dir, 0755, true));
        $this->tmpDirs[] = $dir;

        return $dir;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
                continue;
            }
            unlink($path);
        }

        rmdir($dir);
    }
}
