<?php
/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

if (!defined('NOCSRFCHECK')) { define('NOCSRFCHECK', '1'); }
if (!defined('NOTOKENRENEWAL')) { define('NOTOKENRENEWAL', '1'); }

require '../../../main.inc.php';
dol_include_once('/knot/class/autoload.php');

use Knot\Api\CsrfGuard;
use Knot\Api\JsonResponse;
use Knot\Repository\WebhookRepository;
use Knot\Repository\WorkflowRepository;

JsonResponse::installFatalHandler();

if (!$user->hasRight('knot', 'workflow', 'read')) {
    JsonResponse::error('permission_denied', 'Permission denied', 403);
    exit;
}

$entity = (int) $conf->entity;
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

$workflowId = (int) GETPOST('workflow_id', 'int');
if ($workflowId <= 0) {
    JsonResponse::error('validation_failed', 'workflow_id is required.', 400);
    exit;
}

$workflowRepo = new WorkflowRepository($db);
$wf = $workflowRepo->fetch($workflowId, $entity);
if ($wf === null) {
    JsonResponse::error('not_found', 'Workflow not found.', 404);
    exit;
}

$repo = new WebhookRepository($db);

$rootUrl = '';
if (function_exists('dol_buildpath')) {
    $rootUrl = (string) dol_buildpath('/knot/api/webhook.php', 2);
}
if ($rootUrl === '') {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $rootUrl = $scheme . '://' . $host . DOL_URL_ROOT . '/custom/knot/api/webhook.php';
}

$buildPayload = static function (?array $hook) use ($rootUrl): array {
    if ($hook === null) {
        return [
            'webhook' => null,
            'url' => null,
        ];
    }
    return [
        'webhook' => [
            'id' => $hook['id'],
            'workflowId' => $hook['workflowId'],
            'token' => $hook['token'],
            'method' => $hook['method'],
            'isActive' => $hook['isActive'],
            'hitCount' => $hook['hitCount'],
            'lastHitAt' => $hook['lastHitAt'],
            'rateLimitPerMinute' => $hook['rateLimitPerMinute'],
            'ipAllowlist' => $hook['ipAllowlist'],
            'secretHmac' => $hook['secretHmac'],
            'hasSecret' => $hook['secretHmac'] !== '',
        ],
        'url' => $rootUrl . '?token=' . rawurlencode((string) $hook['token']),
    ];
};

if ($method === 'GET') {
    $hook = $repo->fetchByWorkflow($workflowId, $entity);
    JsonResponse::success($buildPayload($hook));
    exit;
}

if ($method === 'POST') {
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
    if ($rawBody !== '' && str_starts_with((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
        $decoded = json_decode($rawBody, true);
        $payload = is_array($decoded) ? $decoded : [];
    }

    $action = (string) GETPOST('action', 'aZ09');
    $config = [
        'method' => (string) ($payload['method'] ?? 'POST'),
        'ipAllowlist' => (string) ($payload['ipAllowlist'] ?? ''),
        'rateLimitPerMinute' => (int) ($payload['rateLimitPerMinute'] ?? 60),
        'isActive' => $payload['isActive'] ?? true,
    ];
    if (isset($payload['secretHmac'])) {
        $config['secretHmac'] = (string) $payload['secretHmac'];
    }
    if ($action === 'rotate') {
        $config['rotateSecret'] = true;
    }

    $hook = $repo->provision($workflowId, $entity, $config);
    if ($hook === null) {
        JsonResponse::error('provision_failed', 'Unable to provision webhook.', 500);
        exit;
    }

    JsonResponse::success($buildPayload($hook), 201);
    exit;
}

JsonResponse::error('method_not_allowed', 'Method not allowed', 405);
