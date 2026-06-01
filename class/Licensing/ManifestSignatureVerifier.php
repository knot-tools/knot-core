<?php

/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

namespace Knot\Licensing;

/**
 * Verifies the Ed25519 manifest signature shipped in extension manifests.
 *
 * The release signing key ({@see PinnedPublicKeys::releaseSigningKeysHex()})
 * signs the canonical JSON of the manifest with `license.manifestSignature`
 * removed — same algorithm as `license/bin/sign_manifest.php`.
 *
 * This replaces digest pinning as the primary anti-fork gate: any manifest
 * signed by the editor release key is accepted without bumping Core.
 */
final class ManifestSignatureVerifier
{
    public function __construct(
        private readonly SignatureVerifier $releaseSignatureVerifier,
    ) {
    }

    /**
     * @param array<string, mixed> $manifest Full installed manifest (including signature).
     */
    public function verify(array $manifest): bool
    {
        $license = $manifest['license'] ?? null;
        if (!is_array($license)) {
            return false;
        }

        $signature = isset($license['manifestSignature'])
            ? strtolower(trim((string) $license['manifestSignature']))
            : '';
        if ($signature === '' || !preg_match('/^[a-f0-9]{128}$/', $signature)) {
            return false;
        }

        $message = self::canonicalMessage($manifest, stripSignature: true);

        return $this->releaseSignatureVerifier->verify($message, $signature);
    }

    /**
     * Build the canonical signed payload bytes for a manifest.
     *
     * @param array<string, mixed> $manifest
     */
    public static function canonicalMessage(array $manifest, bool $stripSignature = true): string
    {
        $payload = $manifest;
        if ($stripSignature && isset($payload['license']) && is_array($payload['license'])) {
            unset($payload['license']['manifestSignature']);
        }

        return (string) json_encode(
            self::canonicalise($payload),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    /**
     * Recursively sort associative keys (lists keep order) — matches sign_manifest.php.
     *
     * @param mixed $value
     * @return mixed
     */
    public static function canonicalise(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $isList = array_keys($value) === range(0, count($value) - 1);
        if ($isList) {
            return array_map(
                static fn (mixed $item): mixed => self::canonicalise($item),
                $value,
            );
        }

        ksort($value);

        return array_map(
            static fn (mixed $item): mixed => self::canonicalise($item),
            $value,
        );
    }
}
