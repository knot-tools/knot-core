<?php

declare(strict_types=1);

namespace Knot\Tests\Credentials;

use Knot\Credentials\CredentialCipher;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CredentialCipherTest extends TestCase
{
    private CredentialCipher $cipher;

    protected function setUp(): void
    {
        $this->cipher = new CredentialCipher('unit-test-instance-secret', 'unit-test-salt');
    }

    public function testEncryptDecryptRoundTripPreservesSecrets(): void
    {
        $plain = [
            'secrets' => ['api_key' => 'sk_test_replace_me', 'header' => 'Bearer x'],
            'meta' => ['label' => 'SMTP'],
        ];

        $encrypted = $this->cipher->encrypt($plain);
        self::assertSame('1', $encrypted['version']);
        self::assertNotSame('', $encrypted['iv']);
        self::assertNotSame('', $encrypted['tag']);
        self::assertNotSame('', $encrypted['data']);

        $decoded = $this->cipher->decrypt($encrypted);
        self::assertSame($plain, $decoded);
    }

    public function testDecryptRejectsInvalidBase64Fields(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid encrypted credential payload.');
        $this->cipher->decrypt(['iv' => '!!!', 'tag' => '!!!', 'data' => '!!!']);
    }

    public function testDecryptRejectsTamperedCipherText(): void
    {
        $encrypted = $this->cipher->encrypt(['secrets' => ['k' => 'v']]);
        $data = base64_decode($encrypted['data'], true);
        self::assertIsString($data);
        $encrypted['data'] = base64_encode($data . 'x');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to decrypt credential payload.');
        $this->cipher->decrypt($encrypted);
    }

    public function testDifferentSaltsProduceDistinctCipherTexts(): void
    {
        $payload = ['secrets' => ['token' => 'same']];
        $a = (new CredentialCipher('secret-a', 'salt-a'))->encrypt($payload);
        $b = (new CredentialCipher('secret-a', 'salt-b'))->encrypt($payload);
        self::assertNotSame($a['data'], $b['data']);
    }
}
