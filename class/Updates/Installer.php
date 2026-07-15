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
            // Beta-tester resilience: the live install folder name can differ
            // from the ZIP top-level folder (e.g. extension deployed under
            // custom/knot-migration/ while the signed artefact ships
            // knotmigration/). When exactly one extracted folder carries a
            // manifest.json, adopt it under the expected name so the swap
            // still targets the live directory.
            $fallback = self::detectSingleManifestRoot($staging);
            if ($fallback !== null && $fallback !== $root && @rename($fallback, $root)) {
                return $root;
            }
            throw new RuntimeException(sprintf(
                'Invalid archive — manifest.json missing at top-level folder "%s"%s.',
                trim($topFolder, '/'),
                $fallback !== null ? sprintf(' (archive ships "%s")', basename($fallback)) : '',
            ));
        }

        return $root;
    }

    /**
     * Returns the only direct sub-directory of `$staging` containing a
     * `manifest.json`, or null when none / more than one matches.
     */
    private static function detectSingleManifestRoot(string $staging): ?string
    {
        $matches = [];
        foreach (glob($staging . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (is_file($dir . DIRECTORY_SEPARATOR . 'manifest.json')) {
                $matches[] = $dir;
            }
        }

        return count($matches) === 1 ? $matches[0] : null;
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
            if (!self::movePath($live, $backup)) {
                throw new RuntimeException('Unable to stash live module tree before swap.');
            }
            $this->backupPath = $backup;
        }

        $this->livePath = $live;

        if (!self::movePath($incoming, $live)) {
            $this->restoreBackupQuiet();
            throw new RuntimeException('Unable to finalize install rename.');
        }

        clearstatcache(true);
        self::purgeDirContents(dirname($incoming));
        @rmdir(dirname($incoming));
    }

    /**
     * Clears in-memory rollback pointers after migrations succeed.
     * The `.backup.*` directory remains on disk for manual recovery.
     */
    public function commitSwap(): void
    {
        $this->livePath = null;
        $this->backupPath = null;
    }

    public function canRollback(): bool
    {
        return $this->livePath !== null
            && $this->backupPath !== null
            && is_dir($this->backupPath);
    }

    public function rollback(): bool
    {
        if (!$this->canRollback()) {
            return false;
        }

        $live = $this->livePath;
        $backup = $this->backupPath;
        $this->livePath = null;
        $this->backupPath = null;

        if ($backup === null || $live === null) {
            return false;
        }

        if (is_dir($live)) {
            @$this->rrmdirQuiet($live);
        }
        if (!is_dir($backup)) {
            return false;
        }
        if (!self::movePath($backup, $live)) {
            return false;
        }
        clearstatcache(true);

        return is_dir($live);
    }

    private function restoreBackupQuiet(): void
    {
        $this->rollback();
    }

    /**
     * Prefer atomic rename; fall back to recursive copy + delete when volumes
     * differ (EXDEV) — common when staging lives under DOL_DATA_ROOT and the
     * module under DOL_DOCUMENT_ROOT/custom.
     */
    private static function movePath(string $from, string $to): bool
    {
        if (@rename($from, $to)) {
            return true;
        }
        if (!is_dir($from) && !is_file($from)) {
            return false;
        }
        if (is_dir($to) || is_file($to)) {
            return false;
        }
        if (!self::copyPathRecursive($from, $to)) {
            self::rrmdirQuietStatic($to);

            return false;
        }
        self::rrmdirQuietStatic($from);

        return is_dir($to) || is_file($to);
    }

    private static function copyPathRecursive(string $from, string $to): bool
    {
        if (is_link($from) || is_file($from)) {
            return @copy($from, $to);
        }
        if (!is_dir($from)) {
            return false;
        }
        if (!@mkdir($to, 0755, true) && !is_dir($to)) {
            return false;
        }
        foreach (glob($from . DIRECTORY_SEPARATOR . '*') ?: [] as $child) {
            $name = basename($child);
            if (!self::copyPathRecursive($child, $to . DIRECTORY_SEPARATOR . $name)) {
                return false;
            }
        }

        return true;
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
