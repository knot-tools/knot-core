<?php

declare(strict_types=1);

namespace Knot\Tests\Capabilities;

use Conf;
use Knot\Capabilities\CapabilitiesBuilder;
use Knot\Tests\Support\CapabilitiesProbeDb;
use Knot\Version;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Knot\Capabilities\CapabilitiesBuilder
 */
final class CapabilitiesBuilderTest extends TestCase
{
    private ?CapabilitiesBuilder $cacheBuilder = null;

    protected function tearDown(): void
    {
        $this->cacheBuilder?->invalidateCache();
        $this->cacheBuilder = null;
        parent::tearDown();
    }

    public function testBuildReturnsManifestSections(): void
    {
        $conf = new Conf();
        $conf->modules = ['facture' => 1, 'societe' => 1];
        $builder = new CapabilitiesBuilder(new CapabilitiesProbeDb(), $conf, 1);

        $manifest = $builder->build();

        self::assertSame(Version::FALLBACK, $manifest['knot']['version']);
        self::assertArrayHasKey('objects', $manifest);
        self::assertGreaterThan(0, $manifest['objects']['supported_count']);
        self::assertArrayHasKey('connectors', $manifest);
        self::assertArrayHasKey('schema_versioning', $manifest);
        self::assertGreaterThan(0, $manifest['schema_versioning']['snapshots_count']);
        self::assertSame(['facture' => 2], $manifest['dolibarr']['extrafields_count_by_object']);
    }

    public function testCacheRoundTripHonoursTtl(): void
    {
        $this->cacheBuilder = new CapabilitiesBuilder(new CapabilitiesProbeDb(), new Conf(), 4242);
        $path = sys_get_temp_dir() . '/knot/cache/capabilities-4242.json';

        $payload = ['hello' => 'world'];
        $this->cacheBuilder->saveCache($payload);

        self::assertSame($payload, $this->cacheBuilder->loadCache());
        self::assertFileExists($path);

        $raw = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($raw);
        $raw['expires_at'] = time() - 10;
        file_put_contents($path, json_encode($raw, JSON_THROW_ON_ERROR));

        self::assertNull($this->cacheBuilder->loadCache());

        $this->cacheBuilder->invalidateCache();
        self::assertFileDoesNotExist($path);
    }

    public function testLoadCacheReturnsFreshPayload(): void
    {
        $this->cacheBuilder = new CapabilitiesBuilder(new CapabilitiesProbeDb(), new Conf(), 7777);
        $payload = ['cached' => true, 'knot' => ['version' => '2.12.1']];
        $this->cacheBuilder->saveCache($payload);

        self::assertSame($payload, $this->cacheBuilder->loadCache());
    }
}
