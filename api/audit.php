<?php
/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

if (!defined('NOCSRFCHECK')) { define('NOCSRFCHECK', '1'); }
if (!defined('NOTOKENRENEWAL')) { define('NOTOKENRENEWAL', '1'); }

require '../../../main.inc.php';
dol_include_once('/knot/class/autoload.php');

use Knot\Api\JsonResponse;
use Knot\Repository\AuditLogRepository;

JsonResponse::installFatalHandler();

if (!$user->hasRight('knot', 'workflow', 'read') && !$user->admin) {
    JsonResponse::error('permission_denied', 'Permission denied', 403);
    exit;
}

$entity = (int) $conf->entity;
$repo = new AuditLogRepository($db);

$actionTypeRaw = trim((string) (GETPOST('action_type', 'nohtml') ?: GETPOST('actionType', 'nohtml')));
$actionType = ($actionTypeRaw !== '' && preg_match('/^[a-zA-Z0-9._-]{1,128}$/', $actionTypeRaw) === 1)
    ? $actionTypeRaw
    : null;
$entityTypeRaw = trim((string) (GETPOST('entity_type', 'nohtml') ?: GETPOST('entityType', 'nohtml')));
$entityType = ($entityTypeRaw !== '' && preg_match('/^[a-zA-Z0-9._-]{1,128}$/', $entityTypeRaw) === 1)
    ? $entityTypeRaw
    : null;
$qRaw = trim((string) GETPOST('q', 'restricthtml'));
$q = $qRaw !== '' ? mb_substr($qRaw, 0, 200) : null;

$filters = [
    'actionType' => $actionType,
    'entityType' => $entityType,
    'userId' => (GETPOST('user_id', 'int') !== '' && GETPOST('user_id', 'int') !== null) ? (int) GETPOST('user_id', 'int') : null,
    'since' => GETPOST('since', 'alphanohtml') ?: null,
    'q' => $q,
];
$format = strtolower((string) GETPOST('format', 'aZ09'));

// V2.5.0b-ux-ops (plan chantier 7.F) — CSV export for compliance / GDPR
// audits. Up to 5 000 rows per export to keep the response cheap.
if ($format === 'csv') {
    $limit = max(1, min((int) GETPOST('limit', 'int') ?: 5000, 5000));
    $offset = max(0, (int) GETPOST('offset', 'int'));
    $rows = $repo->listRecent($entity, $filters, $limit, $offset);

    $filename = 'knot-audit-' . gmdate('Ymd-His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    if ($out === false) { exit; }

    // BOM so Excel recognises UTF-8.
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['id', 'createdAt', 'actionType', 'entityType', 'entityId', 'userId', 'ip', 'payload']);
    foreach ($rows as $row) {
        $payload = is_array($row['payload'] ?? null) ? $row['payload'] : [];
        $payloadJson = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        fputcsv($out, [
            (string) ($row['id'] ?? ''),
            (string) ($row['createdAt'] ?? ''),
            (string) ($row['actionType'] ?? ''),
            (string) ($row['entityType'] ?? ''),
            $row['entityId'] !== null ? (string) $row['entityId'] : '',
            $row['userId'] !== null ? (string) $row['userId'] : '',
            (string) ($row['ip'] ?? ''),
            $payloadJson,
        ]);
    }
    fclose($out);

    // Trace the export itself so a forensic reviewer can prove who
    // pulled which slice of the audit log and when.
    $repo->record('audit.export_csv', 'audit_log', null, (int) $user->id, [
        'rows' => count($rows),
        'filters' => $filters,
        'limit' => $limit,
        'offset' => $offset,
    ], $entity);
    exit;
}

$limit = max(1, min((int) GETPOST('limit', 'int') ?: 100, 500));
$offset = max(0, (int) GETPOST('offset', 'int'));

JsonResponse::success([
    'audit' => $repo->listRecent($entity, $filters, $limit, $offset),
    'limit' => $limit,
    'offset' => $offset,
]);
