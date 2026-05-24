<?php

declare(strict_types=1);

namespace Knot\Tests\Updates;

use Knot\Updates\UpdateClient;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class UpdateClientChannelFilterTest extends TestCase
{
    /** @var list<resource> */
    private array $processes = [];

    /** @var list<string> */
    private array $routerPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->processes as $process) {
            if (is_resource($process)) {
                @proc_terminate($process, 9);
                @proc_close($process);
            }
        }
        foreach ($this->routerPaths as $path) {
            @unlink($path);
        }
        $this->processes = [];
        $this->routerPaths = [];
        parent::tearDown();
    }

    public function testFetchLatestPassesReleaseChannelToBackend(): void
    {
        $baseUrl = $this->startRouter();
        $client = new UpdateClient($baseUrl, 5, 'beta');
        $manifest = $client->fetchLatest('knot-pro-pack');

        self::assertNotNull($manifest);
        self::assertSame('knot-pro-pack', $manifest['slug']);
        self::assertStringContainsString('channel=beta', (string) file_get_contents($this->lastRequestPath()));
    }

    public function testUsesBetaDefaultWhenConstantAbsent(): void
    {
        $client = new UpdateClient();
        self::assertSame('beta', (new \ReflectionClass($client))->getProperty('releaseChannel')->getValue($client));
    }

    public function testStableChannelIsForwarded(): void
    {
        $baseUrl = $this->startRouter();
        $client = new UpdateClient($baseUrl, 5, 'stable');
        $manifest = $client->fetchLatest('knot-migration');

        self::assertNotNull($manifest);
        self::assertStringContainsString('channel=stable', (string) file_get_contents($this->lastRequestPath()));
    }

    private ?string $requestLogPath = null;

    private function lastRequestPath(): string
    {
        return $this->requestLogPath ?? '';
    }

    private function startRouter(): string
    {
        $sock = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($sock === false) {
            throw new RuntimeException('Cannot allocate port: ' . $errstr);
        }
        $name = stream_socket_get_name($sock, false) ?: '';
        @fclose($sock);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);

        $this->requestLogPath = tempnam(sys_get_temp_dir(), 'knot-req-log-') ?: '';
        $router = tempnam(sys_get_temp_dir(), 'knot-router-') ?: '';
        $this->routerPaths[] = $router;
        $log = var_export($this->requestLogPath, true);
        $script = <<<PHP
<?php
file_put_contents({$log}, \$_SERVER['REQUEST_URI'] ?? '');
header('Content-Type: application/json');
echo json_encode([
    'product_slug' => 'knot-pro-pack',
    'version' => '0.1.4',
    'channel' => 'beta',
], JSON_UNESCAPED_SLASHES);
PHP;
        file_put_contents($router, $script);

        $cmd = [PHP_BINARY, '-S', '127.0.0.1:' . $port, $router];
        $pipes = [];
        $process = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start router');
        }
        $this->processes[] = $process;

        $deadline = microtime(true) + 3.0;
        while (microtime(true) < $deadline) {
            $ctx = stream_context_create(['http' => ['timeout' => 1]]);
            if (@file_get_contents('http://127.0.0.1:' . $port . '/', false, $ctx) !== false) {
                break;
            }
            usleep(50_000);
        }

        return 'http://127.0.0.1:' . $port;
    }
}
