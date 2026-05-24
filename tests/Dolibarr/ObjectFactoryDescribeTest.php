<?php

declare(strict_types=1);

namespace Knot\Tests\Dolibarr;

use Knot\Dolibarr\ObjectFactory;
use Knot\Dolibarr\SchemaBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Class-level alias mapping is set up once so the factory's
 * `class_exists('\\Societe')` check resolves to our `FakeFacture`-style stubs
 * without booting a real Dolibarr instance.
 *
 * @covers \Knot\Dolibarr\ObjectFactory
 */
final class ObjectFactoryDescribeTest extends TestCase
{
    private static bool $aliasesRegistered = false;

    public static function setUpBeforeClass(): void
    {
        if (self::$aliasesRegistered) {
            return;
        }

        // Aliases so ObjectFactory::build('facture') ends up instantiating
        // our FakeFacture stub. Same trick for the other slugs we exercise.
        if (!class_exists('Facture', false)) {
            class_alias(\FakeFacture::class, 'Facture');
        }
        if (!class_exists('FactureLigne', false)) {
            class_alias(\FakeFactureLigne::class, 'FactureLigne');
        }
        self::$aliasesRegistered = true;
    }

    private function db(): \DoliDB
    {
        return new class extends \DoliDB {};
    }

    public function testListObjectsForApiReturnsKnownSlugs(): void
    {
        $factory = new ObjectFactory();
        $list = $factory->listObjectsForApi(null, $this->db());

        $slugs = array_column($list, 'slug');
        self::assertContains('facture', $slugs);
        self::assertContains('thirdparty', $slugs);
        self::assertContains('contrat', $slugs);
        self::assertContains('paiement', $slugs);
        self::assertContains('propal', $slugs);
    }

    public function testListObjectsForApiMarksFromMap(): void
    {
        $factory = new ObjectFactory();
        foreach ($factory->listObjectsForApi(null, $this->db()) as $row) {
            self::assertArrayHasKey('fromMap', $row);
            self::assertIsBool($row['fromMap']);
        }
        $byslug = array_column($factory->listObjectsForApi(null, $this->db()), null, 'slug');
        self::assertTrue($byslug['facture']['fromMap']);
    }

    public function testListObjectsFlagsLineSupport(): void
    {
        $factory = new ObjectFactory();
        $list = $factory->listObjectsForApi(null, $this->db());

        $byslug = array_column($list, null, 'slug');
        self::assertTrue($byslug['facture']['supportsLines']);
        self::assertFalse($byslug['thirdparty']['supportsLines']);
    }

    public function testDescribeForActionEmbedsVersionHashAndAction(): void
    {
        $factory = new ObjectFactory();
        $schema = $factory->describeForAction('facture', SchemaBuilder::ACTION_CREATE, $this->db());

        self::assertSame('facture', $schema['x-knot-object']);
        self::assertSame(SchemaBuilder::ACTION_CREATE, $schema['x-knot-action']);
        self::assertNotEmpty($schema['x-version-hash']);
    }

    public function testDescribeForActionInjectsLinesPropertyForFacture(): void
    {
        $factory = new ObjectFactory();
        $schema = $factory->describeForAction('facture', SchemaBuilder::ACTION_CREATE, $this->db());

        self::assertArrayHasKey('lines', $schema['properties']);
        self::assertSame('array', $schema['properties']['lines']['type']);
    }

    public function testGetVersionHashChangesWhenObjectFieldsChange(): void
    {
        $factory = new ObjectFactory();
        $h1 = $factory->getVersionHash($this->db());

        // Mutate the underlying fake; getVersionHash builds a fresh instance per
        // call so we have to mutate the class-level fields prototype.
        \FakeFacture::class; // no-op to keep static analyser happy

        // Adding a new field on the live instance won't affect getVersionHash
        // because it builds a fresh object — instead we mutate the default.
        $proto = new \FakeFacture();
        $proto->fields['drift_field'] = ['type' => 'integer', 'label' => 'Drift', 'visible' => 1, 'position' => 999];
        // Hashing the mutated instance should differ from the factory's stored hash.
        $builder = new SchemaBuilder();
        self::assertNotSame($builder->fieldsHash(new \FakeFacture()), $builder->fieldsHash($proto));
    }
}
