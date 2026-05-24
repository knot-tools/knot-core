<?php

declare(strict_types=1);

namespace Knot\Tests\Security;

use Knot\Security\SecretMasker;
use PHPUnit\Framework\TestCase;

final class SecretMaskerTest extends TestCase
{
    private SecretMasker $masker;

    protected function setUp(): void
    {
        $this->masker = new SecretMasker();
    }

    public function testMasksKnownSensitiveKeys(): void
    {
        $input = [
            'username' => 'alice',
            'password' => 'super-secret',
            'token' => 'abc.def.ghi',
            'api_key' => 'sk-XYZ',
            'apiKey' => 'masked-because-normalized-to-apikey',
            'authorization' => 'Bearer xxx',
        ];
        $masked = $this->masker->maskArray($input);

        self::assertSame('alice', $masked['username']);
        self::assertSame('********', $masked['password']);
        self::assertSame('********', $masked['token']);
        self::assertSame('********', $masked['api_key']);
        self::assertSame('********', $masked['apiKey']);
        self::assertSame('********', $masked['authorization']);
    }

    public function testMasksRecursively(): void
    {
        $masked = $this->masker->maskArray([
            'config' => [
                'secret' => 'leaky',
                'nested' => ['private_key' => 'rsa', 'safe' => 'public'],
            ],
        ]);
        self::assertSame('********', $masked['config']['secret']);
        self::assertSame('********', $masked['config']['nested']['private_key']);
        self::assertSame('public', $masked['config']['nested']['safe']);
    }

    public function testReturnsArrayUnchangedWhenNoSecretKeys(): void
    {
        $input = ['a' => 1, 'b' => ['c' => 2]];
        self::assertSame($input, $this->masker->maskArray($input));
    }

    public function testMasksBearerTokenValuePattern(): void
    {
        $masked = $this->masker->maskString('curl -H "Authorization: Bearer eyJabc.def.ghi" https://api');
        self::assertStringContainsString('********', $masked);
        self::assertStringNotContainsString('eyJabc', $masked);
    }

    public function testMasksAwsAccessKeyValuePattern(): void
    {
        $masked = $this->masker->maskString('export AWS_KEY=AKIAIOSFODNN7EXAMPLE');
        self::assertStringContainsString('********', $masked);
        self::assertStringNotContainsString('AKIAIOSFODNN7EXAMPLE', $masked);
    }

    public function testMasksSlackTokenValuePattern(): void
    {
        $masked = $this->masker->maskString('xoxb-1234567890-abcdefghijkl');
        self::assertSame('********', trim($masked));
    }

    public function testMasksStripeKeyValuePattern(): void
    {
        $masked = $this->masker->maskString('SK = sk_live_abcdefghijklmnop');
        self::assertStringContainsString('********', $masked);
        self::assertStringNotContainsString('sk_live_abcdefghijklmnop', $masked);
    }

    public function testMasksGithubPatValuePattern(): void
    {
        $masked = $this->masker->maskString('token: ghp_1234567890123456789012345678901234567890');
        self::assertStringContainsString('********', $masked);
        self::assertStringNotContainsString('ghp_1234567890', $masked);
    }

    public function testMasksJwtPattern(): void
    {
        $jwt = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NSJ9.SflKxwRJSMeKKF2QT4fwpMeJf36POk6yJV_adQssw5c';
        $masked = $this->masker->maskString("token=$jwt");
        self::assertStringContainsString('********', $masked);
        self::assertStringNotContainsString('eyJhbGciOiJIUzI1NiI', $masked);
    }

    public function testMasksValueInsideArray(): void
    {
        $masked = $this->masker->maskArray([
            'log' => 'caught: Bearer eyJabc.def.ghi',
        ]);
        self::assertStringContainsString('********', $masked['log']);
    }

    public function testMasksDeploymentNonceKeyedPayloads(): void
    {
        $masked = $this->masker->maskArray([
            'deployment_nonce' => '00000000-0000-4000-8000-000000000001',
        ]);
        self::assertSame(SecretMasker::REDACTED, $masked['deployment_nonce']);
    }

    public function testMasksDeploymentTokenKeyedPayloads(): void
    {
        $masked = $this->masker->maskArray([
            'deployment_token' => str_repeat('a', 64),
        ]);
        self::assertSame(SecretMasker::REDACTED, $masked['deployment_token']);
    }

    public function testNonSensitiveValuesArePreserved(): void
    {
        self::assertSame('hello world', $this->masker->maskString('hello world'));
    }

    public function testMaskStringReturnsEmptyStringUnchanged(): void
    {
        self::assertSame('', $this->masker->maskString(''));
    }

    public function testIgnoresEmptyNormalisedKeyNames(): void
    {
        $masked = $this->masker->maskArray([
            '   ' => 'visible',
        ]);
        self::assertSame('visible', $masked['   ']);
    }

    public function testNormalisedKeyMatchingHandlesCamelCaseAndPunctuation(): void
    {
        $masked = $this->masker->maskArray([
            'AccessTokenV2' => 'leaky',
            'webhook-secret' => 'leaky',
            'CLIENT_SECRET' => 'leaky',
        ]);
        self::assertSame('********', $masked['AccessTokenV2']);
        self::assertSame('********', $masked['webhook-secret']);
        self::assertSame('********', $masked['CLIENT_SECRET']);
    }
}
