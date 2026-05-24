<?php

declare(strict_types=1);

namespace Knot\Licensing;

/**
 * Value object representing the resolved status of a licence after
 * the dolistore validator chain has run.
 *
 * Status values:
 *  - 'valid'          : signature OK, instance bound, not expired, online or within grace.
 *  - 'expired'        : reached `expiresAt` OR exceeded the offline grace window.
 *  - 'missing'        : no licence file or no cached payload at all.
 *  - 'tampered'       : signature invalid, or `instanceId` mismatch, or fork detected.
 *  - 'invalid'        : payload structure is corrupted (JSON unreadable, etc.).
 *  - 'not_required'   : extension is free OR validation mode is 'none'.
 */
final class LicenseStatus
{
    public const VALID = 'valid';
    public const EXPIRED = 'expired';
    public const MISSING = 'missing';
    public const TAMPERED = 'tampered';
    public const INVALID = 'invalid';
    public const NOT_REQUIRED = 'not_required';

    public function __construct(
        public readonly string $status,
        public readonly ?string $extensionId,
        public readonly ?string $expiresAt,
        public readonly ?string $signedAt,
        public readonly ?string $plan,
        public readonly ?string $issuedTo,
        public readonly ?string $error,
        public readonly bool $offlineGrace = false,
    ) {
    }

    public function isUsable(): bool
    {
        return $this->status === self::VALID || $this->status === self::NOT_REQUIRED;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'extensionId' => $this->extensionId,
            'expiresAt' => $this->expiresAt,
            'signedAt' => $this->signedAt,
            'plan' => $this->plan,
            'issuedTo' => $this->issuedTo,
            'error' => $this->error,
            'offlineGrace' => $this->offlineGrace,
        ];
    }
}
