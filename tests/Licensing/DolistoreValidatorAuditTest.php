<?php

declare(strict_types=1);

namespace Knot\Tests\Licensing;

use Knot\Licensing\ActivationCodeProtector;
use Knot\Licensing\Audit\LicenseAuditEvent;
use Knot\Licensing\Audit\LicenseAuditWriter;
use Knot\Licensing\DolistoreValidator;
use Knot\Licensing\LicenseAuditThrottlePolicy;
use Knot\Licensing\ForkDetector;
use Knot\Licensing\InstanceBinder;
use Knot\Licensing\LicenseCache;
use Knot\Licensing\ManifestSignatureVerifier;
use Knot\Licensing\OfflineGracePolicy;
use Knot\Licensing\SignatureVerifier;
use Knot\Repository\AuditLogRepository;
use Knot\Tests\Licensing\Audit\CapturingDb;
use Knot\Tests\Licensing\Support\Ed25519TestHarness;
use Knot\Tests\Licensing\Support\FakeDolistoreClient;
use PHPUnit\Framework\TestCase;

/**
 * Validates that the DolistoreValidator emits the expected audit events
 * at every critical decision point — wiring confidence test for the
 * V2.5.0a Bonus B chantier (audit logging).
 */
final class DolistoreValidatorAuditTest extends TestCase
{
    private string $tmpDir;
    private Ed25519TestHarness $harness;
    private InstanceBinder $binder;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/knot-dolistore-audit-' . uniqid();
        $this->harness = new Ed25519TestHarness();
        $this->binder = new InstanceBinder('Acme', 'https://erp.acme.com', 'salt-test');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            $this->deleteTree($this->tmpDir);
        }
    }

    /**
     * Recursively remove temp dir (includes `.refresh-throttle/`).
     */
    private function deleteTree(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $path) {
            if (is_dir($path)) {
                $this->deleteTree($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    public function testFirstActivationEmitsLicenseActivated(): void
    {
        // After license_activate.php the cache already exists; the first
        // DolistoreValidator network refresh emits REFRESH_SUCCESS.
        $this->seedStaleActivationCache();
        $db = new CapturingDb();
        $writer = new LicenseAuditWriter(new AuditLogRepository($db));
        $client = $this->validClient();
        $validator = $this->makeValidator($client, $writer);

        $validator->inspect($this->manifest());

        $this->assertEventEmitted($db, LicenseAuditEvent::LICENSE_REFRESH_SUCCESS);
    }

    public function testSubsequentRefreshEmitsRefreshSuccess(): void
    {
        // Pre-warm cache so the next backend call is a refresh, not first activation.
        $cache = new LicenseCache($this->tmpDir);
        $cache->write([
            'extensionId' => 'knot-pro-pack',
            'instanceId' => $this->binder->compute(),
            'signedPayload' => ['valid' => true, 'extensionId' => 'knot-pro-pack', 'instanceId' => $this->binder->compute()],
            'signature' => $this->harness->sign(['valid' => true, 'extensionId' => 'knot-pro-pack', 'instanceId' => $this->binder->compute()]),
            'signedAt' => date('c', strtotime('-25 hours')),
            'expiresAt' => '2027-04-29T00:00:00+00:00',
            'plan' => 'standard',
            'issuedTo' => 'Acme',
            'lastSuccessfulRefresh' => date('c', strtotime('-1 day')),
            'lastAttempt' => date('c'),
            'lastError' => null,
            'activationCodeEnc' => $this->activationCodeEnc(),
        ]);

        $db = new CapturingDb();
        $writer = new LicenseAuditWriter(new AuditLogRepository($db));
        $client = $this->validClient();
        $validator = $this->makeValidator($client, $writer);

        $validator->inspect($this->manifest());

        $this->assertEventEmitted($db, LicenseAuditEvent::LICENSE_REFRESH_SUCCESS);
    }

    public function testNetworkErrorWithoutCacheEmitsRefreshFailed(): void
    {
        $this->seedStaleActivationCache();
        $db = new CapturingDb();
        $writer = new LicenseAuditWriter(new AuditLogRepository($db));
        $client = new FakeDolistoreClient();
        $client->failWith('Network down');
        $validator = $this->makeValidator($client, $writer);

        $validator->inspect($this->manifest());

        $this->assertEventEmitted($db, LicenseAuditEvent::LICENSE_REFRESH_FAILED);
    }

    public function testNetworkErrorWithoutCacheThrottlesRefreshFailedAudit(): void
    {
        $this->seedStaleActivationCache();
        $db = new CapturingDb();
        $writer = new LicenseAuditWriter(new AuditLogRepository($db));
        $client = new FakeDolistoreClient();
        $client->failWith('HTTP error 404 contacting license backend');
        $validator = $this->makeValidator($client, $writer);

        $validator->inspect($this->manifest());
        $validator->inspect($this->manifest());

        self::assertSame(1, $this->countAuditEvents($db, LicenseAuditEvent::LICENSE_REFRESH_FAILED));
    }

    public function testNetworkErrorWithoutCacheThrottlesNon404Failures(): void
    {
        $this->seedStaleActivationCache();
        $db = new CapturingDb();
        $writer = new LicenseAuditWriter(new AuditLogRepository($db));
        $client = new FakeDolistoreClient();
        $client->failWith('Network down');
        $validator = $this->makeValidator($client, $writer);

        $validator->inspect($this->manifest());
        $validator->inspect($this->manifest());

        self::assertSame(1, $this->countAuditEvents($db, LicenseAuditEvent::LICENSE_REFRESH_FAILED));
    }

    public function testGraceWindowEntryEmitsGraceEntered(): void
    {
        $cache = new LicenseCache($this->tmpDir);
        $cache->write([
            'extensionId' => 'knot-pro-pack',
            'instanceId' => $this->binder->compute(),
            'signedPayload' => ['valid' => true, 'extensionId' => 'knot-pro-pack', 'instanceId' => $this->binder->compute()],
            'signature' => $this->harness->sign(['valid' => true, 'extensionId' => 'knot-pro-pack', 'instanceId' => $this->binder->compute()]),
            'signedAt' => date('c', strtotime('-25 hours')),
            'expiresAt' => '2027-04-29T00:00:00+00:00',
            'plan' => 'standard',
            'issuedTo' => 'Acme',
            'lastSuccessfulRefresh' => date('c', strtotime('-5 days')),
            'lastAttempt' => date('c'),
            'lastError' => null,
            'activationCodeEnc' => $this->activationCodeEnc(),
        ]);

        $db = new CapturingDb();
        $writer = new LicenseAuditWriter(new AuditLogRepository($db));
        $client = new FakeDolistoreClient();
        $client->failWith('Network down');
        $validator = $this->makeValidator($client, $writer);

        $validator->inspect($this->manifest());

        $this->assertEventEmitted($db, LicenseAuditEvent::LICENSE_REFRESH_FAILED);
        $this->assertEventEmitted($db, LicenseAuditEvent::LICENSE_GRACE_ENTERED);
    }

    public function testRepeatedOfflineInspectThrottlesRefreshFailedAndGraceEnteredAudits(): void
    {
        $cache = new LicenseCache($this->tmpDir);
        $cache->write([
            'extensionId' => 'knot-pro-pack',
            'instanceId' => $this->binder->compute(),
            'signedPayload' => ['valid' => true, 'extensionId' => 'knot-pro-pack', 'instanceId' => $this->binder->compute()],
            'signature' => $this->harness->sign(['valid' => true, 'extensionId' => 'knot-pro-pack', 'instanceId' => $this->binder->compute()]),
            'signedAt' => date('c', strtotime('-25 hours')),
            'expiresAt' => '2027-04-29T00:00:00+00:00',
            'plan' => 'standard',
            'issuedTo' => 'Acme',
            'lastSuccessfulRefresh' => date('c', strtotime('-5 days')),
            'lastAttempt' => date('c'),
            'lastError' => null,
            'activationCodeEnc' => $this->activationCodeEnc(),
        ]);

        $db = new CapturingDb();
        $writer = new LicenseAuditWriter(new AuditLogRepository($db));
        $client = new FakeDolistoreClient();
        $client->failWith('HTTP error 404 contacting license backend');
        $validator = $this->makeValidator($client, $writer);

        $validator->inspect($this->manifest());
        $validator->inspect($this->manifest());

        self::assertSame(1, $this->countAuditEvents($db, LicenseAuditEvent::LICENSE_REFRESH_FAILED));
        self::assertSame(1, $this->countAuditEvents($db, LicenseAuditEvent::LICENSE_GRACE_ENTERED));
    }

    public function testRefreshFailedAuditEmittedAgainWhenErrorClassChanges(): void
    {
        $cache = new LicenseCache($this->tmpDir);
        $cache->write([
            'extensionId' => 'knot-pro-pack',
            'instanceId' => $this->binder->compute(),
            'signedPayload' => ['valid' => true, 'extensionId' => 'knot-pro-pack', 'instanceId' => $this->binder->compute()],
            'signature' => $this->harness->sign(['valid' => true, 'extensionId' => 'knot-pro-pack', 'instanceId' => $this->binder->compute()]),
            'signedAt' => date('c', strtotime('-25 hours')),
            'expiresAt' => '2027-04-29T00:00:00+00:00',
            'plan' => 'standard',
            'issuedTo' => 'Acme',
            'lastSuccessfulRefresh' => date('c', strtotime('-5 days')),
            'lastAttempt' => date('c'),
            'lastError' => null,
            'auditThrottle' => [
                'refreshFailedAt' => date('c'),
                'refreshFailedClass' => LicenseAuditThrottlePolicy::REFRESH_FAILURE_CLASS_HTTP_404,
            ],
            'activationCodeEnc' => $this->activationCodeEnc(),
        ]);

        $db = new CapturingDb();
        $writer = new LicenseAuditWriter(new AuditLogRepository($db));
        $client = new FakeDolistoreClient();
        $client->failWith('connection reset');
        $validator = $this->makeValidator($client, $writer);

        $validator->inspect($this->manifest());

        self::assertSame(1, $this->countAuditEvents($db, LicenseAuditEvent::LICENSE_REFRESH_FAILED));
    }

    public function testGraceWindowExhaustedEmitsGraceExhausted(): void
    {
        $cache = new LicenseCache($this->tmpDir);
        $cache->write([
            'extensionId' => 'knot-pro-pack',
            'instanceId' => $this->binder->compute(),
            'signedPayload' => ['valid' => true, 'extensionId' => 'knot-pro-pack', 'instanceId' => $this->binder->compute()],
            'signature' => $this->harness->sign(['valid' => true, 'extensionId' => 'knot-pro-pack', 'instanceId' => $this->binder->compute()]),
            'signedAt' => date('c', strtotime('-25 hours')),
            'expiresAt' => '2027-04-29T00:00:00+00:00',
            'plan' => 'standard',
            'issuedTo' => 'Acme',
            'lastSuccessfulRefresh' => date('c', strtotime('-15 days')),
            'lastAttempt' => date('c'),
            'lastError' => null,
            'activationCodeEnc' => $this->activationCodeEnc(),
        ]);

        $db = new CapturingDb();
        $writer = new LicenseAuditWriter(new AuditLogRepository($db));
        $client = new FakeDolistoreClient();
        $client->failWith('Network down');
        $validator = $this->makeValidator($client, $writer);

        $validator->inspect($this->manifest());

        $this->assertEventEmitted($db, LicenseAuditEvent::LICENSE_GRACE_EXHAUSTED);
    }

    public function testForkDetectionEmitsLicenseForkDetected(): void
    {
        $db = new CapturingDb();
        $writer = new LicenseAuditWriter(new AuditLogRepository($db));
        $client = $this->validClient();
        $forkDetector = new ForkDetector(['knot-pro-pack' => 'official-manifest-signature']);
        $validator = $this->makeValidator($client, $writer, $forkDetector);

        $manifest = $this->manifest();
        $manifest['license']['manifestSignature'] = 'forged-signature';

        $validator->inspect($manifest);

        $this->assertEventEmitted($db, LicenseAuditEvent::LICENSE_FORK_DETECTED);
    }

    public function testRevokedLicenseEmitsLicenseRevoked(): void
    {
        $this->seedStaleActivationCache();
        $db = new CapturingDb();
        $writer = new LicenseAuditWriter(new AuditLogRepository($db));
        $client = new FakeDolistoreClient();
        $client->setResponder(fn (array $params) => $this->signedResponse(
            (string) ($params['instanceFingerprint'] ?? ''),
            false,
            '2027-04-29T00:00:00+00:00',
        ));
        $validator = $this->makeValidator($client, $writer);

        $validator->inspect($this->manifest());

        $this->assertEventEmitted($db, LicenseAuditEvent::LICENSE_REVOKED);
    }

    public function testNoEventsEmittedWhenWriterIsAbsent(): void
    {
        $this->seedStaleActivationCache();
        // No writer wired => existing behaviour is preserved (back-compat).
        $client = $this->validClient();
        $validator = $this->makeValidator($client, null);
        $status = $validator->inspect($this->manifest());
        self::assertSame('valid', $status->status);
    }

    private function assertEventEmitted(CapturingDb $db, LicenseAuditEvent $event): void
    {
        foreach ($db->queries as $sql) {
            if (str_contains($sql, "'" . $event->value . "'")) {
                self::assertTrue(true);
                return;
            }
        }
        self::fail(sprintf('Expected audit event "%s" was not emitted', $event->value));
    }

    private function countAuditEvents(CapturingDb $db, LicenseAuditEvent $event): int
    {
        $needle = "'" . $event->value . "'";
        $c = 0;
        foreach ($db->queries as $sql) {
            if (str_contains($sql, $needle)) {
                ++$c;
            }
        }

        return $c;
    }

    private function validClient(): FakeDolistoreClient
    {
        $client = new FakeDolistoreClient();
        $client->setResponder(fn (array $params) => $this->signedResponse(
            (string) ($params['instanceFingerprint'] ?? ''),
            true,
            '2027-04-29T00:00:00+00:00',
        ));
        return $client;
    }

    private function makeValidator(
        FakeDolistoreClient $client,
        ?LicenseAuditWriter $writer,
        ?ForkDetector $forkDetector = null
    ): DolistoreValidator {
        return new DolistoreValidator(
            $client,
            new SignatureVerifier([$this->harness->publicKeyHex()]),
            $this->binder,
            $forkDetector ?? new ForkDetector([]),
            new LicenseCache($this->tmpDir),
            new OfflineGracePolicy(14),
            24,
            'erp.acme.com',
            null,
            null,
            $writer,
            new ManifestSignatureVerifier(new SignatureVerifier([$this->harness->publicKeyHex()])),
        );
    }

    /** @return array{id: string, license: array<string, mixed>} */
    private function manifest(): array
    {
        return [
            'id' => 'knot-pro-pack',
            'license' => [
                'type' => 'commercial',
                'validation' => 'dolistore',
                'productId' => '12345',
            ],
        ];
    }

    /**
     * @return array{
     *     valid: bool,
     *     expiresAt: ?string,
     *     plan: ?string,
     *     issuedTo: ?string,
     *     signature: string,
     *     signedAt: string,
     *     payload: array<string, mixed>
     * }
     */
    private function activationCodeEnc(string $code = 'KNOT-TEST-AAAA-BBBB-CCCC-DDDD'): string
    {
        return ActivationCodeProtector::encrypt($code, $this->binder->localSaltValue(), 'knot-pro-pack');
    }

    private function seedStaleActivationCache(string $code = 'KNOT-TEST-AAAA-BBBB-CCCC-DDDD'): void
    {
        $extensionId = 'knot-pro-pack';
        $fingerprint = $this->binder->compute();
        $payload = [
            'status' => 'active',
            'instance_fingerprint' => $fingerprint,
            'issued_at' => gmdate('c', time() - 100_000),
        ];
        (new LicenseCache($this->tmpDir))->write([
            'extensionId' => $extensionId,
            'instanceId' => $fingerprint,
            'signedPayload' => $payload,
            'signature' => $this->harness->sign($payload),
            'signedAt' => gmdate('c', time() - 86_400 * 3),
            'expiresAt' => '2027-04-29T00:00:00+00:00',
            'activationCodeEnc' => $this->activationCodeEnc($code),
            'lastSuccessfulRefresh' => gmdate('c', time() - 86_400 * 3),
            'lastAttempt' => gmdate('c', time() - 86_400 * 3),
            'lastError' => null,
        ]);
    }

    private function signedResponse(string $instanceFingerprint, bool $valid, ?string $expiresAt): array
    {
        $payload = [
            'valid' => $valid,
            'status' => $valid ? 'active' : 'expired',
            'extensionId' => 'knot-pro-pack',
            'instance_fingerprint' => $instanceFingerprint,
            'instanceId' => $instanceFingerprint,
            'expiresAt' => $expiresAt,
            'expires_at' => $expiresAt,
            'plan' => 'standard',
            'issuedTo' => 'Acme',
        ];

        return [
            'valid' => $valid,
            'expiresAt' => $expiresAt,
            'plan' => 'standard',
            'issuedTo' => 'Acme',
            'signature' => $this->harness->sign($payload),
            'signedAt' => date('c'),
            'payload' => $payload,
        ];
    }
}
