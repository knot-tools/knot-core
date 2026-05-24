<?php
/* Copyright (C) 2026 Knot */

declare(strict_types=1);

// Public endpoint hit by external SaaS (Stripe/Shopify/Meta/Twilio…).
// Bypass Dolibarr login + CSRF; webhook signature verification happens below.
if (!defined('NOLOGIN')) { define('NOLOGIN', '1'); }
if (!defined('NOCSRFCHECK')) { define('NOCSRFCHECK', '1'); }
if (!defined('NOTOKENRENEWAL')) { define('NOTOKENRENEWAL', '1'); }
if (!defined('NOIPCHECK')) { define('NOIPCHECK', '1'); }
if (!defined('NOBROWSERNOTIF')) { define('NOBROWSERNOTIF', '1'); }

require '../../../main.inc.php';
dol_include_once('/knot/class/autoload.php');

use Knot\Api\JsonResponse;
use Knot\Repository\ExecutionRepository;
use Knot\Repository\IdempotencyRepository;
use Knot\Repository\WebhookRepository;
use Knot\Security\RateLimiter;

JsonResponse::installFatalHandler();

$token = GETPOST('token', 'alphanohtml');
if ($token === '') {
    JsonResponse::error('not_found', 'Webhook not found.', 404);
    exit;
}

$webhooks = new WebhookRepository($db);
$webhook = $webhooks->fetchActiveByToken($token);
if ($webhook === null) {
    JsonResponse::error('not_found', 'Webhook not found.', 404);
    exit;
}

$expectedMethod = strtoupper((string) $webhook['method']);
if ($expectedMethod !== strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'))) {
    JsonResponse::error('method_not_allowed', 'Method not allowed.', 405);
    exit;
}

$rateLimitMax = (int) (defined('KNOT_WEBHOOK_RATE_LIMIT') ? KNOT_WEBHOOK_RATE_LIMIT : 60);
if (isset($webhook['rateLimitPerMinute']) && (int) $webhook['rateLimitPerMinute'] > 0) {
    $rateLimitMax = (int) $webhook['rateLimitPerMinute'];
}
$limiter = new RateLimiter($db);
if (!$limiter->consume('webhook:' . (string) $token, $rateLimitMax)) {
    header('Retry-After: ' . $limiter->retryAfterSeconds());
    JsonResponse::error('rate_limited', 'Too many requests.', 429);
    exit;
}

$remoteAddr = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
$ipAllowlist = array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', (string) $webhook['ipAllowlist']) ?: [])));
if ($ipAllowlist !== [] && !ipAllowed($remoteAddr, $ipAllowlist)) {
    JsonResponse::error('ip_forbidden', 'Webhook source IP is not allowed.', 403);
    exit;
}

$rawBody = file_get_contents('php://input') ?: '';
$secretHmac = (string) $webhook['secretHmac'];
if ($secretHmac !== '') {
    $stripeSignature = (string) ($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '');
    $shopifyHmac = (string) ($_SERVER['HTTP_X_SHOPIFY_HMAC_SHA256'] ?? '');
    $whatsappSignature = (string) ($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '');
    if ($stripeSignature !== '' && str_starts_with($secretHmac, 'whsec_')) {
        if (!verifyStripeSignature($rawBody, $stripeSignature, $secretHmac, 300)) {
            JsonResponse::error('signature_invalid', 'Invalid Stripe webhook signature.', 403);
            exit;
        }
    } elseif ($shopifyHmac !== '') {
        if (!verifyShopifySignature($rawBody, $shopifyHmac, $secretHmac)) {
            JsonResponse::error('signature_invalid', 'Invalid Shopify webhook signature.', 403);
            exit;
        }
    } elseif ($whatsappSignature !== '') {
        if (!verifyMetaSignature($rawBody, $whatsappSignature, $secretHmac)) {
            JsonResponse::error('signature_invalid', 'Invalid Meta/WhatsApp webhook signature.', 403);
            exit;
        }
    } else {
        $signature = (string) ($_SERVER['HTTP_X_KNOT_SIGNATURE'] ?? '');
        $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $secretHmac);
        if (!hash_equals($expected, $signature)) {
            JsonResponse::error('signature_invalid', 'Invalid webhook signature.', 403);
            exit;
        }
    }
}

$decoded = json_decode($rawBody, true);
$payload = is_array($decoded) ? $decoded : ['raw' => $rawBody];
$payload['_headers'] = [
    'contentType' => $_SERVER['CONTENT_TYPE'] ?? '',
    'remoteAddr' => $_SERVER['REMOTE_ADDR'] ?? '',
];

$idempotencyKey = (string) ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? $_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? '');
$idempotencyKey = preg_replace('/[^a-zA-Z0-9_.:-]/', '', trim($idempotencyKey)) ?: '';
$idempotency = new IdempotencyRepository($db);
if ($idempotencyKey !== '') {
    $existingExecutionId = $idempotency->findExecution($idempotencyKey, (int) $webhook['entity']);
    if ($existingExecutionId !== null) {
        JsonResponse::success(['executionId' => $existingExecutionId, 'idempotentReplay' => true], 200);
        exit;
    }
    $payload['_idempotencyKey'] = $idempotencyKey;
}

$executionId = (new ExecutionRepository($db))->enqueue(
    (int) $webhook['workflowId'],
    'webhook',
    $payload,
    (int) $webhook['entity']
);

if ($executionId <= 0) {
    JsonResponse::error('queue_failed', 'Unable to queue workflow execution.', 500);
    exit;
}
if ($idempotencyKey !== '') {
    $idempotency->remember($idempotencyKey, $executionId, (int) $webhook['entity']);
}

$webhooks->recordHit((int) $webhook['id']);

JsonResponse::success(['executionId' => $executionId], 202);

/**
 * @param array<int, string> $allowlist
 */
function ipAllowed(string $ip, array $allowlist): bool
{
    foreach ($allowlist as $rule) {
        if ($rule === $ip) {
            return true;
        }
        if (str_contains($rule, '/') && cidrContains($rule, $ip)) {
            return true;
        }
    }
    return false;
}

function verifyStripeSignature(string $payload, string $header, string $secret, int $tolerance): bool
{
    $items = array_filter(array_map('trim', explode(',', $header)));
    $timestamp = null;
    $signatures = [];
    foreach ($items as $item) {
        [$key, $value] = array_pad(explode('=', $item, 2), 2, '');
        if ($key === 't') {
            $timestamp = (int) $value;
        } elseif ($key === 'v1') {
            $signatures[] = $value;
        }
    }
    if ($timestamp === null || $signatures === []) {
        return false;
    }
    if ($tolerance > 0 && abs(time() - $timestamp) > $tolerance) {
        return false;
    }
    $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    foreach ($signatures as $sig) {
        if (hash_equals($expected, $sig)) {
            return true;
        }
    }
    return false;
}

function verifyShopifySignature(string $payload, string $header, string $secret): bool
{
    $expected = base64_encode(hash_hmac('sha256', $payload, $secret, true));
    return hash_equals($expected, $header);
}

function verifyMetaSignature(string $payload, string $header, string $secret): bool
{
    if (!str_starts_with($header, 'sha256=')) {
        return false;
    }
    $provided = substr($header, 7);
    $expected = hash_hmac('sha256', $payload, $secret);
    return hash_equals($expected, $provided);
}

function cidrContains(string $cidr, string $ip): bool
{
    [$subnet, $mask] = array_pad(explode('/', $cidr, 2), 2, null);
    if ($subnet === null || $mask === null || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
        return false;
    }
    $ipLong = ip2long($ip);
    $subnetLong = ip2long($subnet);
    if ($ipLong === false || $subnetLong === false) {
        return false;
    }
    $maskLong = -1 << (32 - max(0, min(32, (int) $mask)));
    return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
}
