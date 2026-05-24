<?php

/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

namespace Knot\Tests\Updates;

use Knot\Tests\Support\LocalZipHttpsHarness;
use Knot\Updates\ZipDownloader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \Knot\Updates\ZipDownloader
 */
final class ZipDownloaderTest extends TestCase
{
    /** @var list<LocalZipHttpsHarness> */
    private array $servers = [];

    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            $server->stop();
        }
        $this->servers = [];
        putenv('KNOT_TEST_ZIP_CA');

        parent::tearDown();
    }

    /**
     * @return array<string, array{0: string, 1: bool, 2?: list<string>}>
     */
    public static function allowedHostProvider(): array
    {
        return [
            'github apex' => ['github.com', true],
            'github subdomain' => ['releases.github.com', true],
            'objects host' => ['objects.githubusercontent.com', true],
            'license portal' => ['license.knot.tools', true],
            'knot.tools downloads' => ['knot.tools', true],
            'www knot.tools' => ['www.knot.tools', true],
            'localhost' => ['localhost', true],
            'loopback ip' => ['127.0.0.1', true],
            'extra host override' => ['mirror.example.test', true, ['mirror.example.test']],
            'empty host' => ['', false],
            'blocked apex' => ['evil.example', false],
            'blocked metadata ip style' => ['169.254.169.254', false],
        ];
    }

    #[DataProvider('allowedHostProvider')]
    public function testAllowedHost(string $host, bool $expected, array $extraHosts = []): void
    {
        self::assertSame($expected, ZipDownloader::allowedHost($host, $extraHosts));
    }

    public function testFetchToRejectsFileScheme(): void
    {
        $dest = $this->tempDestination();
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Invalid download URL.');
            ZipDownloader::fetchTo('file:///tmp/demo.zip', $dest);
        } finally {
            @unlink($dest);
        }
    }

    public function testFetchToRejectsPlainHttpEvenOnLoopback(): void
    {
        $dest = $this->tempDestination();
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Only HTTPS artefact URLs are permitted.');
            ZipDownloader::fetchTo('http://127.0.0.1/demo.zip', $dest);
        } finally {
            @unlink($dest);
        }
    }

    public function testFetchToRejectsBlockedHost(): void
    {
        $dest = $this->tempDestination();
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('ZIP host `evil.example` is blocked');
            ZipDownloader::fetchTo('https://evil.example/artifact.zip', $dest);
        } finally {
            @unlink($dest);
        }
    }

    public function testFetchToDownloadsZipOverHttpsFromLoopbackHarness(): void
    {
        if (!function_exists('curl_init')) {
            self::markTestSkipped('PHP cURL extension required');
        }
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ZipArchive extension required');
        }

        $server = new LocalZipHttpsHarness();
        try {
            $server->start();
        } catch (RuntimeException $e) {
            self::markTestSkipped('Local HTTPS ZIP harness unavailable: ' . $e->getMessage());
        }
        $this->servers[] = $server;

        putenv('KNOT_TEST_ZIP_CA=' . $server->caBundlePath());

        $dest = $this->tempDestination();
        try {
            ZipDownloader::fetchTo($server->httpsUrl(), $dest);
            self::assertFileExists($dest);
            self::assertGreaterThan(512, (int) filesize($dest));
            self::assertSame($server->expectedZipBytes(), (string) file_get_contents($dest));
        } finally {
            @unlink($dest);
        }
    }

    private function tempDestination(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'knot-zip-dl-');
        self::assertIsString($path);
        @unlink($path);

        return $path . '.zip';
    }
}
