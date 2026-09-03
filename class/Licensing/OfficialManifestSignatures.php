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
                // 0.1.11 primary — signed 2026-09-01 (module id 262877).
                'd29cf7d9e6ba6a13a6258c700889c5c0af109c939205c41f01d280a4c53474d47f1d9c845e2c90380ca635963202e25f64d23a50f7fe4119b18e811752a0c703',
                // Deprecated transition pin — 0.1.10 primary (2026-07-14).
                '752cc8c0353bf9a7b17e996684ee5d3e56cf3830394adfe6b2a689cee4694b115ab89c402cfbe7b50ab0217d1932f07a606ad2acbb6444811f4ad6a8f1006500',
                // 0.1.9 — signed 2026-06-08.
                '38a6cc9d3b41eb84a9572d2b928ad3e0f930b8cf6dad65e46763710e5478f75fcc36cea055a6be828db3914347be78dc61d8fb7590e011d5137e1bf6edd18a09',
                // 0.1.8 transition pin — previous primary (2026-06-01).
                'a0c85b69bbbd75985f6b44a57b5ef3453ac383298e149633797b405520a5f6ccb7a5aee7e611195b8facf9684afeeeadea655f7c0ede5e25b784b79bfdd68b06',
                '2779b2d82df05e37171ce7782a7691517f51c1ada9802cd002cd059c49275995bb17af25cd40df331145c113d4ef1002aa70a77430e8fcca7eb642cfde34620c',
                // 0.1.5 official manifest — keep through transition window.
                '1d9680af068768471e965434e53096c1dcd263aca6b1e1e5037eaffa8add54d588a43d6b150c9e4e032d9f5fcce28c1fa29f358a0c8a891666be9b34b00a740c',
                // 0.1.4 official manifest — keep through transition window.
                '1e6f32da1704926175928048d68dfdea768a1005ae3f140ad45aeff0461509d12c66c5b4404e2bd3990adb439ab5e6b98d0185629a4cc4e390e5ed2deafae904',
            ],
            KnownSkus::MIGRATION => [
                // 0.21.10 primary — signed 2026-09-01 (module id 262878, rights 262879–80).
                '60142d805800cae49de79bb1ee735df171ba38e43ac7a0c0e465c7297a06846121214083ed409297fa8c11aab2f1f0694f38cd9e5b1d6219be068802ab07d709',
                // Deprecated transition pin — 0.21.9 primary (2026-07-14).
                '160abd8c22bbdb6680e6696826200cad0098b45984228b2848b0101f6a04ec06168b8f3d5227b555be0c5addd8e46379ce16a108d71a30945360749c19edb102',
                // 0.21.9 previous primary (with Workspace tools entry) — transition.
                'e7582def54c8ef0005a2ce36f2bfec90572878422a194f8132859a62df1ad097ee43fe3fe276e796d5d8a0f44b57444321a047aa25c25e1b7c9210f2d3ec310f',
                // 0.21.8 primary — signed 2026-06-08.
                '8e20dfac66511436a9a1d0128d9af3fb7f559d4069e72745e24a72cd613911d9a8ea0c89ba79d48521355ef515f990ed72e6b28bd11a75da596e07a4de1cb007',
                // 0.21.7 official manifest — transition pin (verified against
                // the signed knotmigration-0.21.7.zip release artefact).
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
