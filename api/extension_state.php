<?php
/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

if (!defined('NOTOKENRENEWAL')) { define('NOTOKENRENEWAL', '1'); }
// CSRF is verified explicitly via ApiAuth::requireCsrf() on writes, so the
// blanket NOCSRFCHECK shortcut is intentionally NOT set here.

require_once __DIR__ . '/../lib/load_dolibarr.inc.php';
dol_include_once('/knot/class/autoload.php');

use Knot\Api\ApiAuth;
use Knot\Api\JsonResponse;
use Knot\Extension\ExtensionRegistry;
use Knot\Extension\ExtensionStateRepository;
use Knot\Licensing\Bootstrap;

JsonResponse::installFatalHandler();
ApiAuth::installCrashHandler();

/**
 * ADR-20 slice 4 — Per-(user, extension) state store.
 *
 *   GET    /api/extension_state.php?extension_id=knot-migration
 *          -> { state: { key: value, ... } } (every key for current user)
 *
 *   POST   /api/extension_state.php
 *          body: { extension_id, key, value }
 *          (CSRF required) — value is stored verbatim, callers JSON-encode
 *
 *   DELETE /api/extension_state.php?extension_id=knot-migration&key=foo
 *          (CSRF required) — omit key= to wipe every key for that pair
 *
 * Auth: Dolibarr session (every authenticated user owns their slot).
 * The endpoint refuses unknown extension IDs (must be in the active
 * ExtensionRegistry) so a misbehaving client cannot squat the table.
 */

$activeUser = ApiAuth::requireUser();
$userId = (int) ($activeUser->id ?? 0);
$entity = (int) ($conf->entity ?? 1);

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$repo = new ExtensionStateRepository($db);

$activeExtensionIds = knot_extension_state_active_ids($db);

if ($method === 'GET') {
    $extensionId = knot_extension_state_resolve_id(GETPOST('extension_id', 'aZ09-'), $activeExtensionIds);
    if ($extensionId === null) {
        JsonResponse::error('unknown_extension', 'Unknown or inactive extension', 404);
        exit;
    }
    JsonResponse::success(['state' => $repo->all($userId, $extensionId, $entity)]);
    exit;
}

if ($method === 'POST') {
    ApiAuth::requireCsrf();
    $extensionId = knot_extension_state_resolve_id(GETPOST('extension_id', 'aZ09-'), $activeExtensionIds);
    if ($extensionId === null) {
        JsonResponse::error('unknown_extension', 'Unknown or inactive extension', 404);
        exit;
    }
    $key = (string) GETPOST('key', 'alphanohtml');
    $value = (string) GETPOST('value', 'restricthtml');

    if (strlen($value) > ExtensionStateRepository::MAX_VALUE_BYTES) {
        JsonResponse::error('value_too_large', 'Value exceeds maximum size', 413, [
            'maxBytes' => ExtensionStateRepository::MAX_VALUE_BYTES,
        ]);
        exit;
    }

    // Anti-flood: refuse if the user already holds MAX_KEYS_PER_PAIR keys
    // and the incoming key is a new one (existing keys are an UPDATE).
    $existingForPair = $repo->all($userId, $extensionId, $entity);
    if (!array_key_exists($key, $existingForPair)
        && count($existingForPair) >= ExtensionStateRepository::MAX_KEYS_PER_PAIR
    ) {
        JsonResponse::error('quota_exceeded', 'Per-extension key quota exceeded', 429, [
            'maxKeys' => ExtensionStateRepository::MAX_KEYS_PER_PAIR,
            'current' => count($existingForPair),
        ]);
        exit;
    }

    if (!$repo->set($userId, $extensionId, $key, $value, $entity)) {
        JsonResponse::error('invalid_key', 'Invalid or unwritable state key', 400);
        exit;
    }
    JsonResponse::success(['extension_id' => $extensionId, 'key' => $key]);
    exit;
}

if ($method === 'DELETE') {
    ApiAuth::requireCsrf();
    $extensionId = knot_extension_state_resolve_id(GETPOST('extension_id', 'aZ09-'), $activeExtensionIds);
    if ($extensionId === null) {
        JsonResponse::error('unknown_extension', 'Unknown or inactive extension', 404);
        exit;
    }
    $key = trim((string) GETPOST('key', 'alphanohtml'));
    $ok = $key === ''
        ? $repo->clear($userId, $extensionId, $entity)
        : $repo->remove($userId, $extensionId, $key, $entity);
    if (!$ok) {
        JsonResponse::error('delete_failed', 'Failed to delete state entry', 500);
        exit;
    }
    JsonResponse::success(['extension_id' => $extensionId, 'cleared' => $key === '']);
    exit;
}

JsonResponse::error('method_not_allowed', 'Method not allowed', 405);
exit;

/**
 * Resolve the requested extension id against the active registry. Returns the
 * normalised id on success, null otherwise.
 *
 * @param array<int, string> $activeIds
 */
function knot_extension_state_resolve_id($raw, array $activeIds): ?string
{
    $id = strtolower(trim((string) $raw));
    if ($id === '' || preg_match('/^[a-z][a-z0-9-]{0,63}$/', $id) !== 1) {
        return null;
    }
    return in_array($id, $activeIds, true) ? $id : null;
}

/**
 * @return array<int, string>
 */
function knot_extension_state_active_ids($db): array
{
    try {
        if ($db instanceof \DoliDB && class_exists(Bootstrap::class)) {
            $registry = Bootstrap::buildExtensionRegistry($db);
        } else {
            $registry = new ExtensionRegistry();
        }
        return array_keys($registry->active());
    } catch (\Throwable $e) {
        error_log('[knot extension_state] registry failed: ' . $e->getMessage());
        return [];
    }
}
