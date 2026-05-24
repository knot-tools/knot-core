<?php
/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

if (!defined('NOCSRFCHECK')) {
    define('NOCSRFCHECK', '1');
}
if (!defined('NOTOKENRENEWAL')) {
    define('NOTOKENRENEWAL', '1');
}
// We do NOT require login for this endpoint when the bearer token is set:
// Prometheus scrapers run unattended.
if (!defined('NOLOGIN')) {
    define('NOLOGIN', '1');
}

require '../../../main.inc.php';
dol_include_once('/knot/class/autoload.php');

use Knot\Api\JsonResponse;
use Knot\Reporting\MetricsCollector;
use Knot\Reporting\PrometheusFormatter;

JsonResponse::installFatalHandler();

/**
 * Knot Prometheus exporter (V2.5 observability sprint).
 *
 * Two output formats:
 *   - text/plain (Prometheus 0.0.4) — default, scrapeable by Prometheus.
 *   - application/json — when `?format=json` is passed, for the Knot
 *     dashboard heatmap.
 *
 * Authentication:
 *   - Gated by `KNOT_METRICS_PROMETHEUS_ENABLED` (Dolibarr global const).
 *   - Bearer token from `KNOT_METRICS_BEARER_TOKEN` is required if set.
 *   - When NOT set, only allows local IPs (127.0.0.1, ::1).
 */

if (!getDolGlobalInt('KNOT_METRICS_PROMETHEUS_ENABLED')) {
    header('HTTP/1.1 404 Not Found');
    echo 'Knot metrics endpoint disabled.';
    exit;
}

$expectedToken = (string) getDolGlobalString('KNOT_METRICS_BEARER_TOKEN');
$providedToken = '';
$authHeader = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) {
    $providedToken = trim((string) $m[1]);
}

if ($expectedToken !== '') {
    if ($providedToken === '' || !hash_equals($expectedToken, $providedToken)) {
        header('HTTP/1.1 401 Unauthorized');
        header('WWW-Authenticate: Bearer realm="knot-metrics"');
        echo 'Unauthorized.';
        exit;
    }
} else {
    $remoteAddr = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if (!in_array($remoteAddr, ['127.0.0.1', '::1', 'localhost'], true)) {
        header('HTTP/1.1 403 Forbidden');
        echo 'Forbidden — set KNOT_METRICS_BEARER_TOKEN to allow non-local scrapers.';
        exit;
    }
}

$entity = (int) (getDolGlobalString('KNOT_METRICS_ENTITY', '1') ?: 1);
$collector = new MetricsCollector($db);
$since = strtotime('-7 days');

$executions = $collector->executionsTotal($entity, $since);
$quantiles = $collector->durationQuantiles($entity, $since);
$queue = $collector->queueDepth($entity);

$format = (string) GETPOST('format', 'aZ09');
if ($format === 'json') {
    JsonResponse::success([
        'entity' => $entity,
        'executions' => $executions,
        'quantiles' => $quantiles,
        'queue' => $queue,
        'heatmap' => $collector->failureHeatmap($entity, $since),
    ]);
    exit;
}

header('Content-Type: text/plain; version=0.0.4; charset=utf-8');
echo PrometheusFormatter::format($executions, $quantiles, $queue);
exit;
