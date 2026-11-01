<?php

declare(strict_types=1);

namespace Knot\Tests\Api;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * api/health.php (version banner source) answers through JsonResponse::success().
 * Proves over real HTTP that the JSON envelope is sent with `Cache-Control: no-store`,
 * even when Dolibarr already emitted its own `Cache-Control: Public, must-revalidate`.
 */
final class JsonResponseHeadersTest extends TestCase
{
    /** @var resource|null */
    private $process = null;

    private ?string $docRoot = null;

    protected function tearDown(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process, 9);
            proc_close($this->process);
        }
        if ($this->docRoot !== null) {
            @unlink($this->docRoot . '/index.php');
            @rmdir($this->docRoot);
        }
    }

    public function testSuccessOverridesDolibarrCacheControlWithNoStore(): void
    {
        $headers = $this->fetchHeaders('success');

        self::assertSame(['no-store, max-age=0'], $headers['cache-control'] ?? null);
    }

    public function testErrorOverridesDolibarrCacheControlWithNoStore(): void
    {
        $headers = $this->fetchHeaders('error');

        self::assertSame(['no-store, max-age=0'], $headers['cache-control'] ?? null);
    }

    /**
     * @return array<string, list<string>>
     */
    private function fetchHeaders(string $mode): array
    {
        $autoload = var_export(dirname(__DIR__, 2) . '/class/autoload.php', true);
        $this->docRoot = sys_get_temp_dir() . '/knot-json-headers-' . bin2hex(random_bytes(4));
        mkdir($this->docRoot);
        file_put_contents($this->docRoot . '/index.php', <<<PHP
<?php
require {$autoload};
// What Dolibarr's main.inc.php / session start sends before the API script runs.
header('Cache-Control: Public, must-revalidate');
if ((\$_GET['mode'] ?? '') === 'error') {
    \Knot\Api\JsonResponse::error('permission_denied', 'Permission denied', 403);
} else {
    \Knot\Api\JsonResponse::success(['version' => \Knot\Version::current()]);
}
PHP);

        $sock = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($sock);
        $name = (string) stream_socket_get_name($sock, false);
        fclose($sock);
        $url = 'http://' . $name . '/index.php?mode=' . $mode;

        $process = proc_open(
            [PHP_BINARY, '-S', $name, '-t', $this->docRoot],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes
        );
        self::assertIsResource($process);
        $this->process = $process;

        $ctx = stream_context_create(['http' => ['timeout' => 1, 'ignore_errors' => true]]);
        $deadline = microtime(true) + 3.0;
        while (microtime(true) < $deadline) {
            $http_response_header = null;
            if (@file_get_contents($url, false, $ctx) !== false && is_array($http_response_header)) {
                $headers = [];
                foreach ($http_response_header as $line) {
                    $parts = explode(':', $line, 2);
                    if (count($parts) === 2) {
                        $headers[strtolower(trim($parts[0]))][] = trim($parts[1]);
                    }
                }

                return $headers;
            }
            usleep(50_000);
        }

        throw new RuntimeException('PHP built-in server did not answer within 3s');
    }
}
