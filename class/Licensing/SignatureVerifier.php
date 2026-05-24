<?php

declare(strict_types=1);

namespace Knot\Licensing;

use RuntimeException;

/**
 * Verifies Ed25519 signatures issued by the Knot licence backend.
 *
 * The backend signs every Dolistore-mode response with the editor
 * private key. Knot Core embeds the matching public key (rotated via
 * the manifest signing service). Without a valid signature we treat
 * the payload as `tampered` — this prevents the trivial "intercept the
 * API call with a reverse proxy and return {valid: true}" attack.
 *
 * Algorithm: Ed25519 (libsodium). The PHP Sodium extension ships with
 * the core distribution since PHP 7.2 and is MIT-licensed.
 */
final class SignatureVerifier
{
    /**
     * Hex-encoded public key of the Knot licence backend.
     * Knot Core ships with one or more pinned public keys; rotation
     * happens by adding a new entry and removing the old one.
     *
     * @var array<int, string>
     */
    private array $publicKeys;

    /**
     * @param array<int, string> $publicKeysHex Hex-encoded Ed25519 public keys.
     */
    public function __construct(array $publicKeysHex)
    {
        if ($publicKeysHex === []) {
            throw new RuntimeException('SignatureVerifier requires at least one public key');
        }
        if (!extension_loaded('sodium')) {
            throw new RuntimeException('PHP sodium extension is required for Ed25519 verification');
        }
        foreach ($publicKeysHex as $hex) {
            if (strlen($hex) !== 64 || !ctype_xdigit($hex)) {
                throw new RuntimeException('Public key must be 64 hex characters (32 raw bytes)');
            }
        }
        $this->publicKeys = $publicKeysHex;
    }

    /**
     * Verify a detached signature against a payload.
     *
     * Accepts the detached signature either as lowercase/uppercase hex
     * (128 characters, the wire format used by the Knot licence backend
     * and by `bin/sign_manifest.php`) or as standard base64 (the legacy
     * format documented in early V2.5.0a drafts). Hex is tested first
     * because it is the canonical wire format produced by the backend
     * via `sodium_bin2hex(sodium_crypto_sign_detached(...))`.
     *
     * @param string $payload   Canonicalised JSON payload as bytes.
     * @param string $signature Hex-encoded (128 chars) OR base64-encoded
     *                          detached Ed25519 signature.
     * @return bool True if the signature is valid against any pinned key.
     */
    public function verify(string $payload, string $signature): bool
    {
        $bin = self::decodeSignature($signature);
        if ($bin === null) {
            return false;
        }
        foreach ($this->publicKeys as $hex) {
            $publicKey = sodium_hex2bin($hex);
            try {
                if (sodium_crypto_sign_verify_detached($bin, $payload, $publicKey)) {
                    return true;
                }
            } catch (\SodiumException $_) {
                continue;
            }
        }
        return false;
    }

    /**
     * Decode a signature accepted in either hex (preferred) or base64.
     *
     * Returns the raw 64-byte signature, or null if the input is neither
     * a valid hex string of 128 chars nor a valid base64 string decoding
     * to {@see SODIUM_CRYPTO_SIGN_BYTES} bytes.
     */
    private static function decodeSignature(string $signature): ?string
    {
        $trimmed = trim($signature);
        if ($trimmed === '') {
            return null;
        }
        if (strlen($trimmed) === SODIUM_CRYPTO_SIGN_BYTES * 2 && ctype_xdigit($trimmed)) {
            try {
                $bin = sodium_hex2bin(strtolower($trimmed));
            } catch (\SodiumException $_) {
                $bin = false;
            }
            if (is_string($bin) && strlen($bin) === SODIUM_CRYPTO_SIGN_BYTES) {
                return $bin;
            }
        }
        $bin = base64_decode($trimmed, true);
        if ($bin !== false && strlen($bin) === SODIUM_CRYPTO_SIGN_BYTES) {
            return $bin;
        }
        return null;
    }

    /**
     * Canonicalise a payload before signing or verifying. The order
     * of keys must be deterministic — JSON-encode with sorted keys.
     *
     * @param array<string, mixed> $payload
     */
    public static function canonicalize(array $payload): string
    {
        self::sortRecursive($payload);
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('Failed to canonicalise payload: ' . json_last_error_msg());
        }
        return $json;
    }

    /**
     * @param array<string, mixed> $array
     */
    private static function sortRecursive(array &$array): void
    {
        ksort($array);
        foreach ($array as &$value) {
            if (is_array($value)) {
                self::sortRecursive($value);
            }
        }
    }
}
