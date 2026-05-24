<?php

declare(strict_types=1);

namespace Knot\Tests\Updates;

use Knot\Tests\Support\LocalJsonHttpHarness;
use Knot\Updates\GithubReleasesClient;
use Knot\Updates\UpdateClient;
use Knot\Updates\UpdateLatestResolver;
use PHPUnit\Framework\TestCase;

final class UpdateLatestResolverTest extends TestCase
{
    /** @var list<LocalJsonHttpHarness> */
    private array $servers = [];

    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            $server->stop();
        }
        $this->servers = [];
    }

    public function testExtensionSlugUsesLicenseClientOnly(): void
    {
        $license = new FakeUpdateClient([
            'knot-pro-pack' => $this->manifest('knot-pro-pack', '1.2.0'),
        ]);
        $resolver = new UpdateLatestResolver($license);

        $resolved = $resolver->fetchLatest('knot-pro-pack');

        self::assertSame('live', $resolved['source']);
        self::assertNotNull($resolved['payload']);
        self::assertSame('1.2.0', $resolved['payload']['version']);
        self::assertNull($resolved['error']);
        self::assertSame(1, $license->fetchCount);
    }

    public function testCoreUsesGithubManifestWhenAvailable(): void
    {
        $body = json_encode([
            'latest' => [
                'version' => '2.13.0',
                'channel' => 'beta',
                'published_at' => '2026-05-20T12:00:00+00:00',
                'zip_url' => 'https://example.com/knot.zip',
                'zip_sha256' => str_repeat('b', 64),
            ],
        ], JSON_THROW_ON_ERROR);
        $server = $this->startServer($body);
        $license = new FakeUpdateClient([]);
        $resolver = new UpdateLatestResolver(
            $license,
            new GithubReleasesClient(),
            $server->baseUrl() . '/',
        );

        $resolved = $resolver->fetchLatest('knot');

        self::assertSame('live', $resolved['source']);
        self::assertNotNull($resolved['payload']);
        self::assertSame('2.13.0', $resolved['payload']['version']);
        self::assertSame('beta', $resolved['payload']['channel']);
        self::assertSame(0, $license->fetchCount, 'license must not be queried when GitHub succeeds');
    }

    public function testCoreFallsBackToLicenseReleasesJsonWhenGithubFails(): void
    {
        $releasesBody = json_encode([
            'latest' => [
                'version' => '2.13.1',
                'channel' => 'stable',
                'published_at' => '2026-05-21T00:00:00+00:00',
                'zip_url' => 'https://example.com/knot.zip',
                'zip_sha256' => str_repeat('d', 64),
            ],
        ], JSON_THROW_ON_ERROR);
        $githubServer = $this->startServer('{"error":"offline"}', 503);
        $licenseServer = $this->startServer($releasesBody);
        $license = new UpdateClient($licenseServer->baseUrl());
        $resolver = new UpdateLatestResolver(
            $license,
            new GithubReleasesClient(),
            $githubServer->baseUrl() . '/',
        );

        $resolved = $resolver->fetchLatest('knot');

        self::assertSame('live_license_releases', $resolved['source']);
        self::assertNotNull($resolved['payload']);
        self::assertSame('2.13.1', $resolved['payload']['version']);
        self::assertNull($resolved['error']);
    }

    public function testCoreFallsBackToLicenseWhenGithubFails(): void
    {
        $server = $this->startServer('{"error":"offline"}', 503);
        $license = new FakeUpdateClient([
            'knot' => $this->manifest('knot', '2.12.2'),
        ]);
        $resolver = new UpdateLatestResolver(
            $license,
            new GithubReleasesClient(),
            $server->baseUrl() . '/',
        );

        $resolved = $resolver->fetchLatest('knot');

        self::assertSame('live_license', $resolved['source']);
        self::assertNotNull($resolved['payload']);
        self::assertSame('2.12.2', $resolved['payload']['version']);
        self::assertNull($resolved['error']);
        self::assertSame(1, $license->fetchCount);
    }

    public function testCoreUnavailableWhenGithubAndLicenseFail(): void
    {
        $server = $this->startServer('{"error":"offline"}', 503);
        $license = new FakeUpdateClient([], lastErrorOverride: 'unknown_product');
        $resolver = new UpdateLatestResolver(
            $license,
            new GithubReleasesClient(),
            $server->baseUrl() . '/',
        );

        $resolved = $resolver->fetchLatest('knot');

        self::assertNull($resolved['payload']);
        self::assertSame('unavailable', $resolved['source']);
        self::assertStringContainsString('github:', (string) $resolved['error']);
        self::assertStringContainsString('license_releases:', (string) $resolved['error']);
        self::assertStringContainsString('license:unknown_product', (string) $resolved['error']);
    }

    public function testNotifyPayloadFromGithubManifest(): void
    {
        $payload = UpdateLatestResolver::notifyPayloadFromGithubManifest([
            'latest' => [
                'version' => '2.12.1',
                'channel' => 'stable',
                'published_at' => '2026-01-01T00:00:00+00:00',
                'zip_sha256' => str_repeat('c', 64),
            ],
        ]);

        self::assertSame('knot', $payload['slug']);
        self::assertSame('2.12.1', $payload['version']);
        self::assertSame('stable', $payload['channel']);
        self::assertSame(str_repeat('c', 64), $payload['zipSha256']);
    }

    private function startServer(string $body, int $status = 200): LocalJsonHttpHarness
    {
        $server = new LocalJsonHttpHarness($body, $status);
        $server->start();
        $this->servers[] = $server;

        return $server;
    }

    /**
     * @return array{
     *     slug: string,
     *     version: string,
     *     channel: string,
     *     publishedAt: string,
     *     zipSize: int,
     *     zipSha256: string,
     *     signatureKid: string
     * }
     */
    private function manifest(string $slug, string $version): array
    {
        return [
            'slug' => $slug,
            'version' => $version,
            'channel' => 'beta',
            'publishedAt' => '2026-05-16T12:00:00+00:00',
            'zipSize' => 1024,
            'zipSha256' => str_repeat('a', 64),
            'signatureKid' => 'rel-2026-04',
        ];
    }
}

/**
 * @internal test double
 */
final class FakeUpdateClient extends UpdateClient
{
    /** @var array<string, array<string, mixed>> */
    private array $manifests;

    public int $fetchCount = 0;

    public function __construct(array $manifests, private readonly ?string $lastErrorOverride = null)
    {
        parent::__construct('https://license.example.test');
        $this->manifests = $manifests;
    }

    public function fetchLatest(string $slug): ?array
    {
        $this->fetchCount++;
        $this->lastError = $this->lastErrorOverride;
        return $this->manifests[$slug] ?? null;
    }
}
