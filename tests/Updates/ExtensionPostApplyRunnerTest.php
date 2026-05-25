<?php

/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

namespace Knot\Tests\Updates;

use Knot\Updates\ExtensionPostApplyRunner;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \Knot\Updates\ExtensionPostApplyRunner
 */
final class ExtensionPostApplyRunnerTest extends TestCase
{
    private string $tmpRoot = '';

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpRoot);
        parent::tearDown();
    }

    private function rrmdir(string $dir): void
    {
        if ($dir === '' || !is_dir($dir)) {
            return;
        }
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $child) {
            is_dir($child) ? $this->rrmdir($child) : @unlink($child);
        }
        @rmdir($dir);
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function writeExtension(array $manifest, string $runnerBody, string $nsSuffix): string
    {
        $this->tmpRoot = sys_get_temp_dir() . '/knot-postapply-' . bin2hex(random_bytes(4));
        @mkdir($this->tmpRoot . '/class/Demo' . $nsSuffix, 0777, true);
        file_put_contents($this->tmpRoot . '/knot-extension.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        file_put_contents(
            $this->tmpRoot . '/autoload.php',
            "<?php\nrequire_once __DIR__ . '/class/Demo{$nsSuffix}/Migrator.php';\n",
        );
        file_put_contents($this->tmpRoot . '/class/Demo' . $nsSuffix . '/Migrator.php', $runnerBody);

        return $this->tmpRoot;
    }

    /** @return array{0: array<string, mixed>, 1: string} */
    private function baseManifestPair(): array
    {
        $suffix = bin2hex(random_bytes(3));
        $namespace = 'DemoPostApply' . $suffix . '\\';

        return [
            [
                'id' => 'knot-demo',
                'label' => 'Demo',
                'version' => '1.0.0',
                'author' => 'Knot',
                'namespace' => $namespace,
                'connectors' => [],
                'postApply' => [
                    'contractVersion' => 1,
                    'autoload' => 'autoload.php',
                    'migrationRunner' => $namespace . 'Migrator',
                ],
            ],
            $suffix,
        ];
    }

    private function okRunner(string $namespace): string
    {
        $ns = rtrim($namespace, '\\');

        return <<<PHP
<?php
declare(strict_types=1);
namespace {$ns};
final class Migrator
{
    public function __construct(private readonly object \$db, private readonly string \$root)
    {
    }

    /** @return array<int, array{version: string, file: string, status: string, durationMs: int}> */
    public function run(): array
    {
        return [
            ['version' => 'v1.0.0', 'file' => '01.sql', 'status' => 'applied', 'durationMs' => 1],
        ];
    }
}
PHP;
    }

    public function testRunReturnsEmptyWhenPostApplyAbsent(): void
    {
        $this->tmpRoot = sys_get_temp_dir() . '/knot-postapply-empty-' . bin2hex(random_bytes(4));
        @mkdir($this->tmpRoot, 0777, true);
        file_put_contents($this->tmpRoot . '/knot-extension.json', '{"id":"knot-demo","label":"x","version":"1.0.0","author":"a","connectors":[]}');

        $runner = new ExtensionPostApplyRunner();
        self::assertSame([], $runner->run(new \stdClass(), $this->tmpRoot));
    }

    public function testRunExecutesDeclaredRunner(): void
    {
        [$manifest, $suffix] = $this->baseManifestPair();
        $root = $this->writeExtension($manifest, $this->okRunner($manifest['namespace']), $suffix);
        $log = (new ExtensionPostApplyRunner())->run(new \stdClass(), $root);

        self::assertCount(1, $log);
        self::assertSame('applied', $log[0]['status']);
    }

    public function testInvalidFqcnRejected(): void
    {
        [$manifest, $suffix] = $this->baseManifestPair();
        $manifest['postApply']['migrationRunner'] = 'Other\\Migrator';
        $root = $this->writeExtension($manifest, $this->okRunner($manifest['namespace']), $suffix);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('manifest namespace');
        (new ExtensionPostApplyRunner())->run(new \stdClass(), $root);
    }

    public function testUnsupportedContractVersionRejected(): void
    {
        [$manifest, $suffix] = $this->baseManifestPair();
        $manifest['postApply']['contractVersion'] = 99;
        $root = $this->writeExtension($manifest, $this->okRunner($manifest['namespace']), $suffix);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported postApply contractVersion');
        (new ExtensionPostApplyRunner())->run(new \stdClass(), $root);
    }

    public function testMissingAutoloadRejected(): void
    {
        [$manifest, $suffix] = $this->baseManifestPair();
        $root = $this->writeExtension($manifest, $this->okRunner($manifest['namespace']), $suffix);
        @unlink($root . '/autoload.php');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('autoload file is missing');
        (new ExtensionPostApplyRunner())->run(new \stdClass(), $root);
    }

    public function testCorruptAutoloadThrows(): void
    {
        [$manifest, $suffix] = $this->baseManifestPair();
        $root = $this->writeExtension($manifest, $this->okRunner($manifest['namespace']), $suffix);
        file_put_contents($root . '/autoload.php', "<?php syntax error here");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('postApply autoload failed:');
        (new ExtensionPostApplyRunner())->run(new \stdClass(), $root);
    }

    public function testRunnerThrowPropagates(): void
    {
        [$manifest, $suffix] = $this->baseManifestPair();
        $ns = rtrim($manifest['namespace'], '\\');
        $root = $this->writeExtension($manifest, <<<PHP
<?php
declare(strict_types=1);
namespace {$ns};
final class Migrator
{
    public function __construct(private readonly object \$db, private readonly string \$root)
    {
    }

    public function run(): array
    {
        throw new \RuntimeException('boom');
    }
}
PHP, $suffix);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('postApply migration runner failed: boom');
        (new ExtensionPostApplyRunner())->run(new \stdClass(), $root);
    }
}
