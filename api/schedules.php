<?php
/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

if (!defined('NOCSRFCHECK')) { define('NOCSRFCHECK', '1'); }
if (!defined('NOTOKENRENEWAL')) { define('NOTOKENRENEWAL', '1'); }

require_once __DIR__ . '/../lib/load_dolibarr.inc.php';
dol_include_once('/knot/class/autoload.php');

use Knot\Api\CsrfGuard;
use Knot\Api\JsonResponse;
use Knot\Repository\ScheduleRepository;

JsonResponse::installFatalHandler();

if (!$user->hasRight('knot', 'workflow', 'read')) {
    JsonResponse::error('permission_denied', 'Permission denied', 403);
    exit;
}

$entity = (int) $conf->entity;
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$repo = new ScheduleRepository($db);
$workflowId = (int) GETPOST('workflow_id', 'int');

if ($method === 'GET') {
    if ($workflowId <= 0) {
        JsonResponse::error('validation_failed', 'workflow_id required', 400);
        exit;
    }
    JsonResponse::success(['schedules' => $repo->listForWorkflow($workflowId, $entity)]);
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

$payload = [];
$body = (string) file_get_contents('php://input');
if ($body !== '' && str_starts_with((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
    $decoded = json_decode($body, true);
    $payload = is_array($decoded) ? $decoded : [];
}

if ($method === 'POST') {
    if ($workflowId <= 0) {
        JsonResponse::error('validation_failed', 'workflow_id required', 400);
        exit;
    }
    $id = isset($payload['id']) ? (int) $payload['id'] : null;
    $newId = $repo->save($id, $workflowId, $payload, $entity);
    if ($newId === null) {
        JsonResponse::error('validation_failed', 'Could not save schedule', 422);
        exit;
    }
    JsonResponse::success(['id' => $newId]);
    exit;
}

if ($method === 'DELETE') {
    $id = (int) GETPOST('id', 'int');
    if ($id <= 0) {
        JsonResponse::error('validation_failed', 'id required', 400);
        exit;
    }
    JsonResponse::success(['deleted' => $repo->delete($id, $entity)]);
    exit;
}

JsonResponse::error('method_not_allowed', 'Method not allowed', 405);
