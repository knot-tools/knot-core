<?php

declare(strict_types=1);

namespace Knot\Tests\Licensing;

use Knot\KnownSkus;
use Knot\Licensing\ActivationCodeProtector;
use Knot\Licensing\DolistoreValidator;
use Knot\Licensing\ForkDetector;
use Knot\Licensing\InstanceBinder;
use Knot\Licensing\LicenseCache;
use Knot\Licensing\LicenseStatus;
use Knot\Licensing\ManifestSignatureVerifier;
use Knot\Licensing\OfflineGracePolicy;
use Knot\Licensing\OfficialManifestSignatures;
use Knot\Licensing\SignatureVerifier;
use Knot\Tests\Licensing\Support\Ed25519TestHarness;
use Knot\Tests\Licensing\Support\FakeDolistoreClient;
use PHPUnit\Framework\TestCase;

final class DolistoreValidatorTest extends TestCase
{
    private string $tmpDir;
    private Ed25519TestHarness $harness;
    private InstanceBinder $binder;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/knot-dolistore-' . uniqid();
        $this->harness = new Ed25519TestHarness();
        $this->binder = new InstanceBinder('Acme', 'https://erp.acme.com', 'salt-test');
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

    public function testHappyPathValidLicenseGetsCached(): void
    {
        $this->seedStaleActivationCache();
        $client = new FakeDolistoreClient();
        $client->setResponder(fn (array $params) => $this->signedResponse(
            (string) ($params['instanceFingerprint'] ?? ''),
            true,
            '2027-04-29T00:00:00+00:00',
        ));
        $validator = $this->makeValidator($client);

        $status = $validator->inspect($this->manifest());

        self::assertSame(LicenseStatus::VALID, $status->status);
        self::assertNull($status->error);
        self::assertCount(1, $client->calls);
        self::assertNotNull((new LicenseCache($this->tmpDir))->read('knot-pro-pack'));
    }

    public function testCacheHitWithinTtlSkipsBackend(): void
    {
        $this->seedStaleActivationCache();
        $client = new FakeDolistoreClient();
        $client->setResponder(fn (array $params) => $this->signedResponse(
            (string) ($params['instanceFingerprint'] ?? ''),
            true,
            '2027-04-29T00:00:00+00:00',
        ));
        $validator = $this->makeValidator($client);

        $validator->inspect($this->manifest());
        $validator->inspect($this->manifest());

        self::assertCount(1, $client->calls, 'Second inspect must be served from cache');
    }

    public function testExpiredLicenseFlaggedAndCacheCleared(): void
    {
        $this->seedStaleActivationCache();
        $client = new FakeDolistoreClient();
        $client->setResponder(fn (array $params) => $this->signedResponse(
            (string) ($params['instanceFingerprint'] ?? ''),
            false,
            '2026-01-01T00:00:00+00:00',
        ));
        $validator = $this->makeValidator($client);

        $status = $validator->inspect($this->manifest());

        self::assertSame(LicenseStatus::EXPIRED, $status->status);
        self::assertNull((new LicenseCache($this->tmpDir))->read('knot-pro-pack'));
    }

    public function testMissingLicenseWhenBackendUnreachableAndNoCache(): void
    {
        $client = new FakeDolistoreClient();
        $client->failWith('Network down');
        $validator = $this->makeValidator($client);

        $status = $validator->inspect($this->manifest());

        self::assertSame(LicenseStatus::MISSING, $status->status);
        self::assertStringContainsString('activation code', (string) $status->error);
    }

    public function testMissingActivationCodeBlocksNetworkRefresh(): void
    {
        $client = new FakeDolistoreClient();
        $client->setResponder(fn (array $params) => $this->signedResponse(
            (string) ($params['instanceFingerprint'] ?? ''),
            true,
            '2027-04-29T00:00:00+00:00',
        ));
        $validator = $this->makeValidator($client);

        $status = $validator->inspect($this->manifest());

        self::assertSame(LicenseStatus::MISSING, $status->status);
        self::assertCount(0, $client->calls);
    }

    public function testMissingLicenseWhenBackendUnreachableWithActivationCode(): void
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
            'activationCodeEnc' => $this->activationCodeEnc(),
            'lastSuccessfulRefresh' => gmdate('c', time() - 86_400 * 15),
            'lastAttempt' => gmdate('c', time() - 86_400 * 3),
            'lastError' => null,
        ]);
        $client = new FakeDolistoreClient();
        $client->failWith('Network down');
        $validator = $this->makeValidator($client);

        $status = $validator->inspect($this->manifest());

        self::assertSame(LicenseStatus::EXPIRED, $status->status);
        self::assertStringContainsString('Network down', (string) $status->error);
    }

    public function testTamperedRejectsUnsignedResponse(): void
    {
        $this->seedStaleActivationCache();
        $client = new FakeDolistoreClient();
        $client->setResponder(function (array $params) {
            $fingerprint = (string) ($params['instanceFingerprint'] ?? '');
            $payload = ['valid' => true, 'extensionId' => 'knot-pro-pack', 'instanceId' => $fingerprint];
            return [
                'valid' => true,
                'expiresAt' => '2027-04-29T00:00:00+00:00',
                'plan' => 'standard',
                'issuedTo' => 'Acme',
                'signature' => base64_encode(str_repeat("\0", SODIUM_CRYPTO_SIGN_BYTES)),
                'signedAt' => date('c'),
                'payload' => $payload,
            ];
        });
        $validator = $this->makeValidator($client);

        $status = $validator->inspect($this->manifest());

        self::assertSame(LicenseStatus::TAMPERED, $status->status);
    }

    public function testTamperedRejectsForeignKeySignature(): void
    {
        $this->seedStaleActivationCache();
        $client = new FakeDolistoreClient();
        $client->setResponder(function (array $params) {
            $fingerprint = (string) ($params['instanceFingerprint'] ?? '');
            $payload = ['valid' => true, 'extensionId' => 'knot-pro-pack', 'instanceId' => $fingerprint];
            return [
                'valid' => true,
                'expiresAt' => '2027-04-29T00:00:00+00:00',
                'plan' => 'standard',
                'issuedTo' => 'Acme',
                'signature' => $this->harness->signWithForeignKey($payload),
                'signedAt' => date('c'),
                'payload' => $payload,
            ];
        });
        $validator = $this->makeValidator($client);

        $status = $validator->inspect($this->manifest());

        self::assertSame(LicenseStatus::TAMPERED, $status->status);
    }

    public function testTamperedRejectsWrongInstanceBinding(): void
    {
        $this->seedStaleActivationCache();
        $client = new FakeDolistoreClient();
        $client->setResponder(fn (array $params) => $this->signedResponse('foreign-instance-id', true, '2027-04-29T00:00:00+00:00'));
        $validator = $this->makeValidator($client);

        $status = $validator->inspect($this->manifest());

        self::assertSame(LicenseStatus::TAMPERED, $status->status);
    }

    public function testForkDetectorBlocksImpersonation(): void
    {
        $client = new FakeDolistoreClient();
        $client->setResponder(fn (array $params) => $this->signedResponse(
            (string) ($params['instanceFingerprint'] ?? ''),
            true,
            '2027-04-29T00:00:00+00:00',
        ));
        $official = str_repeat('f', 128);
        $forkDetector = new ForkDetector(['knot-pro-pack' => $official]);
        $validator = $this->makeValidator($client, null, $forkDetector);

        $manifest = $this->manifest();
        $manifest['license']['manifestSignature'] = str_repeat('0', 128);

        $status = $validator->inspect($manifest);

        self::assertSame(LicenseStatus::TAMPERED, $status->status);
        self::assertCount(0, $client->calls, 'Fork detection runs before any backend call');
    }

    public function testOfflineWithinGraceWindowKeepsValid(): void
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

        $client = new FakeDolistoreClient();
        $client->failWith('Network down');
        $validator = $this->makeValidator($client);

        $status = $validator->inspect($this->manifest());

        self::assertSame(LicenseStatus::VALID, $status->status);
        self::assertTrue($status->offlineGrace);
    }

    public function testOfflineBeyondGraceWindowDowngradesToExpired(): void
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

        $client = new FakeDolistoreClient();
        $client->failWith('Network down');
        $validator = $this->makeValidator($client);

        $status = $validator->inspect($this->manifest());

        self::assertSame(LicenseStatus::EXPIRED, $status->status);
    }

    public function testPinnedOfficialExtensionRejectsAbsentManifestSignature(): void
    {
        $pins = OfficialManifestSignatures::map();
        if (($pins[KnownSkus::PRO_PACK] ?? []) === []) {
            self::markTestSkipped('No pinned digest for Pro Pack in this checkout.');
        }

        $client = new FakeDolistoreClient();
        $fork = new ForkDetector($pins);
        $validator = $this->makeValidator($client, null, $fork);

        $status = $validator->inspect($this->manifest());

        self::assertSame(LicenseStatus::TAMPERED, $status->status);
        self::assertCount(0, $client->calls, 'Licence backend must not be queried when pinning fails early');
    }

    public function testPinnedOfficialPackAcceptsMatchingManifestSignature(): void
    {
        $pins = OfficialManifestSignatures::primaryMap();
        $hex = $pins[KnownSkus::PRO_PACK] ?? '';
        if ($hex === '') {
            self::markTestSkipped('No pinned digest for Pro Pack in this checkout.');
        }

        $this->seedStaleActivationCache();
        $client = new FakeDolistoreClient();
        $client->setResponder(fn (array $params) => $this->signedResponse(
            (string) ($params['instanceFingerprint'] ?? ''),
            true,
            '2027-04-29T00:00:00+00:00',
        ));
        $fork = new ForkDetector(OfficialManifestSignatures::map());
        $validator = $this->makeValidator($client, null, $fork);

        $status = $validator->inspect($this->manifest(['manifestSignature' => $hex]));

        self::assertSame(LicenseStatus::VALID, $status->status);
    }

    public function testDeprecatedTransitionManifestSignatureStillAccepted(): void
    {
        $map = OfficialManifestSignatures::map();
        $deprecated = $map[KnownSkus::PRO_PACK][1] ?? '';
        if ($deprecated === '') {
            self::markTestSkipped('No deprecated transition pin for Pro Pack.');
        }

        $this->seedStaleActivationCache();
        $client = new FakeDolistoreClient();
        $client->setResponder(fn (array $params) => $this->signedResponse(
            (string) ($params['instanceFingerprint'] ?? ''),
            true,
            '2027-04-29T00:00:00+00:00',
        ));
        $fork = new ForkDetector($map);
        $validator = $this->makeValidator($client, null, $fork);

        $status = $validator->inspect($this->manifest(['manifestSignature' => $deprecated]));

        self::assertSame(LicenseStatus::VALID, $status->status);
    }

    public function testMissingProductIdReturnsInvalid(): void
    {
        $client = new FakeDolistoreClient();
        $validator = $this->makeValidator($client);

        $status = $validator->inspect([
            'id' => 'knot-pro-pack',
            'license' => [
                'type' => 'commercial',
                'validation' => 'dolistore',
            ],
        ]);

        self::assertSame(LicenseStatus::INVALID, $status->status);
        self::assertCount(0, $client->calls);
    }

    public function testFreshCacheWithInvalidSignatureForcesBackendRefresh(): void
    {
        $cache = new LicenseCache($this->tmpDir);
        $cache->write([
            'extensionId' => 'knot-pro-pack',
            'instanceId' => $this->binder->compute(),
            'signedPayload' => ['valid' => true, 'extensionId' => 'knot-pro-pack', 'instanceId' => $this->binder->compute()],
            'signature' => base64_encode(str_repeat("\0", SODIUM_CRYPTO_SIGN_BYTES)),
            'signedAt' => date('c'),
            'expiresAt' => '2027-04-29T00:00:00+00:00',
            'plan' => 'standard',
            'issuedTo' => 'Acme',
            'lastSuccessfulRefresh' => date('c'),
            'lastAttempt' => date('c'),
            'lastError' => null,
            'activationCodeEnc' => $this->activationCodeEnc(),
        ]);

        $client = new FakeDolistoreClient();
        $client->setResponder(fn (array $params) => $this->signedResponse(
            (string) ($params['instanceFingerprint'] ?? ''),
            true,
            '2027-04-29T00:00:00+00:00',
        ));
        $validator = $this->makeValidator($client);

        $status = $validator->inspect($this->manifest());

        self::assertSame(LicenseStatus::VALID, $status->status);
        self::assertCount(1, $client->calls);
    }

    public function testFreshCacheWithExpiredPayloadReturnsExpiredWithoutBackend(): void
    {
        $cache = new LicenseCache($this->tmpDir);
        $payload = ['valid' => true, 'extensionId' => 'knot-pro-pack', 'instanceId' => $this->binder->compute()];
        $cache->write([
            'extensionId' => 'knot-pro-pack',
            'instanceId' => $this->binder->compute(),
            'signedPayload' => $payload,
            'signature' => $this->harness->sign($payload),
            'signedAt' => date('c'),
            'expiresAt' => '2020-01-01T00:00:00+00:00',
            'plan' => 'standard',
            'issuedTo' => 'Acme',
            'lastSuccessfulRefresh' => date('c'),
            'lastAttempt' => date('c'),
            'lastError' => null,
        ]);

        $client = new FakeDolistoreClient();
        $validator = $this->makeValidator($client);

        $status = $validator->inspect($this->manifest());

        self::assertSame(LicenseStatus::EXPIRED, $status->status);
        self::assertCount(0, $client->calls);
    }

    public function testBackendPastExpiryClearsCache(): void
    {
        $this->seedStaleActivationCache();
        $client = new FakeDolistoreClient();
        $client->setResponder(fn (array $params) => $this->signedResponse(
            (string) ($params['instanceFingerprint'] ?? ''),
            true,
            '2020-01-01T00:00:00+00:00'
        ));
        $validator = $this->makeValidator($client);

        $status = $validator->inspect($this->manifest());

        self::assertSame(LicenseStatus::EXPIRED, $status->status);
        self::assertNull((new LicenseCache($this->tmpDir))->read('knot-pro-pack'));
    }

    public function testOfflineGraceReturnsTamperedWhenCachedPayloadInvalid(): void
    {
        $cache = new LicenseCache($this->tmpDir);
        $cache->write([
            'extensionId' => 'knot-pro-pack',
            'instanceId' => 'foreign-instance-id',
            'signedPayload' => ['valid' => true, 'extensionId' => 'knot-pro-pack', 'instanceId' => 'foreign-instance-id'],
            'signature' => $this->harness->sign(['valid' => true, 'extensionId' => 'knot-pro-pack', 'instanceId' => 'foreign-instance-id']),
            'signedAt' => date('c', strtotime('-25 hours')),
            'expiresAt' => '2027-04-29T00:00:00+00:00',
            'plan' => 'standard',
            'issuedTo' => 'Acme',
            'lastSuccessfulRefresh' => date('c', strtotime('-5 days')),
            'lastAttempt' => date('c'),
            'lastError' => null,
            'activationCodeEnc' => $this->activationCodeEnc(),
        ]);

        $client = new FakeDolistoreClient();
        $client->failWith('Network down');
        $validator = $this->makeValidator($client);

        $status = $validator->inspect($this->manifest());

        self::assertSame(LicenseStatus::TAMPERED, $status->status);
    }

    public function testMalformedCacheSignedAtTriggersBackendRefresh(): void
    {
        $cache = new LicenseCache($this->tmpDir);
        $payload = ['valid' => true, 'extensionId' => 'knot-pro-pack', 'instanceId' => $this->binder->compute()];
        $cache->write([
            'extensionId' => 'knot-pro-pack',
            'instanceId' => $this->binder->compute(),
            'signedPayload' => $payload,
            'signature' => $this->harness->sign($payload),
            'signedAt' => 'not-a-date',
            'expiresAt' => '2027-04-29T00:00:00+00:00',
            'plan' => 'standard',
            'issuedTo' => 'Acme',
            'lastSuccessfulRefresh' => date('c'),
            'lastAttempt' => date('c'),
            'lastError' => null,
            'activationCodeEnc' => $this->activationCodeEnc(),
        ]);

        $client = new FakeDolistoreClient();
        $client->setResponder(fn (array $params) => $this->signedResponse(
            (string) ($params['instanceFingerprint'] ?? ''),
            true,
            '2027-04-29T00:00:00+00:00',
        ));
        $validator = $this->makeValidator($client);

        $status = $validator->inspect($this->manifest());

        self::assertSame(LicenseStatus::VALID, $status->status);
        self::assertCount(1, $client->calls);
    }

    public function testCryptographicManifestSignatureAcceptsVersionNotInPinList(): void
    {
        $manifest = [
            'id' => 'knot-pro-pack',
            'version' => '9.9.9-not-pinned',
            'license' => [
                'type' => 'commercial',
                'validation' => 'dolistore',
                'productId' => '12345',
            ],
        ];
        $manifest['license']['manifestSignature'] = $this->harness->signManifest($manifest);

        $this->seedStaleActivationCache();
        $client = new FakeDolistoreClient();
        $client->setResponder(fn (array $params) => $this->signedResponse(
            (string) ($params['instanceFingerprint'] ?? ''),
            true,
            '2027-04-29T00:00:00+00:00',
        ));
        $fork = new ForkDetector(['knot-pro-pack' => str_repeat('f', 128)]);
        $validator = $this->makeValidator($client, null, $fork);

        $status = $validator->inspect($manifest);

        self::assertSame(LicenseStatus::VALID, $status->status);
    }

    public function testPinnedExtensionRejectsMalformedManifestSignature(): void
    {
        $client = new FakeDolistoreClient();
        $fork = new ForkDetector(['knot-pro-pack' => str_repeat('a', 128)]);
        $validator = $this->makeValidator($client, null, $fork);

        $status = $validator->inspect($this->manifest(['manifestSignature' => 'not-hex']));

        self::assertSame(LicenseStatus::TAMPERED, $status->status);
        self::assertCount(0, $client->calls);
    }

    public function testForwardsDeploymentIdentityToDolistoreBackend(): void
    {
        $this->seedStaleActivationCache();
        $client = new FakeDolistoreClient();
        $client->setResponder(fn (array $params) => $this->signedResponse(
            (string) ($params['instanceFingerprint'] ?? ''),
            true,
            '2027-04-29T00:00:00+00:00',
        ));

        $dt = str_repeat('a', 64);
        $nonce = '00000000-0000-4000-8000-000000000001';
        $validator = $this->makeValidator($client, null, null, $dt, $nonce);
        $validator->inspect($this->manifest());

        $params = $client->calls[0]['params'];
        self::assertSame($dt, $params['deploymentToken']);
        self::assertSame($nonce, $params['deploymentNonce']);
    }

    private function makeValidator(
        FakeDolistoreClient $client,
        ?OfflineGracePolicy $policy = null,
        ?ForkDetector $forkDetector = null,
        ?string $deploymentToken = null,
        ?string $deploymentNonce = null,
    ): DolistoreValidator {
        return new DolistoreValidator(
            $client,
            new SignatureVerifier([$this->harness->publicKeyHex()]),
            $this->binder,
            $forkDetector ?? new ForkDetector([]),
            new LicenseCache($this->tmpDir),
            $policy ?? new OfflineGracePolicy(14),
            24,
            'erp.acme.com',
            $deploymentToken,
            $deploymentNonce,
            null,
            new ManifestSignatureVerifier(new SignatureVerifier([$this->harness->publicKeyHex()])),
        );
    }

    /**
     * @return array{id: string, license: array<string, mixed>}
     */
    private function manifest(array $licenseExtras = []): array
    {
        return [
            'id' => 'knot-pro-pack',
            'license' => array_merge([
                'type' => 'commercial',
                'validation' => 'dolistore',
                'productId' => '12345',
            ], $licenseExtras),
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
