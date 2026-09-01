<?php
/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

if (!defined('NOCSRFCHECK')) { define('NOCSRFCHECK', '1'); }
if (!defined('NOTOKENRENEWAL')) { define('NOTOKENRENEWAL', '1'); }

require_once __DIR__ . '/../lib/load_dolibarr.inc.php';
dol_include_once('/knot/class/autoload.php');

use Knot\Api\CsrfGuard;
use Knot\Api\JsonResponse;
use Knot\Repository\WorkflowFolderRepository;

JsonResponse::installFatalHandler();

if (!$user->hasRight('knot', 'workflow', 'read')) {
    JsonResponse::error('permission_denied', 'Permission denied', 403);
    exit;
}

$entity = (int) $conf->entity;
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$repo = new WorkflowFolderRepository($db);

if ($method === 'GET') {
    JsonResponse::success(['folders' => $repo->listAll($entity)]);
    exit;
}

if (!$user->hasRight('knot', 'workflow', 'write')) {
    JsonResponse::error('permission_denied', 'Permission denied', 403);
    exit;
}
if (!CsrfGuard::verify()) {
    JsonResponse::error('csrf_invalid', 'Invalid CSRF token', 403);
    exit;
}

$rawBody = (string) file_get_contents('php://input');
$payload = [];
if ($rawBody !== '') {
    $decoded = json_decode($rawBody, true);
    $payload = is_array($decoded) ? $decoded : [];
}
$action = (string) GETPOST('action', 'aZ09');

if ($method === 'POST' && $action === 'assign') {
    $workflowId = (int) ($payload['workflowId'] ?? 0);
    $folderId = isset($payload['folderId']) && $payload['folderId'] !== null ? (int) $payload['folderId'] : null;
    if ($workflowId <= 0) {
        JsonResponse::error('validation_failed', 'workflowId is required.', 400);
        exit;
    }
    $ok = $repo->assignWorkflow($workflowId, $folderId, $entity);
    JsonResponse::success(['updated' => $ok]);
    exit;
}

if ($method === 'POST') {
    $label = (string) ($payload['label'] ?? '');
    if ($label === '') {
        JsonResponse::error('validation_failed', 'label is required.', 400);
        exit;
    }
    $newId = $repo->create(
        $label,
        $payload['color'] ?? null,
        isset($payload['parentId']) ? (int) $payload['parentId'] : null,
        $entity,
        (int) $user->id,
    );
    if ($newId <= 0) {
        JsonResponse::error('create_failed', 'Unable to create folder.', 500);
        exit;
    }
    JsonResponse::success(['id' => $newId], 201);
    exit;
}

if ($method === 'PUT' || $method === 'PATCH') {
    $id = (int) ($payload['id'] ?? GETPOST('id', 'int'));
    if ($id <= 0) {
        JsonResponse::error('validation_failed', 'id is required.', 400);
        exit;
    }
    $patch = [];
    foreach (['label', 'color', 'parentId'] as $key) {
        if (array_key_exists($key, $payload)) {
            $patch[$key] = $payload[$key];
        }
    }
    $ok = $repo->update($id, $patch, $entity);
    JsonResponse::success(['updated' => $ok]);
    exit;
}

if ($method === 'DELETE') {
    $id = (int) GETPOST('id', 'int');
    if ($id <= 0) {
        JsonResponse::error('validation_failed', 'id is required.', 400);
        exit;
    }
    $ok = $repo->delete($id, $entity);
    JsonResponse::success(['deleted' => $ok]);
    exit;
}

JsonResponse::error('method_not_allowed', 'Method not allowed', 405);
