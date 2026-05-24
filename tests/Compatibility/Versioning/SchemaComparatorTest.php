<?php

declare(strict_types=1);

namespace Knot\Tests\Compatibility\Versioning;

use Knot\Compatibility\Versioning\BreakingChangeDetector;
use Knot\Compatibility\Versioning\SchemaComparator;
use PHPUnit\Framework\TestCase;

final class SchemaComparatorTest extends TestCase
{
    public function testDetectsRemovedPropertyVerbAndTypeChange(): void
    {
        $left = json_decode((string) file_get_contents(
            dirname(__DIR__, 3) . '/data/compatibility/snapshots/sample-v1.json'
        ), true);
        $right = json_decode((string) file_get_contents(
            dirname(__DIR__, 3) . '/data/compatibility/snapshots/sample-v2.json'
        ), true);
        self::assertIsArray($left);
        self::assertIsArray($right);

        $diff = (new SchemaComparator())->diff($left, $right);
        $kinds = array_map(static fn (array $r): string => (string) ($r['kind'] ?? ''), $diff);

        self::assertContains('property_removed', $kinds);
        self::assertContains('property_type_changed', $kinds);
        self::assertContains('transition_verb_removed', $kinds);

        $breaking = (new BreakingChangeDetector())->classify($diff);
        self::assertNotSame([], $breaking);
    }

    public function testBundledReferenceSelfDiffIsEmpty(): void
    {
        $root = dirname(__DIR__, 3) . '/data/compatibility/snapshots/dolibarr-21.0.4.json';
        $snap = json_decode((string) file_get_contents($root), true);
        self::assertIsArray($snap);

        $diff = (new SchemaComparator())->diff($snap, $snap);
        self::assertSame([], $diff);
    }

    public function testDemoDiffAgainstBundledReferenceFindsStructuralChanges(): void
    {
        $demo = dirname(__DIR__, 3) . '/data/compatibility/snapshots/reference-diff-demo.json';
        $ref = dirname(__DIR__, 3) . '/data/compatibility/snapshots/dolibarr-21.0.4.json';
        $left = json_decode((string) file_get_contents($demo), true);
        $right = json_decode((string) file_get_contents($ref), true);
        self::assertIsArray($left);
        self::assertIsArray($right);

        $diff = (new SchemaComparator())->diff($left, $right);
        $kinds = array_map(static fn (array $r): string => (string) ($r['kind'] ?? ''), $diff);

        self::assertContains('property_added', $kinds);
    }

    public function testDiffDetectsObjectAddedAndRemoved(): void
    {
        $baseline = [
            'objects' => [
                'facture' => ['property_keys' => ['ref']],
                'propal' => ['property_keys' => ['ref']],
            ],
        ];
        $target = [
            'objects' => [
                'facture' => ['property_keys' => ['ref']],
                'commande' => ['property_keys' => ['ref']],
            ],
        ];

        $diff = (new SchemaComparator())->diff($baseline, $target);
        $kinds = array_column($diff, 'kind');

        self::assertContains('object_added', $kinds);
        self::assertContains('object_removed', $kinds);
    }

    public function testDiffDetectsPropertyAddedAndStatusChanges(): void
    {
        $baseline = [
            'objects' => [
                'facture' => [
                    'property_keys' => ['ref', 'total'],
                    'property_types' => ['ref' => 'string', 'total' => 'number'],
                    'status_constants' => ['DRAFT' => 0, 'VALIDATED' => 1],
                    'transition_verbs' => ['validate'],
                ],
            ],
        ];
        $target = [
            'objects' => [
                'facture' => [
                    'property_keys' => ['ref', 'total', 'note'],
                    'property_types' => ['ref' => 'string', 'total' => 'string'],
                    'status_constants' => ['DRAFT' => 0, 'VALIDATED' => 2],
                    'transition_verbs' => [],
                ],
            ],
        ];

        $diff = (new SchemaComparator())->diff($baseline, $target);
        $kinds = array_column($diff, 'kind');

        self::assertContains('property_added', $kinds);
        self::assertContains('property_type_changed', $kinds);
        self::assertContains('status_constant_value_changed', $kinds);
        self::assertContains('transition_verb_removed', $kinds);
    }

    public function testDiffDetectsStatusConstantRemoved(): void
    {
        $baseline = [
            'objects' => [
                'ticket' => [
                    'property_keys' => [],
                    'status_constants' => ['OPEN' => 0, 'CLOSED' => 1],
                ],
            ],
        ];
        $target = [
            'objects' => [
                'ticket' => [
                    'property_keys' => [],
                    'status_constants' => ['OPEN' => 0],
                ],
            ],
        ];

        $diff = (new SchemaComparator())->diff($baseline, $target);
        self::assertSame('status_constant_removed', $diff[0]['kind'] ?? null);
    }

    public function testDiffIgnoresNonArrayTargetObjects(): void
    {
        $diff = (new SchemaComparator())->diff(
            ['objects' => ['a' => ['property_keys' => []]]],
            ['objects' => ['a' => 'broken', 'b' => ['property_keys' => ['x']]]],
        );

        self::assertCount(1, $diff);
        self::assertSame('object_added', $diff[0]['kind']);
        self::assertSame('b', $diff[0]['slug']);
    }
}
