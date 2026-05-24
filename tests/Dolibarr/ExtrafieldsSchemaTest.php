<?php

declare(strict_types=1);

namespace Knot\Tests\Dolibarr;

use Knot\Dolibarr\ExtrafieldsSchema;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Knot\Dolibarr\ExtrafieldsSchema
 */
final class ExtrafieldsSchemaTest extends TestCase
{
    public function testMergeAddsExtrafieldProperties(): void
    {
        /** @phpstan-ignore-next-line */
        $db = new class (
            [(object) [
                'name' => 'custcode',
                'label' => 'Customer code',
                'type' => 'varchar',
                'size' => '32',
                'fielddefault' => '',
                'fieldrequired' => 0,
            ]]
        ) extends \DoliDB {
            /** @param list<object> $objects */
            public function __construct(private array $objects)
            {
            }

            public function query(string $sql): bool
            {
                Assert::assertStringContainsStringIgnoringCase('llx_extrafields', $sql);
                Assert::assertStringContainsString('product', $sql);

                return true;
            }

            private int $index = -1;

            /** @param mixed $resource */
            public function fetch_object($resource): ?object
            {
                $this->index++;
                return $this->objects[$this->index] ?? null;
            }

            /** @inheritdoc */
            public function escape(string $value): string
            {
                return addslashes($value);
            }
        };

        $object = new class {
            public string $element = 'product';
        };

        $schema = [
            'type' => 'object',
            'properties' => [
                'ref' => ['type' => 'string'],
            ],
        ];

        $schema = (new ExtrafieldsSchema())->mergeInto($schema, $db, $object, 1, null);

        self::assertArrayHasKey('custcode', $schema['properties']);
        self::assertTrue($schema['properties']['custcode']['x-dolibarr-extrafield']);
    }
}
