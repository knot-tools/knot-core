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
final class UpdatesApplySignatureBindingTest extends TestCase
{
    public function testApplyExtensionRequiresValidSignature(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('signature_hex is missing');
        ReleaseVerifier::assertExtensionReleaseSignature(
            ['channel' => 'beta', 'product_slug' => 'knot-pro-pack', 'version' => '0.1.4', 'zip_sha256' => str_repeat('a', 64)],
            '',
        );
    }

    public function testApplyCore2134RequiresValidSignature(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('signature_hex is missing');
        ReleaseVerifier::assertCoreReleaseSignature('2.13.4', ['version' => '2.13.4', 'zip_sha256' => str_repeat('b', 64)], '');
    }

    public function testApplyCore2133AcceptsEmptySignature(): void
    {
        ReleaseVerifier::assertCoreReleaseSignature('2.13.3', ['version' => '2.13.3'], '');
        self::addToAssertionCount(1);
    }

    public function testTamperedExtensionSignatureFailsVerification(): void
    {
        if (!extension_loaded('sodium')) {
            self::markTestSkipped('sodium extension required');
        }

        $harness = new Ed25519TestHarness();
        $payload = [
            'channel' => 'beta',
            'product_slug' => 'knot-pro-pack',
            'version' => '0.1.4',
            'zip_sha256' => str_repeat('c', 64),
        ];
        $canonical = SignatureVerifier::canonicalize($payload);
        $sig = sodium_bin2hex(sodium_crypto_sign_detached($canonical, $harness->secretKey()));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('verification failed');
        ReleaseVerifier::assertExtensionReleaseSignature($payload, $sig);
    }
}
