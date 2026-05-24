<?php

declare(strict_types=1);

namespace Knot\Tests\Support;

use RuntimeException;

/**
 * Local HTTP server that returns a redirect before the final JSON body.
 */
final class RedirectJsonHttpHarness
{
    /** @var resource|null */
    private $process = null;

    /** @var array<int, resource>|null */
    private ?array $pipes = null;

    private int $port;

    private ?string $routerPath = null;

    public function __construct(
        private readonly string $finalBody,
        private readonly int $finalStatus = 200,
        private readonly string $redirectLocation = '/final',
        private readonly int $redirectStatus = 302,
    ) {
        $this->port = LocalJsonHttpHarness::pickFreePortForTests();
    }

    public function start(): void
    {
        $router = tempnam(sys_get_temp_dir(), 'knot-redirect-http-');
        if ($router === false) {
            throw new RuntimeException('Cannot create temp router file');
        }

        $location = addslashes($this->redirectLocation);
        $body = var_export($this->finalBody, true);
        $script = <<<PHP
<?php
\$path = parse_url(\$_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (\$path === '/') {
    http_response_code({$this->redirectStatus});
    header('Location: {$location}');
    exit;
}
http_response_code({$this->finalStatus});
header('Content-Type: application/json');
echo {$body};
PHP;

        file_put_contents($router, $script);
        $this->routerPath = $router;

        $cmd = [PHP_BINARY, '-S', '127.0.0.1:' . $this->port, $router];
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            @unlink($router);
            throw new RuntimeException('Failed to start redirect HTTP server');
        }
        $this->process = $process;
        $this->pipes = $pipes;

        $deadline = microtime(true) + 3.0;
        while (microtime(true) < $deadline) {
            $ctx = stream_context_create(['http' => ['timeout' => 1, 'ignore_errors' => true]]);
            $resp = @file_get_contents($this->baseUrl() . '/final', false, $ctx);
            if (is_string($resp)) {
                return;
            }
            usleep(50_000);
        }
        $this->stop();
        throw new RuntimeException('Redirect HTTP server did not become ready');
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
}
