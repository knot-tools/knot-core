<?php

/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

namespace Knot\Updates;

use RuntimeException;

/**
 * Resolves and prunes Apply rollback stashes outside Dolibarr `custom/`.
 *
 * Layout (the directory is the previous live module tree):
 * `{dir_output}/update-stashes/{slug}.{YmdHis}_{hex}/manifest.json`
 *
 * `dir_output` is `$conf->knot->dir_output` when set, otherwise
 * `DOL_DATA_ROOT/knot`. Stashes must never be siblings of the live
 * module under `DOL_DOCUMENT_ROOT/custom/` — Dolibarr's native Modules
 * list would then warn « Module found twice ».
 */
final class UpdateStashStore
{
    public const KEEP_DEFAULT = 2;

    public const SUBDIR = 'update-stashes';

    private ?string $stashRootOverride;

    public function __construct(?string $stashRoot = null)
    {
        $this->stashRootOverride = ($stashRoot !== null && $stashRoot !== '')
            ? self::normalize($stashRoot)
            : null;
    }

    /**
     * Absolute stash root (`…/knot/update-stashes`).
     */
    public static function resolveRoot(?string $override = null): string
    {
        if (is_string($override) && $override !== '') {
            return self::normalize($override);
        }

        $fromConf = self::dirOutputFromConf();
        if ($fromConf !== null) {
            return self::normalize($fromConf) . DIRECTORY_SEPARATOR . self::SUBDIR;
        }

        if (defined('DOL_DATA_ROOT')) {
            $data = self::normalize((string) constant('DOL_DATA_ROOT'));
            if ($data !== '' && $data !== '/') {
                return $data . DIRECTORY_SEPARATOR . 'knot' . DIRECTORY_SEPARATOR . self::SUBDIR;
            }
        }

        return self::normalize(sys_get_temp_dir())
            . DIRECTORY_SEPARATOR . 'knot'
            . DIRECTORY_SEPARATOR . self::SUBDIR;
    }

    public function root(): string
    {
        return self::resolveRoot($this->stashRootOverride);
    }

    /**
     * Next unused stash path. Creates the stash root, not the leaf directory
     * (the leaf is created by renaming/copying the live tree).
     *
     * @throws RuntimeException
     */
    public function allocate(string $slug): string
    {
        self::assertSafeSlug($slug);
        $root = $this->ensureRoot();

        return $root
            . DIRECTORY_SEPARATOR
            . $slug
            . '.'
            . gmdate('YmdHis')
            . '_'
            . bin2hex(random_bytes(3));
    }

    /**
     * @throws RuntimeException when the stash root would be visible to
     *                          Dolibarr's `custom/` module scanner
     */
    public function assertSafeForLive(string $liveModuleRoot): void
    {
        $root = self::normalize($this->root());
        $live = self::normalize($liveModuleRoot);
        $liveParent = dirname($live);

        if (self::isInside($root, $live)) {
            throw new RuntimeException(
                'Update stash root must be outside the live module tree.',
            );
        }

        if ($root === $liveParent) {
            throw new RuntimeException(
                'Update stash root must not be the live module parent (that would recreate custom/ siblings).',
            );
        }

        if (self::hasCustomSegment($root)) {
            throw new RuntimeException(
                'Update stash root must not live under a custom/ directory (Dolibarr would list the stash as a second module).',
            );
        }

        if (defined('DOL_DOCUMENT_ROOT')) {
            $custom = self::normalize(
                rtrim((string) constant('DOL_DOCUMENT_ROOT'), DIRECTORY_SEPARATOR . '/')
                . DIRECTORY_SEPARATOR
                . 'custom',
            );
            if (self::isInside($root, $custom)) {
                throw new RuntimeException(
                    'Update stash root must not live under DOL_DOCUMENT_ROOT/custom.',
                );
            }
        }
    }

    /**
     * Stash directories for `$slug`, newest name first (`YmdHis` is sortable).
     *
     * @return list<string>
     */
    public function listForSlug(string $slug): array
    {
        self::assertSafeSlug($slug);
        $root = $this->root();
        if (!is_dir($root)) {
            return [];
        }

        $pattern = '/^' . preg_quote($slug, '/') . '\.\d{14}_[0-9a-f]{6}$/i';
        $found = [];
        foreach (scandir($root) ?: [] as $name) {
            if ($name === '.' || $name === '..' || !preg_match($pattern, $name)) {
                continue;
            }
            $full = $root . DIRECTORY_SEPARATOR . $name;
            if (is_dir($full)) {
                $found[] = $full;
            }
        }

        usort($found, static fn (string $a, string $b): int => strcmp(basename($b), basename($a)));

        return $found;
    }

    /**
     * Deletes older stashes for `$slug`, keeping the `$keep` newest.
     *
     * @return int Number of directories removed
     */
    public function prune(string $slug, int $keep = self::KEEP_DEFAULT): int
    {
        if ($keep < 0) {
            $keep = 0;
        }

        $removed = 0;
        $rootReal = is_dir($this->root()) ? realpath($this->root()) : false;
        foreach (array_slice($this->listForSlug($slug), $keep) as $path) {
            if (!self::isSafeToDelete($path, $rootReal)) {
                continue;
            }
            self::rrmdirQuiet($path);
            ++$removed;
        }

        return $removed;
    }

    /**
     * @throws RuntimeException
     */
    public function ensureRoot(): string
    {
        $root = $this->root();
        if (!is_dir($root) && !@mkdir($root, 0755, true) && !is_dir($root)) {
            throw new RuntimeException('Cannot create update stash root: ' . $root);
        }

        return $root;
    }

    /**
     * True when `$path` matches `{slug}.{YmdHis}_{hex}`.
     */
    public static function isStashDirectoryName(string $name, string $slug): bool
    {
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $slug)) {
            return false;
        }

        return (bool) preg_match(
            '/^' . preg_quote($slug, '/') . '\.\d{14}_[0-9a-f]{6}$/i',
            $name,
        );
    }

    private static function dirOutputFromConf(): ?string
    {
        $conf = $GLOBALS['conf'] ?? null;
        if (!is_object($conf)) {
            return null;
        }
        $knot = $conf->knot ?? null;
        if (!is_object($knot)) {
            return null;
        }
        $dir = $knot->dir_output ?? null;
        if (!is_string($dir) || trim($dir) === '') {
            return null;
        }

        return $dir;
    }

    /**
     * @throws RuntimeException
     */
    private static function assertSafeSlug(string $slug): void
    {
        if ($slug === '' || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $slug)) {
            throw new RuntimeException('Invalid module folder name for update stash.');
        }
    }

    private static function normalize(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $path = rtrim($path, '/');

        return $path === '' ? '/' : $path;
    }

    private static function isInside(string $path, string $parent): bool
    {
        $path = self::normalize($path);
        $parent = self::normalize($parent);

        return $path === $parent || str_starts_with($path, $parent . '/');
    }

    private static function hasCustomSegment(string $path): bool
    {
        foreach (explode('/', self::normalize($path)) as $segment) {
            if ($segment === 'custom') {
                return true;
            }
        }

        return false;
    }

    private static function isSafeToDelete(string $path, string|false $rootReal): bool
    {
        if ($rootReal === false || !is_dir($path)) {
            return false;
        }
        $real = realpath($path);
        if ($real === false) {
            return false;
        }

        return str_starts_with($real, $rootReal . DIRECTORY_SEPARATOR);
    }

    private static function rrmdirQuiet(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (glob($path . DIRECTORY_SEPARATOR . '*') ?: [] as $child) {
            self::rrmdirQuiet($child);
        }
        @rmdir($path);
    }
}
