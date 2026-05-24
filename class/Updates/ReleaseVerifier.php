<?php

/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

namespace Knot\Updates;

use Knot\Licensing\PinnedPublicKeys;
use Knot\Licensing\SignatureVerifier;
use RuntimeException;

/**
 * Validates update artefacts downloaded as ZIP bytes on disk before install.
 *
 * Mandatory SHA-256 checksum. Optional detached Ed25519 signature over an
 * operator-supplied canonical JSON payload (matching the Knot release pipeline).
 */
final class ReleaseVerifier
{
    /**
     * @throws RuntimeException when the checksum does not match or the file cannot be hashed.
     */
    public static function assertZipSha256(string $absoluteZipPath, string $expectedSha256Hex): void
    {
        $norm = strtolower(trim($expectedSha256Hex));
        if ($norm === '') {
            return;
        }
        if (
            strlen($norm) !== 64
            || !ctype_xdigit($norm)
        ) {
            throw new RuntimeException('Invalid expected zip_sha256 (must be empty or 64 hex chars).');
        }
        $hash = hash_file('sha256', $absoluteZipPath);
        if ($hash === false) {
            throw new RuntimeException('Cannot compute SHA-256 hash of ZIP payload.');
        }
        if (!hash_equals($norm, strtolower($hash))) {
            throw new RuntimeException('ZIP checksum mismatch — refusing install.');
        }
    }

    /**
     * Validates a detached signature when {@see $signatureHex} is provided.
     *
     * When signature payload is omitted (null) OR signature hex missing, this is a no-op
     * so GPL GitHub artefacts can defer signing until CI is wired end-to-end.
     *
     * @param array<string, mixed>|null $payloadForSigning
     *
     * @throws RuntimeException on invalid signature inputs.
     */
    public static function assertOptionalDetachedSignature(?array $payloadForSigning, ?string $signatureHex): void
    {
        $sig = trim((string) $signatureHex);
        if ($sig === '') {
            return;
        }

        if (!extension_loaded('sodium')) {
            throw new RuntimeException('PHP sodium extension is required when signature_hex is supplied.');
        }

        if ($payloadForSigning === null || $payloadForSigning === []) {
            throw new RuntimeException(
                'signature_hex was provided but canonical signing payload metadata is missing.',
            );
        }

        $canonical = SignatureVerifier::canonicalize($payloadForSigning);
        $verifier = new SignatureVerifier(PinnedPublicKeys::releaseSigningKeysHex());
        if (!$verifier->verify($canonical, $sig)) {
            throw new RuntimeException('Release detached signature verification failed.');
        }
    }
}
