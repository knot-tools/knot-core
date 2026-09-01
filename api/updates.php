<?php
/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

if (!defined('NOCSRFCHECK')) {
    define('NOCSRFCHECK', '1');
}
if (!defined('NOTOKENRENEWAL')) {
    define('NOTOKENRENEWAL', '1');
}

require_once __DIR__ . '/../lib/load_dolibarr.inc.php';
dol_include_once('/knot/class/autoload.php');

use Knot\Api\ApiAuth;
use Knot\Api\JsonResponse;
use Knot\Licensing\Bootstrap;
use Knot\Repository\KnotConfigRepository;
use Knot\Updates\UpdateChecker;
use Knot\Updates\UpdateClient;
use Knot\Updates\GithubReleasesClient;
use Knot\Updates\UpdateLatestResolver;
use Knot\Updates\UpdateStatusCache;
use Knot\Version;

JsonResponse::installFatalHandler();
ApiAuth::installCrashHandler();

/**
 * V2.5.0b — Phase 7d notify-only update endpoint.
 *
 * GET /api/updates.php
 *  → returns the data needed by the AppShell update badge :
 *    {
 *      checkedAt: 1715000000,
 *      hasAnyUpdate: true,
 *      entries: [
 *        {
 *          slug: "knot",
 *          installedVersion: "2.9.0",
 *          latestVersion: "2.9.1",
 *          channel: "beta",
 *          publishedAt: "2026-05-16T12:34:56+00:00",
 *          hasUpdate: true,
 *          source: "live" | "cache" | "cache_stale" | "unavailable",
 *          error: null
 *        },
 *        ...
 *      ]
 *    }
 *
 * Notify-only by design: the response contains no download URL.
 * Applying an update is done via `api/updates_apply.php` (admin + CSRF).
 * Commercial artefacts use `api/license_download_token.php` for a JWT download URL.
 *
 * Caching: results are persisted in `llx_knot_config` (entity-aware)
 * with a 24h TTL. Each invocation transparently refreshes any entry
 * older than the TTL through {@see UpdateLatestResolver::fetchLatest()}.
 *
 * Core alert source order: GitHub releases.json, then license.knot.tools
 * `/api/core/releases.json`, then `/api/products/knot/latest`. Core apply
 * remains GitHub-only ({@see api/updates_apply.php}).
 *
 * Query `force=1` bypasses the 24h notify cache and re-fetches live metadata
 * (GitHub and/or license backend). Same permission as the default GET.
 */

ApiAuth::requireRight('knot', 'workflow', 'read');

$entity = (int) $conf->entity;

$forceRefresh = in_array(strtolower(trim((string) GETPOST('force', 'alphanohtml'))), ['1', 'true', 'yes'], true);

$configRepo = new KnotConfigRepository($db);
$githubManifestUrl = function_exists('getDolGlobalString')
    ? trim((string) getDolGlobalString('MAIN_KNOT_CORE_RELEASES_JSON_URL', ''))
    : '';
$releaseChannel = function_exists('getDolGlobalString')
    ? strtolower(trim((string) getDolGlobalString('KNOT_RELEASE_CHANNEL', 'beta')))
    : 'beta';
if ($releaseChannel === '') {
    $releaseChannel = 'beta';
}
$source = new UpdateLatestResolver(
    new UpdateClient(releaseChannel: $releaseChannel),
    new GithubReleasesClient(),
    $githubManifestUrl !== '' ? $githubManifestUrl : null,
);
$cache = new UpdateStatusCache($configRepo);
$checker = new UpdateChecker($source, $cache);

$installed = [['slug' => 'knot', 'version' => Version::current()]];

$registry = Bootstrap::buildExtensionRegistry($db);
foreach ($registry->discover() as $ext) {
    $slug = (string) ($ext['id'] ?? '');
    $version = (string) ($ext['version'] ?? '');
    if ($slug === '' || $version === '') {
        continue;
    }
    $installed[] = ['slug' => $slug, 'version' => $version];
}

$result = $checker->check($installed, $forceRefresh);

JsonResponse::success($result);
