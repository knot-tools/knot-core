<?php
/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

if (!defined('NOCSRFCHECK')) {
    define('NOCSRFCHECK', '1');
}
if (!defined('NOTOKENRENEWAL')) {
    define('NOTOKENRENEWAL', '1');
}

require '../../../main.inc.php';
dol_include_once('/knot/class/autoload.php');

use Knot\Api\JsonResponse;
use Knot\Reporting\MetricsCollector;

JsonResponse::installFatalHandler();

if (!$user->hasRight('knot', 'workflow', 'read')) {
    JsonResponse::error('permission_denied', 'Permission denied', 403);
    exit;
}

$entity = (int) $conf->entity;

$days = (int) GETPOST('days', 'int');
if ($days <= 0) {
    $days = 7;
}
if ($days > 365) {
    $days = 365;
}

$limitTypes = (int) GETPOST('limit_types', 'int');
if ($limitTypes <= 0) {
    $limitTypes = 40;
}
if ($limitTypes > 200) {
    $limitTypes = 200;
}

$since = strtotime('-' . $days . ' days');
if ($since === false) {
    $since = strtotime('-7 days');
}

$collector = new MetricsCollector($db);

JsonResponse::success([
    'entity' => $entity,
    'window_days' => $days,
    'since_unix' => $since,
    'queue' => $collector->queueDepth($entity),
    'executions_total' => $collector->executionsTotal($entity, $since),
    'duration_quantiles' => $collector->durationQuantiles($entity, $since),
    'failure_heatmap' => $collector->failureHeatmap($entity, $since),
    'nodes_by_type' => $collector->nodeObservabilityByType($entity, $since, $limitTypes),
]);
