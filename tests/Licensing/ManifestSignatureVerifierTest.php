<?php

declare(strict_types=1);

namespace Knot\Tests\Licensing;

use Knot\Licensing\ManifestSignatureVerifier;
use Knot\Licensing\SignatureVerifier;
use Knot\Tests\Licensing\Support\Ed25519TestHarness;
use PHPUnit\Framework\TestCase;

final class ManifestSignatureVerifierTest extends TestCase
{
    public function testVerifyAcceptsDetachedHexSignatureOnCanonicalManifest(): void
    {
        $harness = new Ed25519TestHarness();
        $manifest = [
            'id' => 'knot-pro-pack',
            'version' => '0.99.0-unlisted',
            'license' => [
                'type' => 'commercial',
                'validation' => 'dolistore',
                'productId' => '12345',
            ],
        ];
        $message = ManifestSignatureVerifier::canonicalMessage($manifest, stripSignature: true);
        $manifest['license']['manifestSignature'] = sodium_bin2hex(
            sodium_crypto_sign_detached($message, $harness->secretKey()),
        );

        $verifier = new ManifestSignatureVerifier(
            new SignatureVerifier([$harness->publicKeyHex()]),
        );

        self::assertTrue($verifier->verify($manifest));
    }

    public function testVerifyRejectsTamperedManifestBody(): void
    {
        $harness = new Ed25519TestHarness();
        $manifest = [
            'id' => 'knot-pro-pack',
            'license' => [
                'type' => 'commercial',
                'validation' => 'dolistore',
                'productId' => '12345',
            ],
        ];
        $message = ManifestSignatureVerifier::canonicalMessage($manifest, stripSignature: true);
        $manifest['license']['manifestSignature'] = sodium_bin2hex(
            sodium_crypto_sign_detached($message, $harness->secretKey()),
        );
        $manifest['license']['productId'] = '99999';

        $verifier = new ManifestSignatureVerifier(
            new SignatureVerifier([$harness->publicKeyHex()]),
        );

        self::assertFalse($verifier->verify($manifest));
    }
}
