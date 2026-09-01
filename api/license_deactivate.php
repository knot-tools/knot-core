<?php
/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

if (!defined('NOTOKENRENEWAL')) { define('NOTOKENRENEWAL', '1'); }
// Knot uses its own CSRF guard (CsrfGuard::verify reads X-Csrf-Token).
// Bypass Dolibarr's main.inc.php auto-CSRF (MAIN_SECURITY_CSRF_WITH_TOKEN=2)
// which would 403 every POST that does not carry ?token=… in the URL or
// "token" in the POST body before our PHP entry point even runs. CsrfGuard
// below provides the equivalent guarantee from a header that the JS client
// can set safely.
if (!defined('NOCSRFCHECK')) { define('NOCSRFCHECK', '1'); }

require_once __DIR__ . '/../lib/load_dolibarr.inc.php';
dol_include_once('/knot/class/autoload.php');

use Knot\Api\ApiAuth;
use Knot\Api\CsrfGuard;
use Knot\Api\JsonResponse;
use Knot\Licensing\Bootstrap;
use Knot\Licensing\InstanceBinder;
use Knot\Licensing\InstallationIdentity;
use Knot\Licensing\LicenseCache;
use Knot\Repository\KnotConfigRepository;

JsonResponse::installFatalHandler();

ApiAuth::installCrashHandler();

/**
 * Phase 7a-bonus — Marketplace UI deactivation endpoint.
 *
 * POST /api/license_deactivate.php
 *  body: {
 *    activation_code: "KNOTPRO-XXXX-XXXX-XXXX-XXXX",  (required — re-asked
 *                                                       from the admin because
 *                                                       LicenseCache does not
 *                                                       persist it; see
 *                                                       docs/runbooks/
 *                                                       licensing-tech-debt.md)
 *    extension_id: "knot-pro-pack"                    (required, used as
 *                                                       cache key)
 *  }
 *
 * Mirror of {@see license_activate.php}: instead of binding the local
 * fingerprint, it asks the backend to soft-delete it. On success, the
 * local cache entry is wiped so the next `ExtensionRegistry::discover()`
 * pass reports the extension as `license_invalid` and the sidebar item
 * disappears (or surfaces a CTA, depending on the manifest's
 * `onboarding.ctaIfPermissionMissingForAdmin`).
 *
 * Idempotent against the backend: re-running with the same fingerprint
 * after the row was already soft-deleted forwards the backend's
 * 404 `unknown_binding` verbatim so the UI can show "already
 * deactivated".
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

$rawBody = (string) file_get_contents('php://input');
$body = $rawBody !== '' ? json_decode($rawBody, true) : null;
if (!is_array($body)) {
    $body = $_POST;
}

$activationCode = trim((string) ($body['activation_code'] ?? ''));
$extensionId    = trim((string) ($body['extension_id'] ?? ''));

if ($activationCode === '' || $extensionId === '') {
    JsonResponse::error(
        'missing_field',
        'Both activation_code and extension_id are required.',
        422
    );
    exit;
}
if (!preg_match('/^[a-z0-9-]{2,64}$/', $extensionId)) {
    JsonResponse::error('invalid_extension_id', 'Invalid extension_id format.', 422);
    exit;
}

$baseUrl = function_exists('getDolGlobalString')
    ? trim((string) getDolGlobalString('MAIN_KNOT_LICENSE_BASE_URL', ''))
    : '';
if ($baseUrl === '') {
    $baseUrl = 'https://license.knot.tools';
}

// Re-compute the same fingerprint InstanceBinder produced at activation
// time. Must match exactly or the backend will return 404 unknown_binding.
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

$payload = [
    'activation_code' => $activationCode,
    'instance_fingerprint' => $fingerprint,
];

$configRepo = new KnotConfigRepository($db);
$identity = new InstallationIdentity($configRepo, $db);
$deployHeaders = $identity->deploymentHeaderLines();

$ch = curl_init(rtrim($baseUrl, '/') . '/api/license/deactivate');
if ($ch === false) {
    JsonResponse::error('curl_init_failed', 'Cannot initialise HTTP client', 500);
    exit;
}
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
    CURLOPT_TIMEOUT => 15,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_HTTPHEADER => array_merge(
        [
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: ' . InstallationIdentity::knotCoreUserAgent('LicenseDeactivate'),
        ],
        $deployHeaders,
    ),
]);
$rawResponse = curl_exec($ch);
$httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr = curl_error($ch);

if ($rawResponse === false) {
    JsonResponse::error(
        'backend_unreachable',
        'Cannot reach license backend: ' . $curlErr,
        502
    );
    exit;
}

$decoded = json_decode((string) $rawResponse, true);
if (!is_array($decoded)) {
    JsonResponse::error('backend_invalid', 'License backend returned non-JSON', 502);
    exit;
}

if ($httpStatus < 200 || $httpStatus >= 300 || !($decoded['deactivated'] ?? false)) {
    // Forward the backend error verbatim — typically 404
    // `unknown_activation_code` (wrong code) or 404 `unknown_binding`
    // (already deactivated, or fingerprint mismatch). The UI knows
    // how to surface these.
    JsonResponse::success([
        'deactivated' => false,
        'backendStatus' => $httpStatus,
        'backend' => $decoded,
        'fingerprint' => $fingerprint,
    ], $httpStatus >= 400 ? $httpStatus : 200);
    exit;
}

// Wipe the local cache so the next ExtensionRegistry pass treats the
// extension as license_invalid. Best-effort: if the cache write fails
// the backend deactivation is already persisted, surface the warning
// so the admin can clean up manually if needed.
$cacheWarning = null;
try {
    $cache = new LicenseCache();
    $cache->delete($extensionId);
} catch (\Throwable $e) {
    $cacheWarning = $e->getMessage();
}

JsonResponse::success([
    'deactivated' => true,
    'license_id' => $decoded['license_id'] ?? null,
    'remaining_seats' => $decoded['remaining_seats'] ?? null,
    'fingerprint' => $fingerprint,
    'extensionId' => $extensionId,
    'cacheDeleteWarning' => $cacheWarning,
]);
