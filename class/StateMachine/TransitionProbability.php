<?php

declare(strict_types=1);

namespace Knot\StateMachine;

/**
 * Lightweight UX hints — does not replace Dolibarr business rules.
 */
final class TransitionProbability
{
    public static function rank(?string $logicalStateConstant, string $verbMethod): string
    {
        $v = strtolower($verbMethod);
        $s = $logicalStateConstant !== null ? strtolower($logicalStateConstant) : '';

        $paidLike = str_contains($s, 'paid')
            || str_contains($s, 'paye')
            || str_contains($s, 'closed')
            || str_contains($s, 'cloture');
        $draftLike = str_contains($s, 'draft')
            || str_contains($s, 'brouillon');

        if ($paidLike && preg_match('/valid|setpaid|classifyaccepted/', $v) === 1) {
            return 'low';
        }
        if ($draftLike && preg_match('/valid|validate|classifyaccepted/', $v) === 1) {
            return 'high';
        }
        if (preg_match('/setdraft|reopen|cancel/', $v) === 1) {
            return $paidLike ? 'medium' : 'low';
        }

        return 'medium';
    }
}
