<?php
/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

if (!defined('NOCSRFCHECK')) { define('NOCSRFCHECK', '1'); }
if (!defined('NOTOKENRENEWAL')) { define('NOTOKENRENEWAL', '1'); }

require_once __DIR__ . '/../lib/load_dolibarr.inc.php';
dol_include_once('/knot/class/autoload.php');

use Knot\Api\ApiAuth;
use Knot\Api\JsonResponse;
use Knot\Licensing\Bootstrap;
use Knot\Licensing\LicenseCache;

JsonResponse::installFatalHandler();

ApiAuth::installCrashHandler();

/**
 * V2.5.0b — Marketplace UI status endpoint.
 *
 * GET /api/license_status.php
 *  → returns the local-cache verdict for every installed Knot extension
 *    plus the resolved status from {@see ExtensionRegistry}.
 *
 * The endpoint never reaches out to license.knot.tools — that is the
 * responsibility of {@see /api/license_activate.php}. It strictly
 * reports what Knot Core knows right now, which is what the UI needs
 * to render badges and the activation modal CTA.
 *
 * Response shape:
 *  {
 *    success: true,
 *    data: {
 *      extensions: [
 *        {
 *          id: "knot-pro-pack",
 *          label: "Knot Pro Pack",
 *          version: "0.1.0",
 *          category: "pro",
 *          status: "loaded" | "license_invalid" | "missing_dep" | ...,
 *          licenseStatus: "valid" | "invalid" | "expired" | null,
 *          licenseExpiresAt: "2026-12-31T00:00:00+00:00" | null,
 *          cachedVerdict: { ... raw cache entry ... } | null
 *        },
 *        ...
 *      ]
 *    }
 *  }
 */

ApiAuth::requireRight('knot', 'workflow', 'read');

$registry = Bootstrap::buildExtensionRegistry($db);
$cache = new LicenseCache();

$result = [];
foreach ($registry->discover() as $ext) {
    $extId = (string) ($ext['id'] ?? '');
    if ($extId === '') {
        continue;
    }
    $cached = null;
    try {
        $cached = $cache->read($extId);
    } catch (\Throwable) {
        $cached = null;
    }

    $result[] = [
        'id' => $extId,
        'label' => $ext['label'] ?? $extId,
        'version' => $ext['version'] ?? null,
        'author' => $ext['author'] ?? null,
        'category' => $ext['category'] ?? 'third-party',
        'status' => $ext['status'] ?? null,
        'error' => $ext['error'] ?? null,
        'licenseStatus' => $ext['licenseInfo']['status'] ?? null,
        'licenseExpiresAt' => $ext['licenseInfo']['expiresAt'] ?? null,
        'connectorIds' => $ext['connectorIds'] ?? [],
        // The cached verdict is only echoed when present so the frontend
        // can surface the bound instance fingerprint and the next-refresh
        // window without a second round-trip.
        'cachedVerdict' => $cached !== null ? [
            'instanceId' => $cached['instanceId'] ?? null,
            'plan' => $cached['plan'] ?? null,
            'expiresAt' => $cached['expiresAt'] ?? null,
            'lastSuccessfulRefresh' => $cached['lastSuccessfulRefresh'] ?? null,
            'lastAttempt' => $cached['lastAttempt'] ?? null,
            'lastError' => $cached['lastError'] ?? null,
        ] : null,
    ];
}

JsonResponse::success([
    'extensions' => $result,
    'backendUrl' => function_exists('getDolGlobalString')
        ? trim((string) getDolGlobalString('MAIN_KNOT_LICENSE_BASE_URL', 'https://license.knot.tools'))
        : 'https://license.knot.tools',
]);
