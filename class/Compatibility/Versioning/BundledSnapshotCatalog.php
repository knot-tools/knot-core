<?php

declare(strict_types=1);

namespace Knot\Compatibility\Versioning;

/**
 * Lists JSON snapshots shipped under data/compatibility/snapshots/ (excludes sample-*.json).
 */
final class BundledSnapshotCatalog
{
    public function __construct(private readonly string $snapshotsDirectory)
    {
    }

    /**
     * Default directory for Knot module data snapshots (relative resolution via caller).
     */
    public static function defaultFromApiDir(string $apiDir): self
    {
        return new self(rtrim($apiDir, '/') . '/../data/compatibility/snapshots');
    }

    /**
     * @return list<array{filename: string, dolibarr_version: string, schema_version: string, generated_at: string|null}>
     */
    /** @return list<array{filename: string, dolibarr_version: string, schema_version: string, generated_at: string|null}> */
    public function listReferenceSnapshots(): array
    {
        if (!is_dir($this->snapshotsDirectory)) {
            return [];
        }

        $paths = glob($this->snapshotsDirectory . '/*.json') ?: [];
        sort($paths);

        $out = [];
        foreach ($paths as $path) {
            $base = basename($path);
            if (!preg_match('/^[a-zA-Z0-9._-]+\\.json$/', $base)) {
                continue;
            }
            if (str_starts_with($base, 'sample-')) {
                continue;
            }
            $meta = $this->readMeta($path);
            if ($meta === null) {
                continue;
            }
            $out[] = [
                'filename' => $base,
                'dolibarr_version' => $meta['dolibarr_version'],
                'schema_version' => $meta['schema_version'],
                'generated_at' => $meta['generated_at'],
            ];
        }

        return $out;
    }

    public function resolveReadablePath(string $basename): ?string
    {
        if (!preg_match('/^[a-zA-Z0-9._-]+\\.json$/', $basename)) {
            return null;
        }
        if (str_starts_with($basename, 'sample-')) {
            return null;
        }

        $path = $this->snapshotsDirectory . '/' . $basename;
        $realFile = realpath($path);
        $root = realpath($this->snapshotsDirectory);
        if ($realFile === false || $root === false || !is_readable($realFile)) {
            return null;
        }

        if ($realFile !== $root && !str_starts_with($realFile, $root . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $realFile;
    }

    /**
     * @return array{dolibarr_version: string, schema_version: string, generated_at: string|null}|null
     */
    private function readMeta(string $path): ?array
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        $json = json_decode($raw, true);
        if (!is_array($json)) {
            return null;
        }

        return [
            'dolibarr_version' => (string) ($json['dolibarr_version'] ?? 'unknown'),
            'schema_version' => (string) ($json['schema_version'] ?? ''),
            'generated_at' => isset($json['generated_at']) ? (string) $json['generated_at'] : null,
        ];
    }
}
