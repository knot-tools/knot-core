<?php

declare(strict_types=1);

namespace Knot\Compatibility\Versioning;

/**
 * Maps comparator rows into coarse breaking-change categories (spec types 1–6).
 *
 * Categories are informational for reports; upgrade decisions remain human-owned.
 */
final class BreakingChangeDetector
{
    public const CAT_FIELD_REMOVED = 1;

    public const CAT_TYPE_CHANGED = 2;

    public const CAT_STATUS_SHIFT = 3;

    public const CAT_TRANSITION_REMOVED = 4;

    public const CAT_OBJECT_REMOVED = 5;

    public const CAT_OBJECT_ADDED = 6;

    public const CAT_PROPERTY_ADDED = 7;

    /**
     * @param list<array<string, mixed>> $diff
     *
     * @return list<array{category:int, severity:string, confidence:string, detail:array<string,mixed>}>
     */
    public function classify(array $diff): array
    {
        $out = [];
        foreach ($diff as $row) {
            $kind = (string) ($row['kind'] ?? '');
            switch ($kind) {
                case 'property_removed':
                    $out[] = [
                        'category' => self::CAT_FIELD_REMOVED,
                        'severity' => 'breaking',
                        'confidence' => 'high',
                        'detail' => $row,
                    ];
                    break;
                case 'property_type_changed':
                    $out[] = [
                        'category' => self::CAT_TYPE_CHANGED,
                        'severity' => 'maybe_breaking',
                        'confidence' => 'medium',
                        'detail' => $row,
                    ];
                    break;
                case 'status_constant_removed':
                case 'status_constant_value_changed':
                    $out[] = [
                        'category' => self::CAT_STATUS_SHIFT,
                        'severity' => 'breaking',
                        'confidence' => 'medium',
                        'detail' => $row,
                    ];
                    break;
                case 'transition_verb_removed':
                    $out[] = [
                        'category' => self::CAT_TRANSITION_REMOVED,
                        'severity' => 'breaking',
                        'confidence' => 'high',
                        'detail' => $row,
                    ];
                    break;
                case 'object_removed':
                    $out[] = [
                        'category' => self::CAT_OBJECT_REMOVED,
                        'severity' => 'breaking',
                        'confidence' => 'high',
                        'detail' => $row,
                    ];
                    break;
                case 'object_added':
                    $out[] = [
                        'category' => self::CAT_OBJECT_ADDED,
                        'severity' => 'informational',
                        'confidence' => 'high',
                        'detail' => $row,
                    ];
                    break;
                case 'property_added':
                    $out[] = [
                        'category' => self::CAT_PROPERTY_ADDED,
                        'severity' => 'informational',
                        'confidence' => 'high',
                        'detail' => $row,
                    ];
                    break;
                default:
                    break;
            }
        }

        return $out;
    }
}
