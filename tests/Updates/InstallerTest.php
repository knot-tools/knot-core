<?php

/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

namespace Knot\Tests\Updates;

use Knot\Updates\Installer;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use ZipArchive;

/**
 * @covers \Knot\Updates\Installer
 */
final class InstallerTest extends TestCase
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
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $fi) {
            /** @phpstan-ignore-next-line */
            $p = $fi->getPathname();
            $fi->isDir() ? @rmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    /** @throws RuntimeException */
    private function makeDemoZip(string $destinationZip, string $topFolder = 'knot'): string
    {
        $staging = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zip-build-' . bin2hex(random_bytes(4));
        $module = $staging . DIRECTORY_SEPARATOR . $topFolder;
        @mkdir($module, 0777, true);
        file_put_contents($module . DIRECTORY_SEPARATOR . 'manifest.json', "{\"name\":\"demo\"}\n");

        $zip = new ZipArchive();
        if ($zip->open($destinationZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $this->rrmdir($staging);
            throw new RuntimeException('zip open failure');
        }
        /** @phpstan-ignore-next-line */
        $zip->addFile($module . DIRECTORY_SEPARATOR . 'manifest.json', $topFolder . '/manifest.json');
        $zip->close();
        $this->rrmdir($staging);

        return $destinationZip;
    }

    public function testPrepareAndSwapReplacesLiveTree(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive extension required');
        }

        $this->tmpRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'installer-test-' . bin2hex(random_bytes(6));

        @mkdir($this->tmpRoot, 0777, true);
        @mkdir($this->tmpRoot . DIRECTORY_SEPARATOR . 'live-old', 0777, true);
        file_put_contents($this->tmpRoot . DIRECTORY_SEPARATOR . 'live-old/manifest.json', "{\"v\":1}\n");

        $artifact = $this->tmpRoot . DIRECTORY_SEPARATOR . 'pkg.zip';
        $this->makeDemoZip($artifact, 'knot');

        $extractParent = $this->tmpRoot . DIRECTORY_SEPARATOR . 'stage-parent';
        $installer = new Installer();
        $prepared = $installer->prepare($artifact, $extractParent, 'knot');

        self::assertSame('knot', basename($prepared));
        self::assertFileExists($prepared . DIRECTORY_SEPARATOR . 'manifest.json');

        $installer->swap($prepared, $this->tmpRoot . DIRECTORY_SEPARATOR . 'live-old');

        $live = $this->tmpRoot . DIRECTORY_SEPARATOR . 'live-old/manifest.json';
        self::assertFileExists($live);
        self::assertStringContainsString('"name":"demo"', (string) file_get_contents($live));
    }

    public function testSwapFallsBackWhenRenameAcrossDirectoriesFails(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive extension required');
        }

        $this->tmpRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'installer-xdev-' . bin2hex(random_bytes(6));
        $stageRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'installer-stage-' . bin2hex(random_bytes(6));
        @mkdir($this->tmpRoot, 0777, true);
        @mkdir($stageRoot, 0777, true);

        $artifact = $this->tmpRoot . DIRECTORY_SEPARATOR . 'pkg.zip';
        $this->makeDemoZip($artifact, 'knot');

        $installer = new Installer();
        $prepared = $installer->prepare($artifact, $stageRoot, 'knot');
        $liveRoot = $this->tmpRoot . DIRECTORY_SEPARATOR . 'live-old';
        @mkdir($liveRoot, 0777, true);
        file_put_contents($liveRoot . DIRECTORY_SEPARATOR . 'old.txt', 'prev');

        $installer->swap($prepared, $liveRoot);

        self::assertFileExists($liveRoot . DIRECTORY_SEPARATOR . 'manifest.json');
        self::assertFileDoesNotExist($liveRoot . DIRECTORY_SEPARATOR . 'old.txt');
    }

    public function testPrepareAdoptsArchiveFolderWhenLiveDirNameDiffers(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive extension required');
        }

        $this->tmpRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'installer-alias-' . bin2hex(random_bytes(6));
        @mkdir($this->tmpRoot, 0777, true);

        // Signed Migration artefacts ship `knotmigration/` while operators may
        // have deployed under `custom/knot-migration/` — prepare() must adopt
        // the archive folder under the expected (live) name.
        $artifact = $this->tmpRoot . DIRECTORY_SEPARATOR . 'pkg.zip';
        $this->makeDemoZip($artifact, 'knotmigration');

        $prepared = (new Installer())->prepare(
            $artifact,
            $this->tmpRoot . DIRECTORY_SEPARATOR . 'stage-parent',
            'knot-migration',
        );

        self::assertSame('knot-migration', basename($prepared));
        self::assertFileExists($prepared . DIRECTORY_SEPARATOR . 'manifest.json');
    }

    public function testPrepareMismatchErrorNamesTheArchiveFolder(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive extension required');
        }

        $this->tmpRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'installer-multi-' . bin2hex(random_bytes(6));
        @mkdir($this->tmpRoot, 0777, true);

        // Two manifest-bearing folders → ambiguous, no silent adoption.
        $staging = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zip-multi-' . bin2hex(random_bytes(3));
        foreach (['alpha', 'beta'] as $folder) {
            @mkdir($staging . DIRECTORY_SEPARATOR . $folder, 0777, true);
            file_put_contents($staging . DIRECTORY_SEPARATOR . $folder . '/manifest.json', "{}\n");
        }
        $artifact = $this->tmpRoot . DIRECTORY_SEPARATOR . 'multi.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($artifact, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFile($staging . '/alpha/manifest.json', 'alpha/manifest.json');
        $zip->addFile($staging . '/beta/manifest.json', 'beta/manifest.json');
        $zip->close();
        $this->rrmdir($staging);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('manifest.json missing at top-level folder "expected"');
        (new Installer())->prepare($artifact, $this->tmpRoot . DIRECTORY_SEPARATOR . 'ext', 'expected');
    }

    public function testPrepareFailsWhenManifestMissing(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive extension required');
        }

        $this->tmpRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'installer-bad-' . bin2hex(random_bytes(6));
        @mkdir($this->tmpRoot, 0777, true);

        $artifact = $this->tmpRoot . DIRECTORY_SEPARATOR . 'bad.zip';
        $staging = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zip-bad-' . bin2hex(random_bytes(3));
        @mkdir($staging . DIRECTORY_SEPARATOR . 'knot', 0777, true);
        file_put_contents($staging . DIRECTORY_SEPARATOR . 'knot/readme.txt', 'x');
        $zip = new ZipArchive();
        self::assertTrue($zip->open($artifact, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFile($staging . DIRECTORY_SEPARATOR . 'knot/readme.txt', 'knot/readme.txt');
        $zip->close();
        $this->rrmdir($staging);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('manifest.json missing');
        (new Installer())->prepare($artifact, $this->tmpRoot . DIRECTORY_SEPARATOR . 'ext', 'knot');
    }

    public function testManualFallbackInstructionsMentionsBackupPattern(): void
    {
        $lines = Installer::manualFallbackInstructions('/var/www/custom/knot');
        self::assertCount(2, $lines);
        self::assertStringContainsString('knot.backup.', implode("\n", $lines));
    }

    public function testPrepareThrowsWhenZipPathUnreadable(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unreadable');
        (new Installer())->prepare('/path/does/not/exist.zip', sys_get_temp_dir(), 'knot');
    }
}
