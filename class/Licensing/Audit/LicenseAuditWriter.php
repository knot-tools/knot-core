<?php

/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

namespace Knot\Licensing\Audit;

use Knot\Repository\AuditLogRepository;
use Knot\Security\SecretMasker;

/**
 * Type-safe writer for Licensing audit events.
 *
 * Wraps {@see AuditLogRepository::record()} with:
 *  - automatic `entity_type = 'knot_license'` so the support UI can
 *    isolate licensing events from workflow / cron events,
 *  - automatic SecretMasker sweep over the context payload — licences
 *    occasionally carry `issuedTo` (PII) or `payload` snippets that must
 *    not leak into the audit DB. The masker recurses into nested arrays.
 *  - resolution of the active Dolibarr entity via `$conf->entity`,
 *  - resolution of the active user id via `$user->id` when available
 *    (background workers set neither — the entry then carries `null`).
 *
 * The writer never throws: a misbehaving audit DB must not break the
 * licence validation path. Failures are best-effort silent.
 */
final class LicenseAuditWriter
{
    public function __construct(
        private readonly AuditLogRepository $auditRepository,
        private readonly ?SecretMasker $masker = null,
    ) {
    }

    /**
     * Record a Licensing audit entry.
     *
     * @param array<string, mixed> $context Free-form context (extension
     *                                      id, error message, signedAt,
     *                                      etc.). Sensitive keys are
     *                                      automatically masked.
     */
    public function record(LicenseAuditEvent $event, string $extensionId, array $context = []): void
    {
        try {
            $payload = array_merge(
                ['extensionId' => $extensionId],
                $context,
            );
            if ($this->masker !== null) {
                $payload = $this->masker->maskArray($payload);
            }
            $this->auditRepository->record(
                $event->value,
                'knot_license',
                null,
                $this->resolveUserId(),
                $payload,
                $this->resolveEntity(),
            );
        } catch (\Throwable) {
            // Swallow — audit write must never break licensing flow.
        }
    }

    private function resolveEntity(): int
    {
        if (isset($GLOBALS['conf']) && is_object($GLOBALS['conf']) && isset($GLOBALS['conf']->entity)) {
            return (int) $GLOBALS['conf']->entity;
        }
        return 1;
    }

    private function resolveUserId(): ?int
    {
        if (isset($GLOBALS['user']) && is_object($GLOBALS['user']) && !empty($GLOBALS['user']->id)) {
            return (int) $GLOBALS['user']->id;
        }
        return null;
    }
}
