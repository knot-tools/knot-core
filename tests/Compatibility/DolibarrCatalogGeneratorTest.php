<?php

declare(strict_types=1);

namespace Knot\Tests\Compatibility;

use Knot\Compatibility\DolibarrCatalogGenerator;
use Knot\Dolibarr\ObjectFactory;
use Knot\Dolibarr\ObjectIntrospector;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @covers \Knot\Compatibility\DolibarrCatalogGenerator
 */
final class DolibarrCatalogGeneratorTest extends TestCase
{
    public function testBuildCatalogShape(): void
    {
        $root = sys_get_temp_dir() . '/knot-empty-dol-' . bin2hex(random_bytes(4));
        self::assertTrue(mkdir($root, 0700, true));

        try {
            $map = (new ReflectionClass(ObjectFactory::class))->getReflectionConstant('MAP')->getValue();
            $scan = (new ObjectIntrospector($root))->scan();
            $gen = new DolibarrCatalogGenerator();
            $catalog = $gen->buildCatalog($root, $map, $scan);

            self::assertSame('knot.dolibarr-catalog', $catalog['format']);
            self::assertSame(1, $catalog['formatVersion']);
            self::assertArrayHasKey('counts', $catalog);
            self::assertSame(27, $catalog['counts']['mapSlugs']);
        } finally {
            @rmdir($root);
        }
    }
}
