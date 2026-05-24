<?php

/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

namespace Knot\Licensing;

use Knot\Repository\KnotConfigRepository;
use Knot\Version;

/**
 * Deterministic deployment fingerprint (public preimage) + per-entity UUID nonce.
 *
 * {@see docs/licensing.md} documents preimage `knot-deploy-v2`, URL canonicalisation
 * and DB host/name normalization. The pepper is deliberately public GPL code —
 * telemetry correlation only; licensing truth remains InstanceBinder + Dolistore.
 */
final class InstallationIdentity
{
    public const CONFIG_KEY_DEPLOYMENT_UUID = 'install.deployment_uuid';

    /** Preimage label embedded before hashing — allows future coexistence schemes. */
    public const PREIMAGE_SCHEME = 'knot-deploy-v2';

    /**
     * Public Knot pepper mixed into preimage (same class of asset as pinned public keys).
     * Changing it intentionally rotates deploymentToken for all installs.
     */
    public const PUBLIC_PEPPER = 'knot-install-identity-pepper-public-20260503';

    public function __construct(
        private readonly KnotConfigRepository $configRepo,
        private readonly \DoliDB $db,
    ) {
    }

    public function deploymentToken(): string
    {
        $url = self::normalizeCanonicalPublicUrl();
        $dbFp = $this->databaseFingerprint();

        $preimage = implode('|', [
            self::PREIMAGE_SCHEME,
            $url,
            $dbFp,
            self::PUBLIC_PEPPER,
        ]);

        return hash('sha256', $preimage);
    }

    /**
     * UUID v4 persisted in llx_knot_config (one row per Dolibarr entity knot row).
     */
    public function deploymentNonce(): string
    {
        $existing = $this->configRepo->get(self::CONFIG_KEY_DEPLOYMENT_UUID);
        if ($existing !== null && $existing !== '') {
            return trim($existing);
        }
        $uuid = self::generateUuidV4();
        $this->configRepo->set(self::CONFIG_KEY_DEPLOYMENT_UUID, $uuid);

        return $uuid;
    }

    /**
     * Headers for outbound HTTP toward license backend (preferred over GET query).
     *
     * @return list<string>
     */
    public function deploymentHeaderLines(): array
    {
        return [
            'X-Knot-Deployment-Token: ' . $this->deploymentToken(),
            'X-Knot-Deployment-Nonce: ' . $this->deploymentNonce(),
        ];
    }

    /**
     * @return non-empty-string
     */
    public static function knotCoreUserAgent(string $suffix): string
    {
        $ver = Version::current();

        return 'Knot-' . $suffix . '/' . $ver;
    }

    /**
     * 64 lowercase hex chars: SHA256 of normalized logical db host + name (no passwords).
     */
    private function databaseFingerprint(): string
    {
        $host = self::normalizeDbHost(self::readDbHost($this->db));
        $dbName = self::normalizeDbName(self::readDbName($this->db));

        return hash('sha256', $host . '|' . $dbName);
    }

    private static function readDbHost(\DoliDB $db): string
    {
        foreach (['dbhost', 'database_host', 'host'] as $prop) {
            if (!property_exists($db, $prop)) {
                continue;
            }
            $v = (string) $db->{$prop};
            if ($v !== '') {
                return $v;
            }
        }

        return '';
    }

    private static function readDbName(\DoliDB $db): string
    {
        foreach (['dbname', 'database_name', 'db_name', 'name'] as $prop) {
            if (!property_exists($db, $prop)) {
                continue;
            }
            $v = (string) $db->{$prop};
            if ($v !== '') {
                return $v;
            }
        }

        return '';
    }

    /**
     * Unix socket paths: trim only. TCP hosts: lowercase, strip default :3306 / :3307.
     */
    public static function normalizeDbHost(string $host): string
    {
        $host = trim($host);
        if ($host === '') {
            return '';
        }
        if ($host[0] === '/' || str_starts_with($host, './')) {
            return $host;
        }
        $lower = strtolower($host);
        if ($lower === 'localhost') {
            return '127.0.0.1';
        }
        foreach (['[::1]', '::1'] as $loop) {
            if ($lower === $loop) {
                return '127.0.0.1';
            }
        }
        if (preg_match('/^(\[[0-9a-f:]+\])(?::(\d+))?$/i', $lower, $m)) {
            return $m[1] . self::normalizedPortSuffix($m[2] ?? '');
        }
        // host:port (IPv4 hostname)
        if (preg_match('/^([^\]]+?):(\d+)$/', $lower, $m) && !str_contains($m[1], '[')) {
            return $m[1] . self::normalizedPortSuffix($m[2]);
        }

        return preg_replace('/:3306$/', '', $lower) ?? $lower;
    }

    /** Database logical name — lowercase except preserve case if empty after trim forbidden. */
    public static function normalizeDbName(string $name): string
    {
        $name = trim($name);

        return strtolower($name);
    }

    /**
     * MAIN_URL_PUBLIC when set; else https://HTTP_HOST+DOL_URL_ROOT (no trailing slash on full URL root).
     */
    public static function normalizeCanonicalPublicUrl(): string
    {
        $fromConst = '';
        if (function_exists('getDolGlobalString')) {
            $fromConst = trim((string) getDolGlobalString('MAIN_URL_PUBLIC', ''));
        }
        if ($fromConst !== '') {
            return self::normalizeUrlRoot($fromConst);
        }

        $host = isset($_SERVER['HTTP_HOST']) && is_string($_SERVER['HTTP_HOST'])
            ? trim($_SERVER['HTTP_HOST'])
            : '';
        $path = '';
        if (defined('DOL_URL_ROOT')) {
            $path = trim((string) constant('DOL_URL_ROOT'));
        } elseif (function_exists('getDolGlobalString')) {
            $path = trim((string) getDolGlobalString('DOL_URL_ROOT', ''));
        }
        if ($host === '') {
            // Last resort: path-only (weak correlation — documented in docs/licensing.md)
            return self::normalizeUrlRoot($path !== '' ? $path : 'https://unknown-host.invalid');
        }
        $scheme = 'https';
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            $scheme = 'https';
        } elseif (!empty($_SERVER['REQUEST_SCHEME'])) {
            $scheme = strtolower((string) $_SERVER['REQUEST_SCHEME']) === 'http' ? 'http' : 'https';
        }

        $base = $scheme . '://' . $host;
        if ($path !== '') {
            $base .= '/' . ltrim($path, '/');
        }

        return self::normalizeUrlRoot($base);
    }

    /**
     * Lowercase host, strip trailing slash, drop default :80 / :443 on host part.
     */
    public static function normalizeUrlRoot(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        $parts = @parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return rtrim($url, '/');
        }
        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        if ($port === 80 && $scheme === 'http') {
            $port = null;
        }
        if ($port === 443 && $scheme === 'https') {
            $port = null;
        }
        $hostPart = $host;
        if ($port !== null && $port > 0) {
            $hostPart .= ':' . $port;
        }
        $path = isset($parts['path']) ? '/' . trim((string) $parts['path'], '/') : '';
        $out = $scheme . '://' . $hostPart . $path;

        return rtrim($out, '/');
    }

    private static function normalizedPortSuffix(string $port): string
    {
        if ($port === '' || $port === '3306') {
            return '';
        }

        return ':' . $port;
    }

    private static function generateUuidV4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);

        return sprintf(
            '%s-%s-%s-%s-%s',
            bin2hex(substr($b, 0, 4)),
            bin2hex(substr($b, 4, 2)),
            bin2hex(substr($b, 6, 2)),
            bin2hex(substr($b, 8, 2)),
            bin2hex(substr($b, 10, 6)),
        );
    }
}
