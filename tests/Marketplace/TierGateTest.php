<?php

declare(strict_types=1);

namespace Knot\Tests\Marketplace;

use Knot\Extension\ExtensionRegistry;
use Knot\Extension\LicenseValidator;
use Knot\Marketplace\TierGate;
use PHPUnit\Framework\TestCase;

/**
 * V2.5.0c — Verifies the marketplace tier gate enforces the
 * "active licence, not just installed module" invariant the user
 * explicitly called out:
 *
 *   "le fait que les éléments s'affichent ou pas dans la marketplace
 *    doivent le faire si la licence pro est active pas juste le module
 *    pro installé."
 *
 * Each test substitutes a tiny ExtensionRegistry stub so we can drive
 * the licence verdict through every transition (valid / expired /
 * missing / not installed) without touching disk or Dolistore.
 */
final class TierGateTest extends TestCase
{
    public function testFreeTierIsAlwaysAllowed(): void
    {
        $gate = new TierGate(new StubRegistry([]));
        self::assertTrue($gate->canUseTier(TierGate::TIER_FREE));
        self::assertTrue($gate->canUseTier(''));
    }

    public function testProTierRequiresActiveProLicense(): void
    {
        $gate = new TierGate(new StubRegistry([
            TierGate::EXTENSION_PRO => [
                'licenseInfo' => ['status' => LicenseValidator::STATUS_VALID],
            ],
        ]));
        self::assertTrue($gate->canUseTier(TierGate::TIER_PRO));
    }

    public function testProTierRefusedWhenLicenseExpired(): void
    {
        $gate = new TierGate(new StubRegistry([
            TierGate::EXTENSION_PRO => [
                'licenseInfo' => ['status' => LicenseValidator::STATUS_EXPIRED],
            ],
        ]));
        self::assertFalse($gate->canUseTier(TierGate::TIER_PRO));
    }

    public function testProTierRefusedWhenModuleInstalledButNeverActivated(): void
    {
        $gate = new TierGate(new StubRegistry([
            TierGate::EXTENSION_PRO => [
                'licenseInfo' => ['status' => LicenseValidator::STATUS_MISSING],
            ],
        ]));
        self::assertFalse($gate->canUseTier(TierGate::TIER_PRO));
    }

    public function testProTierRefusedWhenModuleNotInstalledAtAll(): void
    {
        $gate = new TierGate(new StubRegistry([]));
        self::assertFalse($gate->canUseTier(TierGate::TIER_PRO));
    }

    public function testEnterpriseTierIgnoresProLicense(): void
    {
        $gate = new TierGate(new StubRegistry([
            TierGate::EXTENSION_PRO => [
                'licenseInfo' => ['status' => LicenseValidator::STATUS_VALID],
            ],
        ]));
        self::assertFalse($gate->canUseTier(TierGate::TIER_ENTERPRISE));
    }

    public function testBetaTierAllowedWithProOrEnterprise(): void
    {
        $proOnly = new TierGate(new StubRegistry([
            TierGate::EXTENSION_PRO => [
                'licenseInfo' => ['status' => LicenseValidator::STATUS_VALID],
            ],
        ]));
        self::assertTrue($proOnly->canUseTier(TierGate::TIER_BETA));

        $entOnly = new TierGate(new StubRegistry([
            TierGate::EXTENSION_ENTERPRISE => [
                'licenseInfo' => ['status' => LicenseValidator::STATUS_VALID],
            ],
        ]));
        self::assertTrue($entOnly->canUseTier(TierGate::TIER_BETA));

        $none = new TierGate(new StubRegistry([]));
        self::assertFalse($none->canUseTier(TierGate::TIER_BETA));
    }

    public function testTierStatusReportsAllowed(): void
    {
        $gate = new TierGate(new StubRegistry([
            TierGate::EXTENSION_PRO => [
                'licenseInfo' => [
                    'status' => LicenseValidator::STATUS_VALID,
                    'expiresAt' => '2099-01-01T00:00:00+00:00',
                ],
            ],
        ]));
        $status = $gate->tierStatus(TierGate::TIER_PRO);
        self::assertSame('allowed', $status['status']);
        self::assertSame('2099-01-01T00:00:00+00:00', $status['expiresAt']);
    }

    public function testTierStatusReportsNotInstalled(): void
    {
        $gate = new TierGate(new StubRegistry([]));
        $status = $gate->tierStatus(TierGate::TIER_PRO);
        self::assertSame('not_installed', $status['status']);
    }

    public function testTierStatusReportsExpired(): void
    {
        $gate = new TierGate(new StubRegistry([
            TierGate::EXTENSION_PRO => [
                'licenseInfo' => ['status' => LicenseValidator::STATUS_EXPIRED],
            ],
        ]));
        $status = $gate->tierStatus(TierGate::TIER_PRO);
        self::assertSame(LicenseValidator::STATUS_EXPIRED, $status['status']);
    }

    public function testUnknownTierIsRefusedAndReported(): void
    {
        $gate = new TierGate(new StubRegistry([]));
        self::assertFalse($gate->canUseTier('mythical'));
        self::assertSame('unknown_tier', $gate->tierStatus('mythical')['status']);
    }
}

/**
 * Tiny ExtensionRegistry double — overrides `discover()` so the gate
 * sees a deterministic snapshot. We extend the real class (which had
 * `final` removed in V2.5.0c) instead of building a separate
 * interface to keep the production code surface unchanged.
 */
final class StubRegistry extends ExtensionRegistry
{
    /** @param array<string, array<string, mixed>> $snapshot */
    public function __construct(private readonly array $snapshot)
    {
        parent::__construct([sys_get_temp_dir() . '/knot-tier-gate-tests']);
    }

    public function discover(): array
    {
        return $this->snapshot;
    }

    public function clearCache(): void
    {
        // No-op — the snapshot is immutable.
    }
}
