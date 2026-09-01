<?php
/* Copyright (C) 2026 Knot */

declare(strict_types=1);

if (!defined('NOCSRFCHECK')) { define('NOCSRFCHECK', '1'); }
if (!defined('NOTOKENRENEWAL')) { define('NOTOKENRENEWAL', '1'); }

require_once __DIR__ . '/../lib/load_dolibarr.inc.php';
dol_include_once('/knot/class/autoload.php');

use Knot\Api\CsrfGuard;
use Knot\Api\JsonResponse;
use Knot\Engine\CronWorker;
use Knot\Engine\WorkflowValidationException;
use Knot\Errors\DolibarrErrorTranslator;
use Knot\Errors\KnotError;
use Knot\Errors\SchemaViolationError;
use Knot\Repository\ExecutionRepository;
use Knot\Repository\IdempotencyRepository;
use Knot\Repository\WorkflowRepository;
use Knot\Security\RateLimiter;

JsonResponse::installFatalHandler();

if (!$user->hasRight('knot', 'workflow', 'execute')) {
    JsonResponse::error('permission_denied', 'Permission denied', 403);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    JsonResponse::error('method_not_allowed', 'Method not allowed', 405);
    exit;
}

if (!CsrfGuard::verify()) {
    JsonResponse::error('csrf_invalid', 'Invalid CSRF token', 403);
    exit;
}

// Per-user rate limiting on the execute endpoint (sync + async). Prevents
// a compromised account from carpet-bombing the worker with thousands of
// runs per minute, which would either DoS Dolibarr or rack up SaaS spend
// (Stripe / Twilio / etc.) before anyone notices.
$execLimiter = new RateLimiter($db);
$execMax = function_exists('getDolGlobalInt')
    ? max(0, (int) getDolGlobalInt('MAIN_KNOT_EXECUTE_RATE_LIMIT_PER_MIN'))
    : 60;
if ($execMax === 0) {
    $execMax = 60;
}
$execBucket = 'execute:user:' . (int) $user->id;
if (!$execLimiter->consume($execBucket, $execMax)) {
    header('Retry-After: ' . $execLimiter->retryAfterSeconds());
    JsonResponse::error('rate_limited', 'Too many execution requests; please slow down.', 429);
    exit;
}

$rawBody = (string) file_get_contents('php://input');
$jsonPayload = [];
if (str_starts_with((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') && $rawBody !== '') {
    $decoded = json_decode($rawBody, true);
    $jsonPayload = is_array($decoded) ? $decoded : [];
}

$workflowId = (int) ($jsonPayload['workflowId'] ?? $jsonPayload['workflow_id'] ?? GETPOST('workflow_id', 'int'));
if ($workflowId <= 0) {
    JsonResponse::error('validation_failed', 'workflow_id is required.', 400);
    exit;
}

$triggerData = [
    'manual' => true,
    'userId' => (int) $user->id,
    'queuedAt' => dol_print_date(dol_now(), 'dayhourlog'),
];
$fromNode = (string) ($jsonPayload['fromNode'] ?? $jsonPayload['startNodeId'] ?? GETPOST('from_node', 'alphanohtml'));
if ($fromNode !== '') {
    $triggerData['_startNodeId'] = preg_replace('/[^a-zA-Z0-9_.:-]/', '', $fromNode) ?: '';
    $triggerData['_inputData'] = is_array($jsonPayload['inputData'] ?? null) ? $jsonPayload['inputData'] : [];
}

$dryRun = filter_var($jsonPayload['dryRun'] ?? GETPOST('dry_run', 'alphanohtml'), FILTER_VALIDATE_BOOLEAN);
if ($dryRun) {
    $triggerData['_dryRun'] = true;
    $triggerData['manual'] = true;
}

$entity = (int) $conf->entity;
$idempotencyKey = (string) ($jsonPayload['idempotencyKey'] ?? $jsonPayload['idempotency_key'] ?? GETPOST('idempotency_key', 'alphanohtml'));
$idempotency = new IdempotencyRepository($db);
if ($idempotencyKey !== '') {
    $existingExecutionId = $idempotency->findExecution($idempotencyKey, $entity);
    if ($existingExecutionId !== null) {
        JsonResponse::success(['executionId' => $existingExecutionId, 'idempotentReplay' => true], 200);
        exit;
    }
    $triggerData['_idempotencyKey'] = $idempotencyKey;
}

$mode = (string) ($jsonPayload['mode'] ?? GETPOST('mode', 'aZ09'));
if ($dryRun) {
    $mode = 'sync';
}

if ($mode === 'sync') {
    $worker = new CronWorker();
    $ctx = $worker->buildContext();
    /** @var WorkflowRepository $workflows */
    $workflows = $ctx['workflows'];
    $engine = $ctx['engine'];
    $workflow = $workflows->fetch($workflowId, $entity);
    if ($workflow === null) {
        JsonResponse::error('not_found', 'Workflow not found.', 404);
        exit;
    }
    $definition = is_array($workflow['definition'] ?? null) ? $workflow['definition'] : [];
    @set_time_limit(25);
    $startedAt = microtime(true);
    try {
        $finalContext = $engine->execute($definition, $triggerData, [
            'idempotencyKey' => $triggerData['_idempotencyKey'] ?? null,
            'startNodeId' => $triggerData['_startNodeId'] ?? null,
            'inputData' => is_array($triggerData['_inputData'] ?? null) ? $triggerData['_inputData'] : null,
            'dryRun' => (bool) ($triggerData['_dryRun'] ?? false),
        ]);
        $durationMs = (int) ((microtime(true) - $startedAt) * 1000);
        $logs = is_array($finalContext['_logs'] ?? null) ? $finalContext['_logs'] : [];
        unset($finalContext['_logs']);
        $status = $engine->hasNodeErrors() ? 'error' : 'success';
        $payload = [
            'mode' => 'sync',
            'status' => $status,
            'durationMs' => $durationMs,
            'logs' => $logs,
            'finalContext' => $finalContext,
            'dryRun' => (bool) ($triggerData['_dryRun'] ?? false),
        ];
        if ($status === 'error') {
            $payload['errorMessage'] = $engine->firstNodeErrorMessage()
                ?? 'One or more workflow nodes failed.';
            $knotPayload = $engine->firstNodeKnotError();
            if ($knotPayload !== null) {
                $payload['errorPayload'] = $knotPayload;
            }
        }
        JsonResponse::success($payload);
        exit;
    } catch (WorkflowValidationException $e) {
        $durationMs = (int) ((microtime(true) - $startedAt) * 1000);
        $err = new SchemaViolationError(
            'KNOT_SCHEMA_WORKFLOW_INVALID',
            'This workflow definition is invalid.',
            $e->getMessage(),
            'https://knot.tools/docs/errors/catalog#knot-schema-workflow-invalid',
            ['mode' => 'sync', 'durationMs' => $durationMs],
            'Fix triggers, nodes, and edges in the editor.',
            'warning',
            $e
        );
        JsonResponse::knotError($err);
        exit;
    } catch (KnotError $e) {
        JsonResponse::knotError($e);
        exit;
    } catch (\Throwable $t) {
        $durationMs = (int) ((microtime(true) - $startedAt) * 1000);
        $knot = (new DolibarrErrorTranslator())->translate($t, [
            'endpoint' => 'execute.php',
            'workflowId' => $workflowId,
            'mode' => 'sync',
            'durationMs' => $durationMs,
        ]);
        JsonResponse::knotError($knot);
        exit;
    }
}

$executions = new ExecutionRepository($db);
$executionId = $executions->enqueue($workflowId, 'manual', $triggerData, $entity);
if ($executionId <= 0) {
    JsonResponse::error('queue_failed', 'Unable to queue workflow execution.', 500);
    exit;
}
if ($idempotencyKey !== '') {
    $idempotency->remember($idempotencyKey, $executionId, $entity);
}

JsonResponse::success(['executionId' => $executionId], 202);
