<?php

declare(strict_types=1);

namespace Knot\StateMachine;

/**
 * File cache for L1+L2 artefacts under {@see DOL_DATA_ROOT}/knot/state-machine/{DOL_VERSION}/.
 */
final class StateMachineCache
{
    public function __construct(private readonly ?string $dataRoot = null)
    {
    }

    private function baseDir(): string
    {
        $root = $this->dataRoot;
        if ($root === null || $root === '') {
            $root = defined('DOL_DATA_ROOT') ? (string) constant('DOL_DATA_ROOT') : sys_get_temp_dir();
        }
        $dolVer = defined('DOL_VERSION')
            ? (string) preg_replace('/[^0-9a-zA-Z._-]+/', '_', (string) constant('DOL_VERSION'))
            : 'unknown';

        return $root . '/knot/state-machine/' . $dolVer;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function read(string $cacheKey): ?array
    {
        $path = $this->path($cacheKey);
        if (!is_readable($path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            return null;
        }
        $stored = (string) ($decoded['dolibarr_version'] ?? '');
        $current = defined('DOL_VERSION') ? (string) constant('DOL_VERSION') : '';
        if ($current !== '' && $stored !== '' && $stored !== $current) {
            return null;
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function write(string $cacheKey, array $payload): void
    {
        $dir = $this->baseDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $payload['dolibarr_version'] = defined('DOL_VERSION') ? (string) constant('DOL_VERSION') : 'unknown';
        $payload['cached_at'] = gmdate('c');
        file_put_contents(
            $this->path($cacheKey),
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n"
        );
    }

    private function path(string $cacheKey): string
    {
        $safe = (string) preg_replace('/[^a-z0-9._-]+/i', '_', $cacheKey);

        return $this->baseDir() . '/' . $safe . '.json';
    }
}
