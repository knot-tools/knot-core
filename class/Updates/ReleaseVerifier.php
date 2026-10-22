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
 * Every release (Knot Core and commercial extensions) must carry a detached
 * Ed25519 signature whose payload covers the downloaded ZIP (`zip_sha256`)
 * and the requested channel, version and, for extensions, product slug.
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
     * @param array<string, mixed>|null $payloadForSigning
     *
     * @throws RuntimeException
     */
    public static function assertCoreRelease(
        string $absoluteZipPath,
        ?array $payloadForSigning,
        ?string $signatureHex,
        string $version,
        string $channel,
    ): void {
        self::assertSignedRelease($absoluteZipPath, $payloadForSigning, $signatureHex, [
            'channel' => $channel,
            'version' => $version,
        ]);
    }

    /**
     * @param array<string, mixed>|null $payloadForSigning
     *
     * @throws RuntimeException
     */
    public static function assertExtensionRelease(
        string $absoluteZipPath,
        ?array $payloadForSigning,
        ?string $signatureHex,
        string $slug,
        string $version,
        string $channel,
    ): void {
        self::assertSignedRelease($absoluteZipPath, $payloadForSigning, $signatureHex, [
            'channel' => $channel,
            'product_slug' => $slug,
            'version' => $version,
        ]);
    }

    /**
     * @param array<string, mixed>|null $payloadForSigning
     * @param array<string, string> $expected
     *
     * @throws RuntimeException
     */
    private static function assertSignedRelease(
        string $absoluteZipPath,
        ?array $payloadForSigning,
        ?string $signatureHex,
        array $expected,
    ): void {
        $sig = trim((string) $signatureHex);
        if ($sig === '') {
            throw new RuntimeException('Release signature is required but signature_hex is missing.');
        }
        if ($payloadForSigning === null || $payloadForSigning === []) {
            throw new RuntimeException('Release signature payload is missing.');
        }

        $hash = hash_file('sha256', $absoluteZipPath);
        if ($hash === false) {
            throw new RuntimeException('Cannot compute SHA-256 hash of ZIP payload.');
        }
        $signedHash = $payloadForSigning['zip_sha256'] ?? null;
        if (!is_string($signedHash) || !hash_equals(strtolower($hash), strtolower(trim($signedHash)))) {
            throw new RuntimeException('Release signature does not cover the downloaded ZIP.');
        }

        foreach ($expected as $field => $value) {
            $want = strtolower(trim($value));
            $signed = $payloadForSigning[$field] ?? null;
            if ($want === '' || !is_string($signed) || strtolower(trim($signed)) !== $want) {
                throw new RuntimeException(sprintf('Release signature does not match the requested %s.', $field));
            }
        }

        if (!extension_loaded('sodium')) {
            throw new RuntimeException('PHP sodium extension is required to verify the release signature.');
        }
        $canonical = SignatureVerifier::canonicalize($payloadForSigning);
        $verifier = new SignatureVerifier(PinnedPublicKeys::releaseSigningKeysHex());
        if (!$verifier->verify($canonical, $sig)) {
            throw new RuntimeException('Release detached signature verification failed.');
        }
    }
}
