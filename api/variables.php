<?php
/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

if (!defined('NOCSRFCHECK')) { define('NOCSRFCHECK', '1'); }
if (!defined('NOTOKENRENEWAL')) { define('NOTOKENRENEWAL', '1'); }

require '../../../main.inc.php';
dol_include_once('/knot/class/autoload.php');

use Knot\Api\CsrfGuard;
use Knot\Api\JsonResponse;
use Knot\Repository\VariableRepository;

JsonResponse::installFatalHandler();

if (!$user->hasRight('knot', 'workflow', 'read')) {
    JsonResponse::error('permission_denied', 'Permission denied', 403);
    exit;
}

$entity = (int) $conf->entity;
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$repo = new VariableRepository($db);

if ($method === 'GET') {
    $scope = GETPOST('scope', 'aZ09') ?: null;
    JsonResponse::success(['variables' => $repo->listAll($entity, $scope)]);
    exit;
}

$payload = [];
$body = (string) file_get_contents('php://input');
if ($body !== '' && str_starts_with((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
    $decoded = json_decode($body, true);
    $payload = is_array($decoded) ? $decoded : [];
}

if (!$user->hasRight('knot', 'workflow', 'write')) {
    JsonResponse::error('permission_denied', 'Permission denied', 403);
    exit;
}
if (!CsrfGuard::verify()) {
    JsonResponse::error('csrf_invalid', 'Invalid CSRF token', 403);
    exit;
}

if ($method === 'POST') {
    $id = $repo->create($payload, $entity, isset($user->id) ? (int) $user->id : null);
    if ($id === null) {
        JsonResponse::error('validation_failed', 'Could not create variable.', 422);
        exit;
    }
    JsonResponse::success(['id' => $id], 201);
    exit;
}

if ($method === 'PUT' || $method === 'PATCH') {
    $id = (int) ($payload['id'] ?? GETPOST('id', 'int'));
    if ($id <= 0) {
        JsonResponse::error('validation_failed', 'Missing variable id.', 400);
        exit;
    }
    $ok = $repo->update($id, $payload, $entity);
    JsonResponse::success(['updated' => $ok]);
    exit;
}

if ($method === 'DELETE') {
    $id = (int) GETPOST('id', 'int');
    if ($id <= 0) {
        JsonResponse::error('validation_failed', 'Missing variable id.', 400);
        exit;
    }
    JsonResponse::success(['deleted' => $repo->delete($id, $entity)]);
    exit;
}

JsonResponse::error('method_not_allowed', 'Method not allowed', 405);
