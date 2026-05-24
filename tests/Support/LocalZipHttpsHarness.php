<?php

/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

namespace Knot\Tests\Support;

use RuntimeException;
use ZipArchive;

/**
 * Local HTTPS server (openssl s_server -WWW) serving a minimal ZIP for ZipDownloader tests.
 */
final class LocalZipHttpsHarness
{
    /** @var resource|null */
    private $process = null;

    /** @var array<int, resource>|null */
    private ?array $pipes = null;

    private int $port;

    private string $workDir = '';

    private string $artifactName = 'artifact.zip';

    public function start(): void
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('ZipArchive extension required for LocalZipHttpsHarness');
        }

        $openssl = self::resolveOpenSslBinary();
        $this->workDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'knot-zip-https-' . bin2hex(random_bytes(5));
        if (!@mkdir($this->workDir, 0700, true) && !is_dir($this->workDir)) {
            throw new RuntimeException('Cannot create harness work directory');
        }

        $this->writeMinimalZip($this->workDir . DIRECTORY_SEPARATOR . $this->artifactName);
        $this->generateTlsMaterial($openssl);

        $this->port = LocalJsonHttpHarness::pickFreePortForTests();
        $cmd = [
            $openssl,
            's_server',
            '-accept',
            (string) $this->port,
            '-cert',
            'cert.pem',
            '-key',
            'key.pem',
            '-WWW',
            '-quiet',
        ];
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($cmd, $descriptors, $pipes, $this->workDir);
        if (!is_resource($process)) {
            $this->purgeWorkDir();
            throw new RuntimeException('Failed to start openssl s_server');
        }
        $this->process = $process;
        $this->pipes = $pipes;

        $deadline = microtime(true) + 8.0;
        $ca = $this->caBundlePath();
        while (microtime(true) < $deadline) {
            if ($this->probeDownload($ca)) {
                return;
            }
            usleep(100_000);
        }

        $stderr = '';
        if (is_array($this->pipes) && isset($this->pipes[2]) && is_resource($this->pipes[2])) {
            $stderr = trim((string) stream_get_contents($this->pipes[2]));
        }
        $this->stop();
        throw new RuntimeException(
            'Local HTTPS ZIP server did not become ready within 8s'
            . ($stderr !== '' ? ': ' . $stderr : ''),
        );
    }

    public function stop(): void
    {
        if ($this->process !== null) {
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
        }
        $this->purgeWorkDir();
    }

    public function httpsUrl(): string
    {
        return 'https://127.0.0.1:' . $this->port . '/' . $this->artifactName;
    }

    public function caBundlePath(): string
    {
        return $this->workDir . DIRECTORY_SEPARATOR . 'ca.pem';
    }

    public function expectedZipBytes(): string
    {
        $bytes = @file_get_contents($this->workDir . DIRECTORY_SEPARATOR . $this->artifactName);

        return is_string($bytes) ? $bytes : '';
    }

    private function probeDownload(string $caBundle): bool
    {
        if (!function_exists('curl_init')) {
            return false;
        }

        $ch = curl_init($this->httpsUrl());
        if ($ch === false) {
            return false;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CAINFO => $caBundle,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_TIMEOUT => 3,
            CURLOPT_CONNECTTIMEOUT => 2,
        ]);
        /** @phpstan-ignore-next-line */
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        return is_string($body)
            && $status >= 200
            && $status < 300
            && strlen($body) > 512
            && str_starts_with($body, "PK\x03\x04");
    }

    private function writeMinimalZip(string $destination): void
    {
        $staging = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zip-harness-build-' . bin2hex(random_bytes(4));
        $module = $staging . DIRECTORY_SEPARATOR . 'knot';
        @mkdir($module, 0777, true);
        file_put_contents($module . DIRECTORY_SEPARATOR . 'payload.bin', random_bytes(2000));
        file_put_contents($module . DIRECTORY_SEPARATOR . 'manifest.json', "{\"name\":\"harness\"}\n");

        $zip = new ZipArchive();
        if ($zip->open($destination, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $this->purgeDir($staging);
            throw new RuntimeException('Cannot create harness ZIP');
        }
        /** @phpstan-ignore-next-line */
        $zip->addFile($module . DIRECTORY_SEPARATOR . 'payload.bin', 'knot/payload.bin');
        /** @phpstan-ignore-next-line */
        $zip->addFile($module . DIRECTORY_SEPARATOR . 'manifest.json', 'knot/manifest.json');
        $zip->close();
        $this->purgeDir($staging);

        if ((int) @filesize($destination) <= 512) {
            throw new RuntimeException('Harness ZIP must exceed ZipDownloader minimum size');
        }
    }

    private function generateTlsMaterial(string $openssl): void
    {
        $caKey = $this->workDir . DIRECTORY_SEPARATOR . 'ca.key';
        $caCert = $this->workDir . DIRECTORY_SEPARATOR . 'ca.pem';
        $serverKey = $this->workDir . DIRECTORY_SEPARATOR . 'key.pem';
        $serverCsr = $this->workDir . DIRECTORY_SEPARATOR . 'server.csr';
        $serverCert = $this->workDir . DIRECTORY_SEPARATOR . 'cert.pem';

        $steps = [
            [$openssl, 'genrsa', '-out', $caKey, '2048'],
            [$openssl, 'req', '-x509', '-new', '-key', $caKey, '-out', $caCert, '-days', '1', '-subj', '/CN=KnotTestHarnessCA'],
            [$openssl, 'genrsa', '-out', $serverKey, '2048'],
            [$openssl, 'req', '-new', '-key', $serverKey, '-out', $serverCsr, '-subj', '/CN=127.0.0.1'],
            [$openssl, 'x509', '-req', '-in', $serverCsr, '-CA', $caCert, '-CAkey', $caKey, '-CAcreateserial', '-out', $serverCert, '-days', '1'],
        ];

        foreach ($steps as $cmd) {
            if ($this->runOpenSslCommand($cmd) !== 0) {
                throw new RuntimeException('openssl failed while generating harness TLS material');
            }
        }
    }

    /**
     * @param list<string> $cmd
     */
    private function runOpenSslCommand(array $cmd): int
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($proc)) {
            return 1;
        }
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                @fclose($pipe);
            }
        }

        return (int) proc_close($proc);
    }

    private static function resolveOpenSslBinary(): string
    {
        $candidates = ['openssl', '/usr/bin/openssl', '/opt/homebrew/bin/openssl'];
        foreach ($candidates as $bin) {
            $out = [];
            $code = 1;
            @exec(escapeshellarg($bin) . ' version 2>/dev/null', $out, $code);
            if ($code === 0) {
                return $bin;
            }
        }

        throw new RuntimeException('openssl binary not found (required for LocalZipHttpsHarness)');
    }

    private function purgeWorkDir(): void
    {
        if ($this->workDir !== '') {
            $this->purgeDir($this->workDir);
            $this->workDir = '';
        }
    }

    private function purgeDir(string $dir): void
    {
        if ($dir === '' || !is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $dir . DIRECTORY_SEPARATOR . $entry;
            is_dir($full) ? $this->purgeDir($full) : @unlink($full);
        }
        @rmdir($dir);
    }
}
