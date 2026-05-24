<?php

declare(strict_types=1);

namespace Knot\Licensing;

/**
 * Decides whether an extension is still usable when the Dolistore
 * backend is unreachable.
 *
 * Rules:
 *   - The countdown starts at the last *successful signed* refresh,
 *     not at the last attempt — this prevents an attacker from
 *     extending the grace window by repeatedly failing refreshes.
 *   - Default grace window: 14 days, configurable via the
 *     `KNOT_LICENSE_DOLISTORE_OFFLINE_DAYS` Dolibarr global constant.
 *   - The cached `expiresAt` is always honoured: if the licence has
 *     already expired, no grace window applies.
 */
final class OfflineGracePolicy
{
    public const DEFAULT_GRACE_DAYS = 14;

    public function __construct(
        private readonly int $graceDays = self::DEFAULT_GRACE_DAYS,
    ) {
    }

    public function getGraceDays(): int
    {
        return $this->graceDays;
    }

    /**
     * @param string $lastSuccessfulRefreshIso ISO-8601 timestamp of the
     *                                         most recent successful signed refresh.
     * @param int|null $now Unix timestamp (defaults to time()), used in tests.
     */
    public function isWithinGrace(string $lastSuccessfulRefreshIso, ?int $now = null): bool
    {
        $now ??= time();
        $refreshTs = strtotime($lastSuccessfulRefreshIso);
        if ($refreshTs === false) {
            return false;
        }
        $deadline = $refreshTs + ($this->graceDays * 86400);
        return $now <= $deadline;
    }

    /**
     * Number of seconds remaining in the grace window (0 if exhausted).
     */
    public function remainingSeconds(string $lastSuccessfulRefreshIso, ?int $now = null): int
    {
        $now ??= time();
        $refreshTs = strtotime($lastSuccessfulRefreshIso);
        if ($refreshTs === false) {
            return 0;
        }
        $deadline = $refreshTs + ($this->graceDays * 86400);
        return max(0, $deadline - $now);
    }
}
