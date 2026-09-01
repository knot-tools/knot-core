<?php
/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

if (!defined('NOCSRFCHECK')) { define('NOCSRFCHECK', '1'); }
if (!defined('NOTOKENRENEWAL')) { define('NOTOKENRENEWAL', '1'); }

require_once __DIR__ . '/../lib/load_dolibarr.inc.php';
dol_include_once('/knot/class/autoload.php');

use Knot\Api\CsrfGuard;
use Knot\Api\JsonResponse;
use Knot\Engine\CronWorker;
use Knot\Errors\DolibarrErrorTranslator;
use Knot\Repository\ExecutionLogRepository;
use Knot\Repository\ExecutionRepository;

JsonResponse::installFatalHandler();

if (!$user->hasRight('knot', 'workflow', 'read')) {
    JsonResponse::error('permission_denied', 'Permission denied', 403);
    exit;
}

$entity = (int) $conf->entity;
$executions = new ExecutionRepository($db);
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = (string) GETPOST('action', 'alphanohtml');

if ($method === 'GET' && $action === 'queue_dashboard') {
    JsonResponse::success([
        'counts' => $executions->statusCounts($entity),
        'topRetries' => $executions->topRetryRows($entity, 15),
        'queuedByWorkflow' => $executions->queuedAggregatedByWorkflow($entity),
    ]);
    exit;
}

if ($method === 'POST') {
    if (!$user->hasRight('knot', 'workflow', 'execute')) {
        JsonResponse::error('permission_denied', 'Execute permission required', 403);
        exit;
    }
    if (!CsrfGuard::verify()) {
        JsonResponse::error('csrf_invalid', 'Invalid CSRF token', 403);
        exit;
    }

    $rawBody = (string) file_get_contents('php://input');
    $payload = [];
    if (str_starts_with((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') && $rawBody !== '') {
        $decoded = json_decode($rawBody, true);
        $payload = is_array($decoded) ? $decoded : [];
    }

    if ($action === 'purge_failures') {
        $days = max(1, min(3650, (int) ($payload['olderThanDays'] ?? 7)));
        $deleted = $executions->purgeFailuresOlderThan($entity, $days);
        JsonResponse::success(['deleted' => $deleted, 'olderThanDays' => $days]);
        exit;
    }

    $executionId = (int) ($payload['executionId'] ?? GETPOST('id', 'int'));
    if ($executionId <= 0) {
        JsonResponse::error('validation_failed', 'executionId is required.', 400);
        exit;
    }

    if ($action === 'cancel') {
        $ok = $executions->cancel($executionId, $entity);
        if (!$ok) {
            JsonResponse::error('cancel_failed', 'Execution not cancellable (already finished or not found).', 409);
            exit;
        }
        JsonResponse::success(['executionId' => $executionId, 'status' => 'cancelled']);
        exit;
    }

    if ($action === 'retry') {
        $newId = $executions->retry($executionId, $entity);
        if ($newId <= 0) {
            JsonResponse::error('retry_failed', 'Unable to clone execution for retry.', 500);
            exit;
        }
        JsonResponse::success(['originalExecutionId' => $executionId, 'executionId' => $newId, 'status' => 'queued'], 202);
        exit;
    }

    if ($action === 'run_now') {
        $worker = new CronWorker();
        $ok = $worker->runOnce($executionId);
        if (!$ok) {
            $current = $executions->fetchOne($executionId, $entity);
            $msg = (string) ($current['errorMessage'] ?? '');
            if ($msg === '') {
                $msg = 'Execution did not complete successfully.';
            }
            $knot = (new DolibarrErrorTranslator())->translate(new \Exception($msg), [
                'endpoint' => 'executions.php',
                'action' => 'run_now',
                'executionId' => $executionId,
                'execution' => $current,
            ]);
            JsonResponse::knotError($knot);
            exit;
        }
        $row = $executions->fetchOne($executionId, $entity);
        JsonResponse::success(['execution' => $row]);
        exit;
    }

    JsonResponse::error('unknown_action', 'Unknown action: ' . $action, 400);
    exit;
}

$executionId = (int) GETPOST('id', 'int');
if ($executionId > 0) {
    $row = $executions->fetchOne($executionId, $entity);
    if ($row === null) {
        JsonResponse::error('not_found', 'Execution not found', 404);
        exit;
    }
    $logRepo = new ExecutionLogRepository($db);
    $limit = 500;
    $logs = $logRepo->fetchByExecution($executionId, $entity, $limit);
    $totalLogs = $logRepo->countByExecution($executionId, $entity);
    JsonResponse::success([
        'execution' => $row,
        'logs' => $logs,
        'totalLogs' => $totalLogs,
        'truncated' => $totalLogs > count($logs),
    ]);
    exit;
}

$workflowId = (int) GETPOST('workflow_id', 'int');
$limit = max(1, min(200, (int) GETPOST('limit', 'int') ?: 50));
$offset = max(0, (int) GETPOST('offset', 'int'));

$statusRaw = strtolower(trim((string) GETPOST('status', 'alphanohtml')));
$statusFilter = null;
if ($statusRaw !== '') {
    if (!in_array($statusRaw, ExecutionRepository::executionListStatusWhitelist(), true)) {
        JsonResponse::error('validation_failed', 'Invalid status filter.', 400);
        exit;
    }
    $statusFilter = $statusRaw;
}

$rows = $executions->list($entity, $workflowId > 0 ? $workflowId : null, $limit, $offset, $statusFilter);

JsonResponse::success([
    'executions' => $rows,
    'counts' => $executions->statusCounts($entity),
]);
