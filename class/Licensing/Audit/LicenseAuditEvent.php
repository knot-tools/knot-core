<?php

/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

namespace Knot\Licensing\Audit;

/**
 * Closed enum of audit events emitted by the Licensing subsystem.
 *
 * Each value maps 1:1 with an action_type prefix in `llx_knot_audit_log`
 * — the Audit log query UI groups events by their `license.*` prefix so
 * adding a new event is a matter of adding a case here, never a string
 * literal scattered across the codebase.
 *
 * Wire convention: `licensing.<verb>.<outcome>` (e.g.
 * `licensing.refresh.failed`). The string backing values are stable
 * across releases — they are persisted and consulted by the support team.
 */
enum LicenseAuditEvent: string
{
    /** First successful activation (extension installed + license bound). */
    case LICENSE_ACTIVATED = 'licensing.license.activated';

    /** Periodic refresh succeeded against the Dolistore backend. */
    case LICENSE_REFRESH_SUCCESS = 'licensing.refresh.success';

    /** Refresh attempt failed (network error, 5xx, signature mismatch). */
    case LICENSE_REFRESH_FAILED = 'licensing.refresh.failed';

    /** Cache or response signature did not verify — possible tampering. */
    case LICENSE_TAMPERED = 'licensing.license.tampered';

    /** Backend reports the licence as revoked (refund, dispute, admin op). */
    case LICENSE_REVOKED = 'licensing.license.revoked';

    /** Licence reached its `expiresAt` without renewal. */
    case LICENSE_EXPIRED = 'licensing.license.expired';

    /** `instanceId` no longer matches the bound one (re-bind needed). */
    case LICENSE_BINDING_CHANGED = 'licensing.binding.changed';

    /** Backend unreachable, falling back on cached payload within grace. */
    case LICENSE_GRACE_ENTERED = 'licensing.grace.entered';

    /** Offline grace window exhausted, extension is now blocked. */
    case LICENSE_GRACE_EXHAUSTED = 'licensing.grace.exhausted';

    /** Manifest signature does not match the official pinned signature. */
    case LICENSE_FORK_DETECTED = 'licensing.fork.detected';
}
