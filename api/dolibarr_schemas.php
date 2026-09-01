<?php
/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

if (!defined('NOCSRFCHECK')) { define('NOCSRFCHECK', '1'); }
if (!defined('NOTOKENRENEWAL')) { define('NOTOKENRENEWAL', '1'); }

require_once __DIR__ . '/../lib/load_dolibarr.inc.php';
dol_include_once('/knot/class/autoload.php');

use Knot\Api\CsrfGuard;
use Knot\Api\JsonResponse;
use Knot\Dolibarr\DescriptorCache;
use Knot\Dolibarr\ObjectFactory;
use Knot\Dolibarr\SchemaBuilder;

JsonResponse::installFatalHandler();

if (!$user->hasRight('knot', 'workflow', 'read')) {
    JsonResponse::error('permission_denied', 'Permission denied', 403);
    exit;
}

$factory = new ObjectFactory();

$descriptorCacheSnapshot = function () use ($factory): array {
    $dcb = (new DescriptorCache())->read();

    return [
        'present' => $dcb !== null,
        'storedDescriptorCount' => $dcb !== null && isset($dcb['descriptors']) && is_array($dcb['descriptors'])
            ? count($dcb['descriptors'])
            : 0,
        'discoveryOnlyCount' => count($factory->discoveredDescriptors()),
    ];
};
// V2.4 Sprint 3: explicit refresh of the introspection cache. Mutating
// (rebuilds documents/knot/dolibarr_descriptors.json), so it requires
// admin-level rights and a CSRF token. Returns the fresh hash so the
// frontend can purge its in-memory schema cache.
if (isset($_GET['refresh']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$user->admin && !$user->hasRight('knot', 'workflow', 'write')) {
        JsonResponse::error('permission_denied', 'Refresh requires admin or workflow write right.', 403);
        exit;
    }
    if (!CsrfGuard::verify()) {
        JsonResponse::error('csrf_invalid', 'Invalid CSRF token', 403);
        exit;
    }
    try {
        $report = $factory->refreshIntrospection($db);
    } catch (\Throwable $e) {
        JsonResponse::error('refresh_failed', $e->getMessage(), 500);
        exit;
    }
    JsonResponse::success([
        'hash' => $factory->getVersionHash($db),
        'descriptors' => $report['count'],
        'objects' => $factory->listObjectsForApi($langs, $db),
        'descriptorCache' => $descriptorCacheSnapshot(),
    ]);
    exit;
}

// Cheap "ping" route — returns the global hash so the frontend can decide
// whether to invalidate its cached schemas without paying the cost of
// reflecting every object.
if (isset($_GET['hash'])) {
    $hash = $factory->getVersionHash($db);
    header('Cache-Control: max-age=60, must-revalidate');
    JsonResponse::success(['hash' => $hash]);
    exit;
}

if (isset($_GET['list'])) {
    $hash = $factory->getVersionHash($db);
    $etag = '"' . $hash . '"';
    if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
        http_response_code(304);
        header('ETag: ' . $etag);
        exit;
    }

    $objects = $factory->listObjectsForApi($langs, $db);

    header('ETag: ' . $etag);
    header('Cache-Control: max-age=300, must-revalidate');
    JsonResponse::success([
        'objects' => $objects,
        'hash' => $hash,
        'descriptorCache' => $descriptorCacheSnapshot(),
    ]);
    exit;
}

$slug = (string) ($_GET['slug'] ?? '');
$action = (string) ($_GET['action'] ?? 'create');

if ($slug === '') {
    JsonResponse::error('missing_slug', 'Query parameter "slug" is required.', 400);
    exit;
}

// V2.4 Sprint 2: verb discovery endpoint. Returns the list of
// state-transition methods we auto-detected on the object class
// (validate, setPaid, reopen, ...) with maturity tags so the palette
// can render Verified / Experimental badges.
if (isset($_GET['verbs'])) {
    try {
        $simulate = ($_GET['simulate'] ?? '1') !== '0';
        $verbs = $factory->discoverVerbs($slug, $db, $simulate);
    } catch (\Throwable $e) {
        JsonResponse::error('verbs_failed', $e->getMessage(), 404);
        exit;
    }
    header('Cache-Control: max-age=300, must-revalidate');
    JsonResponse::success(['slug' => $slug, 'verbs' => $verbs]);
    exit;
}

$allowedActions = [
    SchemaBuilder::ACTION_CREATE,
    SchemaBuilder::ACTION_UPDATE,
    SchemaBuilder::ACTION_FETCH,
    SchemaBuilder::ACTION_DELETE,
    SchemaBuilder::ACTION_CHANGE_STATUS,
    SchemaBuilder::ACTION_ADD_NOTE,
    SchemaBuilder::ACTION_GENERATE_PDF,
];
if (!in_array($action, $allowedActions, true)) {
    JsonResponse::error('invalid_action', 'Unsupported action: ' . $action, 400);
    exit;
}

try {
    $fieldView = strtolower(trim((string) ($_GET['field_view'] ?? SchemaBuilder::FIELD_VIEW_STANDARD)));
    if ($fieldView === SchemaBuilder::FIELD_VIEW_FULL) {
        $fieldView = SchemaBuilder::FIELD_VIEW_FULL;
    } else {
        $fieldView = SchemaBuilder::FIELD_VIEW_STANDARD;
    }
    $schema = $factory->describeForAction($slug, $action, $db, $langs, $fieldView);
} catch (\Throwable $e) {
    JsonResponse::error('schema_failed', $e->getMessage(), 404);
    exit;
}

$etag = '"' . ($schema['x-version-hash'] ?? 'na') . ':' . $action . ':' . $fieldView . '"';
if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304);
    header('ETag: ' . $etag);
    exit;
}

header('ETag: ' . $etag);
header('Cache-Control: max-age=300, must-revalidate');
JsonResponse::success(['schema' => $schema]);
