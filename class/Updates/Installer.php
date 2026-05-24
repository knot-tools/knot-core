<?php

/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

namespace Knot\Updates;

use RuntimeException;
use ZipArchive;

/**
 * Stage + atomic swap module trees bundled with Dolistore-style prefixes.
 */
final class Installer
{
    private ?string $livePath = null;

    private ?string $backupPath = null;

    /**
     * Extract the top-level module folder `$topFolder/` under `$stagingParentDirectory`.
     *
     * `$topFolder` defaults to {@see 'knot'} for Core bundles; extensions typically use their slug (`knot-pro-pack`).
     *
     * @throws RuntimeException
     */
    public function prepare(string $zipPath, string $stagingParentDirectory, string $topFolder = 'knot'): string
    {
        if ($zipPath === '' || !is_readable($zipPath)) {
            throw new RuntimeException('ZIP path is unreadable.');
        }

        $staging = rtrim($stagingParentDirectory, DIRECTORY_SEPARATOR . '/');
        self::purgeDirContents($staging);
        if (!is_dir($staging) && !@mkdir($staging, 0755, true) && !is_dir($staging)) {
            throw new RuntimeException('Cannot prepare staging directory: ' . $staging);
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Cannot open Knot module ZIP.');
        }
        $ok = $zip->extractTo($staging);
        $zip->close();
        if (!$ok) {
            throw new RuntimeException('ZIP extraction failed.');
        }

        $root = $staging . DIRECTORY_SEPARATOR . trim($topFolder, '/');
        if (!is_file($root . DIRECTORY_SEPARATOR . 'manifest.json')) {
            throw new RuntimeException('Invalid archive — manifest.json missing at top-level folder.');
        }

        return $root;
    }

    public function swap(string $preparedRoot, string $liveModuleRoot): void
    {
        $this->rollback();

        $live = rtrim($liveModuleRoot, DIRECTORY_SEPARATOR . '/');
        $incoming = rtrim($preparedRoot, DIRECTORY_SEPARATOR . '/');

        $parent = dirname($live);
        if (!is_dir($parent) && !@mkdir($parent, 0755, true) && !is_dir($parent)) {
            throw new RuntimeException('Cannot create Knot directory parent.');
        }

        $backup = dirname($live) . DIRECTORY_SEPARATOR . basename($live)
            . '.backup.' . gmdate('YmdHis') . '_' . bin2hex(random_bytes(3));

        if (is_dir($live) || is_file($live)) {
            if (!rename($live, $backup)) {
                throw new RuntimeException('Unable to stash live module tree before swap.');
            }
            $this->backupPath = $backup;
        }

        $this->livePath = $live;

        if (!rename($incoming, $live)) {
            $this->restoreBackupQuiet();
            throw new RuntimeException('Unable to finalize install rename.');
        }

        clearstatcache(true);
        self::purgeDirContents(dirname($incoming));
        @rmdir(dirname($incoming));

        // Keep `backup` on filesystem for manual recovery — drop pointers only.
        $this->livePath = null;
        $this->backupPath = null;
    }

    public function rollback(): void
    {
        $this->restoreBackupQuiet();
    }

    private function restoreBackupQuiet(): void
    {
        $live = $this->livePath;
        $backup = $this->backupPath;
        $this->livePath = null;
        $this->backupPath = null;

        if ($backup !== null && $live !== null) {
            if (is_dir($live)) {
                @$this->rrmdirQuiet($live);
            }
            if (is_dir($backup)) {
                @rename($backup, $live);
            }
            clearstatcache(true);
        }
    }

    /**
     * @return string[]
     */
    public static function manualFallbackInstructions(string $liveDir): array
    {
        return [
            sprintf('Look under %s for `%s.backup.*` directories.', dirname($liveDir), basename($liveDir)),
            sprintf('Rename the freshest backup folder back to `%s` after removing the broken live tree.', basename($liveDir)),
        ];
    }

    private static function purgeDirContents(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob(rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*') ?: [] as $child) {
            self::rrmdirQuietStatic($child);
        }
    }

    private function rrmdirQuiet(string $path): void
    {
        self::rrmdirQuietStatic($path);
    }

    private static function rrmdirQuietStatic(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (glob($path . DIRECTORY_SEPARATOR . '*') ?: [] as $child) {
            self::rrmdirQuietStatic($child);
        }
        @rmdir($path);
    }
}
