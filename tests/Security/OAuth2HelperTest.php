<?php

declare(strict_types=1);

namespace Knot\Tests\Security;

use Knot\Security\HttpClient;
use Knot\Security\OAuth2Helper;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \Knot\Security\OAuth2Helper
 */
final class OAuth2HelperTest extends TestCase
{
    public function testBuildAuthorizationUrlAppendsQueryParameters(): void
    {
        $helper = new OAuth2Helper();
        $url = $helper->buildAuthorizationUrl(
            ['authorization_endpoint' => 'https://auth.example.com/oauth/authorize'],
            'client-id',
            'https://app.example.com/callback',
            ['openid', 'profile'],
            'state-xyz',
        );

        self::assertStringStartsWith('https://auth.example.com/oauth/authorize?', $url);
        self::assertStringContainsString('client_id=client-id', $url);
        self::assertStringContainsString('scope=openid', $url);
        self::assertStringContainsString('state=state-xyz', $url);
    }

    public function testBuildAuthorizationUrlUsesAmpersandWhenEndpointAlreadyHasQuery(): void
    {
        $helper = new OAuth2Helper();
        $url = $helper->buildAuthorizationUrl(
            ['authorization_endpoint' => 'https://auth.example.com/oauth/authorize?tenant=1'],
            'client-id',
            'https://app.example.com/callback',
            ['email'],
            'state-abc',
        );

        self::assertStringContainsString('authorize?tenant=1&', $url);
    }

    public function testBuildAuthorizationUrlRequiresEndpoint(): void
    {
        $helper = new OAuth2Helper();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OAuth authorization endpoint is missing.');
        $helper->buildAuthorizationUrl([], 'client-id', 'https://app.example.com/callback', [], 'state');
    }

    public function testExchangeCodeReturnsTokenPayload(): void
    {
        $http = $this->createMock(HttpClient::class);
        $http->expects(self::once())
            ->method('request')
            ->with(
                'POST',
                'https://auth.example.com/oauth/token',
                self::anything(),
                self::stringContains('grant_type=authorization_code'),
                30,
            )
            ->willReturn([
                'status' => 200,
                'json' => ['access_token' => 'access-123', 'refresh_token' => 'refresh-456'],
            ]);

        $helper = new OAuth2Helper($http);
        $payload = $helper->exchangeCode(
            ['token_endpoint' => 'https://auth.example.com/oauth/token'],
            'client-id',
            'client-secret',
            'https://app.example.com/callback',
            'auth-code',
        );

        self::assertSame('access-123', $payload['access_token'] ?? null);
    }

    public function testRefreshTokenReturnsTokenPayload(): void
    {
        $http = $this->createMock(HttpClient::class);
        $http->expects(self::once())
            ->method('request')
            ->willReturn([
                'status' => 200,
                'json' => ['access_token' => 'rotated'],
            ]);

        $helper = new OAuth2Helper($http);
        $payload = $helper->refreshToken(
            ['token_endpoint' => 'https://auth.example.com/oauth/token'],
            'client-id',
            'client-secret',
            'refresh-old',
        );

        self::assertSame('rotated', $payload['access_token'] ?? null);
    }

    public function testTokenRequestFailsWhenEndpointMissing(): void
    {
        $helper = new OAuth2Helper();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OAuth token endpoint is missing.');
        $helper->exchangeCode([], 'client-id', 'secret', 'https://app.example.com/callback', 'code');
    }

    public function testTokenRequestFailsOnHttpErrorStatus(): void
    {
        $http = $this->createMock(HttpClient::class);
        $http->method('request')->willReturn(['status' => 401, 'json' => ['error' => 'invalid_grant']]);

        $helper = new OAuth2Helper($http);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OAuth token request failed.');
        $helper->refreshToken(
            ['token_endpoint' => 'https://auth.example.com/oauth/token'],
            'client-id',
            'client-secret',
            'refresh-old',
        );
    }
}
