<?php

/* Copyright (C) 2026 Sébastien Audel (EXIATIS) — Knot Tools™ Core, GPL-3.0-or-later */

declare(strict_types=1);

namespace Knot\Engine;

/**
 * Computes delay before the next queued execution attempt after a failure.
 *
 * @see \Knot\Repository\ExecutionRepository::recordFailureAndScheduleRetry()
 */
final class ExecutionBackoff
{
    /**
     * @param int $failureCount Number of failures already recorded after this run (1 = first failure).
     */
    public static function delaySecondsBeforeNextAttempt(int $failureCount, string $strategy): int
    {
        $failureCount = max(1, $failureCount);
        $strategy = strtolower(trim($strategy));

        return match ($strategy) {
            'linear' => 120 * $failureCount,
            'fixed' => 300,
            default => min(3600, 60 * (2 ** ($failureCount - 1))),
        };
    }
}
