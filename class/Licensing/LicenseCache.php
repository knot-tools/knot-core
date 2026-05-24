<?php

declare(strict_types=1);

namespace Knot\Licensing;

use RuntimeException;

/**
 * Persists the last signed licence response on disk so Knot can keep
 * working even when the Dolistore backend is unreachable.
 *
 * Layout (one file per extension):
 *   documents/knot/licenses/<extension-id>.cache.json
 *
 * Cache entries store the raw signed payload (so signature can be
 * re-verified at every read), never just the boolean verdict — this
 * prevents an attacker from tampering with the cache.
 *
 * Standalone throttle files (no signed cache yet) live under
 * `documents/knot/licenses/.refresh-throttle/<extension-id>.json` so
 * {@see DolistoreValidator} can rate-limit `licensing.refresh.failed`
 * audits when the extension has never successfully cached a payload.
 *
 * @phpstan-type AuditThrottleState array{
 *     refreshFailedAt?: string,
 *     refreshFailedClass?: string,
 *     graceEnteredAt?: string
 * }
 * @phpstan-type CacheEntry array{
 *     extensionId: string,
 *     instanceId: string,
 *     signedPayload: array<string, mixed>,
 *     signature: string,
 *     signedAt: string,
 *     expiresAt: ?string,
 *     plan: ?string,
 *     issuedTo: ?string,
 *     lastSuccessfulRefresh: string,
 *     lastAttempt: string,
 *     lastError: ?string,
 *     auditThrottle?: AuditThrottleState,
 *     activationCodeEnc?: string
 * }
 */
final class LicenseCache
{
    private string $cacheDir;

    public function __construct(?string $cacheDir = null)
    {
        if ($cacheDir !== null) {
            $this->cacheDir = rtrim($cacheDir, '/\\');
            return;
        }
        $base = defined('DOL_DATA_ROOT')
            ? (string) constant('DOL_DATA_ROOT')
            : sys_get_temp_dir();
        $this->cacheDir = $base . '/knot/licenses';
    }

    public function getCacheDir(): string
    {
        return $this->cacheDir;
    }

    /**
     * @return CacheEntry|null
     */
    public function read(string $extensionId): ?array
    {
        $path = $this->path($extensionId);
        if (!is_readable($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }
        if (!isset($data['extensionId'], $data['signedPayload'], $data['signature'], $data['signedAt'])) {
            return null;
        }
        /** @var CacheEntry $data */
        return $data;
    }

    /**
     * @param CacheEntry $entry
     */
    public function write(array $entry): void
    {
        if (!is_dir($this->cacheDir)) {
            if (!@mkdir($this->cacheDir, 0755, true) && !is_dir($this->cacheDir)) {
                throw new RuntimeException('Cannot create license cache dir: ' . $this->cacheDir);
            }
        }
        @chmod($this->cacheDir, 0755);
        $path = $this->path((string) $entry['extensionId']);
        $json = json_encode($entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Cannot encode license cache entry');
        }
        $tmp = $path . '.tmp';
        if (file_put_contents($tmp, $json, LOCK_EX) === false) {
            throw new RuntimeException('Cannot write license cache: ' . $tmp);
        }
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Cannot atomically replace license cache: ' . $path);
        }
        // World-readable so PHP-FPM (often a different Unix user than CLI
        // activation) can verify the signed cache on web requests.
        @chmod($path, 0644);
        $this->clearStandaloneRefreshThrottle($entry['extensionId']);
    }

    public function delete(string $extensionId): void
    {
        $path = $this->path($extensionId);
        if (is_file($path)) {
            @unlink($path);
        }
        $this->clearStandaloneRefreshThrottle($extensionId);
    }

    /**
     * Read throttle slice persisted without a full licence cache entry.
     *
     * @return AuditThrottleState|null
     */
    public function readStandaloneRefreshThrottle(string $extensionId): ?array
    {
        $path = $this->standaloneThrottlePath($extensionId);
        if (!is_readable($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }
        if (!isset($data['refreshFailedAt'], $data['refreshFailedClass'])) {
            return null;
        }
        /** @var AuditThrottleState $data */
        return $data;
    }

    /**
     * Persist refresh-failure throttle when no signed cache file exists yet.
     */
    public function writeStandaloneRefreshThrottle(string $extensionId, string $failureClass): void
    {
        $dir = $this->cacheDir . '/.refresh-throttle';
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0750, true) && !is_dir($dir)) {
                throw new RuntimeException('Cannot create license refresh throttle dir: ' . $dir);
            }
        }
        $path = $this->standaloneThrottlePath($extensionId);
        $payload = json_encode([
            'refreshFailedAt' => date('c'),
            'refreshFailedClass' => $failureClass,
        ], JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            throw new RuntimeException('Cannot encode standalone refresh throttle');
        }
        $tmp = $path . '.tmp';
        if (file_put_contents($tmp, $payload, LOCK_EX) === false) {
            throw new RuntimeException('Cannot write standalone refresh throttle: ' . $tmp);
        }
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Cannot atomically replace standalone refresh throttle: ' . $path);
        }
        @chmod($path, 0640);
    }

    public function clearStandaloneRefreshThrottle(string $extensionId): void
    {
        $path = $this->standaloneThrottlePath($extensionId);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Update only the timestamps after a failed refresh attempt
     * (network down, backend 5xx). The signed payload is kept intact.
     *
     * @param ?AuditThrottleState $auditThrottleMerge optional merge into `auditThrottle`
     */
    public function recordFailedAttempt(string $extensionId, string $error, ?array $auditThrottleMerge = null): void
    {
        $entry = $this->read($extensionId);
        if ($entry === null) {
            return;
        }
        $entry['lastAttempt'] = date('c');
        $entry['lastError'] = $error;
        if ($auditThrottleMerge !== null && $auditThrottleMerge !== []) {
            $existing = $entry['auditThrottle'] ?? [];
            /** @var AuditThrottleState $merged */
            $merged = array_merge($existing, $auditThrottleMerge);
            $entry['auditThrottle'] = $merged;
        }
        $this->write($entry);
    }

    /**
     * @param AuditThrottleState $partial
     */
    public function mergeAuditThrottle(string $extensionId, array $partial): void
    {
        $entry = $this->read($extensionId);
        if ($entry === null) {
            return;
        }
        $existing = $entry['auditThrottle'] ?? [];
        /** @var AuditThrottleState $merged */
        $merged = array_merge($existing, $partial);
        $entry['auditThrottle'] = $merged;
        $this->write($entry);
    }

    private function path(string $extensionId): string
    {
        $this->assertSafeExtensionId($extensionId);
        return $this->cacheDir . '/' . $extensionId . '.cache.json';
    }

    private function standaloneThrottlePath(string $extensionId): string
    {
        $this->assertSafeExtensionId($extensionId);
        return $this->cacheDir . '/.refresh-throttle/' . $extensionId . '.json';
    }

    private function assertSafeExtensionId(string $extensionId): void
    {
        if (!preg_match('/^[a-z0-9-]{2,64}$/', $extensionId)) {
            throw new RuntimeException('Invalid extensionId for cache path');
        }
    }
}
