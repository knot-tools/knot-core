<?php

declare(strict_types=1);

namespace Knot\Tests\Security;

use Knot\Security\HttpClient;
use Knot\Security\UrlPolicy;
use Knot\Tests\Support\LocalJsonHttpHarness;
use Knot\Tests\Support\LoopbackUrlPolicy;
use Knot\Tests\Support\RedirectJsonHttpHarness;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

/**
 * @covers \Knot\Security\HttpClient
 */
final class HttpClientTest extends TestCase
{
    /** @var list<LocalJsonHttpHarness|RedirectJsonHttpHarness> */
    private array $servers = [];

    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            $server->stop();
        }
        $this->servers = [];
        parent::tearDown();
    }

    public function testRequestRejectsBlockedUrl(): void
    {
        $client = new HttpClient(new UrlPolicy());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('URL is not allowed');
        $client->request('GET', 'http://127.0.0.1/');
    }

    public function testRequestReturnsJsonBody(): void
    {
        $server = new LocalJsonHttpHarness('{"hello":"world"}');
        $server->start();
        $this->servers[] = $server;

        $client = new HttpClient(new LoopbackUrlPolicy());
        $response = $client->request('GET', $server->baseUrl() . '/');

        self::assertSame(200, $response['status']);
        self::assertSame('world', $response['json']['hello'] ?? null);
        self::assertStringContainsString('application/json', $response['headers']['content-type'] ?? '');
    }

    public function testRequestFollowsRedirectAfterPolicyRevalidation(): void
    {
        $server = new RedirectJsonHttpHarness('{"after":"redirect"}');
        $server->start();
        $this->servers[] = $server;

        $client = new HttpClient(new LoopbackUrlPolicy());
        $response = $client->request('GET', $server->baseUrl() . '/');

        self::assertSame(200, $response['status']);
        self::assertSame('redirect', $response['json']['after'] ?? null);
    }

    public function testRequestTruncatesOversizedBody(): void
    {
        $large = str_repeat('x', 120);
        $server = new LocalJsonHttpHarness($large);
        $server->start();
        $this->servers[] = $server;

        $client = new HttpClient(new LoopbackUrlPolicy(), 15, 50);
        $response = $client->request('GET', $server->baseUrl() . '/');

        self::assertSame(50, strlen($response['body']));
    }

    public function testRequestRejectsRedirectToBlockedTarget(): void
    {
        $router = tempnam(sys_get_temp_dir(), 'knot-bad-redirect-');
        self::assertNotFalse($router);
        file_put_contents($router, <<<'PHP'
<?php
http_response_code(302);
header('Location: http://169.254.169.254/latest/meta-data/');
exit;
PHP);

        $port = LocalJsonHttpHarness::pickFreePortForTests();
        $cmd = [PHP_BINARY, '-S', '127.0.0.1:' . $port, $router];
        $process = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);

        $deadline = microtime(true) + 3.0;
        while (microtime(true) < $deadline) {
            $ctx = stream_context_create(['http' => ['timeout' => 1, 'ignore_errors' => true]]);
            if (@file_get_contents('http://127.0.0.1:' . $port . '/', false, $ctx) !== false) {
                break;
            }
            usleep(50_000);
        }

        try {
            $client = new HttpClient(new LoopbackUrlPolicy());
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Redirect target is not allowed');
            $client->request('GET', 'http://127.0.0.1:' . $port . '/');
        } finally {
            if (is_array($pipes)) {
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        @fclose($pipe);
                    }
                }
            }
            @proc_terminate($process, 9);
            @proc_close($process);
            @unlink($router);
        }
    }

    public function testResolveRedirectTargetHandlesAbsoluteAndRelativeLocations(): void
    {
        $client = new HttpClient(new LoopbackUrlPolicy());
        $method = new ReflectionMethod(HttpClient::class, 'resolveRedirectTarget');

        self::assertSame(
            'https://other.example/final',
            $method->invoke($client, 'https://crm.example.com/api/start', 'https://other.example/final')
        );
        self::assertSame(
            'https://crm.example.com/next',
            $method->invoke($client, 'https://crm.example.com/api/start', '/next')
        );
        self::assertSame(
            'https://crm.example.com/api/v1/v2/item',
            $method->invoke($client, 'https://crm.example.com/api/v1/item', 'v2/item')
        );
    }
}
