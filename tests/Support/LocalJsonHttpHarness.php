<?php

declare(strict_types=1);

namespace Knot\Tests\Support;

use RuntimeException;

/**
 * Minimal PHP built-in server for UpdateClient / CatalogClient HTTP tests.
 */
final class LocalJsonHttpHarness
{
    /** @var resource|null */
    private $process = null;

    /** @var array<int, resource>|null */
    private ?array $pipes = null;

    private int $port;

    private ?string $routerPath = null;

    /** @param array<string, string> $extraHeaders */
    public function __construct(
        private readonly string $body,
        private readonly int $statusCode = 200,
        private readonly array $extraHeaders = [],
    ) {
        $this->port = self::pickFreePort();
    }

    public function start(): void
    {
        $router = tempnam(sys_get_temp_dir(), 'knot-json-http-');
        if ($router === false) {
            throw new RuntimeException('Cannot create temp router file');
        }

        $headerLines = array_merge(
            ['Content-Type: application/json'],
            array_map(
                static fn (string $k, string $v): string => $k . ': ' . $v,
                array_keys($this->extraHeaders),
                array_values($this->extraHeaders)
            )
        );
        $headerPhp = implode("\n", array_map(
            static fn (string $line): string => "header(" . var_export($line, true) . ");",
            $headerLines
        ));

        $script = <<<PHP
<?php
http_response_code({$this->statusCode});
{$headerPhp}
echo {$this->exportBody($this->body)};
PHP;

        file_put_contents($router, $script);
        $this->routerPath = $router;

        $cmd = [
            PHP_BINARY,
            '-S',
            '127.0.0.1:' . $this->port,
            $router,
        ];
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            @unlink($router);
            throw new RuntimeException('Failed to start local JSON HTTP server');
        }
        $this->process = $process;
        $this->pipes = $pipes;

        $deadline = microtime(true) + 3.0;
        while (microtime(true) < $deadline) {
            $ctx = stream_context_create([
                'http' => [
                    'timeout' => 1,
                    'ignore_errors' => true,
                ],
            ]);
            $resp = @file_get_contents($this->baseUrl() . '/', false, $ctx);
            if (is_string($resp)) {
                return;
            }
            usleep(50_000);
        }
        $this->stop();
        @unlink($router);
        throw new RuntimeException('Local JSON HTTP server did not become ready within 2s');
    }

    public function stop(): void
    {
        if ($this->process === null) {
            return;
        }
        if (is_array($this->pipes)) {
            foreach ($this->pipes as $pipe) {
                if (is_resource($pipe)) {
                    @fclose($pipe);
                }
            }
        }
        @proc_terminate($this->process, 9);
        @proc_close($this->process);
        $this->process = null;
        $this->pipes = null;
        if ($this->routerPath !== null) {
            @unlink($this->routerPath);
            $this->routerPath = null;
        }
    }

    public function baseUrl(): string
    {
        return 'http://127.0.0.1:' . $this->port;
    }

    public static function pickFreePortForTests(): int
    {
        return self::pickFreePort();
    }

    private static function pickFreePort(): int
    {
        $sock = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($sock === false) {
            throw new RuntimeException('Cannot allocate free TCP port: ' . $errstr);
        }
        $name = stream_socket_get_name($sock, false) ?: '';
        @fclose($sock);
        $parts = explode(':', $name);

        return (int) end($parts);
    }

    private function exportBody(string $body): string
    {
        return var_export($body, true);
    }
}
