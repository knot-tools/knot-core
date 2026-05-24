<?php

declare(strict_types=1);

namespace Knot\Tests\Dolibarr;

use Knot\Dolibarr\DescriptorCache;
use PHPUnit\Framework\TestCase;

final class DescriptorCacheFileCountTest extends TestCase
{
    private string $tmpKnotDir = '';

    protected function tearDown(): void
    {
        if ($this->tmpKnotDir !== '' && is_dir($this->tmpKnotDir)) {
            $file = $this->tmpKnotDir . '/' . DescriptorCache::FILENAME;
            if (is_file($file)) {
                unlink($file);
            }
            rmdir($this->tmpKnotDir);
            $this->tmpKnotDir = '';
        }
        parent::tearDown();
    }

    public function testReadCountsDescriptorRows(): void
    {
        $this->tmpKnotDir = sys_get_temp_dir() . '/knot-desc-test-' . uniqid('', true);
        self::assertTrue(mkdir($this->tmpKnotDir, 0755, true));

        $payload = [
            'hash' => 'testhash',
            'generatedAt' => '2026-01-01T00:00:00+00:00',
            'descriptors' => [
                ['slug' => 'a', 'class' => 'A', 'module' => ''],
                ['slug' => 'b', 'class' => 'B', 'module' => ''],
            ],
        ];
        $written = file_put_contents(
            $this->tmpKnotDir . '/' . DescriptorCache::FILENAME,
            json_encode($payload, JSON_UNESCAPED_SLASHES)
        );
        self::assertNotFalse($written);

        $cache = new DescriptorCache($this->tmpKnotDir);
        $read = $cache->read();
        self::assertIsArray($read);
        self::assertArrayHasKey('descriptors', $read);
        self::assertCount(2, $read['descriptors']);
    }
}
