<?php

/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

namespace Knot\Licensing;

use Knot\KnownSkus;

/**
 * Pinned map `extensionId => manifestSignature` for official Knot extensions.
 *
 * Used by {@see ForkDetector} to flag third-party packs that try to
 * impersonate an official extension by reusing its `id`. When an
 * installed manifest declares an `id` listed here but its signature
 * does not match, Knot Core marks the licence as `tampered`, refuses
 * to load the extension, and writes a {@see Audit\LicenseAuditEvent::FORK_DETECTED}
 * audit entry.
 *
 * Each extension maps to an ordered list of 128-hex Ed25519 digests:
 * index {@see self::PRIMARY_INDEX} is the current official manifest;
 * further entries are deprecated transition pins kept for
 * {@see self::TRANSITION_RETENTION_DAYS} after a manifest re-sign so
 * operators can roll out Core before every client has pulled the new ZIP.
 *
 * Operator runbook: {@see docs/runbooks/extension-manifest-release.md}
 *
 * ```bash
 * ssh knot-license-vm
 * cd /var/www/vhosts/license.knot.tools/license-app
 * php bin/sign_manifest.php /path/to/knot-extension.json
 * ```
 *
 * Then sync with {@see core/scripts/sync_extension_manifest_signature.py}.
 */
final class OfficialManifestSignatures
{
    public const PRIMARY_INDEX = 0;

    /** Deprecated pins should be removed after this many days. */
    public const TRANSITION_RETENTION_DAYS = 90;

    /**
     * @return array<string, list<non-empty-string>> Ordered digests per extension
     *                                              (primary first, then deprecated).
     */
    public static function map(): array
    {
        return [
            KnownSkus::PRO_PACK => [
                '1e6f32da1704926175928048d68dfdea768a1005ae3f140ad45aeff0461509d12c66c5b4404e2bd3990adb439ab5e6b98d0185629a4cc4e390e5ed2deafae904',
                // Deprecated transition pin — remove after 90 days.
                '75883bb3ae23e2e1dfbd917a695e296e42c431e03bb01a0fb2cf933de35334dcb14740619fd189d45eaa70bb9e319aa99507e5a79a65b0eadc92cd549199e40f',
                // Deprecated transition pin — remove after 90 days.
                '8f2e6cb7d1531425c67069189a1a604b5eea883eb5621d74e0de24f08179b255a0ee0e54185c09c732fa0948fe50764075822eea0d685f124632b42ff4d93604',
            ],
            KnownSkus::MIGRATION => [
                '8f2e6cb7d1531425c67069189a1a604b5eea883eb5621d74e0de24f08179b255a0ee0e54185c09c732fa0948fe50764075822eea0d685f124632b42ff4d93604',
                // Deprecated transition pin — remove after 90 days.
                'f3b3c142034771bb386b1b02a77dddf707c415e198b0901a27fb3c3723af7fd8e7c1d3bc9aa5b125fcd2bc40d99a728ad6c2e244e9b95398d35ca27d69a9900f',
            ],
        ];
    }

    /**
     * Primary digest per extension — must match shipped `knot-extension.json`.
     *
     * @return array<string, non-empty-string>
     */
    public static function primaryMap(): array
    {
        $primary = [];
        foreach (self::map() as $extensionId => $digests) {
            if ($digests === []) {
                continue;
            }
            $primary[$extensionId] = $digests[self::PRIMARY_INDEX];
        }

        return $primary;
    }
}
