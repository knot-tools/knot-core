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
 * further entries are transition pins (deprecated but still accepted) for
 * {@see self::TRANSITION_RETENTION_DAYS} after a re-sign.
 *
 * When adding a transition pin, it MUST be the digest that was primary
 * immediately before the release (the manifestSignature shipped with the
 * previous extension version). See docs/runbooks/extension-manifest-release.md
 * § « Transition pins » and incident 2026-05-25.
 *
 * Prefer `scripts/sync_extension_manifest_signature.py` without
 * `--deprecate-previous` so the former primary is picked automatically.
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
                '1d9680af068768471e965434e53096c1dcd263aca6b1e1e5037eaffa8add54d588a43d6b150c9e4e032d9f5fcce28c1fa29f358a0c8a891666be9b34b00a740c',
                // 0.1.4 official manifest — keep through transition window.
                '1e6f32da1704926175928048d68dfdea768a1005ae3f140ad45aeff0461509d12c66c5b4404e2bd3990adb439ab5e6b98d0185629a4cc4e390e5ed2deafae904',
                // Deprecated transition pin — remove after 90 days.
                '8b6f6a43b3b25a1a19f7ec923680d1c363500d07073fe995d2d85c9fde5e011d53ba943cdbadace16d67f25ef91855f59b0884bb73167ce4f4bdeae21b4db60a',
            ],
            KnownSkus::MIGRATION => [
                'c2e45f8fa59834c1c88f18b6f52df63f1a643f02bdaa32afcc143893b8e9cbd50275f6e60760af973f306b8d02707d8c6338389b0814893e6a57fd09a5c1d30b',
                // 0.21.4 official manifest — keep through transition window.
                '8f2e6cb7d1531425c67069189a1a604b5eea883eb5621d74e0de24f08179b255a0ee0e54185c09c732fa0948fe50764075822eea0d685f124632b42ff4d93604',
                // Deprecated transition pin — remove after 90 days.
                '73cfbad75b9e061bff3fd73a29d75fd9e8eb9a6f9a4a8efc85dfa3eb433c2134b891f01465680975c2e7ef2d250015137299735e1691ed8ff66cdcf2c5392d00',
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
