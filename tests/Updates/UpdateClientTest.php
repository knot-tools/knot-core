<?php

declare(strict_types=1);

namespace Knot\Tests\Updates;

use Knot\Tests\Support\LocalJsonHttpHarness;
use Knot\Updates\UpdateClient;
use PHPUnit\Framework\TestCase;

final class UpdateClientTest extends TestCase
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

    public function testFetchLatestRejectsInvalidSlug(): void
    {
        $client = new UpdateClient();

        self::assertNull($client->fetchLatest(''));
        self::assertSame('invalid_slug', $client->lastError());

        self::assertNull($client->fetchLatest('Bad Slug'));
        self::assertSame('invalid_slug', $client->lastError());

        self::assertNull($client->fetchLatest('-starts-with-dash'));
        self::assertSame('invalid_slug', $client->lastError());
    }

    public function testLastErrorStartsNull(): void
    {
        $client = new UpdateClient();
        self::assertNull($client->lastError());
    }

    public function testFetchLatestReturnsNormalisedManifestOnSuccess(): void
    {
        $body = json_encode([
            'product_slug' => 'knot-core',
            'version' => '2.12.0',
            'channel' => 'beta',
            'published_at' => '2026-05-19T10:00:00+00:00',
            'zip_size_bytes' => 1234567,
            'zip_sha256' => str_repeat('a', 64),
            'signature_kid' => 'rel-2026-05',
        ], JSON_THROW_ON_ERROR);

        $server = $this->startServer($body);
        $client = new UpdateClient($server->baseUrl());

        $manifest = $client->fetchLatest('knot-core');

        self::assertNotNull($manifest);
        self::assertSame('knot-core', $manifest['slug']);
        self::assertSame('2.12.0', $manifest['version']);
        self::assertSame('beta', $manifest['channel']);
        self::assertSame('2026-05-19T10:00:00+00:00', $manifest['publishedAt']);
        self::assertSame(1234567, $manifest['zipSize']);
        self::assertSame(str_repeat('a', 64), $manifest['zipSha256']);
        self::assertSame('rel-2026-05', $manifest['signatureKid']);
        self::assertNull($client->lastError());
    }

    public function testFetchLatestMaps404ToUnknownProduct(): void
    {
        $server = $this->startServer('{"error":"not found"}', 404);
        $client = new UpdateClient($server->baseUrl());

        self::assertNull($client->fetchLatest('missing-product'));
        self::assertSame('unknown_product', $client->lastError());
    }

    public function testFetchLatestMapsHttp500(): void
    {
        $server = $this->startServer('internal error', 500);
        $client = new UpdateClient($server->baseUrl());

        self::assertNull($client->fetchLatest('knot-core'));
        self::assertSame('http_500', $client->lastError());
    }

    public function testFetchLatestRejectsMalformedPayload(): void
    {
        $server = $this->startServer('{"foo":"bar"}');
        $client = new UpdateClient($server->baseUrl());

        self::assertNull($client->fetchLatest('knot-core'));
        self::assertSame('invalid_payload', $client->lastError());
    }

    public function testFetchLatestDefaultsOptionalFields(): void
    {
        $body = json_encode([
            'product_slug' => 'knot-pro-pack',
            'version' => '1.0.0',
        ], JSON_THROW_ON_ERROR);

        $server = $this->startServer($body);
        $client = new UpdateClient($server->baseUrl());

        $manifest = $client->fetchLatest('knot-pro-pack');

        self::assertNotNull($manifest);
        self::assertSame('stable', $manifest['channel']);
        self::assertSame('', $manifest['publishedAt']);
        self::assertSame(0, $manifest['zipSize']);
        self::assertSame('', $manifest['zipSha256']);
        self::assertSame('', $manifest['signatureKid']);
    }

    private function startServer(string $body, int $status = 200): LocalJsonHttpHarness
    {
        $server = new LocalJsonHttpHarness($body, $status);
        $server->start();
        $this->servers[] = $server;

        return $server;
    }
}
