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
 * Mandatory SHA-256 checksum. Detached Ed25519 signature policy:
 * - Commercial extensions: always mandatory.
 * - Knot Core: mandatory from {@see CORE_SIGNATURE_MANDATORY_FROM}; older
 *   releases tolerate an empty signature_hex for retrocompat.
 */
final class ReleaseVerifier
{
    public const CORE_SIGNATURE_MANDATORY_FROM = '2.13.4';

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
     * Extension releases must always ship a valid detached signature.
     *
     * @param array<string, mixed>|null $payloadForSigning
     *
     * @throws RuntimeException
     */
    public static function assertExtensionReleaseSignature(?array $payloadForSigning, ?string $signatureHex): void
    {
        self::assertMandatoryDetachedSignature($payloadForSigning, $signatureHex);
    }

    /**
     * Core release signature requirement depends on the target version being applied.
     *
     * @param array<string, mixed>|null $payloadForSigning
     *
     * @throws RuntimeException
     */
    public static function assertCoreReleaseSignature(
        string $targetVersion,
        ?array $payloadForSigning,
        ?string $signatureHex,
    ): void {
        if (self::coreSignatureMandatoryForVersion($targetVersion)) {
            self::assertMandatoryDetachedSignature($payloadForSigning, $signatureHex);

            return;
        }

        self::assertOptionalDetachedSignature($payloadForSigning, $signatureHex);
    }

    public static function coreSignatureMandatoryForVersion(string $targetVersion): bool
    {
        $version = trim($targetVersion);
        if ($version === '') {
            return false;
        }

        return version_compare($version, self::CORE_SIGNATURE_MANDATORY_FROM, '>=');
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

        self::verifyDetachedSignature($payloadForSigning, $sig);
    }

    /**
     * @param array<string, mixed>|null $payloadForSigning
     *
     * @throws RuntimeException
     */
    private static function assertMandatoryDetachedSignature(?array $payloadForSigning, ?string $signatureHex): void
    {
        $sig = trim((string) $signatureHex);
        if ($sig === '') {
            throw new RuntimeException('Release signature is required but signature_hex is missing.');
        }

        self::verifyDetachedSignature($payloadForSigning, $sig);
    }

    /**
     * @param array<string, mixed>|null $payloadForSigning
     *
     * @throws RuntimeException
     */
    private static function verifyDetachedSignature(?array $payloadForSigning, string $signatureHex): void
    {
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
        if (!$verifier->verify($canonical, $signatureHex)) {
            throw new RuntimeException('Release detached signature verification failed.');
        }
    }
}
