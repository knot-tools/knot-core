<?php

/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

namespace Knot\Marketplace;

use Knot\KnownSkus;
use Knot\Extension\ExtensionRegistry;
use Knot\Extension\LicenseValidator;

/**
 * V2.5.0c — Marketplace tier gating.
 *
 * Decides whether the current Dolibarr instance is allowed to use a
 * marketplace item of a given tier. The single source of truth is the
 * licence verdict surfaced by {@see ExtensionRegistry::discover()}:
 *
 *  - `free`        → always allowed.
 *  - `beta`        → allowed when a valid Pro **or** Enterprise licence
 *                    is currently active (lets early adopters preview
 *                    upcoming gated content).
 *  - `pro`         → allowed when extension id {@see \Knot\KnownSkus::PRO_PACK}
 *                   carries a
 *                    {@see LicenseValidator::STATUS_VALID} verdict.
 *  - `enterprise`  → allowed when extension id {@see \Knot\KnownSkus::ENTERPRISE} carries a
 *                    {@see LicenseValidator::STATUS_VALID} verdict.
 *
 * The gate intentionally refuses `not_required`, `expired`, `missing`,
 * `tampered` and `invalid` verdicts: gated content must require a
 * **truly active** licence, not just the presence of an installed
 * module folder. This is the security invariant the marketplace API
 * relies on to strip workflow JSON definitions before sending them to
 * the browser (defence in depth — even with the API check, the strip
 * guarantees the JSON cannot leak through any future code path).
 *
 * Rationale (cf. user feedback 2026-04-30):
 *   "le fait que les éléments s'affichent ou pas dans la marketplace
 *    doivent le faire si la licence pro est active pas juste le module
 *    pro installé."
 *
 * The mapping between tiers and extension ids is intentionally hard
 * coded for now: there is no use case yet for runtime-configurable
 * mappings, and shipping a stable mapping prevents an attacker from
 * elevating their tier by tweaking a config row.
 */
final class TierGate
{
    public const TIER_FREE = 'free';
    public const TIER_BETA = 'beta';
    public const TIER_PRO = 'pro';
    public const TIER_ENTERPRISE = 'enterprise';

    public const ALL_TIERS = [
        self::TIER_FREE,
        self::TIER_BETA,
        self::TIER_PRO,
        self::TIER_ENTERPRISE,
    ];

    public const EXTENSION_PRO = KnownSkus::PRO_PACK;

    public const EXTENSION_ENTERPRISE = KnownSkus::ENTERPRISE;

    /** @var array<string, array<string, mixed>>|null */
    private ?array $registrySnapshot = null;

    public function __construct(private readonly ExtensionRegistry $registry)
    {
    }

    /**
     * Reset the in-memory snapshot. Mostly useful in tests where a
     * single TierGate instance can outlive the underlying registry
     * cache (e.g. when toggling licences between assertions).
     */
    public function refresh(): void
    {
        $this->registrySnapshot = null;
        $this->registry->clearCache();
    }

    public function canUseTier(string $tier): bool
    {
        $tier = strtolower(trim($tier));
        if ($tier === '' || $tier === self::TIER_FREE) {
            return true;
        }

        return match ($tier) {
            self::TIER_PRO => $this->hasActiveLicense(self::EXTENSION_PRO),
            self::TIER_ENTERPRISE => $this->hasActiveLicense(self::EXTENSION_ENTERPRISE),
            self::TIER_BETA => $this->hasActiveLicense(self::EXTENSION_PRO)
                || $this->hasActiveLicense(self::EXTENSION_ENTERPRISE),
            default => false,
        };
    }

    /**
     * @return array{status: string, expiresAt: ?string}
     */
    public function tierStatus(string $tier): array
    {
        $tier = strtolower(trim($tier));
        if ($tier === self::TIER_FREE || $tier === '') {
            return ['status' => 'allowed', 'expiresAt' => null];
        }
        $extId = $this->extensionForTier($tier);
        if ($extId === null) {
            return ['status' => 'unknown_tier', 'expiresAt' => null];
        }
        $ext = $this->discover()[$extId] ?? null;
        if ($ext === null) {
            return ['status' => 'not_installed', 'expiresAt' => null];
        }
        $licenseStatus = (string) ($ext['licenseInfo']['status'] ?? 'invalid');
        $expiresAt = $ext['licenseInfo']['expiresAt'] ?? null;
        return [
            'status' => $licenseStatus === LicenseValidator::STATUS_VALID
                ? 'allowed'
                : $licenseStatus,
            'expiresAt' => $expiresAt,
        ];
    }

    /**
     * Map a tier to the extension whose licence governs it.
     */
    private function extensionForTier(string $tier): ?string
    {
        return match ($tier) {
            self::TIER_PRO, self::TIER_BETA => self::EXTENSION_PRO,
            self::TIER_ENTERPRISE => self::EXTENSION_ENTERPRISE,
            default => null,
        };
    }

    private function hasActiveLicense(string $extensionId): bool
    {
        $extensions = $this->discover();
        $ext = $extensions[$extensionId] ?? null;
        if ($ext === null) {
            return false;
        }
        $status = (string) ($ext['licenseInfo']['status'] ?? '');
        return $status === LicenseValidator::STATUS_VALID;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function discover(): array
    {
        if ($this->registrySnapshot === null) {
            $this->registrySnapshot = $this->registry->discover();
        }
        return $this->registrySnapshot;
    }
}
