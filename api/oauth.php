<?php
/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

if (!defined('NOCSRFCHECK')) { define('NOCSRFCHECK', '1'); }
if (!defined('NOTOKENRENEWAL')) { define('NOTOKENRENEWAL', '1'); }

require '../../../main.inc.php';
dol_include_once('/knot/class/autoload.php');

use Knot\Api\JsonResponse;
use Knot\Security\OAuth2Helper;

JsonResponse::installFatalHandler();

if (!$user->hasRight('knot', 'credential', 'manage')) {
    JsonResponse::error('permission_denied', 'Permission denied', 403);
    exit;
}

$action = (string) GETPOST('action', 'alphanohtml');

if ($action === 'providers') {
    JsonResponse::success(['providers' => listProviders()]);
    exit;
}

if ($action === 'start') {
    $provider = providerDefinition((string) GETPOST('provider', 'alphanohtml'));
    if ($provider === null) {
        JsonResponse::error('unknown_provider', 'Unknown OAuth provider.', 400);
        exit;
    }

    $clientId = (string) GETPOST('client_id', 'nohtml');
    $redirectUri = (string) GETPOST('redirect_uri', 'nohtml');
    $scopesParam = (string) GETPOST('scopes', 'nohtml');
    $scopes = $scopesParam !== ''
        ? array_values(array_filter(array_map('trim', explode(',', $scopesParam))))
        : (array) ($provider['default_scopes'] ?? []);
    if ($clientId === '' || $redirectUri === '' || $scopes === []) {
        JsonResponse::error('validation_failed', 'client_id, redirect_uri and scopes are required.', 400);
        exit;
    }

    $state = bin2hex(random_bytes(16));
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    $_SESSION['knot_oauth_state'] = [
        'state' => $state,
        'provider' => (string) $provider['id'],
        'created_at' => time(),
    ];

    $helper = new OAuth2Helper();
    JsonResponse::success([
        'authorizationUrl' => $helper->buildAuthorizationUrl($provider, $clientId, $redirectUri, $scopes, $state),
        'state' => $state,
        'provider' => $provider['id'],
    ]);
    exit;
}

if ($action === 'callback') {
    $clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $oauthLimiter = new \Knot\Security\RateLimiter($db);
    if (!$oauthLimiter->consume('oauth_cb:' . $clientIp, 10)) {
        header('Retry-After: ' . $oauthLimiter->retryAfterSeconds());
        JsonResponse::error('rate_limited', 'Too many requests.', 429);
        exit;
    }
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    $state = (string) GETPOST('state', 'nohtml');
    $code = (string) GETPOST('code', 'nohtml');
    $sessionState = is_array($_SESSION['knot_oauth_state'] ?? null) ? $_SESSION['knot_oauth_state'] : [];

    if ($state === '' || $code === '' || !hash_equals((string) ($sessionState['state'] ?? ''), $state)) {
        JsonResponse::error('oauth_state_invalid', 'OAuth state is invalid or expired.', 403);
        exit;
    }

    JsonResponse::success([
        'code' => $code,
        'provider' => (string) ($sessionState['provider'] ?? ''),
        'message' => 'OAuth callback received. Save the code in a credential to exchange it server-side.',
    ]);
    exit;
}

JsonResponse::error('method_not_allowed', 'Unsupported OAuth action.', 405);

/**
 * @return array<int, array<string, mixed>>
 */
function listProviders(): array
{
    $providers = [];
    foreach (['google', 'stripe', 'shopify', 'notion', 'github', 'gitlab', 'slack'] as $key) {
        $def = providerDefinition($key);
        if ($def !== null) {
            $providers[] = [
                'id' => $def['id'],
                'label' => $def['label'],
                'authorizationEndpoint' => $def['authorization_endpoint'] ?? null,
                'defaultScopes' => $def['default_scopes'] ?? [],
                'docsUrl' => $def['docs_url'] ?? null,
                'icon' => $def['icon'] ?? null,
            ];
        }
    }
    return $providers;
}

/**
 * @return array<string, mixed>|null
 */
function providerDefinition(string $provider): ?array
{
    return match ($provider) {
        'google' => [
            'id' => 'google',
            'label' => 'Google Workspace',
            'authorization_endpoint' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token_endpoint' => 'https://oauth2.googleapis.com/token',
            'default_scopes' => [
                'https://www.googleapis.com/auth/gmail.send',
                'https://www.googleapis.com/auth/spreadsheets',
                'https://www.googleapis.com/auth/drive.file',
                'https://www.googleapis.com/auth/calendar',
            ],
            'docs_url' => 'https://developers.google.com/identity/protocols/oauth2',
            'icon' => 'google',
        ],
        'stripe' => [
            'id' => 'stripe',
            'label' => 'Stripe',
            'authorization_endpoint' => 'https://connect.stripe.com/oauth/authorize',
            'token_endpoint' => 'https://connect.stripe.com/oauth/token',
            'default_scopes' => ['read_write'],
            'docs_url' => 'https://stripe.com/docs/connect/oauth-reference',
            'icon' => 'credit-card',
        ],
        'shopify' => [
            'id' => 'shopify',
            'label' => 'Shopify',
            // Shop name is needed; redirect URL must be: https://{shop}.myshopify.com/admin/oauth/authorize
            'authorization_endpoint' => 'https://{shop}.myshopify.com/admin/oauth/authorize',
            'token_endpoint' => 'https://{shop}.myshopify.com/admin/oauth/access_token',
            'default_scopes' => ['read_orders', 'write_orders', 'read_products'],
            'docs_url' => 'https://shopify.dev/docs/apps/auth/oauth',
            'icon' => 'shopping-cart',
        ],
        'notion' => [
            'id' => 'notion',
            'label' => 'Notion',
            'authorization_endpoint' => 'https://api.notion.com/v1/oauth/authorize',
            'token_endpoint' => 'https://api.notion.com/v1/oauth/token',
            'default_scopes' => [],
            'docs_url' => 'https://developers.notion.com/docs/authorization',
            'icon' => 'file-text',
        ],
        'github' => [
            'id' => 'github',
            'label' => 'GitHub',
            'authorization_endpoint' => 'https://github.com/login/oauth/authorize',
            'token_endpoint' => 'https://github.com/login/oauth/access_token',
            'default_scopes' => ['repo', 'read:user'],
            'docs_url' => 'https://docs.github.com/en/apps/oauth-apps/building-oauth-apps',
            'icon' => 'github',
        ],
        'gitlab' => [
            'id' => 'gitlab',
            'label' => 'GitLab',
            'authorization_endpoint' => 'https://gitlab.com/oauth/authorize',
            'token_endpoint' => 'https://gitlab.com/oauth/token',
            'default_scopes' => ['api', 'read_user'],
            'docs_url' => 'https://docs.gitlab.com/ee/api/oauth2.html',
            'icon' => 'gitlab',
        ],
        'slack' => [
            'id' => 'slack',
            'label' => 'Slack',
            'authorization_endpoint' => 'https://slack.com/oauth/v2/authorize',
            'token_endpoint' => 'https://slack.com/api/oauth.v2.access',
            'default_scopes' => ['chat:write', 'channels:read'],
            'docs_url' => 'https://api.slack.com/authentication/oauth-v2',
            'icon' => 'message-square',
        ],
        default => null,
    };
}
