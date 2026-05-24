<?php
/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

if (!defined('NOCSRFCHECK')) { define('NOCSRFCHECK', '1'); }
if (!defined('NOTOKENRENEWAL')) { define('NOTOKENRENEWAL', '1'); }

require '../../../main.inc.php';
dol_include_once('/knot/class/autoload.php');

use Knot\Api\JsonResponse;
use Knot\Dolibarr\NativeWorkflowScanner;
use Knot\Reporting\ConflictAnalyzer;
use Knot\Reporting\CascadePredictor;
use Knot\Repository\WorkflowRepository;

JsonResponse::installFatalHandler();

if (!$user->hasRight('knot', 'workflow', 'read')) {
    JsonResponse::error('permission_denied', 'Permission denied', 403);
    exit;
}

$entity = (int) $conf->entity;
$action = (string) GETPOST('action', 'alphanohtml');
$workflows = new WorkflowRepository($db);
$scanner = new NativeWorkflowScanner($db);

if ($action === 'native') {
    JsonResponse::success(['nativeWorkflows' => $scanner->scan($entity)]);
    exit;
}

if ($action === 'cascade') {
    $workflowId = (int) GETPOST('workflow_id', 'int');
    $workflow = $workflowId > 0 ? $workflows->fetch($workflowId, $entity) : null;
    if ($workflow === null) {
        JsonResponse::error('not_found', 'Workflow not found', 404);
        exit;
    }
    $definition = is_array($workflow['definition'] ?? null) ? $workflow['definition'] : [];
    JsonResponse::success(['cascade' => (new CascadePredictor())->predict($definition)]);
    exit;
}

$analyzer = new ConflictAnalyzer($workflows, $scanner);
JsonResponse::success(['report' => $analyzer->analyze($entity)]);
