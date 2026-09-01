<?php

/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

if (!defined('NOTOKENRENEWAL')) {
    define('NOTOKENRENEWAL', '1');
}
if (!defined('NOCSRFCHECK')) {
    define('NOCSRFCHECK', '1');
}

require_once __DIR__ . '/../lib/load_dolibarr.inc.php';
dol_include_once('/knot/class/autoload.php');

use Knot\Api\ApiAuth;
use Knot\Api\CsrfGuard;
use Knot\Api\JsonResponse;
use Knot\Licensing\ActivationCodeProtector;
use Knot\Licensing\Bootstrap;
use Knot\Licensing\DolistoreClient;
use Knot\Licensing\InstanceBinder;
use Knot\Licensing\InstallationIdentity;
use Knot\Licensing\LicenseCache;
use Knot\Repository\KnotConfigRepository;

JsonResponse::installFatalHandler();
ApiAuth::installCrashHandler();

/**
 * POST /api/license_download_token.php — authenticated Dolibarr JSON proxy
 * to `license.knot.tools/api/license/download-token`.
 *
 * Body:
 * {
 *   "product_slug": "knot-pro-pack",
 *   "activation_code": "…",           // optional if locally cached encrypted
 *   "extension_id": "knot-pro-pack" // optional alias of product_slug
 * }
 */

ApiAuth::requireRight('knot', 'admin', 'configure');

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method !== 'POST') {
    JsonResponse::error('method_not_allowed', 'Method not allowed', 405);
    exit;
}

if (!CsrfGuard::verify()) {
    JsonResponse::error('csrf_failed', 'CSRF token missing or invalid', 419);
    exit;
}

if (!extension_loaded('curl')) {
    JsonResponse::error('curl_required', 'PHP cURL extension is required for this endpoint.', 500);
    exit;
}

$rawBody = (string) file_get_contents('php://input');
$body = $rawBody !== '' ? json_decode($rawBody, true) : null;
if (!is_array($body)) {
    $body = $_POST;
}

$productSlug = strtolower(trim((string) ($body['product_slug'] ?? '')));
$extensionIdInput = strtolower(trim((string) ($body['extension_id'] ?? '')));
$extensionId = $extensionIdInput !== '' ? $extensionIdInput : $productSlug;
$activationIncoming = isset($body['activation_code'])
    ? trim((string) $body['activation_code'])
    : '';

if ($productSlug === '' || !preg_match('/^[a-z0-9-]{2,64}$/', $productSlug)) {
    JsonResponse::error('invalid_product_slug', 'A valid product_slug field is required.', 422);
    exit;
}

if ($extensionId === '' || !preg_match('/^[a-z0-9-]{2,64}$/', $extensionId)) {
    JsonResponse::error('invalid_extension_id', 'extension_id invalid.', 422);
    exit;
}

$configRepo = new KnotConfigRepository($db);

$societeName = function_exists('getDolGlobalString')
    ? (string) getDolGlobalString('MAIN_INFO_SOCIETE_NOM', '')
    : '';
$dolUrlRoot = defined('DOL_URL_ROOT') ? (string) constant('DOL_URL_ROOT') : '';
$binder = new InstanceBinder(
    $societeName,
    $dolUrlRoot,
    Bootstrap::localSalt($db),
);
$fingerprint = $binder->compute();

$activationCode = $activationIncoming;
if ($activationCode === '') {
    try {
        $cached = (new LicenseCache())->read($extensionId);
        if (
            $cached !== null
            && isset($cached['activationCodeEnc'])
            && is_string($cached['activationCodeEnc'])
            && $cached['activationCodeEnc'] !== ''
        ) {
            $activationCode = ActivationCodeProtector::decrypt(
                $cached['activationCodeEnc'],
                Bootstrap::localSalt($db),
                $extensionId,
            );
        }
    } catch (\Throwable) {
        $activationCode = '';
    }
}

if ($activationCode === '') {
    JsonResponse::error(
        'activation_code_missing',
        'Provide activation_code or complete extension activation once so Knot can decrypt the code locally.',
        422,
    );
    exit;
}

$identity = new InstallationIdentity($configRepo, $db);

$baseUrl = function_exists('getDolGlobalString')
    ? trim((string) getDolGlobalString('MAIN_KNOT_LICENSE_BASE_URL', ''))
    : '';
if ($baseUrl === '') {
    $baseUrl = DolistoreClient::FALLBACK_BASE_URL;
}

$insecureTls = false;
if (function_exists('getDolGlobalString')) {
    $raw = strtolower(trim((string) getDolGlobalString('KNOT_LICENSE_DEV_INSECURE', '')));
    $insecureTls = in_array($raw, ['1', 'true', 'yes', 'on'], true);
}

$client = new DolistoreClient($baseUrl, 15, $insecureTls);
try {
    $http = $client->licenseDownloadTokenRequest([
        'activationCode' => $activationCode,
        'instanceFingerprint' => $fingerprint,
        'productSlug' => $productSlug,
        'deploymentToken' => $identity->deploymentToken(),
        'deploymentNonce' => $identity->deploymentNonce(),
    ]);
} catch (\Throwable $e) {
    JsonResponse::error(
        'backend_unreachable',
        'Cannot reach Knot license backend.',
        502,
        ['cause' => $e->getMessage()],
    );
    exit;
}

$decoded = json_decode($http['body'], true);
if (!is_array($decoded)) {
    JsonResponse::error(
        'backend_invalid_payload',
        'License backend returned invalid JSON.',
        502,
    );
    exit;
}

if ($http['status'] === 200) {
    JsonResponse::success([
        'download_url' => (string) ($decoded['download_url'] ?? ''),
        'token' => (string) ($decoded['token'] ?? ''),
        'expires_in_seconds' => (int) ($decoded['expires_in_seconds'] ?? 0),
        'release' => is_array($decoded['release'] ?? null) ? $decoded['release'] : [],
    ]);
    exit;
}

$messages = trim((string) ($decoded['error'] ?? ''));

JsonResponse::error(
    'license_download_token_denied',
    $messages !== '' ? $messages : 'License backend denied download token issuance.',
    $http['status'] >= 400 && $http['status'] <= 599 ? $http['status'] : 502,
    [],
);
exit;
