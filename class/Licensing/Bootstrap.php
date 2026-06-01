<?php

/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

namespace Knot\Licensing;

use Knot\Licensing\Audit\LicenseAuditWriter;
use Knot\Repository\AuditLogRepository;
use Knot\Repository\KnotConfigRepository;
use Knot\Security\SecretMasker;
use Knot\Extension\LicenseValidator as ExtensionLicenseValidator;
use Knot\Extension\ExtensionRegistry;

/**
 * Central composer that wires the Licensing chain from Dolibarr
 * configuration constants (cf. plan §1.1, V2.5.0a Chantier 1).
 *
 * Before V2.5.0a no part of Knot Core actually instantiated a fully
 * wired {@see DolistoreValidator}: the crypto bricks were tested in
 * isolation but never composed — every endpoint that ran extension
 * discovery (e.g. `api/connectors.php`) silently used a `LicenseValidator`
 * with `dolistoreValidator = null`, so manifests declaring
 * `validation: dolistore` were rejected as INVALID even when the
 * licence was perfectly fine.
 *
 * This class fixes that by exposing the canonical entry point:
 *
 * ```php
 * $registry = Bootstrap::buildExtensionRegistry($db);
 * ```
 *
 * Convenience accessors are also provided to build the lower-level
 * components for tests or admin tooling.
 *
 * Configuration sources (read once per call):
 *  - `MAIN_KNOT_LICENSE_BASE_URL` — backend root URL, defaults to
 *    {@see DolistoreClient::FALLBACK_BASE_URL}.
 *  - `KNOT_LICENSE_DOLISTORE_TTL_HOURS` — cache TTL between refreshes.
 *  - `KNOT_LICENSE_DOLISTORE_OFFLINE_DAYS` — offline grace window.
 *  - `KNOT_LICENSE_DEV_INSECURE` — disables TLS verification (dev only).
 *  - `MAIN_KNOT_LICENSE_PUBKEY` — optional dev override (single key).
 *  - `MAIN_INFO_SOCIETE_NOM` + `DOL_URL_ROOT` — feed the InstanceBinder.
 *
 * The composer is idempotent and creates `licensing.local_salt` on
 * first call (32 random bytes hex, persisted in `llx_knot_config`,
 * never logged). Subsequent calls reuse the stored salt.
 */
final class Bootstrap
{
    public const CONFIG_KEY_LOCAL_SALT = 'licensing.local_salt';

    /** Docs Docker: pin InstanceBinder to the licence-bound fingerprint (see seed_docs_migration.php). */
    public const CONFIG_KEY_DOCS_PINNED_FINGERPRINT = 'licensing.docs_pinned_fingerprint';

    /**
     * Build an {@see ExtensionRegistry} fully wired with the runtime
     * licensing chain. This is the single recommended entry point for
     * any HTTP endpoint that needs to discover installed extensions.
     */
    public static function buildExtensionRegistry(\DoliDB $db): ExtensionRegistry
    {
        return new ExtensionRegistry(null, self::buildLicenseValidator($db));
    }

    /**
     * Build the {@see ExtensionLicenseValidator} facade with the
     * dolistore branch wired (when configuration permits).
     */
    public static function buildLicenseValidator(\DoliDB $db): ExtensionLicenseValidator
    {
        return new ExtensionLicenseValidator(null, self::buildDolistoreValidator($db));
    }

    /**
     * Build the {@see DolistoreValidator} or `null` when the runtime
     * cannot meaningfully validate (no pinned keys, sodium absent...).
     *
     * Returning `null` is a deliberate design choice: it lets the
     * facade keep working in the local-license-file mode without
     * coupling the caller to a half-initialised dolistore branch.
     */
    public static function buildDolistoreValidator(\DoliDB $db): ?DolistoreValidator
    {
        if (!extension_loaded('sodium')) {
            return null;
        }
        $pinnedKeys = PinnedPublicKeys::fromConstants();
        if ($pinnedKeys === []) {
            return null;
        }

        $baseUrl = function_exists('getDolGlobalString')
            ? trim((string) getDolGlobalString('MAIN_KNOT_LICENSE_BASE_URL', ''))
            : '';
        if ($baseUrl === '') {
            $baseUrl = DolistoreClient::FALLBACK_BASE_URL;
        }
        $insecure = self::globalBool('KNOT_LICENSE_DEV_INSECURE');
        $ttlHours = self::globalInt('KNOT_LICENSE_DOLISTORE_TTL_HOURS', DolistoreValidator::DEFAULT_TTL_HOURS);
        $graceDays = self::globalInt('KNOT_LICENSE_DOLISTORE_OFFLINE_DAYS', OfflineGracePolicy::DEFAULT_GRACE_DAYS);

        $installation = new InstallationIdentity(new KnotConfigRepository($db), $db);

        $releaseKeys = PinnedPublicKeys::releaseSigningKeysHex();

        return new DolistoreValidator(
            new DolistoreClient($baseUrl, 10, $insecure),
            new SignatureVerifier($pinnedKeys),
            self::buildInstanceBinder($db),
            new ForkDetector(OfficialManifestSignatures::map()),
            new LicenseCache(),
            new OfflineGracePolicy($graceDays),
            $ttlHours,
            self::resolveDomain(),
            $installation->deploymentToken(),
            $installation->deploymentNonce(),
            self::buildAuditWriter($db),
            $releaseKeys !== []
                ? new ManifestSignatureVerifier(new SignatureVerifier($releaseKeys))
                : null,
        );
    }

    /**
     * Build a Licensing audit writer wired against the canonical
     * `Knot\Repository\AuditLogRepository` and a SecretMasker pass.
     */
    public static function buildAuditWriter(\DoliDB $db): LicenseAuditWriter
    {
        return new LicenseAuditWriter(new AuditLogRepository($db), new SecretMasker());
    }

    /**
     * Read or lazily generate the per-instance local salt used by
     * {@see InstanceBinder}. The salt is 32 random bytes (256 bits)
     * encoded as hex; it is created once on first call and persisted
     * in `llx_knot_config` (never logged).
     */
    public static function buildInstanceBinder(\DoliDB $db): InstanceBinder
    {
        $salt = self::localSalt($db);
        if (self::docsSeedActive()) {
            $config = new KnotConfigRepository($db);
            $pinned = trim((string) ($config->get(self::CONFIG_KEY_DOCS_PINNED_FINGERPRINT) ?? ''));
            if ($pinned !== '') {
                return InstanceBinder::withPinnedFingerprint($pinned, $salt);
            }
        }

        return new InstanceBinder(
            self::resolveSocieteName(),
            self::resolveDolUrlRoot(),
            $salt,
        );
    }

    private static function docsSeedActive(): bool
    {
        if (!function_exists('getDolGlobalString')) {
            return false;
        }

        return trim((string) getDolGlobalString('KNOT_DOCS_SEED_V1', '')) === '1';
    }

    public static function localSalt(\DoliDB $db): string
    {
        $config = new KnotConfigRepository($db);
        $existing = $config->get(self::CONFIG_KEY_LOCAL_SALT);
        if ($existing !== null && $existing !== '') {
            return $existing;
        }
        $generated = InstanceBinder::generateLocalSalt();
        $config->set(self::CONFIG_KEY_LOCAL_SALT, $generated);
        return $generated;
    }

    private static function resolveSocieteName(): string
    {
        if (function_exists('getDolGlobalString')) {
            return (string) getDolGlobalString('MAIN_INFO_SOCIETE_NOM', '');
        }
        return '';
    }

    private static function resolveDolUrlRoot(): string
    {
        if (defined('DOL_URL_ROOT')) {
            return (string) constant('DOL_URL_ROOT');
        }
        if (function_exists('getDolGlobalString')) {
            return (string) getDolGlobalString('DOL_URL_ROOT', '');
        }
        return '';
    }

    private static function resolveDomain(): ?string
    {
        if (isset($_SERVER['HTTP_HOST']) && is_string($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== '') {
            return (string) $_SERVER['HTTP_HOST'];
        }
        return null;
    }

    private static function globalInt(string $key, int $default): int
    {
        if (function_exists('getDolGlobalInt')) {
            return getDolGlobalInt($key, $default);
        }
        if (function_exists('getDolGlobalString')) {
            $raw = (string) getDolGlobalString($key, '');
            if ($raw !== '' && ctype_digit(ltrim($raw, '-'))) {
                return (int) $raw;
            }
        }
        return $default;
    }

    private static function globalBool(string $key): bool
    {
        if (function_exists('getDolGlobalString')) {
            $raw = strtolower(trim((string) getDolGlobalString($key, '')));
            return in_array($raw, ['1', 'true', 'yes', 'on'], true);
        }
        return false;
    }
}
