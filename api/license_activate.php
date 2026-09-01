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
use Knot\Licensing\ActivationCodeProtector;
use Knot\Licensing\Audit\LicenseAuditEvent;
use Knot\Licensing\Audit\LicenseAuditWriter;
use Knot\Licensing\Bootstrap;
use Knot\Licensing\InstanceBinder;
use Knot\Licensing\InstallationIdentity;
use Knot\Licensing\LicenseCache;
use Knot\Repository\AuditLogRepository;
use Knot\Repository\KnotConfigRepository;

JsonResponse::installFatalHandler();

ApiAuth::installCrashHandler();

/**
 * V2.5.0b — Marketplace UI activation endpoint.
 *
 * POST /api/license_activate.php
 *  body: {
 *    activation_code: "KNOTPRO-XXXX-XXXX-XXXX-XXXX",  (required)
 *    extension_id: "knot-pro-pack"                    (required, used as cache key)
 *  }
 *
 * Side effects:
 *  1. Compute the local instance fingerprint (InstanceBinder) so the
 *     backend can bind the licence to this Dolibarr deployment.
 *  2. POST that fingerprint + activation_code to
 *     {license_backend}/api/license/activate.
 *  3. On success, persist the signed verdict into LicenseCache so
 *     the next call to /api/connectors.php can show the extension as
 *     loaded without a second round-trip.
 *  4. Audit the call (success or failure) — never logs the
 *     activation_code in clear (truncated to first 8 chars).
 *
 * This endpoint is the only place in Knot Core that knows about the
 * activation_code: subsequent verifications use the bound fingerprint
 * and the signed verdict, which is why we cache them.
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

// Compute the deterministic instance fingerprint used by the backend
// to lock the activation to *this* Dolibarr deployment. Fingerprint =
// sha256(societeName | dolUrlRoot | localSalt) — see InstanceBinder.
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
    'instance_url' => isset($_SERVER['HTTP_HOST'])
        ? 'https://' . (string) $_SERVER['HTTP_HOST']
        : $dolUrlRoot,
    'dolibarr_version' => defined('DOL_VERSION') ? (string) constant('DOL_VERSION') : null,
    'knot_core_version' => class_exists(\Knot\Version::class) ? \Knot\Version::current() : null,
];

$configRepo = new KnotConfigRepository($db);
$identity = new InstallationIdentity($configRepo, $db);
$deployHeaders = $identity->deploymentHeaderLines();

$ch = curl_init(rtrim($baseUrl, '/') . '/api/license/activate');
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
            'User-Agent: ' . InstallationIdentity::knotCoreUserAgent('LicenseActivate'),
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

if ($httpStatus < 200 || $httpStatus >= 300 || !($decoded['activated'] ?? false)) {
    // Forward the backend error verbatim so the modal can surface a
    // precise message (unknown_activation_code, fingerprint_conflict, ...)
    // — these strings are part of the public contract documented in
    // license/CHANGELOG.md.
    JsonResponse::success([
        'activated' => false,
        'backendStatus' => $httpStatus,
        'backend' => $decoded,
        'fingerprint' => $fingerprint,
    ], $httpStatus >= 400 ? $httpStatus : 200);
    exit;
}

$verdict = $decoded['verdict'] ?? [];
$signedPayload = is_array($verdict['payload'] ?? null) ? $verdict['payload'] : [];
$signature = is_array($verdict['signature'] ?? null) ? $verdict['signature'] : [];

// Persist into the offline-grace cache so subsequent ExtensionRegistry
// loads short-circuit the network round-trip. The verdict signature
// is rechecked at every read by SignatureVerifier — we never trust
// the cache content blindly.
$activationCodeEnc = ActivationCodeProtector::encrypt(
    $activationCode,
    Bootstrap::localSalt($db),
    $extensionId,
);
try {
    Bootstrap::persistActivationEnc($db, $extensionId, $activationCodeEnc);
} catch (\Throwable) {
    // Config persist is best-effort; cache write below is the live path.
}
try {
    $cache = new LicenseCache();
    $cache->write([
        'extensionId' => $extensionId,
        'instanceId' => $fingerprint,
        'signedPayload' => $signedPayload,
        'signature' => (string) ($signature['value_hex'] ?? ''),
        'signedAt' => (string) ($signedPayload['issued_at'] ?? gmdate('c')),
        'expiresAt' => $signedPayload['expires_at'] ?? null,
        'plan' => $signedPayload['plan'] ?? null,
        'issuedTo' => $signedPayload['product_slug'] ?? null,
        'activationCodeEnc' => $activationCodeEnc,
        'lastSuccessfulRefresh' => gmdate('c'),
        'lastAttempt' => gmdate('c'),
        'lastError' => null,
    ]);
} catch (\Throwable $e) {
    // Activation succeeded but cache write failed — surface the warning
    // in the response so the admin knows to re-run if needed. We do NOT
    // 500 here: the activation IS persisted on the backend already, only
    // the local cache could not be written (typically an FS perm issue).
    (new LicenseAuditWriter(new AuditLogRepository($db)))->record(
        LicenseAuditEvent::LICENSE_ACTIVATED,
        $extensionId,
        [
            'fingerprint' => $fingerprint,
            'licenseId' => $signedPayload['license_id'] ?? null,
            'cacheWriteError' => $e->getMessage(),
            'source' => 'license_activate',
        ],
    );
    JsonResponse::success([
        'activated' => true,
        'cacheWriteError' => $e->getMessage(),
        'verdict' => $verdict,
        'fingerprint' => $fingerprint,
    ]);
    exit;
}

(new LicenseAuditWriter(new AuditLogRepository($db)))->record(
    LicenseAuditEvent::LICENSE_ACTIVATED,
    $extensionId,
    [
        'fingerprint' => $fingerprint,
        'licenseId' => $signedPayload['license_id'] ?? null,
        'source' => 'license_activate',
    ],
);

JsonResponse::success([
    'activated' => true,
    'verdict' => $verdict,
    'fingerprint' => $fingerprint,
    'extensionId' => $extensionId,
]);
