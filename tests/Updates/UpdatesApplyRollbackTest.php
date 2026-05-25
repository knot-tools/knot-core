<?php

/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

namespace Knot\Tests\Updates;

use Knot\Updates\Installer;
use Knot\Updates\UpdatesApplyPostSwap;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * @covers \Knot\Updates\UpdatesApplyPostSwap
 * @covers \Knot\Updates\Installer
 */
final class UpdatesApplyRollbackTest extends TestCase
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

    public function testInstallerCanRollbackAfterSwapUntilCommit(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive required');
        }

        $this->tmpRoot = sys_get_temp_dir() . '/rollback-' . bin2hex(random_bytes(4));
        @mkdir($this->tmpRoot, 0777, true);
        @mkdir($this->tmpRoot . '/live', 0777, true);
        file_put_contents($this->tmpRoot . '/live/manifest.json', '{"v":1}');

        $zip = $this->tmpRoot . '/pkg.zip';
        $staging = sys_get_temp_dir() . '/zip-' . bin2hex(random_bytes(3));
        @mkdir($staging . '/knot', 0777, true);
        file_put_contents($staging . '/knot/manifest.json', '{"name":"new"}');
        $z = new ZipArchive();
        self::assertTrue($z->open($zip, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $z->addFile($staging . '/knot/manifest.json', 'knot/manifest.json');
        $z->close();
        $this->rrmdir($staging);

        $installer = new Installer();
        $prepared = $installer->prepare($zip, $this->tmpRoot . '/stage-parent', 'knot');
        $installer->swap($prepared, $this->tmpRoot . '/live');

        self::assertTrue($installer->canRollback());
        self::assertTrue($installer->rollback());
        self::assertStringContainsString('"v":1', (string) file_get_contents($this->tmpRoot . '/live/manifest.json'));
    }

    public function testMigrationFailureHttpStatusDifferentiatesRollbackOutcome(): void
    {
        self::assertSame(422, UpdatesApplyPostSwap::migrationFailureHttpStatus('restored'));
        self::assertSame(500, UpdatesApplyPostSwap::migrationFailureHttpStatus('failed'));
        self::assertSame(500, UpdatesApplyPostSwap::migrationFailureHttpStatus('none'));
    }

    public function testApplyEndpointUsesSharedPostSwapWorkflow(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../../api/updates_apply.php');
        self::assertStringContainsString('UpdatesApplyPostSwap::migrateCore', $src);
        self::assertStringContainsString('UpdatesApplyPostSwap::migrateExtension', $src);
        self::assertStringContainsString('UpdatesApplyPostSwap::clearExtensionRegistryCache', $src);
        self::assertStringContainsString('set_time_limit(600)', $src);
        self::assertStringContainsString('updates.apply.started', $src);
        self::assertSame(3, substr_count($src, '$swapAndMigrate('), 'Core + 2 extension branches must share swapAndMigrate');
    }
}
