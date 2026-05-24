<?php

declare(strict_types=1);

namespace Knot\Security;

use RuntimeException;

/**
 * Small generic OAuth2 authorization-code helper.
 *
 * Providers are passed as arrays so SaaS connectors can own their exact
 * endpoints/scopes without coupling the helper to Google, Microsoft, etc.
 */
final class OAuth2Helper
{
    public function __construct(private readonly ?HttpClient $httpClient = null)
    {
    }

    /**
     * @param array<string, mixed> $provider
     * @param array<int, string> $scopes
     */
    public function buildAuthorizationUrl(array $provider, string $clientId, string $redirectUri, array $scopes, string $state): string
    {
        $endpoint = (string) ($provider['authorization_endpoint'] ?? '');
        if ($endpoint === '') {
            throw new RuntimeException('OAuth authorization endpoint is missing.');
        }

        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'scope' => implode(' ', $scopes),
            'state' => $state,
            'access_type' => 'offline',
            'prompt' => 'consent',
        ]);

        return $endpoint . (str_contains($endpoint, '?') ? '&' : '?') . $query;
    }

    /**
     * @param array<string, mixed> $provider
     * @return array<string, mixed>
     */
    public function exchangeCode(array $provider, string $clientId, string $clientSecret, string $redirectUri, string $code): array
    {
        return $this->tokenRequest($provider, [
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirectUri,
            'code' => $code,
        ]);
    }

    /**
     * @param array<string, mixed> $provider
     * @return array<string, mixed>
     */
    public function refreshToken(array $provider, string $clientId, string $clientSecret, string $refreshToken): array
    {
        return $this->tokenRequest($provider, [
            'grant_type' => 'refresh_token',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refreshToken,
        ]);
    }

    /**
     * @param array<string, mixed> $provider
     * @param array<string, string> $payload
     * @return array<string, mixed>
     */
    private function tokenRequest(array $provider, array $payload): array
    {
        $endpoint = (string) ($provider['token_endpoint'] ?? '');
        if ($endpoint === '') {
            throw new RuntimeException('OAuth token endpoint is missing.');
        }

        $client = $this->httpClient ?? new HttpClient();
        $response = $client->request(
            'POST',
            $endpoint,
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            http_build_query($payload),
            30
        );

        $json = is_array($response['json'] ?? null) ? $response['json'] : [];
        if ($response['status'] >= 400) {
            throw new RuntimeException('OAuth token request failed.');
        }

        return $json;
    }
}
