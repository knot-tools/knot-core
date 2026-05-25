<?php

/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

namespace Knot\Updates;

use RuntimeException;

/**
 * Runs an extension-declared post-apply migration runner from knot-extension.json.
 *
 * Core never imports PolyForm extension classes — the FQCN is loaded dynamically
 * from the extension autoload after a successful file swap.
 */
final class ExtensionPostApplyRunner
{
    public const SUPPORTED_CONTRACT_VERSION = 1;

    /**
     * @return array<int, array{version: string, file: string, status: string, durationMs: int}>
     */
    public function run(object $db, string $extensionRoot): array
    {
        $manifestPath = rtrim($extensionRoot, DIRECTORY_SEPARATOR . '/') . DIRECTORY_SEPARATOR . 'knot-extension.json';
        if (!is_readable($manifestPath)) {
            return [];
        }

        $raw = file_get_contents($manifestPath);
        $manifest = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($manifest)) {
            throw new RuntimeException('Extension manifest is unreadable or invalid JSON.');
        }

        $postApply = $manifest['postApply'] ?? null;
        if (!is_array($postApply)) {
            return [];
        }

        $contractVersion = (int) ($postApply['contractVersion'] ?? 0);
        if ($contractVersion !== self::SUPPORTED_CONTRACT_VERSION) {
            throw new RuntimeException(
                'Unsupported postApply contractVersion '
                . $contractVersion
                . '. Update Knot Core to apply this extension.',
            );
        }

        $autoloadRel = trim((string) ($postApply['autoload'] ?? ''));
        $runnerFqcn = trim((string) ($postApply['migrationRunner'] ?? ''));
        if ($autoloadRel === '' || $runnerFqcn === '') {
            throw new RuntimeException('postApply requires autoload and migrationRunner.');
        }

        if (str_contains($autoloadRel, '..') || str_starts_with($autoloadRel, '/') || str_starts_with($autoloadRel, '\\')) {
            throw new RuntimeException('postApply.autoload must be a relative path without parent segments.');
        }

        $namespace = trim((string) ($manifest['namespace'] ?? ''));
        if ($namespace === '' || !str_starts_with($runnerFqcn, rtrim($namespace, '\\') . '\\')) {
            throw new RuntimeException('postApply.migrationRunner must be under the manifest namespace.');
        }

        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)+$/', $runnerFqcn)) {
            throw new RuntimeException('postApply.migrationRunner is not a valid PHP FQCN.');
        }

        $autoloadPath = rtrim($extensionRoot, DIRECTORY_SEPARATOR . '/') . DIRECTORY_SEPARATOR . $autoloadRel;
        if (!is_readable($autoloadPath)) {
            throw new RuntimeException('postApply autoload file is missing or unreadable.');
        }

        try {
            require_once $autoloadPath;
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'postApply autoload failed: ' . $e->getMessage(),
                0,
                $e,
            );
        }

        if (!class_exists($runnerFqcn)) {
            throw new RuntimeException('postApply migration runner class was not found after autoload.');
        }

        try {
            $runner = new $runnerFqcn($db, $extensionRoot);
            if (!method_exists($runner, 'run')) {
                throw new RuntimeException('postApply migration runner must expose a run() method.');
            }

            /** @var mixed $result */
            $result = $runner->run();
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'postApply migration runner failed: ' . $e->getMessage(),
                0,
                $e,
            );
        }

        if (!is_array($result)) {
            throw new RuntimeException('postApply migration runner run() must return an array.');
        }

        return $this->normalizeLog($result);
    }

    /**
     * @param array<int, mixed> $raw
     * @return array<int, array{version: string, file: string, status: string, durationMs: int}>
     */
    private function normalizeLog(array $raw): array
    {
        $out = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[] = [
                'version' => (string) ($row['version'] ?? ''),
                'file' => (string) ($row['file'] ?? ''),
                'status' => (string) ($row['status'] ?? ''),
                'durationMs' => (int) ($row['durationMs'] ?? 0),
            ];
        }

        return $out;
    }
}
