<?php

declare(strict_types=1);

namespace Knot\Tests\Licensing;

use Knot\Licensing\SignatureVerifier;
use Knot\Tests\Licensing\Support\Ed25519TestHarness;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SignatureVerifierTest extends TestCase
{
    public function testRejectsWhenConstructedWithoutPublicKeys(): void
    {
        $this->expectException(RuntimeException::class);
        new SignatureVerifier([]);
    }

    public function testRejectsMalformedPublicKey(): void
    {
        $this->expectException(RuntimeException::class);
        new SignatureVerifier(['not-a-valid-hex-key']);
    }

    public function testVerifiesValidSignature(): void
    {
        $harness = new Ed25519TestHarness();
        $verifier = new SignatureVerifier([$harness->publicKeyHex()]);
        $payload = ['extensionId' => 'knot-pro-pack', 'valid' => true];
        $signature = $harness->sign($payload);

        $canonical = SignatureVerifier::canonicalize($payload);
        self::assertTrue($verifier->verify($canonical, $signature));
    }

    public function testRejectsTamperedPayload(): void
    {
        $harness = new Ed25519TestHarness();
        $verifier = new SignatureVerifier([$harness->publicKeyHex()]);
        $signature = $harness->sign(['valid' => true]);
        $tamperedCanonical = SignatureVerifier::canonicalize(['valid' => false]);

        self::assertFalse($verifier->verify($tamperedCanonical, $signature));
    }

    public function testRejectsForeignKeySignature(): void
    {
        $harness = new Ed25519TestHarness();
        $verifier = new SignatureVerifier([$harness->publicKeyHex()]);
        $payload = ['valid' => true];
        $foreignSig = $harness->signWithForeignKey($payload);

        $canonical = SignatureVerifier::canonicalize($payload);
        self::assertFalse($verifier->verify($canonical, $foreignSig));
    }

    public function testRejectsBase64Garbage(): void
    {
        $harness = new Ed25519TestHarness();
        $verifier = new SignatureVerifier([$harness->publicKeyHex()]);
        self::assertFalse($verifier->verify('whatever', '!!not_base64!!'));
    }

    public function testAcceptsHexEncodedSignature(): void
    {
        $harness = new Ed25519TestHarness();
        $verifier = new SignatureVerifier([$harness->publicKeyHex()]);
        $payload = ['extensionId' => 'knot-migration', 'valid' => true];
        $canonical = SignatureVerifier::canonicalize($payload);
        $sigBin = sodium_crypto_sign_detached($canonical, $harness->secretKey());
        $sigHex = sodium_bin2hex($sigBin);

        self::assertTrue($verifier->verify($canonical, $sigHex));
        self::assertTrue($verifier->verify($canonical, strtoupper($sigHex)));
    }

    public function testHexAndBase64AreBothAcceptedForSameSignature(): void
    {
        $harness = new Ed25519TestHarness();
        $verifier = new SignatureVerifier([$harness->publicKeyHex()]);
        $payload = ['x' => 1];
        $canonical = SignatureVerifier::canonicalize($payload);
        $sigBin = sodium_crypto_sign_detached($canonical, $harness->secretKey());

        self::assertTrue($verifier->verify($canonical, sodium_bin2hex($sigBin)));
        self::assertTrue($verifier->verify($canonical, base64_encode($sigBin)));
    }

    public function testCanonicalizationIsKeyOrderIndependent(): void
    {
        $a = SignatureVerifier::canonicalize(['b' => 1, 'a' => 2]);
        $b = SignatureVerifier::canonicalize(['a' => 2, 'b' => 1]);
        self::assertSame($a, $b);
    }

    public function testCanonicalizationRecursesIntoNestedArrays(): void
    {
        $a = SignatureVerifier::canonicalize(['nested' => ['z' => 1, 'a' => 2]]);
        $b = SignatureVerifier::canonicalize(['nested' => ['a' => 2, 'z' => 1]]);
        self::assertSame($a, $b);
    }
}
