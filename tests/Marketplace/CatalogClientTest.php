<?php

declare(strict_types=1);

namespace Knot\Tests\Marketplace;

use Knot\Marketplace\CatalogClient;
use Knot\Tests\Support\LocalJsonHttpHarness;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit tests for {@see CatalogClient::normalise()} so the network
 * path can be left to integration smoke tests against the real
 * license.knot.tools backend.
 */
final class CatalogClientTest extends TestCase
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

    public function testNormaliseProducesFrontendShape(): void
    {
        $client = new CatalogClient();
        $out = $client->normalise([
            [
                'slug' => 'knot-pro-pack',
                'label' => 'Knot Pro Pack',
                'description' => 'Premium pack',
                'category' => 'pro',
                'pricing' => [
                    'monthly_eur_cents' => 1900,
                    'yearly_eur_cents' => 19000,
                    'trial_days' => 14,
                    'refund_window_days' => 14,
                ],
            ],
        ]);

        self::assertCount(1, $out);
        self::assertSame('knot-pro-pack', $out[0]['slug']);
        self::assertSame('Knot Pro Pack', $out[0]['label']);
        self::assertSame(1900, $out[0]['priceMonthlyCents']);
        self::assertSame(19000, $out[0]['priceYearlyCents']);
        self::assertSame(14, $out[0]['trialDays']);
        self::assertSame('eur', $out[0]['currency']);
        self::assertSame('https://knot.tools/pricing#knot-pro-pack', $out[0]['buyUrl']);
    }

    public function testNormaliseSkipsRowsWithoutSlug(): void
    {
        $client = new CatalogClient();
        $out = $client->normalise([
            ['label' => 'Anonymous'],
            ['slug' => 'valid', 'label' => 'Valid'],
        ]);

        self::assertCount(1, $out);
        self::assertSame('valid', $out[0]['slug']);
    }

    public function testNormaliseToleratesMissingPricing(): void
    {
        $client = new CatalogClient();
        $out = $client->normalise([
            [
                'slug' => 'free-pack',
                'label' => 'Free pack',
                'description' => null,
                'category' => 'community',
            ],
        ]);

        self::assertNull($out[0]['priceMonthlyCents']);
        self::assertNull($out[0]['priceYearlyCents']);
        self::assertSame(0, $out[0]['trialDays']);
        self::assertSame(0, $out[0]['refundWindowDays']);
        self::assertNull($out[0]['description']);
    }

    public function testNormaliseDefaultsCategoryToPro(): void
    {
        $client = new CatalogClient();
        $out = $client->normalise([['slug' => 'foo', 'label' => 'Foo']]);
        self::assertSame('pro', $out[0]['category']);
    }

    public function testNormaliseDefaultsKindAndTier(): void
    {
        $client = new CatalogClient();
        $out = $client->normalise([['slug' => 'foo', 'label' => 'Foo']]);
        self::assertSame('extension', $out[0]['kind']);
        self::assertSame('pro', $out[0]['tier']);
        self::assertNull($out[0]['icon']);
        self::assertNull($out[0]['templateCategory']);
        self::assertNull($out[0]['definition']);
    }

    public function testNormalisePropagatesTierAndKind(): void
    {
        $client = new CatalogClient();
        $out = $client->normalise([
            [
                'slug' => 'tpl-1',
                'label' => 'A template',
                'kind' => 'template',
                'tier' => 'free',
                'icon' => 'mail',
                'template_category' => 'communication',
                'definition' => ['nodes' => [], 'edges' => []],
            ],
        ]);
        self::assertSame('template', $out[0]['kind']);
        self::assertSame('free', $out[0]['tier']);
        self::assertSame('mail', $out[0]['icon']);
        self::assertSame('communication', $out[0]['templateCategory']);
        self::assertSame(['nodes' => [], 'edges' => []], $out[0]['definition']);
    }

    public function testNormaliseRejectsInvalidEnumValues(): void
    {
        $client = new CatalogClient();
        $out = $client->normalise([
            [
                'slug' => 'foo',
                'label' => 'Foo',
                'kind' => 'rogue',
                'tier' => 'lifetime',
            ],
        ]);
        self::assertSame('extension', $out[0]['kind']);
        self::assertSame('pro', $out[0]['tier']);
    }

    public function testFetchReturnsNormalisedProductsOnSuccess(): void
    {
        $body = json_encode([
            'products' => [
                [
                    'slug' => 'knot-pro-pack',
                    'label' => 'Knot Pro Pack',
                    'pricing' => ['monthly_eur_cents' => 1900],
                ],
            ],
            'editorial' => ['version' => 1],
        ], JSON_THROW_ON_ERROR);

        $server = $this->startServer($body);
        $client = new CatalogClient($server->baseUrl());

        $bag = $client->fetchCatalog();
        self::assertCount(1, $bag['products']);
        self::assertSame('knot-pro-pack', $bag['products'][0]['slug']);
        self::assertSame(['version' => 1], $bag['editorial']);
        self::assertSame($bag['products'], $client->fetch());
        self::assertNull($client->lastError());
    }

    public function testFetchAppendsKindQueryParameter(): void
    {
        $body = json_encode(['products' => []], JSON_THROW_ON_ERROR);
        $server = $this->startServer($body);
        $client = new CatalogClient($server->baseUrl());

        $client->fetch('template');

        self::assertNull($client->lastError());
    }

    public function testFetchMapsHttp502(): void
    {
        $server = $this->startServer('bad gateway', 502);
        $client = new CatalogClient($server->baseUrl());

        self::assertSame([], $client->fetch());
        self::assertSame('http_502', $client->lastError());
    }

    public function testFetchRejectsMalformedPayload(): void
    {
        $server = $this->startServer('{"items":[]}');
        $client = new CatalogClient($server->baseUrl());

        self::assertSame([], $client->fetch());
        self::assertSame('invalid_payload', $client->lastError());
    }

    public function testFetchAcceptsDeploymentHeaders(): void
    {
        $body = json_encode(['products' => []], JSON_THROW_ON_ERROR);
        $server = $this->startServer($body);
        $client = new CatalogClient(
            $server->baseUrl(),
            CatalogClient::DEFAULT_TIMEOUT_S,
            'deploy-token',
            'deploy-nonce'
        );

        self::assertSame([], $client->fetch());
        self::assertNull($client->lastError());
    }

    public function testCatalogFetchUrlForDiagnosticsUsesPreviewPathWhenPreviewTokenSet(): void
    {
        $client = new CatalogClient(
            'https://license.example.test',
            CatalogClient::DEFAULT_TIMEOUT_S,
            null,
            null,
            'signed.jwt.token',
        );

        $baseUrl = $client->catalogFetchUrlForDiagnostics();
        self::assertStringStartsWith(
            'https://license.example.test/api/catalog-preview.json?',
            $baseUrl,
        );
        self::assertStringContainsString('token=', $baseUrl);

        $withKind = $client->catalogFetchUrlForDiagnostics('template', null);
        self::assertStringContainsString('kind=template', $withKind);

        $withLang = $client->catalogFetchUrlForDiagnostics(null, 'fr');
        self::assertStringContainsString('lang=fr', $withLang);

        $plain = new CatalogClient('https://license.example.test');
        self::assertSame(
            'https://license.example.test/api/catalog.json',
            $plain->catalogFetchUrlForDiagnostics(),
        );
    }

    private function startServer(string $body, int $status = 200): LocalJsonHttpHarness
    {
        $server = new LocalJsonHttpHarness($body, $status);
        $server->start();
        $this->servers[] = $server;

        return $server;
    }
}
