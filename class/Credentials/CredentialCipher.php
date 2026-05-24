<?php

declare(strict_types=1);

namespace Knot\Credentials;

use RuntimeException;

/**
 * AES-256-GCM encryption helper for credentials.
 */
final class CredentialCipher
{
    private const CIPHER = 'aes-256-gcm';

    public function __construct(private readonly string $instanceSecret, private readonly string $salt)
    {
    }

    /**
     * Encrypt a payload.
     *
     * @param array<string, mixed> $payload Plain payload
     * @return array<string, string>
     */
    public function encrypt(array $payload): array
    {
        $iv = random_bytes(12);
        $tag = '';
        $plain = json_encode($payload, JSON_THROW_ON_ERROR);
        $cipherText = openssl_encrypt($plain, self::CIPHER, $this->key(), OPENSSL_RAW_DATA, $iv, $tag);

        if ($cipherText === false) {
            throw new RuntimeException('Unable to encrypt credential payload.');
        }

        return [
            'version' => '1',
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
            'data' => base64_encode($cipherText),
        ];
    }

    /**
     * Decrypt a payload.
     *
     * @param array<string, string> $payload Encrypted payload
     * @return array<string, mixed>
     */
    public function decrypt(array $payload): array
    {
        $iv = base64_decode($payload['iv'] ?? '', true);
        $tag = base64_decode($payload['tag'] ?? '', true);
        $data = base64_decode($payload['data'] ?? '', true);

        if ($iv === false || $tag === false || $data === false) {
            throw new RuntimeException('Invalid encrypted credential payload.');
        }

        $plain = openssl_decrypt($data, self::CIPHER, $this->key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) {
            throw new RuntimeException('Unable to decrypt credential payload.');
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($plain, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    private function key(): string
    {
        return hash('sha256', $this->instanceSecret . ':' . $this->salt, true);
    }
}
