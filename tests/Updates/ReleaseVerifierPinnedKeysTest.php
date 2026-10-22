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

        $zip = tempnam(sys_get_temp_dir(), 'knot-kid-');
        self::assertIsString($zip);
        file_put_contents($zip, 'payload');
        $harness = new Ed25519TestHarness();
        $payload = [
            'channel' => 'beta',
            'product_slug' => 'knot-migration',
            'version' => '0.21.4',
            'zip_sha256' => hash('sha256', 'payload'),
        ];
        $canonical = SignatureVerifier::canonicalize($payload);
        $sig = sodium_bin2hex(sodium_crypto_sign_detached($canonical, $harness->secretKey()));

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('verification failed');
            ReleaseVerifier::assertExtensionRelease($zip, $payload, $sig, 'knot-migration', '0.21.4', 'beta');
        } finally {
            @unlink($zip);
        }
    }
}
