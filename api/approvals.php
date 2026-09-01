<?php
/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

if (!defined('NOCSRFCHECK')) { define('NOCSRFCHECK', '1'); }
if (!defined('NOTOKENRENEWAL')) { define('NOTOKENRENEWAL', '1'); }

require_once __DIR__ . '/../lib/load_dolibarr.inc.php';
dol_include_once('/knot/class/autoload.php');

use Knot\Api\CsrfGuard;
use Knot\Api\JsonResponse;
use Knot\Repository\ApprovalRepository;

JsonResponse::installFatalHandler();

if (!$user->hasRight('knot', 'workflow', 'execute')) {
    JsonResponse::error('permission_denied', 'Permission denied', 403);
    exit;
}

$repo = new ApprovalRepository($db);
$entity = (int) $conf->entity;
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'GET') {
    JsonResponse::success(['approvals' => $repo->listPending($entity)]);
    exit;
}

if ($method !== 'POST') {
    JsonResponse::error('method_not_allowed', 'Method not allowed', 405);
    exit;
}

if (!CsrfGuard::verify()) {
    JsonResponse::error('csrf_invalid', 'Invalid CSRF token', 403);
    exit;
}

$payload = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($payload)) {
    JsonResponse::error('invalid_payload', 'Invalid JSON payload', 400);
    exit;
}

$id = (int) ($payload['id'] ?? 0);
$status = (string) ($payload['status'] ?? '');
$comment = (string) ($payload['comment'] ?? '');
if ($id <= 0 || !in_array($status, ['approved', 'rejected'], true)) {
    JsonResponse::error('validation_failed', 'id and status are required.', 400);
    exit;
}

if (!$repo->decide($id, $status, (int) $user->id, $comment, $entity)) {
    JsonResponse::error('decision_failed', 'Unable to update approval.', 500);
    exit;
}

JsonResponse::success(['approvalId' => $id, 'status' => $status]);
