<?php

declare(strict_types=1);

namespace Knot\Tests\Dolibarr;

use Knot\Connectors\Dolibarr\ObjectAction;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Guards propal commercial document line mapping (regression guard for beta SQL reports).
 */
final class ObjectActionPropalLineMappingTest extends TestCase
{
    public function testGuessHeaderForeignKeyForPropal(): void
    {
        $action = new ObjectAction();
        $method = new ReflectionMethod(ObjectAction::class, 'guessHeaderForeignKey');

        self::assertSame('fk_propal', $method->invoke($action, 'propal'));
        self::assertSame('fk_facture', $method->invoke($action, 'facture'));
    }

    public function testMapLineToAddLineArgsUsesEightPositionalArgsOnly(): void
    {
        $action = new ObjectAction();
        $method = new ReflectionMethod(ObjectAction::class, 'mapLineToAddLineArgs');

        $args = $method->invoke($action, [
            'desc' => 'Line label',
            'subprice' => 12.5,
            'qty' => 2,
            'tva_tx' => 8.5,
            'fk_product' => 0,
        ]);

        self::assertCount(8, $args);
        self::assertSame('Line label', $args[0]);
        self::assertSame(12.5, $args[1]);
        self::assertSame(2.0, $args[2]);
        self::assertSame(8.5, $args[3]);
    }
}
