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

use Knot\Api\CsrfGuard;
use Knot\Api\JsonResponse;
use Knot\Compatibility\Versioning\BreakingChangeDetector;
use Knot\Compatibility\Versioning\BundledSnapshotCatalog;
use Knot\Compatibility\Versioning\MigrationReportGenerator;
use Knot\Compatibility\Versioning\SchemaComparator;
use Knot\Compatibility\Versioning\SchemaSnapshotter;
use Knot\Compatibility\Versioning\WorkflowImpactAnalyzer;

JsonResponse::installFatalHandler();

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = (string) ($_GET['action'] ?? '');
$postPayload = [];
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
    $postPayload = is_array($decoded) ? $decoded : [];
    if (isset($postPayload['action'])) {
        $action = (string) $postPayload['action'];
    }
}

if ($method === 'GET' && $action === 'snapshot_live') {
    if (!$user->hasRight('knot', 'workflow', 'read')) {
        JsonResponse::error('permission_denied', 'Permission denied', 403);
        exit;
    }
    try {
        $snap = (new SchemaSnapshotter())->snapshot(null, $db, $langs);
        JsonResponse::success(['snapshot' => $snap]);
        exit;
    } catch (\Throwable $e) {
        error_log('[knot compatibility] snapshot_live: ' . $e->getMessage());
        JsonResponse::error('snapshot_failed', 'Could not build a live schema snapshot.', 500);
        exit;
    }
}

if ($method === 'GET' && $action === 'sample') {
    if (!$user->hasRight('knot', 'workflow', 'read')) {
        JsonResponse::error('permission_denied', 'Permission denied', 403);
        exit;
    }
    $path = __DIR__ . '/../data/compatibility/snapshots/sample-v1.json';
    if (!is_readable($path)) {
        JsonResponse::error('sample_missing', 'Bundled sample snapshot not found.', 404);
        exit;
    }
    $json = json_decode((string) file_get_contents($path), true);
    JsonResponse::success(['snapshot' => is_array($json) ? $json : []]);
    exit;
}

if ($method === 'GET' && $action === 'bundled_snapshots') {
    if (!$user->hasRight('knot', 'workflow', 'read')) {
        JsonResponse::error('permission_denied', 'Permission denied', 403);
        exit;
    }
    $catalog = BundledSnapshotCatalog::defaultFromApiDir(__DIR__);
    JsonResponse::success(['snapshots' => $catalog->listReferenceSnapshots()]);
    exit;
}

if ($method === 'GET' && $action === 'bundled_snapshot') {
    if (!$user->hasRight('knot', 'workflow', 'read')) {
        JsonResponse::error('permission_denied', 'Permission denied', 403);
        exit;
    }
    $file = basename((string) ($_GET['file'] ?? ''));
    $catalog = BundledSnapshotCatalog::defaultFromApiDir(__DIR__);
    $path = $catalog->resolveReadablePath($file);
    if ($path === null) {
        JsonResponse::error('snapshot_not_found', 'Bundled snapshot not found.', 404);
        exit;
    }
    $json = json_decode((string) file_get_contents($path), true);
    JsonResponse::success(['snapshot' => is_array($json) ? $json : []]);
    exit;
}

if ($method !== 'POST') {
    JsonResponse::error('method_not_allowed', 'Unsupported method', 405);
    exit;
}

if (!$user->hasRight('knot', 'workflow', 'write')) {
    JsonResponse::error('permission_denied', 'workflow write permission required', 403);
    exit;
}

if (!CsrfGuard::verify()) {
    JsonResponse::error('csrf_invalid', 'Invalid CSRF token', 403);
    exit;
}

$payload = $postPayload;

if ($action === '' && isset($payload['action'])) {
    $action = (string) $payload['action'];
}

try {
    switch ($action) {
        case 'diff':
            $baseline = is_array($payload['baseline'] ?? null) ? $payload['baseline'] : [];
            $target = is_array($payload['target'] ?? null) ? $payload['target'] : [];
            if ($baseline === [] || $target === []) {
                JsonResponse::error('invalid_payload', 'Fields baseline and target snapshots are required.', 400);
                exit;
            }
            $cmp = new SchemaComparator();
            $diff = $cmp->diff($baseline, $target);
            $detector = new BreakingChangeDetector();
            $breaking = $detector->classify($diff);

            $workflows = is_array($payload['workflows'] ?? null) ? $payload['workflows'] : [];
            $hints = $workflows !== []
                ? (new WorkflowImpactAnalyzer())->analyzeMany($workflows, $breaking)
                : [];

            $report = (new MigrationReportGenerator())->generateMarkdown(
                $diff,
                $breaking,
                $hints,
                [
                    'dolibarr_from' => (string) ($baseline['dolibarr_version'] ?? ''),
                    'dolibarr_to' => (string) ($target['dolibarr_version'] ?? ''),
                ]
            );

            JsonResponse::success([
                'diff' => $diff,
                'breaking' => $breaking,
                'workflow_hints' => $hints,
                'report_markdown' => $report,
            ]);
            exit;

        case 'snapshot_save':
            if (!$user->admin && !$user->hasRight('knot', 'admin', 'configure')) {
                JsonResponse::error('permission_denied', 'Saving snapshots requires admin or configure right.', 403);
                exit;
            }
            $snap = is_array($payload['snapshot'] ?? null) ? $payload['snapshot'] : [];
            if ($snap === []) {
                JsonResponse::error('invalid_payload', 'Field snapshot is required.', 400);
                exit;
            }
            $root = defined('DOL_DATA_ROOT') ? DOL_DATA_ROOT : sys_get_temp_dir();
            $dir = $root . '/knot/compatibility/snapshots/entity_' . (int) $conf->entity;
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            $filename = 'snapshot-' . gmdate('Ymd-His') . '.json';
            $path = $dir . '/' . $filename;
            file_put_contents($path, json_encode($snap, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
            JsonResponse::success(['saved' => true, 'path' => $path]);
            exit;

        default:
            JsonResponse::error('unknown_action', 'Unsupported action.', 400);
            exit;
    }
} catch (\Throwable $e) {
    error_log('[knot compatibility] ' . $e->getMessage());
    JsonResponse::error('compatibility_failed', 'Compatibility analysis failed. See server logs.', 500);
    exit;
}
