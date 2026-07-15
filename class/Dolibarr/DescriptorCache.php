<?php

declare(strict_types=1);

namespace Knot\Dolibarr;

/**
 * Persistent cache for the result of an `ObjectIntrospector` scan.
 *
 * Knot Core ships with a static MAP of curated objects. V2.4 adds
 * automatic discovery of every other `CommonObject` subclass present
 * in the install (core + Dolistore + Knot extensions). Scanning takes
 * a few hundred milliseconds on a fresh install, which is too slow
 * to do on every API request, so we persist the result on disk and
 * invalidate it on:
 *
 *   - explicit refresh (admin button or CLI `php scripts/_refresh_introspection.php`)
 *   - module activation/deactivation (Dolibarr hook in V2.5; for now
 *     the admin button is enough)
 *   - mismatched hash (the hash combines mtime of all scanned files)
 *
 * The cache file lives at `documents/knot/dolibarr_descriptors.json`.
 * On platforms without write access we silently fall back to in-memory
 * caching for the lifetime of the request.
 */
final class DescriptorCache
{
    public const FILENAME = 'dolibarr_descriptors.json';

    private string $cacheDir;

    /** @var array<string, mixed>|null */
    private ?array $memoryCache = null;

    public function __construct(?string $cacheDir = null)
    {
        if ($cacheDir !== null) {
            $this->cacheDir = rtrim($cacheDir, '/\\');
            return;
        }
        $base = defined('DOL_DATA_ROOT')
            ? (string) constant('DOL_DATA_ROOT')
            : sys_get_temp_dir();
        $this->cacheDir = $base . '/knot';
    }

    public function getPath(): string
    {
        return $this->cacheDir . '/' . self::FILENAME;
    }

    /**
     * Return the cached payload or null when missing/invalid.
     *
     * @return array{
     *     hash: string,
     *     generatedAt: string,
     *     descriptors: array<int, array<string, mixed>>
     * }|null
     */
    public function read(): ?array
    {
        if ($this->memoryCache !== null) {
            return $this->memoryCache;
        }
        $path = $this->getPath();
        if (!is_readable($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        if (
            !is_array($data)
            || !isset($data['hash'], $data['generatedAt'], $data['descriptors'])
            || !is_array($data['descriptors'])
        ) {
            return null;
        }
        $this->memoryCache = $data;
        return $data;
    }

    /**
     * @param array<int, array<string, mixed>> $descriptors
     */
    public function write(array $descriptors, string $hash): void
    {
        $payload = [
            'hash' => $hash,
            'generatedAt' => date('c'),
            'descriptors' => $descriptors,
        ];
        $this->memoryCache = $payload;
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0o755, true);
        }
        if (!is_dir($this->cacheDir) || !is_writable($this->cacheDir)) {
            // No disk write permission — memory cache will satisfy the
            // current request, the next one will rebuild from scratch.
            return;
        }
        $path = $this->getPath();
        $tmp = $path . '.tmp.' . uniqid();
        if (file_put_contents($tmp, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) !== false) {
            @rename($tmp, $path);
        }
    }

    public function clear(): void
    {
        $this->memoryCache = null;
        @unlink($this->getPath());
    }

    /**
     * Compute a hash over all introspectable file mtimes (used to
     * detect when one of the scanned modules changed and the cache
     * must be rebuilt).
     */
    /**
     * @param array<int, string> $extraRoots
     */
    public static function hashForRoots(string $documentRoot, array $extraRoots = []): string
    {
        $roots = [];
        foreach (ObjectIntrospector::ALLOWED_MODULE_DIRS as $dir) {
            $full = $documentRoot . '/' . $dir;
            if (is_dir($full)) {
                $roots[] = $full;
            }
        }
        $custom = $documentRoot . '/custom';
        if (is_dir($custom)) {
            foreach (scandir($custom) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                if ($entry === 'knot' || strpos($entry, 'modKnot') === 0) {
                    continue;
                }
                $roots[] = $custom . '/' . $entry;
            }
        }
        foreach ($extraRoots as $r) {
            $roots[] = $r;
        }
        $accum = '';
        foreach ($roots as $root) {
            if (!is_dir($root)) {
                continue;
            }
            // GLOB_BRACE is unavailable on non-GNU libc builds (musl/Alpine,
            // Solaris), so expand the brace patterns manually for portability.
            $files = array_merge(
                glob($root . '/class/*.class.php') ?: [],
                glob($root . '/*/class/*.class.php') ?: [],
                glob($root . '/*/*/class/*.class.php') ?: [],
            );
            $files = array_values(array_unique($files));
            sort($files);
            foreach ($files as $f) {
                $mtime = @filemtime($f);
                $accum .= $f . ':' . ($mtime === false ? '0' : (string) $mtime) . '|';
            }
        }
        return substr(sha1($accum), 0, 16);
    }
}
