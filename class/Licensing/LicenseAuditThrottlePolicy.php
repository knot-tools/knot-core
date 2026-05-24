<?php

declare(strict_types=1);

namespace Knot\Licensing;

/**
 * Cooldown rules for licensing audit events that would otherwise spam
 * `llx_knot_audit_log` on repeated inspect() calls (e.g. stable HTTP 404).
 */
final class LicenseAuditThrottlePolicy
{
    /** Normalised bucket when the network/backend error indicates HTTP 404. */
    public const REFRESH_FAILURE_CLASS_HTTP_404 = 'http_404';

    /** Transient or unknown errors — shorter cooldown so ops still see retries. */
    public const REFRESH_FAILURE_CLASS_OTHER = 'other';

    public static function refreshFailureClass(string $networkError): string
    {
        $e = strtolower($networkError);
        if (preg_match('/\b404\b/', $e) !== 0 || str_contains($e, 'not found')) {
            return self::REFRESH_FAILURE_CLASS_HTTP_404;
        }

        return self::REFRESH_FAILURE_CLASS_OTHER;
    }

    public static function refreshFailureCooldownSeconds(string $failureClass): int
    {
        return $failureClass === self::REFRESH_FAILURE_CLASS_HTTP_404 ? 3600 : 300;
    }

    public static function graceEnteredCooldownSeconds(): int
    {
        return 3600;
    }
}
