<?php

declare(strict_types=1);

namespace Knot\Tests\Licensing;

use Knot\Licensing\LicenseStatus;
use PHPUnit\Framework\TestCase;

final class LicenseStatusTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function usableProvider(): iterable
    {
        yield 'valid' => [LicenseStatus::VALID, true];
        yield 'not_required' => [LicenseStatus::NOT_REQUIRED, true];
        yield 'expired' => [LicenseStatus::EXPIRED, false];
        yield 'missing' => [LicenseStatus::MISSING, false];
        yield 'tampered' => [LicenseStatus::TAMPERED, false];
        yield 'invalid' => [LicenseStatus::INVALID, false];
    }

    /**
     * @dataProvider usableProvider
     */
    public function testIsUsable(string $status, bool $expected): void
    {
        $license = new LicenseStatus(
            status: $status,
            extensionId: 'ext-demo',
            expiresAt: '2027-01-01',
            signedAt: '2026-01-01',
            plan: 'pro',
            issuedTo: 'Example Co',
            error: null,
            offlineGrace: $status === LicenseStatus::VALID,
        );

        self::assertSame($expected, $license->isUsable());
    }

    public function testToArrayExportsAllFields(): void
    {
        $license = new LicenseStatus(
            status: LicenseStatus::VALID,
            extensionId: 'knot-pro-pack',
            expiresAt: '2027-06-01T00:00:00+00:00',
            signedAt: '2026-06-01T00:00:00+00:00',
            plan: 'yearly',
            issuedTo: 'Example Industries',
            error: null,
            offlineGrace: true,
        );

        self::assertSame([
            'status' => LicenseStatus::VALID,
            'extensionId' => 'knot-pro-pack',
            'expiresAt' => '2027-06-01T00:00:00+00:00',
            'signedAt' => '2026-06-01T00:00:00+00:00',
            'plan' => 'yearly',
            'issuedTo' => 'Example Industries',
            'error' => null,
            'offlineGrace' => true,
        ], $license->toArray());
    }
}
