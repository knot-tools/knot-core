<?php

declare(strict_types=1);

namespace Knot\Tests\Dolibarr;

use Knot\Dolibarr\SchemaBuilder;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Knot\Dolibarr\SchemaBuilder
 */
final class SchemaBuilderTest extends TestCase
{
    private SchemaBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new SchemaBuilder();
    }

    public function testIntegerFieldMapsToIntegerType(): void
    {
        $object = new \FakeFacture();
        $schema = $this->builder->buildForAction($object, SchemaBuilder::ACTION_CREATE);

        self::assertSame('integer', $schema['properties']['fk_soc']['type']);
    }

    public function testVarcharFieldMapsToStringWithMaxLength(): void
    {
        $object = new \FakeFacture();
        $schema = $this->builder->buildForAction($object, SchemaBuilder::ACTION_CREATE);

        self::assertSame('string', $schema['properties']['ref']['type']);
        self::assertSame(30, $schema['properties']['ref']['maxLength']);
    }

    public function testDatetimeFieldGetsDateTimeFormat(): void
    {
        $object = new \FakeFacture();
        $schema = $this->builder->buildForAction($object, SchemaBuilder::ACTION_UPDATE);

        // visible=-1 means "shown in form but read-only in detail view" —
        // Dolibarr still allows editing it via showInputField, so we keep it.
        self::assertArrayHasKey('datec', $schema['properties']);
        self::assertSame('date-time', $schema['properties']['datec']['format']);

        $dateField = $schema['properties']['date_lim_reglement'];
        self::assertSame('string', $dateField['type']);
        self::assertSame('date', $dateField['format']);
    }

    public function testHtmlTypeFlaggedAsMultilineHtml(): void
    {
        $object = new \FakeFacture();
        $schema = $this->builder->buildForAction($object, SchemaBuilder::ACTION_CREATE);

        $note = $schema['properties']['note_public'];
        self::assertSame('string', $note['type']);
        self::assertTrue($note['multiline']);
        self::assertSame('html', $note['format']);
    }

    public function testForeignKeyExtensionIsParsed(): void
    {
        $object = new \FakeFacture();
        $schema = $this->builder->buildForAction($object, SchemaBuilder::ACTION_CREATE);

        $fk = $schema['properties']['fk_soc']['x-dolibarr-fk'] ?? null;
        self::assertNotNull($fk);
        self::assertSame('Societe', $fk['targetClass']);
        self::assertSame('thirdparty', $fk['targetSlug']);
    }

    public function testArrayOfKeyvalProducesEnum(): void
    {
        $object = new \FakeFacture();
        // status is required so it is shown for create.
        $schema = $this->builder->buildForAction($object, SchemaBuilder::ACTION_CREATE);

        $status = $schema['properties']['status'];
        self::assertSame([0, 1, 2], $status['enum']);
        self::assertSame(['Draft', 'Validated', 'Paid'], $status['enumLabels']);
    }

    public function testRequiredFieldsExcludeFieldsWithDefault(): void
    {
        $object = new \FakeFacture();
        $schema = $this->builder->buildForAction($object, SchemaBuilder::ACTION_CREATE);

        // ref has notnull=1 and no default → required
        self::assertContains('ref', $schema['required']);
        // entity has notnull=1 but a default value → NOT required
        self::assertNotContains('entity', $schema['required'] ?? []);
    }

    public function testHiddenFieldsAreOmittedFromCreateSchema(): void
    {
        $object = new \FakeFacture();
        $schema = $this->builder->buildForAction($object, SchemaBuilder::ACTION_CREATE);

        // visible=-2 (entity, fk_user_creat) → hidden
        self::assertArrayNotHasKey('entity', $schema['properties']);
        self::assertArrayNotHasKey('fk_user_creat', $schema['properties']);
        // noteditable=1 (rowid) → hidden
        self::assertArrayNotHasKey('rowid', $schema['properties']);
    }

    public function testVisibleEqualsTwoIsHidden(): void
    {
        $object = new \FakeFacture();
        // Inject a visible=2 field at runtime to assert it's filtered.
        $object->fields['hidden_two'] = ['type' => 'integer', 'label' => 'HiddenTwo', 'enabled' => 1, 'visible' => 2, 'position' => 999];

        $schema = $this->builder->buildForAction($object, SchemaBuilder::ACTION_CREATE);
        self::assertArrayNotHasKey('hidden_two', $schema['properties']);
    }

    public function testEnabledModEnabledStringIsRespected(): void
    {
        $object = new \FakeFacture();

        // Default — isModEnabled is not declared, evaluator returns the default (1) → field kept.
        $schema = $this->builder->buildForAction($object, SchemaBuilder::ACTION_CREATE);
        self::assertArrayHasKey('multicurrency_code', $schema['properties']);

        // With explicit context evaluator returning false → field is filtered.
        $filtered = $this->builder->buildForAction($object, SchemaBuilder::ACTION_CREATE, null, [
            'enabledEvaluator' => fn (string $expr): bool => false,
        ]);
        self::assertArrayNotHasKey('multicurrency_code', $filtered['properties']);
    }

    public function testTranslateIsCalledWhenLangsProvided(): void
    {
        $object = new \FakeFacture();
        $langs = new \Translate();

        $schema = $this->builder->buildForAction($object, SchemaBuilder::ACTION_CREATE, $langs);

        self::assertSame('TR[Ref]', $schema['properties']['ref']['title']);
    }

    public function testPermissionExtensionUsesElementName(): void
    {
        $object = new \FakeFacture();
        $schema = $this->builder->buildForAction($object, SchemaBuilder::ACTION_CREATE);

        self::assertSame('facture->creer', $schema['x-dolibarr-permission']);
    }

    public function testActionsThatDoNotTakePayloadReturnEmptyProperties(): void
    {
        $object = new \FakeFacture();

        $schema = $this->builder->buildForAction($object, SchemaBuilder::ACTION_DELETE);

        self::assertSame('object', $schema['type']);
        self::assertSame([], $schema['properties']);
        self::assertArrayNotHasKey('required', $schema);
    }

    public function testFieldsAreSortedByPosition(): void
    {
        $object = new \FakeFacture();
        $schema = $this->builder->buildForAction($object, SchemaBuilder::ACTION_CREATE);

        $names = array_keys($schema['properties']);
        $positions = [];
        foreach ($names as $n) {
            $positions[$n] = $schema['properties'][$n]['x-position'] ?? PHP_INT_MAX;
        }

        // Verify monotonically non-decreasing position order.
        $previous = -1;
        foreach ($positions as $pos) {
            self::assertGreaterThanOrEqual($previous, $pos, 'positions must be ascending');
            $previous = $pos;
        }
    }

    public function testFieldsHashIsStableAcrossCalls(): void
    {
        $object = new \FakeFacture();
        $h1 = $this->builder->fieldsHash($object);
        $h2 = $this->builder->fieldsHash($object);

        self::assertSame($h1, $h2);
        self::assertSame(12, strlen($h1));
    }

    public function testFieldsHashChangesWhenFieldsChange(): void
    {
        $object = new \FakeFacture();
        $h1 = $this->builder->fieldsHash($object);

        $object->fields['new_field'] = ['type' => 'integer', 'label' => 'New', 'visible' => 1, 'position' => 999];
        $h2 = $this->builder->fieldsHash($object);

        self::assertNotSame($h1, $h2);
    }

    public function testBuildLinesSchemaWrapsItemsInArray(): void
    {
        $line = new \FakeFactureLigne();
        $schema = $this->builder->buildLinesSchema($line);

        self::assertSame('array', $schema['type']);
        self::assertTrue($schema['x-knot-bulk']);
        self::assertArrayHasKey('items', $schema);
        self::assertArrayHasKey('qty', $schema['items']['properties']);
    }

    public function testBuildForActionFullExposesHiddenAndReadOnlyFields(): void
    {
        $object = new \FakeFacture();
        $object->fields['hidden_two'] = [
            'type' => 'integer',
            'label' => 'HiddenTwo',
            'enabled' => 1,
            'visible' => 2,
            'position' => 999,
        ];
        $schema = $this->builder->buildForActionFull($object, SchemaBuilder::ACTION_CREATE);

        self::assertSame(SchemaBuilder::FIELD_VIEW_FULL, $schema['x-knot-field-view']);
        self::assertArrayHasKey('hidden_two', $schema['properties']);
        self::assertArrayHasKey('entity', $schema['properties']);
        self::assertArrayHasKey('rowid', $schema['properties']);
        self::assertTrue($schema['properties']['rowid']['readOnly'] ?? false);
    }

    public function testBuildLinesSchemaFullIncludesMoreLineKeys(): void
    {
        $line = new \FakeFactureLigne();
        $schema = $this->builder->buildLinesSchemaFull($line);
        $props = $schema['items']['properties'] ?? [];
        self::assertArrayHasKey('fk_facture', $props);
        self::assertArrayHasKey('desc', $props);
    }
}
