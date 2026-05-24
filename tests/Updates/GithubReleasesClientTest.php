<?php

declare(strict_types=1);

namespace Knot\Tests\Updates;

use Knot\Tests\Support\LocalJsonHttpHarness;
use Knot\Updates\GithubReleasesClient;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \Knot\Updates\GithubReleasesClient
 */
final class GithubReleasesClientTest extends TestCase
{
    private ?LocalJsonHttpHarness $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
        $this->server = null;
        parent::tearDown();
    }

    public function testFetchManifestRejectsNonHttpsUrl(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('HTTPS');
        (new GithubReleasesClient())->fetchManifest('http://evil.example/releases.json');
    }

    public function testInferLatestArtifactNormalisesFields(): void
    {
        $artifact = GithubReleasesClient::inferLatestArtifact([
            'latest' => [
                'version' => '2.13.0',
                'zip_url' => ' https://example.com/knot.zip ',
                'zip_sha256' => strtoupper(str_repeat('a', 64)),
                'signature_hex' => 'deadbeef',
                'signature_payload' => ['version' => '2.13.0'],
            ],
        ]);

        self::assertSame('2.13.0', $artifact['version']);
        self::assertSame('https://example.com/knot.zip', $artifact['zip_url']);
        self::assertSame(str_repeat('a', 64), $artifact['zip_sha256']);
        self::assertSame(['version' => '2.13.0'], $artifact['signature_payload']);
    }

    public function testFetchManifestFromLocalHarness(): void
    {
        $body = json_encode([
            'schema_version' => 'knot-core-releases@v1',
            'latest' => ['version' => '2.13.0', 'channel' => 'stable'],
        ], JSON_THROW_ON_ERROR);
        $server = new LocalJsonHttpHarness($body);
        $server->start();
        $this->server = $server;

        $decoded = (new GithubReleasesClient())->fetchManifest($server->baseUrl() . '/');

        self::assertSame('2.13.0', $decoded['latest']['version']);
    }

    public function testVerifyReleaseIntegrityDelegatesToReleaseVerifier(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'knot-gh-rel-');
        self::assertIsString($path);
        try {
            file_put_contents($path, 'payload');
            $sha = hash('sha256', 'payload');
            GithubReleasesClient::verifyReleaseIntegrity($path, $sha, null, '');
            self::addToAssertionCount(1);
        } finally {
            @unlink($path);
        }
    }
}
