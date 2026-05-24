<?php

declare(strict_types=1);

namespace Knot\Tests\Updates;

use Knot\Licensing\SignatureVerifier;
use Knot\Tests\Licensing\Support\Ed25519TestHarness;
use Knot\Updates\ReleaseVerifier;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \Knot\Updates\ReleaseVerifier
 */
final class ReleaseVerifierPinnedKeysTest extends TestCase
{
    public function testRejectsSignatureWithUnknownKid(): void
    {
        if (!extension_loaded('sodium')) {
            self::markTestSkipped('sodium extension required');
        }

        $harness = new Ed25519TestHarness();
        $payload = [
            'channel' => 'beta',
            'product_slug' => 'knot-migration',
            'version' => '0.21.4',
            'zip_sha256' => str_repeat('d', 64),
        ];
        $canonical = SignatureVerifier::canonicalize($payload);
        $sig = sodium_bin2hex(sodium_crypto_sign_detached($canonical, $harness->secretKey()));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('verification failed');
        ReleaseVerifier::assertExtensionReleaseSignature($payload, $sig);
    }

    public function testCoreSignatureMandatoryFromConstant(): void
    {
        self::assertTrue(ReleaseVerifier::coreSignatureMandatoryForVersion('2.13.4'));
        self::assertFalse(ReleaseVerifier::coreSignatureMandatoryForVersion('2.13.3'));
    }
}
