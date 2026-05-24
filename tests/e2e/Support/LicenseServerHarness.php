<?php

declare(strict_types=1);

namespace Knot\Tests\E2E\Support;

use RuntimeException;

/**
 * Spawns the fake licence server (`tests/E2E/fixtures/license-server.php`)
 * via `php -S` so tests can exercise the full chain DolistoreClient ->
 * cURL -> built-in webserver -> SignatureVerifier with a real Ed25519
 * signature path.
 *
 * Usage:
 * ```
 * $harness = new LicenseServerHarness();
 * $harness->start(['SCENARIO' => 'valid']);
 * $url = $harness->baseUrl();              // http://127.0.0.1:<port>
 * $publicHex = $harness->publicKeyHex();   // pin in SignatureVerifier
 * ...
 * $harness->stop();
 * ```
 */
final class LicenseServerHarness
{
    private ?int $pid = null;
    private int $port = 0;
    /** @var resource|null */
    private $process = null;
    /** @var array<int, resource>|null */
    private ?array $pipes = null;

    private string $secretHex = '';
    private string $publicHex = '';

    public function start(array $env = []): void
    {
        if (!extension_loaded('sodium')) {
            throw new RuntimeException('sodium extension is required for the licence harness');
        }
        if (!function_exists('proc_open')) {
            throw new RuntimeException('proc_open is required for the licence harness');
        }

        $kp = sodium_crypto_sign_keypair();
        $this->secretHex = sodium_bin2hex(sodium_crypto_sign_secretkey($kp));
        $this->publicHex = sodium_bin2hex(sodium_crypto_sign_publickey($kp));

        $this->port = self::pickFreePort();
        $fixture = realpath(__DIR__ . '/../fixtures/license-server.php');
        if ($fixture === false) {
            throw new RuntimeException('Cannot resolve license-server.php fixture path');
        }

        $cmd = sprintf(
            '%s -S 127.0.0.1:%d %s',
            escapeshellarg(PHP_BINARY),
            $this->port,
            escapeshellarg($fixture),
        );

        $envFinal = array_merge(getenv() ?: [], [
            'KNOT_TEST_BACKEND_SECRET_HEX' => $this->secretHex,
            'KNOT_TEST_BACKEND_SCENARIO' => $env['SCENARIO'] ?? 'valid',
            'KNOT_TEST_BACKEND_EXPIRES_AT' => $env['EXPIRES_AT'] ?? '',
        ]);

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($cmd, $descriptorSpec, $pipes, null, $envFinal);
        if (!is_resource($process)) {
            throw new RuntimeException('Failed to spawn fake licence server');
        }
        $this->process = $process;
        $this->pipes = $pipes;
        $status = proc_get_status($process);
        $this->pid = isset($status['pid']) ? (int) $status['pid'] : null;

        // Wait until the built-in server is ready (poll /health for 2s max).
        $deadline = microtime(true) + 2.0;
        while (microtime(true) < $deadline) {
            $resp = @file_get_contents($this->baseUrl() . '/health');
            if (is_string($resp) && str_contains($resp, '"ok"')) {
                return;
            }
            usleep(50_000);
        }
        $this->stop();
        throw new RuntimeException('Fake licence server did not become ready within 2s');
    }

    public function stop(): void
    {
        if ($this->process === null) {
            return;
        }
        if (is_array($this->pipes)) {
            foreach ($this->pipes as $p) {
                if (is_resource($p)) {
                    @fclose($p);
                }
            }
        }
        @proc_terminate($this->process, 9);
        @proc_close($this->process);
        $this->process = null;
        $this->pipes = null;
        $this->pid = null;
    }

    public function baseUrl(): string
    {
        return 'http://127.0.0.1:' . $this->port;
    }

    public function publicKeyHex(): string
    {
        return $this->publicHex;
    }

    private static function pickFreePort(): int
    {
        // Bind, get assigned port, close. Tiny race window (acceptable for tests).
        $sock = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($sock === false) {
            throw new RuntimeException('Cannot allocate free TCP port: ' . $errstr);
        }
        $name = stream_socket_get_name($sock, false) ?: '';
        @fclose($sock);
        $parts = explode(':', $name);
        return (int) end($parts);
    }
}
