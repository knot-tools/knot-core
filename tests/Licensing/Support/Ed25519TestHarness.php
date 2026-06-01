<?php

declare(strict_types=1);

namespace Knot\Tests\Licensing\Support;

use Knot\Licensing\SignatureVerifier;
use RuntimeException;

/**
 * Test harness that provides Ed25519 keypairs and helpers to sign
 * payloads as the Knot licence backend would.
 */
final class Ed25519TestHarness
{
    private string $publicKeyHex;
    private string $secretKey;

    public function __construct()
    {
        if (!extension_loaded('sodium')) {
            throw new RuntimeException('PHP sodium extension required for licensing tests');
        }
        $keyPair = sodium_crypto_sign_keypair();
        $this->secretKey = sodium_crypto_sign_secretkey($keyPair);
        $this->publicKeyHex = sodium_bin2hex(sodium_crypto_sign_publickey($keyPair));
    }

    public function publicKeyHex(): string
    {
        return $this->publicKeyHex;
    }

    public function secretKey(): string
    {
        return $this->secretKey;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function signManifest(array $manifest): string
    {
        $message = \Knot\Licensing\ManifestSignatureVerifier::canonicalMessage($manifest, stripSignature: true);
        $sig = sodium_crypto_sign_detached($message, $this->secretKey);

        return sodium_bin2hex($sig);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function sign(array $payload): string
    {
        $canonical = SignatureVerifier::canonicalize($payload);
        $sig = sodium_crypto_sign_detached($canonical, $this->secretKey);
        return base64_encode($sig);
    }

    /**
     * Return a foreign signature (signed with a different keypair).
     *
     * @param array<string, mixed> $payload
     */
    public function signWithForeignKey(array $payload): string
    {
        $foreignKeyPair = sodium_crypto_sign_keypair();
        $foreignSecret = sodium_crypto_sign_secretkey($foreignKeyPair);
        $canonical = SignatureVerifier::canonicalize($payload);
        $sig = sodium_crypto_sign_detached($canonical, $foreignSecret);
        return base64_encode($sig);
    }
}
