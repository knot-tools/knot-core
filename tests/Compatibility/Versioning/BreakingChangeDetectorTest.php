<?php

declare(strict_types=1);

namespace Knot\Tests\Compatibility\Versioning;

use Knot\Compatibility\Versioning\BreakingChangeDetector;
use PHPUnit\Framework\TestCase;

final class BreakingChangeDetectorTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: int, 2: string, 3: string}>
     */
    public static function kindProvider(): iterable
    {
        yield 'property_removed' => ['property_removed', BreakingChangeDetector::CAT_FIELD_REMOVED, 'breaking', 'high'];
        yield 'property_type_changed' => ['property_type_changed', BreakingChangeDetector::CAT_TYPE_CHANGED, 'maybe_breaking', 'medium'];
        yield 'status_constant_removed' => ['status_constant_removed', BreakingChangeDetector::CAT_STATUS_SHIFT, 'breaking', 'medium'];
        yield 'status_constant_value_changed' => ['status_constant_value_changed', BreakingChangeDetector::CAT_STATUS_SHIFT, 'breaking', 'medium'];
        yield 'transition_verb_removed' => ['transition_verb_removed', BreakingChangeDetector::CAT_TRANSITION_REMOVED, 'breaking', 'high'];
        yield 'object_removed' => ['object_removed', BreakingChangeDetector::CAT_OBJECT_REMOVED, 'breaking', 'high'];
        yield 'object_added' => ['object_added', BreakingChangeDetector::CAT_OBJECT_ADDED, 'informational', 'high'];
        yield 'property_added' => ['property_added', BreakingChangeDetector::CAT_PROPERTY_ADDED, 'informational', 'high'];
    }

    /**
     * @dataProvider kindProvider
     */
    public function testClassifyMapsKnownKinds(
        string $kind,
        int $expectedCategory,
        string $expectedSeverity,
        string $expectedConfidence,
    ): void {
        $row = ['kind' => $kind, 'object' => 'Facture', 'field' => 'total'];
        $out = (new BreakingChangeDetector())->classify([$row]);

        self::assertCount(1, $out);
        self::assertSame($expectedCategory, $out[0]['category']);
        self::assertSame($expectedSeverity, $out[0]['severity']);
        self::assertSame($expectedConfidence, $out[0]['confidence']);
        self::assertSame($row, $out[0]['detail']);
    }

    public function testClassifyIgnoresUnknownKinds(): void
    {
        $out = (new BreakingChangeDetector())->classify([
            ['kind' => 'noop'],
            ['kind' => ''],
        ]);
        self::assertSame([], $out);
    }

    public function testClassifyAccumulatesMultipleRows(): void
    {
        $out = (new BreakingChangeDetector())->classify([
            ['kind' => 'object_added', 'object' => 'A'],
            ['kind' => 'object_removed', 'object' => 'B'],
        ]);
        self::assertCount(2, $out);
        self::assertSame(BreakingChangeDetector::CAT_OBJECT_ADDED, $out[0]['category']);
        self::assertSame(BreakingChangeDetector::CAT_OBJECT_REMOVED, $out[1]['category']);
    }
}
