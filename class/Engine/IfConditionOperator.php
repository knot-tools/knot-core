<?php

declare(strict_types=1);

namespace Knot\Engine;

/**
 * Canonical operators for logic.if conditions and LLM alias normalization.
 */
final class IfConditionOperator
{
    /** @var list<string> */
    public const CANONICAL = [
        'equals',
        'not_equals',
        'contains',
        'not_contains',
        'greater',
        'greater_equal',
        'less',
        'less_equal',
        'is_empty',
        'is_not_empty',
        'regex',
    ];

    /** @var array<string, string> Symbol / alias → canonical operator id */
    public const ALIASES = [
        '=' => 'equals',
        '==' => 'equals',
        'eq' => 'equals',
        '!=' => 'not_equals',
        '<>' => 'not_equals',
        'ne' => 'not_equals',
        '>=' => 'greater_equal',
        'gte' => 'greater_equal',
        '<=' => 'less_equal',
        'lte' => 'less_equal',
        '>' => 'greater',
        'gt' => 'greater',
        '<' => 'less',
        'lt' => 'less',
    ];

    public static function normalize(string $operator): string
    {
        $trimmed = strtolower(trim($operator));
        if ($trimmed === '') {
            return 'equals';
        }
        if (in_array($trimmed, self::CANONICAL, true)) {
            return $trimmed;
        }

        return self::ALIASES[$trimmed] ?? $trimmed;
    }

    public static function isCanonical(string $operator): bool
    {
        return in_array(strtolower(trim($operator)), self::CANONICAL, true);
    }

    public static function suggestionFor(string $operator): ?string
    {
        $trimmed = strtolower(trim($operator));
        if (self::isCanonical($trimmed)) {
            return null;
        }
        $normalized = self::normalize($trimmed);

        return $normalized !== $trimmed ? $normalized : null;
    }
}
