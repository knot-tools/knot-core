<?php

/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

namespace Knot\Licensing;

/**
 * Source of truth for the Ed25519 public keys pinned by Knot Core.
 *
 * Two distinct keypairs are used to enforce the principle of least
 * privilege between the licence backend and the release-signing pipeline
 * (cf. plan §1.2 — V2.5.0a):
 *
 *  - **License signing key**: signs every licence response from
 *    `license.knot.tools` (`/api/license/check`, `/api/license/activate`).
 *    Used at every refresh by {@see DolistoreValidator} via
 *    {@see SignatureVerifier}.
 *  - **Release signing key**: signs Knot Core / Pro Pack ZIPs published
 *    on GitHub Releases or the licence backend download endpoint. Used
 *    by the V2.5.0b update mechanism.
 *
 * Each key entry exposes structured metadata (`kid`, `purpose`,
 * `publicHex`, `validFrom`, `validUntil`) so {@see SignatureVerifier}
 * can roll over keys deterministically. The bare hex strings consumed
 * by libsodium are surfaced via {@see licenseSigningKeysHex()} and
 * {@see releaseSigningKeysHex()}.
 *
 * Rotation procedure (cf. plan §6) — pin the new public key alongside
 * the old one, wait `KNOT_LICENSE_DOLISTORE_TTL_HOURS +
 * KNOT_LICENSE_DOLISTORE_OFFLINE_DAYS` worth of caches to roll over,
 * then drop the previous entry. Knot Core ships the array in code: a
 * release signing the licences with a new key MUST be matched by a
 * Knot Core release that pins the new public key.
 *
 * Optional override: the legacy `MAIN_KNOT_LICENSE_PUBKEY` Dolibarr
 * constant is honoured when set (single-key dev override). When both
 * the constant AND the embedded array are set, the constant key is
 * **prepended** so the operator can pin a temporary key without
 * re-releasing Knot Core. This is gated by {@see fromConstants()} so
 * tests stay deterministic.
 */
final class PinnedPublicKeys
{
    /**
     * Pinned licence signing keys. Each entry shape:
     *  - `kid` (string)        : key identifier, ex `lic-2026-04`
     *  - `purpose` (string)    : `'license_signing'`
     *  - `publicHex` (string)  : 64-char hex Ed25519 public key
     *  - `validFrom` (string)  : ISO-8601 date (YYYY-MM-DD)
     *  - `validUntil` (?string): ISO-8601 date or null when active indefinitely
     *
     * @var array<int, array{kid: string, purpose: string, publicHex: string, validFrom: string, validUntil: ?string}>
     */
    private const LICENSE_SIGNING_KEYS = [
        [
            // Generated on the production VM (license.knot.tools) on
            // 2026-04-30 at deployment of V2.5.0a; private seed lives only in
            // /var/www/vhosts/license.knot.tools/license-app/.env.
            'kid'        => 'lic-2026-04',
            'purpose'    => 'license_signing',
            'publicHex'  => '61e5593e4bb243ecc08921025ca6855aaad3e399204dcecc0b7f5db47aef1785',
            'validFrom'  => '2026-04-30',
            'validUntil' => null,
        ],
    ];

    /**
     * Pinned release signing keys. Same shape as {@see LICENSE_SIGNING_KEYS}.
     *
     * @var array<int, array{kid: string, purpose: string, publicHex: string, validFrom: string, validUntil: ?string}>
     */
    private const RELEASE_SIGNING_KEYS = [
        [
            // Generated on the production VM (license.knot.tools) on
            // 2026-04-30 at deployment of V2.5.0a; private seed lives only in
            // /var/www/vhosts/license.knot.tools/license-app/.env.
            'kid'        => 'rel-2026-04',
            'purpose'    => 'release_signing',
            'publicHex'  => '628d5479fee21f70088115ea3dba50264d404d5a82782d6d20c781d3d27e188e',
            'validFrom'  => '2026-04-30',
            'validUntil' => null,
        ],
    ];

    /**
     * Return the pinned licence signing keys with full metadata (kid,
     * purpose, validity window). Used by audit/rotation tooling.
     *
     * @return array<int, array{
     *     kid: string,
     *     purpose: string,
     *     publicHex: string,
     *     validFrom: string,
     *     validUntil: ?string
     * }>
     */
    public static function licenseSigningKeys(): array
    {
        return self::LICENSE_SIGNING_KEYS;
    }

    /**
     * Return the pinned release signing keys with full metadata.
     *
     * @return array<int, array{
     *     kid: string,
     *     purpose: string,
     *     publicHex: string,
     *     validFrom: string,
     *     validUntil: ?string
     * }>
     */
    public static function releaseSigningKeys(): array
    {
        return self::RELEASE_SIGNING_KEYS;
    }

    /**
     * Bare hex strings of the pinned licence signing keys, in the order
     * declared. Consumed by {@see SignatureVerifier} (which expects a
     * plain `array<int, string>`).
     *
     * @return array<int, string>
     */
    public static function licenseSigningKeysHex(): array
    {
        return array_map(
            static fn(array $entry): string => $entry['publicHex'],
            self::LICENSE_SIGNING_KEYS,
        );
    }

    /**
     * Bare hex strings of the pinned release signing keys.
     *
     * @return array<int, string>
     */
    public static function releaseSigningKeysHex(): array
    {
        return array_map(
            static fn(array $entry): string => $entry['publicHex'],
            self::RELEASE_SIGNING_KEYS,
        );
    }

    /**
     * Resolve the effective list of licence signing keys (hex strings),
     * optionally prepended with `MAIN_KNOT_LICENSE_PUBKEY` when set.
     *
     * The Bootstrap composer reads this list and installs a no-op
     * DolistoreValidator (or skips the dolistore branch entirely) when
     * empty. Now that V2.5.0a pins a real backend public key, the array
     * is non-empty by default and the manifest-level `validation =
     * dolistore` flag works out of the box.
     *
     * @return array<int, string>
     */
    public static function fromConstants(): array
    {
        $keys = self::licenseSigningKeysHex();
        $override = function_exists('getDolGlobalString')
            ? trim((string) getDolGlobalString('MAIN_KNOT_LICENSE_PUBKEY', ''))
            : '';
        if ($override !== '' && self::isValidHexKey($override)) {
            array_unshift($keys, $override);
        }
        return array_values(array_unique($keys));
    }

    /**
     * Defensive validation of pinned key shape (hex 32 bytes / 64 chars).
     */
    public static function isValidHexKey(string $hex): bool
    {
        return strlen($hex) === 64 && (bool) ctype_xdigit($hex);
    }
}
