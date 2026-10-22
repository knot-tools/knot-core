<?php

declare(strict_types=1);

namespace Knot\Tests\Updates;

use Knot\Tests\Support\LocalJsonHttpHarness;
use Knot\Updates\GithubReleasesClient;
use PHPUnit\Framework\Attributes\DataProvider;
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
                'notes' => "  ## [2.13.0]\n\n- Fix\n  ",
            ],
        ]);

        self::assertSame('2.13.0', $artifact['version']);
        self::assertSame('https://example.com/knot.zip', $artifact['zip_url']);
        self::assertSame(str_repeat('a', 64), $artifact['zip_sha256']);
        self::assertSame(['version' => '2.13.0'], $artifact['signature_payload']);
        self::assertSame("## [2.13.0]\n\n- Fix", $artifact['notes']);
    }

    public function testInferLatestArtifactDefaultsNotesToEmptyString(): void
    {
        $artifact = GithubReleasesClient::inferLatestArtifact([
            'latest' => ['version' => '2.13.0'],
        ]);

        self::assertSame('', $artifact['notes']);
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

    public function testVerifyReleaseIntegrityRequiresSignature(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'knot-gh-rel-');
        self::assertIsString($path);
        try {
            file_put_contents($path, 'payload');
            $sha = hash('sha256', 'payload');
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('signature_hex is missing');
            GithubReleasesClient::verifyReleaseIntegrity($path, $sha, null, '', '2.13.0', 'beta', '2.12.0');
        } finally {
            @unlink($path);
        }
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function manifestUrlProvider(): array
    {
        return [
            'default github' => [GithubReleasesClient::PUBLIC_MANIFEST_PRIMARY, true],
            'license backend' => ['https://license.knot.tools/api/core/releases.json', true],
            'knot.tools' => ['https://knot.tools/releases.json', true],
            'other github org' => ['https://raw.githubusercontent.com/someone/knot-core/main/releases.json', false],
            'dot segments' => ['https://raw.githubusercontent.com/knot-tools/../someone/x/main/releases.json', false],
            'lookalike host' => ['https://knot.tools.example/releases.json', false],
            'plain http' => ['http://knot.tools/releases.json', false],
            'custom port' => ['https://knot.tools:8443/releases.json', false],
            'userinfo' => ['https://knot.tools@evil.example/releases.json', false],
            'other host' => ['https://evil.example/releases.json', false],
        ];
    }

    #[DataProvider('manifestUrlProvider')]
    public function testIsAllowedManifestUrl(string $url, bool $expected): void
    {
        self::assertSame($expected, GithubReleasesClient::isAllowedManifestUrl($url));
    }

    public function testLoopbackManifestRejectedOutsideTests(): void
    {
        $previous = getenv('KNOT_TEST');
        putenv('KNOT_TEST');
        try {
            self::assertFalse(GithubReleasesClient::isAllowedManifestUrl('http://127.0.0.1:8099/releases.json'));
            self::assertFalse(GithubReleasesClient::isAllowedManifestUrl('http://localhost/releases.json'));
        } finally {
            putenv('KNOT_TEST=' . (string) $previous);
        }
    }
}
