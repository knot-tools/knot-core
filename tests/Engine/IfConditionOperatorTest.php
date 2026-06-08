<?php

declare(strict_types=1);

namespace Knot\Tests\Engine;

use Knot\Engine\IfConditionOperator;
use PHPUnit\Framework\TestCase;

final class IfConditionOperatorTest extends TestCase
{
    /** @return array<string, string> */
    public static function aliasProvider(): array
    {
        return [
            '>=' => ['>=', 'greater_equal'],
            'gte' => ['gte', 'greater_equal'],
            '<=' => ['<=', 'less_equal'],
            'lte' => ['lte', 'less_equal'],
            '==' => ['==', 'equals'],
            'eq' => ['eq', 'equals'],
            '!=' => ['!=', 'not_equals'],
            'ne' => ['ne', 'not_equals'],
        ];
    }

    /** @dataProvider aliasProvider */
    public function testNormalizesAlias(string $input, string $expected): void
    {
        self::assertSame($expected, IfConditionOperator::normalize($input));
    }

    public function testCanonicalOperatorsPassThrough(): void
    {
        self::assertSame('greater_equal', IfConditionOperator::normalize('greater_equal'));
    }
}
