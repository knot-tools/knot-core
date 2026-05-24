<?php

declare(strict_types=1);

namespace Knot\Licensing;

use RuntimeException;

/**
 * Encrypts activation codes at rest in LicenseCache (AES-256-GCM).
 * Key material is derived from the Knot local salt + extension id only
 * (never logged).
 */
final class ActivationCodeProtector
{
    private const VERSION = 'knot-act-v1';

    public static function encrypt(string $activationCode, string $localSalt, string $extensionId): string
    {
        $key = self::deriveKey($localSalt, $extensionId);
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt(
            $activationCode,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            self::VERSION,
        );
        if ($cipher === false) {
            throw new RuntimeException('Cannot encrypt activation code for cache');
        }

        return self::VERSION . ':' . base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(string $encoded, string $localSalt, string $extensionId): string
    {
        if (!str_starts_with($encoded, self::VERSION . ':')) {
            throw new RuntimeException('Unsupported activation code cache format');
        }
        $raw = base64_decode(substr($encoded, strlen(self::VERSION) + 1), true);
        if ($raw === false || strlen($raw) < 28) {
            throw new RuntimeException('Corrupt activation code cache blob');
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $key = self::deriveKey($localSalt, $extensionId);
        $plain = openssl_decrypt(
            $cipher,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            self::VERSION,
        );
        if ($plain === false) {
            throw new RuntimeException('Cannot decrypt activation code from cache');
        }

        return $plain;
    }

    private static function deriveKey(string $localSalt, string $extensionId): string
    {
        return hash('sha256', implode('|', [trim($localSalt), trim($extensionId), self::VERSION]), true);
    }
}
