<?php

declare(strict_types=1);

namespace Knot\Tests\Licensing;

use Knot\Licensing\ActivationCodeProtector;
use Knot\Licensing\Audit\LicenseAuditEvent;
use Knot\Licensing\Audit\LicenseAuditWriter;
use Knot\Licensing\InstanceBinder;
use Knot\Licensing\LicenseCache;
use Knot\Repository\AuditLogRepository;
use Knot\Tests\Licensing\Audit\CapturingDb;
use PHPUnit\Framework\TestCase;

/**
 * Mirrors the cache persistence contract of {@see \Knot\Api\license_activate}
 * for commercial extensions (notably knot-migration).
 *
 * @group e2e
 */
final class LicenseActivateCachePersistenceTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/knot-license-activate-' . uniqid();
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->cacheDir)) {
            return;
        }
        foreach (glob($this->cacheDir . '/*') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($this->cacheDir);
    }

    public function test_migration_extension_cache_file_persists_after_activation_write(): void
    {
        $extensionId = 'knot-migration';
        $activationCode = 'MIGRATIO-TEST-AAAA-BBBB-CCCC';
        $localSalt = 'activate-cache-test-salt';
        $binder = new InstanceBinder('Example Industries', 'https://demo.example.com', $localSalt);
        $fingerprint = $binder->compute();
        $signedPayload = [
            'license_id' => 10,
            'product_slug' => 'knot-migration',
            'status' => 'active',
            'issued_at' => gmdate('c'),
            'expires_at' => '2026-08-20T00:00:00+00:00',
        ];

        $cache = new LicenseCache($this->cacheDir);
        $cache->write([
            'extensionId' => $extensionId,
            'instanceId' => $fingerprint,
            'signedPayload' => $signedPayload,
            'signature' => str_repeat('a', 128),
            'signedAt' => (string) $signedPayload['issued_at'],
            'expiresAt' => $signedPayload['expires_at'],
            'plan' => 'beta',
            'issuedTo' => 'knot-migration',
            'activationCodeEnc' => ActivationCodeProtector::encrypt($activationCode, $localSalt, $extensionId),
            'lastSuccessfulRefresh' => gmdate('c'),
            'lastAttempt' => gmdate('c'),
            'lastError' => null,
        ]);

        $cacheFile = $this->cacheDir . '/knot-migration.cache.json';
        self::assertFileExists($cacheFile);
        $loaded = $cache->read('knot-migration');
        self::assertIsArray($loaded);
        self::assertSame(10, $loaded['signedPayload']['license_id'] ?? null);
        self::assertNotSame('', (string) ($loaded['activationCodeEnc'] ?? ''));
    }

    public function test_activation_emits_license_activated_audit_for_migration(): void
    {
        $db = new CapturingDb();
        $writer = new LicenseAuditWriter(new AuditLogRepository($db));

        $writer->record(
            LicenseAuditEvent::LICENSE_ACTIVATED,
            'knot-migration',
            [
                'fingerprint' => 'abc123',
                'licenseId' => 10,
                'source' => 'license_activate',
            ],
        );

        self::assertSame(1, count($db->queries));
        self::assertStringContainsString(
            LicenseAuditEvent::LICENSE_ACTIVATED->value,
            (string) $db->queries[0],
        );
        self::assertStringContainsString('knot-migration', (string) $db->queries[0]);
    }
}
