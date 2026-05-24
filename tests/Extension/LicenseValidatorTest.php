<?php

declare(strict_types=1);

namespace Knot\Tests\Extension;

use Knot\Extension\LicenseValidator;
use PHPUnit\Framework\TestCase;

final class LicenseValidatorTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/knot-license-test-' . uniqid();
        mkdir($this->tmpDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            foreach (glob($this->tmpDir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->tmpDir);
        }
    }

    public function testFreeManifestIsAlwaysValid(): void
    {
        $validator = new LicenseValidator($this->tmpDir);
        $report = $validator->inspect([
            'id' => 'knot-utility',
            'license' => ['type' => 'free', 'validation' => 'none'],
        ]);
        self::assertSame(LicenseValidator::STATUS_NOT_REQUIRED, $report['status']);
        self::assertNull($report['error']);
    }

    public function testCommercialWithoutLicenseFileFlaggedMissing(): void
    {
        $validator = new LicenseValidator($this->tmpDir);
        $report = $validator->inspect([
            'id' => 'knot-stripe-pro',
            'license' => ['type' => 'commercial', 'validation' => 'local', 'productId' => '12345'],
        ]);
        self::assertSame(LicenseValidator::STATUS_MISSING, $report['status']);
        self::assertStringContainsString('not found', (string) $report['error']);
    }

    public function testValidLocalLicensePasses(): void
    {
        file_put_contents($this->tmpDir . '/knot-stripe-pro.json', json_encode([
            'extensionId' => 'knot-stripe-pro',
            'issuedTo' => 'Acme SARL',
            'issuedAt' => '2026-01-01',
            'expiresAt' => '2099-12-31',
        ]));
        $validator = new LicenseValidator($this->tmpDir);
        $report = $validator->inspect([
            'id' => 'knot-stripe-pro',
            'license' => ['type' => 'commercial', 'validation' => 'local'],
        ]);
        self::assertSame(LicenseValidator::STATUS_VALID, $report['status']);
        self::assertSame('Acme SARL', $report['issuedTo']);
        self::assertNotNull($report['expiresAt']);
    }

    public function testExpiredLicenseFlaggedExpired(): void
    {
        file_put_contents($this->tmpDir . '/knot-stripe-pro.json', json_encode([
            'extensionId' => 'knot-stripe-pro',
            'issuedTo' => 'Acme SARL',
            'issuedAt' => '2020-01-01',
            'expiresAt' => '2020-12-31',
        ]));
        $validator = new LicenseValidator($this->tmpDir);
        $report = $validator->inspect([
            'id' => 'knot-stripe-pro',
            'license' => ['type' => 'commercial', 'validation' => 'local'],
        ]);
        self::assertSame(LicenseValidator::STATUS_EXPIRED, $report['status']);
        self::assertStringContainsString('expired', (string) $report['error']);
    }

    public function testLicenseWithMismatchedExtensionIdFlaggedInvalid(): void
    {
        file_put_contents($this->tmpDir . '/knot-stripe-pro.json', json_encode([
            'extensionId' => 'knot-something-else',
            'expiresAt' => null,
        ]));
        $validator = new LicenseValidator($this->tmpDir);
        $report = $validator->inspect([
            'id' => 'knot-stripe-pro',
            'license' => ['type' => 'commercial', 'validation' => 'local'],
        ]);
        self::assertSame(LicenseValidator::STATUS_INVALID, $report['status']);
    }

    public function testEnsureValidThrowsForMissingLicense(): void
    {
        $validator = new LicenseValidator($this->tmpDir);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('license check failed');
        $validator->ensureValid([
            'id' => 'knot-stripe-pro',
            'license' => ['type' => 'commercial', 'validation' => 'local'],
        ]);
    }

    public function testEnsureValidPassesForFreeManifest(): void
    {
        $validator = new LicenseValidator($this->tmpDir);
        $validator->ensureValid([
            'id' => 'knot-utility',
            'license' => ['type' => 'free', 'validation' => 'none'],
        ]);
        self::assertTrue(true); // no exception = pass
    }
}
