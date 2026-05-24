<?php

declare(strict_types=1);

namespace Knot\Tests\Licensing;

use Knot\Licensing\DolistoreClient;
use Knot\Tests\E2E\Support\LicenseServerHarness;
use Knot\Tests\Support\LocalJsonHttpHarness;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \Knot\Licensing\DolistoreClient
 */
final class DolistoreClientTest extends TestCase
{
    /** @var list<LicenseServerHarness|LocalJsonHttpHarness> */
    private array $servers = [];

    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            $server->stop();
        }
        $this->servers = [];
        parent::tearDown();
    }

    public function testCheckReturnsSignedPayloadFromHarness(): void
    {
        if (!extension_loaded('curl')) {
            self::markTestSkipped('cURL extension required for DolistoreClient transport');
        }

        $harness = new LicenseServerHarness();
        $harness->start(['SCENARIO' => 'valid']);
        $this->servers[] = $harness;
        try {
            $client = new DolistoreClient($harness->baseUrl(), 5, true);
            $result = $client->check([
                'activationCode' => 'KNOT-TEST-AAAA-BBBB-CCCC-DDDD',
                'instanceFingerprint' => str_repeat('a', 64),
                'deploymentToken' => 'deploy-token',
                'deploymentNonce' => 'deploy-nonce',
            ]);

            self::assertTrue($result['valid']);
            self::assertNotSame('', $result['signature']);
            self::assertNotSame('', $result['signedAt']);
            self::assertIsArray($result['payload']);
        } finally {
            // stopped in tearDown
        }
    }

    public function testCheckMapsExpiredScenarioFromHarness(): void
    {
        if (!extension_loaded('curl')) {
            self::markTestSkipped('cURL extension required for DolistoreClient transport');
        }

        $harness = new LicenseServerHarness();
        $harness->start(['SCENARIO' => 'expired']);
        $this->servers[] = $harness;

        $client = new DolistoreClient($harness->baseUrl(), 5, true);
        $result = $client->check([
            'activationCode' => 'KNOT-TEST-AAAA-BBBB-CCCC-DDDD',
            'instanceFingerprint' => str_repeat('b', 64),
        ]);

        self::assertFalse($result['valid']);
        self::assertNotNull($result['expiresAt']);
    }

    public function testCheckThrowsOnHttpErrorFromBackend(): void
    {
        if (!extension_loaded('curl')) {
            self::markTestSkipped('cURL extension required for DolistoreClient transport');
        }

        $server = $this->startJsonServer('{"error":"down"}', 503);
        $client = new DolistoreClient($server->baseUrl(), 5, true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('HTTP 503');
        $client->check([
            'activationCode' => 'KNOT-TEST-AAAA-BBBB-CCCC-DDDD',
            'instanceFingerprint' => str_repeat('c', 64),
        ]);
    }

    public function testCheckThrowsOnNonJsonResponse(): void
    {
        if (!extension_loaded('curl')) {
            self::markTestSkipped('cURL extension required for DolistoreClient transport');
        }

        $server = $this->startJsonServer('not-json');
        $client = new DolistoreClient($server->baseUrl(), 5, true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('non-JSON');
        $client->check([
            'activationCode' => 'KNOT-TEST-AAAA-BBBB-CCCC-DDDD',
            'instanceFingerprint' => str_repeat('c', 64),
        ]);
    }

    public function testCheckThrowsWhenSignatureFieldsMissing(): void
    {
        if (!extension_loaded('curl')) {
            self::markTestSkipped('cURL extension required for DolistoreClient transport');
        }

        $server = $this->startJsonServer('{"payload":{"valid":true}}');
        $client = new DolistoreClient($server->baseUrl(), 5, true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('missing verdict');
        $client->check([
            'activationCode' => 'KNOT-TEST-AAAA-BBBB-CCCC-DDDD',
            'instanceFingerprint' => str_repeat('c', 64),
        ]);
    }

    public function testCheckNormalisesOptionalPayloadFields(): void
    {
        if (!extension_loaded('curl')) {
            self::markTestSkipped('cURL extension required for DolistoreClient transport');
        }

        $body = json_encode([
            'signature' => 'abc',
            'signedAt' => '2026-05-19T10:00:00+00:00',
            'payload' => [
                'valid' => true,
                'expiresAt' => '2027-01-01T00:00:00+00:00',
                'plan' => 'enterprise',
                'issuedTo' => 'Example Co',
            ],
        ], JSON_THROW_ON_ERROR);

        $server = $this->startJsonServer($body);
        $client = new DolistoreClient($server->baseUrl(), 5, true);

        $result = $client->check([
            'activationCode' => 'KNOT-TEST-AAAA-BBBB-CCCC-DDDD',
            'instanceFingerprint' => str_repeat('b', 64),
        ]);

        self::assertTrue($result['valid']);
        self::assertSame('2027-01-01T00:00:00+00:00', $result['expiresAt']);
        self::assertSame('enterprise', $result['plan']);
        self::assertSame('Example Co', $result['issuedTo']);
    }

    public function testCheckThrowsWhenBackendUnreachable(): void
    {
        if (!extension_loaded('curl')) {
            self::markTestSkipped('cURL extension required for DolistoreClient transport');
        }

        $client = new DolistoreClient('http://127.0.0.1:1', 1, false);

        $this->expectException(RuntimeException::class);
        $client->check([
            'activationCode' => 'KNOT-TEST-AAAA-BBBB-CCCC-DDDD',
            'instanceFingerprint' => str_repeat('c', 64),
        ]);
    }

    public function testFallbackBaseUrlConstant(): void
    {
        self::assertSame('https://license.knot.tools', DolistoreClient::FALLBACK_BASE_URL);
    }

    public function testLicenseDownloadTokenRequestPostsAndReturnsEnvelope(): void
    {
        if (!extension_loaded('curl')) {
            self::markTestSkipped('cURL extension required for DolistoreClient transport');
        }

        $body = json_encode([
            'token' => 'jwt-test',
            'expires_in_seconds' => 600,
            'download_url' => 'https://license.knot.tools/api/download/jwt-test',
            'release' => ['version' => '1.0.0', 'zip_sha256' => ''],
        ], JSON_THROW_ON_ERROR);

        $server = $this->startJsonServer($body);
        $client = new DolistoreClient($server->baseUrl(), 5, true);
        $resp = $client->licenseDownloadTokenRequest([
            'activationCode' => 'KNOT-TEST-AAAA-BBBB-CCCC-DDDD',
            'instanceFingerprint' => str_repeat('d', 64),
            'productSlug' => 'knot-pro-pack',
        ]);

        self::assertSame(200, $resp['status']);
        self::assertSame('jwt-test', json_decode($resp['body'], true)['token'] ?? null);
    }

    private function startJsonServer(string $body, int $status = 200): LocalJsonHttpHarness
    {
        $server = new LocalJsonHttpHarness($body, $status);
        $server->start();
        $this->servers[] = $server;

        return $server;
    }
}
