<?php

declare(strict_types=1);

namespace Knot\Tests\Dolibarr;

use Knot\Dolibarr\ObjectFactory;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @covers \Knot\Dolibarr\ObjectFactory
 */
final class ObjectFactoryMapInventoryTest extends TestCase
{
    public function testCuratedMapHasTwentySevenStableSlugs(): void
    {
        $map = (new ReflectionClass(ObjectFactory::class))->getReflectionConstant('MAP')->getValue();
        self::assertIsArray($map);
        self::assertCount(27, $map);

        foreach ([
            'thirdparty', 'contact', 'propal', 'commande', 'facture', 'product',
            'service', 'project', 'task', 'ticket', 'user', 'member', 'entrepot',
            'stockmove', 'agenda', 'actioncomm', 'categorie', 'bankaccount',
            'expense', 'expedition', 'holiday', 'mailing', 'facturefourn', 'commandefourn',
            'propalfourn', 'contrat', 'paiement',
        ] as $slug) {
            self::assertArrayHasKey($slug, $map);
        }

        foreach (['facture', 'contrat', 'expedition'] as $lineBacked) {
            self::assertIsArray($map[$lineBacked]['line'] ?? null, $lineBacked . ' should declare line companion');
        }
    }

    public function testCanonicalAliasSlugsReferenceSameClassesWhereDocumented(): void
    {
        $map = (new ReflectionClass(ObjectFactory::class))->getReflectionConstant('MAP')->getValue();
        self::assertSame($map['actioncomm']['class'], $map['agenda']['class']);
        self::assertSame($map['actioncomm']['file'], $map['agenda']['file']);
        self::assertSame($map['service']['file'], $map['product']['file']);
        self::assertSame($map['service']['class'], $map['product']['class']);
    }

    public function testListSupportedMatchesMapKeysSortedBySlugOrder(): void
    {
        $factory = new ObjectFactory();
        $supported = $factory->listSupported();
        $mapKeys = array_keys((new ReflectionClass(ObjectFactory::class))->getReflectionConstant('MAP')->getValue());
        sort($mapKeys);

        sort($supported);
        self::assertSame($mapKeys, $supported);
    }
}
