<?php
/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

// Knot uses its own CSRF guard (CsrfGuard::verify reads X-Csrf-Token).
// Bypass Dolibarr's auto-CSRF that blocks POSTs without ?token=... in the URL.
if (!defined('NOCSRFCHECK')) { define('NOCSRFCHECK', '1'); }
if (!defined('NOTOKENRENEWAL')) { define('NOTOKENRENEWAL', '1'); }

require_once __DIR__ . '/../lib/load_dolibarr.inc.php';
dol_include_once('/knot/class/autoload.php');

use Knot\Api\CsrfGuard;
use Knot\Api\JsonResponse;
use Knot\Api\WorkflowCreateGuard;
use Knot\Capabilities\CapabilitiesBuilder;
use Knot\Capabilities\WorkflowImportAnalyzer;
use Knot\Engine\WorkflowValidator;
use Knot\Engine\WorkflowDefinitionNormalizer;
use Knot\Errors\SchemaViolationError;
use Knot\Licensing\Bootstrap;
use Knot\Marketplace\TierGate;
use Knot\Marketplace\WorkflowTierAuditor;
use Knot\Repository\AuditLogRepository;
use Knot\Repository\ScheduleRepository;
use Knot\Repository\WorkflowRepository;
use Knot\Repository\WorkflowTagRepository;
use Knot\Repository\WorkflowVersionRepository;
use Knot\Security\WorkflowActivationGuard;
use Knot\Security\WorkflowRiskAnalyzer;

JsonResponse::installFatalHandler();

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$entity = (int) $conf->entity;
$repo = new WorkflowRepository($db);
$tags = new WorkflowTagRepository($db);
$versions = new WorkflowVersionRepository($db);
$audit = new AuditLogRepository($db);
$schedulesRepo = new ScheduleRepository($db);
$extRegistry = Bootstrap::buildExtensionRegistry($db);
$activationGuard = WorkflowActivationGuard::create($extRegistry, $audit);

if ($method === 'GET') {
    if (!$user->hasRight('knot', 'workflow', 'read')) {
        JsonResponse::error('permission_denied', 'Permission denied', 403);
        exit;
    }

    $workflowId = (int) GETPOST('id', 'int');
    $action = (string) GETPOST('action', 'nohtml');
    if ($workflowId > 0) {
        $workflow = $repo->fetch($workflowId, $entity);
        if ($workflow === null) {
            JsonResponse::error('not_found', 'Workflow not found', 404);
            exit;
        }
        $workflow['tags'] = $tags->listForWorkflow($workflowId, $entity);
        $workflow['favorite'] = in_array('favorite', $workflow['tags'], true);
        $extReg = Bootstrap::buildExtensionRegistry($db);
        $workflow['risk'] = (new WorkflowRiskAnalyzer(extensions: $extReg))
            ->analyze(is_array($workflow['definition'] ?? null) ? $workflow['definition'] : [])
            ->toArray();
        if ($action === 'export') {
            $filename = sprintf('knot-workflow-%s.json', $workflow['ref'] ?? (string) $workflowId);
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            echo json_encode([
                'knotExport' => '1.0',
                'exportedAt' => gmdate('c'),
                'workflow' => [
                    'ref' => $workflow['ref'] ?? null,
                    'label' => $workflow['label'] ?? '',
                    'description' => $workflow['description'] ?? '',
                    'definition' => $workflow['definition'] ?? [],
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            exit;
        }
        if ($action === 'versions') {
            JsonResponse::success(['versions' => $versions->list($workflowId, $entity)]);
            exit;
        }
        if ($action === 'diff') {
            $leftId = (int) GETPOST('leftId', 'int');
            $rightId = (int) GETPOST('rightId', 'int');
            $leftDef = is_array($workflow['definition'] ?? null) ? $workflow['definition'] : [];
            $rightDef = $leftDef;
            if ($leftId > 0) {
                $left = $versions->fetch($leftId, $workflowId, $entity);
                if ($left === null) {
                    JsonResponse::error('not_found', 'Left version not found.', 404);
                }
                $leftDef = is_array($left['definition'] ?? null) ? $left['definition'] : [];
            }
            if ($rightId > 0) {
                $right = $versions->fetch($rightId, $workflowId, $entity);
                if ($right === null) {
                    JsonResponse::error('not_found', 'Right version not found.', 404);
                }
                $rightDef = is_array($right['definition'] ?? null) ? $right['definition'] : [];
            }
            $diff = (new \Knot\Engine\WorkflowDiffer())->diff($leftDef, $rightDef);
            JsonResponse::success([
                'diff' => $diff,
                'left' => ['id' => $leftId ?: null, 'definition' => $leftDef],
                'right' => ['id' => $rightId ?: null, 'definition' => $rightDef],
            ]);
            exit;
        }
        JsonResponse::success(['workflow' => $workflow]);
        exit;
    }

    if ($action === 'export_bulk') {
        $idsRaw = (string) GETPOST('ids', 'alphanohtml');
        $ids = array_values(array_filter(array_map('intval', explode(',', $idsRaw))));
        if ($ids === []) {
            JsonResponse::error('validation_failed', 'ids are required.', 400);
            exit;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'knot_zip_');
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            JsonResponse::error('zip_failed', 'Unable to create ZIP archive.', 500);
            exit;
        }
        $manifest = ['knotExport' => '1.0', 'exportedAt' => gmdate('c'), 'workflows' => []];
        foreach ($ids as $id) {
            $wf = $repo->fetch($id, $entity);
            if ($wf === null) { continue; }
            $slug = preg_replace('/[^a-zA-Z0-9_-]+/', '-', (string) ($wf['ref'] ?? (string) $id));
            $payloadJson = [
                'knotExport' => '1.0',
                'exportedAt' => gmdate('c'),
                'workflow' => [
                    'ref' => $wf['ref'] ?? null,
                    'label' => $wf['label'] ?? '',
                    'description' => $wf['description'] ?? '',
                    'definition' => $wf['definition'] ?? [],
                ],
            ];
            $zip->addFromString(
                'workflows/' . $slug . '.knot.json',
                (string) json_encode($payloadJson, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            );
            $manifest['workflows'][] = ['id' => $id, 'ref' => $wf['ref'] ?? null, 'label' => $wf['label'] ?? ''];
        }
        $zip->addFromString('manifest.json', (string) json_encode($manifest, JSON_PRETTY_PRINT));
        $zip->close();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="knot-workflows-' . gmdate('Ymd-His') . '.zip"');
        readfile($tmp);
        @unlink($tmp);
        exit;
    }

    $statusFilter = GETPOST('status', 'alpha');
    $statuses = is_string($statusFilter) && $statusFilter !== '' ? [$statusFilter] : [];
    $limit = max(1, min(200, (int) GETPOST('limit', 'int') ?: 50));
    $offset = max(0, (int) GETPOST('offset', 'int'));
    $rawSearch = GETPOST('q', 'alphanohtml');
    $searchTerm = is_string($rawSearch) ? trim($rawSearch) : '';

    JsonResponse::success([
        'workflows' => enrichWorkflowRisk(
            enrichWorkflowTags(
                $repo->list($entity, $statuses, $limit, $offset, $searchTerm),
                $tags,
                $entity
            ),
            $repo,
            new WorkflowRiskAnalyzer(extensions: $extRegistry),
            $entity,
        ),
        'counts' => $repo->countByStatus($entity),
    ]);
    exit;
}

$rawBody = (string) file_get_contents('php://input');
$payload = json_decode($rawBody, true);

// V2.8 — semantic lint (read-only); single php://input read for entire POST tree.
if (is_array($payload) && ($payload['action'] ?? '') === 'lint') {
    if (!$user->hasRight('knot', 'workflow', 'read')) {
        JsonResponse::error('permission_denied', 'Permission denied', 403);
        exit;
    }
    if (!CsrfGuard::verify()) {
        JsonResponse::error('csrf_invalid', 'Invalid CSRF token', 403);
        exit;
    }
    $maxLintBytes = 512 * 1024;
    if (strlen($rawBody) > $maxLintBytes) {
        JsonResponse::error('payload_too_large', 'Lint payload too large.', 413);
        exit;
    }
    $def = is_array($payload['definition'] ?? null) ? $payload['definition'] : [];
    $def['schemaVersion'] = (string) ($def['schemaVersion'] ?? '1.0');
    $def['workflow'] = is_array($def['workflow'] ?? null) ? $def['workflow'] : [];
    $def['nodes'] = is_array($def['nodes'] ?? null) ? $def['nodes'] : [];
    $def['edges'] = is_array($def['edges'] ?? null) ? $def['edges'] : [];
    $extReg = Bootstrap::buildExtensionRegistry($db);
    $allow = array_keys((new \Knot\Connectors\ConnectorRegistry())->allWithExtensions($extReg));
    $validator = new WorkflowValidator($allow);
    $issues = $validator->validateAll($def);
    $errors = array_values(array_filter($issues, static fn ($i) => ($i['severity'] ?? '') === 'error'));
    JsonResponse::success([
        'issues' => $issues,
        'valid' => $errors === [],
        'errorCount' => count($errors),
        'warningCount' => count(array_filter($issues, static fn ($i) => ($i['severity'] ?? '') === 'warning')),
    ]);
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

$action = (string) GETPOST('action', 'nohtml');

// V2.5.0b-ux-ops (plan chantier 7.G) — clone an existing workflow
// into a new draft. POST /api/workflows.php?action=clone&id=N with
// optional `label` query param. The cloned workflow gets a fresh
// `ref`, status `draft` and an initial version snapshot. We branch
// before the JSON body parse because the UI sends an empty body.
$earlyAction = (string) GETPOST('action', 'nohtml');
if ($earlyAction === 'clone') {
    $sourceId = (int) GETPOST('id', 'int');
    if ($sourceId <= 0) {
        JsonResponse::error('validation_failed', 'id is required.', 400);
        exit;
    }
    $source = $repo->fetch($sourceId, $entity);
    if ($source === null) {
        JsonResponse::error('not_found', 'Workflow not found', 404);
        exit;
    }
    $sourceLabel = (string) ($source['label'] ?? 'Workflow');
    $overrideLabel = trim((string) GETPOST('label', 'alphanohtml'));
    $cloneLabel = $overrideLabel !== '' ? $overrideLabel : $sourceLabel . ' (copy)';
    $sourceDefinition = is_array($source['definition'] ?? null) ? $source['definition'] : [];
    $newId = $repo->create([
        'label' => $cloneLabel,
        'description' => (string) ($source['description'] ?? ''),
        'status' => 'draft',
        'definition' => $sourceDefinition,
    ], $entity, (int) $user->id);
    if ($newId <= 0) {
        JsonResponse::error('clone_failed', 'Unable to clone workflow', 500);
        exit;
    }
    $audit->record('workflow.clone', 'workflow', $newId, (int) $user->id, [
        'sourceId' => $sourceId,
        'label' => $cloneLabel,
    ], $entity);
    $cloned = $repo->fetch($newId, $entity);
    if ($cloned !== null && is_array($cloned['definition'] ?? null)) {
        $versions->createSnapshot($newId, $cloned['definition'], $entity, (int) $user->id, 'Initial version (clone)', true);
    }
    JsonResponse::success(['workflow' => $cloned], 201);
    exit;
}

if (!is_array($payload)) {
    JsonResponse::error('invalid_payload', 'Invalid JSON payload', 400);
    exit;
}

if (!empty($payload['knotExport']) && isset($payload['workflow']) && is_array($payload['workflow'])) {
    $imported = $payload['workflow'];
    $importedDefinition = is_array($imported['definition'] ?? null) ? $imported['definition'] : [];
    // V2.5.0d — refuse single-file imports targeting paid connectors
    // we cannot run on this instance. The bulk path handles its own
    // per-item auditing further down.
    $singleAuditor = new WorkflowTierAuditor(new TierGate(Bootstrap::buildExtensionRegistry($db)));
    $singleAudited = $singleAuditor->audit($importedDefinition);
    if ($singleAudited['blocked']) {
        $audit->record(
            'workflow.import_blocked_tier',
            'workflow',
            null,
            (int) $user->id,
            [
                'label' => (string) ($imported['label'] ?? 'Imported workflow'),
                'missing' => $singleAudited['missing'],
            ],
            $entity
        );
        JsonResponse::error(
            'license_required',
            'Workflow uses connectors that require an active paid licence on this instance.',
            422,
            ['missing' => $singleAudited['missing']]
        );
        exit;
    }
    $importStatus = strtolower((string) ($imported['status'] ?? 'draft'));
    if ($importStatus === 'active') {
        $audit->record('workflow.import_downgraded_active', 'workflow', null, (int) $user->id, [
            'label' => (string) ($imported['label'] ?? 'Imported workflow'),
        ], $entity);
    }
    $payload = [
        'label' => (string) ($imported['label'] ?? 'Imported workflow'),
        'description' => (string) ($imported['description'] ?? ''),
        'status' => 'draft',
        'definition' => $importedDefinition,
    ];
}

if ($action === 'import_precheck' && isset($payload['workflows']) && is_array($payload['workflows'])) {
    $builder = new CapabilitiesBuilder($db, $conf, $entity);
    $cached = $builder->loadCache();
    $manifest = $cached ?? $builder->build();
    if ($cached === null) {
        $builder->saveCache($manifest);
    }
    $warnings = WorkflowImportAnalyzer::analyze($payload['workflows'], $manifest);
    JsonResponse::success([
        'warnings' => $warnings,
        'knot_version' => $manifest['knot']['version'] ?? null,
    ]);
    exit;
}

if ($action === 'import_bulk' || (isset($payload['workflows']) && is_array($payload['workflows']))) {
    $items = is_array($payload['workflows'] ?? null) ? $payload['workflows'] : [];
    $imported = 0;
    $skipped = 0;
    $createdIds = [];
    // V2.5.0d — refuse to import a workflow that targets paid connectors
    // the instance has no active licence for. Otherwise the workflow would
    // sit there as draft and fail at runtime with a confusing
    // `connector_not_found`. The blocked items are reported in `errors`
    // so the UI can surface a precise message per workflow.
    $tierAuditor = new WorkflowTierAuditor(new TierGate(Bootstrap::buildExtensionRegistry($db)));
    $errors = [];
    foreach ($items as $item) {
        $wf = is_array($item['workflow'] ?? null) ? $item['workflow'] : (is_array($item) ? $item : []);
        $label = (string) ($wf['label'] ?? '');
        $definition = is_array($wf['definition'] ?? null) ? $wf['definition'] : [];
        $itemStatus = strtolower((string) ($wf['status'] ?? 'draft'));
        if ($itemStatus === 'active') {
            $audit->record('workflow.import_downgraded_active', 'workflow', null, (int) $user->id, [
                'label' => $label,
            ], $entity);
        }
        if ($label === '' || $definition === []) { $skipped++; continue; }
        $audited = $tierAuditor->audit($definition);
        if ($audited['blocked']) {
            $skipped++;
            $errors[] = [
                'label' => $label,
                'reason' => 'license_required',
                'missing' => $audited['missing'],
            ];
            $audit->record(
                'workflow.import_blocked_tier',
                'workflow',
                null,
                (int) $user->id,
                ['label' => $label, 'missing' => $audited['missing']],
                $entity
            );
            continue;
        }
        $newId = $repo->create([
            'label' => $label,
            'description' => (string) ($wf['description'] ?? ''),
            'status' => 'draft',
            'definition' => $definition,
        ], $entity, (int) $user->id);
        if ($newId > 0) {
            $imported++;
            $createdIds[] = $newId;
            $audit->record('workflow.import', 'workflow', $newId, (int) $user->id, ['label' => $label], $entity);
            syncSchedulesFromDefinition($schedulesRepo, $newId, $entity, $definition);
        } else {
            $skipped++;
        }
    }
    JsonResponse::success([
        'imported' => $imported,
        'skipped' => $skipped,
        'ids' => $createdIds,
        'errors' => $errors,
    ], 201);
    exit;
}

$workflowId = (int) ($payload['id'] ?? GETPOST('id', 'int') ?? 0);
$definition = is_array($payload['definition'] ?? null) ? $payload['definition'] : null;
$label = isset($payload['label']) ? (string) $payload['label'] : null;
$description = isset($payload['description']) ? (string) $payload['description'] : null;
$status = isset($payload['status']) ? (string) $payload['status'] : null;

if ($definition !== null) {
    $definition['schemaVersion'] = (string) ($definition['schemaVersion'] ?? '1.0');
    $definition['workflow'] = is_array($definition['workflow'] ?? null) ? $definition['workflow'] : [];
    $definition['nodes'] = is_array($definition['nodes'] ?? null) ? $definition['nodes'] : [];
    $definition['edges'] = is_array($definition['edges'] ?? null) ? $definition['edges'] : [];

    if (!empty($definition['nodes'])) {
        $definition = (new WorkflowDefinitionNormalizer())->normalize($definition);
        $extReg = Bootstrap::buildExtensionRegistry($db);
        $allow = array_keys((new \Knot\Connectors\ConnectorRegistry())->allWithExtensions($extReg));
        $validator = new WorkflowValidator($allow);
        $issues = $validator->validateAll($definition);
        $errors = array_values(array_filter($issues, static fn ($i) => ($i['severity'] ?? '') === 'error'));
        if ($errors !== []) {
            $audit->record('knot.error', 'workflow', $workflowId > 0 ? $workflowId : null, (int) $user->id, [
                'code' => 'KNOT_SCHEMA_WORKFLOW_INVALID',
                'issues_count' => count($issues),
            ], $entity);
            $err = new SchemaViolationError(
                'KNOT_SCHEMA_WORKFLOW_INVALID',
                'The workflow graph failed validation.',
                json_encode($errors, JSON_UNESCAPED_UNICODE) ?: 'validation errors',
                'https://knot.tools/docs/errors/catalog#knot-schema-workflow-invalid',
                ['issues' => $issues],
                'Review invalid nodes or edges listed in the issues payload.',
                'warning'
            );
            JsonResponse::knotError($err);
            exit;
        }
    }
}

if ($workflowId > 0 && $action === 'rollback') {
    $versionId = (int) ($payload['versionId'] ?? $payload['version_id'] ?? 0);
    $version = $versions->fetch($versionId, $workflowId, $entity);
    if ($version === null) {
        JsonResponse::error('not_found', 'Workflow version not found', 404);
        exit;
    }
    $rollbackDefinition = is_array($version['definition'] ?? null) ? $version['definition'] : [];
    $versions->createSnapshot($workflowId, $rollbackDefinition, $entity, (int) $user->id, 'Rollback from #' . $versionId, true, $versionId);
    if (!$repo->update($workflowId, ['definition' => $rollbackDefinition], $entity, (int) $user->id)) {
        JsonResponse::error('rollback_failed', 'Unable to rollback workflow', 500);
        exit;
    }
    $audit->record('workflow.rollback', 'workflow', $workflowId, (int) $user->id, [
        'versionId' => $versionId,
    ], $entity);
    JsonResponse::success(['workflow' => $repo->fetch($workflowId, $entity)]);
    exit;
}

if ($workflowId > 0 && $action === 'name_version') {
    $versionId = (int) ($payload['versionId'] ?? $payload['version_id'] ?? 0);
    $versionLabel = trim((string) ($payload['label'] ?? ''));
    if ($versionId <= 0 || $versionLabel === '') {
        JsonResponse::error('validation_failed', 'versionId and label are required.', 400);
        exit;
    }
    if (!$versions->nameVersion($versionId, $workflowId, $entity, $versionLabel)) {
        JsonResponse::error('update_failed', 'Unable to name workflow version', 500);
        exit;
    }
    $audit->record('workflow.version.name', 'workflow', $workflowId, (int) $user->id, [
        'versionId' => $versionId,
        'label' => $versionLabel,
    ], $entity);
    JsonResponse::success(['versions' => $versions->list($workflowId, $entity)]);
    exit;
}

if ($action === 'bulk') {
    $ids = array_values(array_filter(array_map('intval', is_array($payload['ids'] ?? null) ? $payload['ids'] : [])));
    $operation = (string) ($payload['operation'] ?? '');
    if ($ids === [] || $operation === '') {
        JsonResponse::error('validation_failed', 'ids and operation are required.', 400);
        exit;
    }

    $criticalAck = knotPayloadBool($payload, 'critical_activation_acknowledged', 'criticalActivationAcknowledged');
    if ($operation === 'active') {
        $blocked = [];
        foreach ($ids as $id) {
            $wf = $repo->fetch($id, $entity);
            if ($wf === null) {
                continue;
            }
            $check = $activationGuard->checkActivation($wf, ['status' => 'active'], $criticalAck);
            if ($check['blocked']) {
                $blocked[] = [
                    'workflowId' => $id,
                    'label' => (string) ($wf['label'] ?? ''),
                    'risk' => $check['report']?->toArray(),
                ];
            }
        }
        if ($blocked !== []) {
            JsonResponse::error(
                'workflow_activation_requires_acknowledgement',
                'One or more workflows require critical activation acknowledgement.',
                409,
                ['workflows' => $blocked]
            );
            exit;
        }
    }

    foreach ($ids as $id) {
        if ($operation === 'delete') {
            $repo->delete($id, $entity);
            $schedulesRepo->deleteForWorkflow($id, $entity);
            continue;
        }
        if (in_array($operation, ['active', 'disabled', 'archived'], true)) {
            $existing = $repo->fetch($id, $entity);
            $repo->update($id, ['status' => $operation], $entity, (int) $user->id);
            $schedulesRepo->setActiveForWorkflow($id, $entity, $operation === 'active');
            if ($operation === 'active' && $existing !== null) {
                $def = is_array($existing['definition'] ?? null) ? $existing['definition'] : [];
                $report = (new WorkflowRiskAnalyzer(extensions: $extRegistry))->analyze($def);
                if ($report->hasCritical() && $criticalAck) {
                    $activationGuard->recordCriticalActivationAudit(
                        $id,
                        (int) $user->id,
                        $entity,
                        $report,
                        (bool) ($existing['activationWarningDismissed'] ?? false)
                    );
                }
            }
            continue;
        }
        if ($operation === 'favorite') {
            $tags->addTagsToMany([$id], ['favorite'], $entity);
            continue;
        }
        if ($operation === 'tag') {
            $tags->addTagsToMany([$id], is_array($payload['tags'] ?? null) ? $payload['tags'] : [], $entity);
        }
    }

    $audit->record('workflow.bulk', 'workflow', 0, (int) $user->id, [
        'ids' => $ids,
        'operation' => $operation,
    ], $entity);
    JsonResponse::success(['updated' => count($ids)]);
    exit;
}

$data = array_filter([
    'label' => $label,
    'description' => $description,
    'status' => $status,
    'definition' => $definition,
], static fn ($v): bool => $v !== null);

$criticalAck = knotPayloadBool($payload, 'critical_activation_acknowledged', 'criticalActivationAcknowledged');
if (knotPayloadHas($payload, 'activation_warning_dismissed', 'activationWarningDismissed')) {
    $data['activation_warning_dismissed'] = knotPayloadBool(
        $payload,
        'activation_warning_dismissed',
        'activationWarningDismissed'
    );
}

if ($workflowId > 0) {
    $existing = $repo->fetch($workflowId, $entity);
    if ($existing === null) {
        JsonResponse::error('not_found', 'Workflow not found', 404);
        exit;
    }
    $activationCheck = $activationGuard->checkActivation($existing, $data, $criticalAck);
    if ($activationCheck['blocked']) {
        JsonResponse::error(
            'workflow_activation_requires_acknowledgement',
            'Critical workflow activation requires explicit acknowledgement.',
            409,
            ['risk' => $activationCheck['report']?->toArray()]
        );
        exit;
    }
    $dismissReset = $activationGuard->resolveDismissFlag($existing, $data);
    if ($dismissReset !== null) {
        $data['activation_warning_dismissed'] = $dismissReset;
    }
    if (!$repo->update($workflowId, $data, $entity, (int) $user->id)) {
        JsonResponse::error('update_failed', 'Unable to update workflow', 500);
        exit;
    }
    if (array_key_exists('status', $data)) {
        $schedulesRepo->setActiveForWorkflow($workflowId, $entity, $data['status'] === 'active');
    }
    $audit->record('workflow.update', 'workflow', $workflowId, (int) $user->id, [
        'fields' => array_keys($data),
    ], $entity);
    if (
        ($data['status'] ?? '') === 'active'
        && strtolower((string) ($existing['status'] ?? '')) !== 'active'
        && $activationCheck['report'] !== null
        && $activationCheck['report']->hasCritical()
        && $criticalAck
    ) {
        $activationGuard->recordCriticalActivationAudit(
            $workflowId,
            (int) $user->id,
            $entity,
            $activationCheck['report'],
            (bool) ($data['activation_warning_dismissed'] ?? $existing['activationWarningDismissed'] ?? false)
        );
    }
    $workflow = $repo->fetch($workflowId, $entity);
    if ($workflow !== null && is_array($workflow['definition'] ?? null)) {
        $versions->createSnapshot($workflowId, $workflow['definition'], $entity, (int) $user->id);
    }
    JsonResponse::success(['workflow' => $workflow]);
    exit;
}

if ($workflowId <= 0 && WorkflowCreateGuard::rejectsEmptyImport($payload, $definition)) {
        JsonResponse::error(
            'workflow_empty_payload',
            'Workflow import payload must include nodes or an explicit label.',
            400
        );
        exit;
    }

if ($definition === null) {
    $data['definition'] = ['schemaVersion' => '1.0', 'workflow' => [], 'nodes' => [], 'edges' => []];
}
if (!isset($data['label'])) {
    $data['label'] = 'Untitled workflow';
}

$createCheck = $activationGuard->checkActivation(null, $data, $criticalAck);
if ($createCheck['blocked']) {
    JsonResponse::error(
        'workflow_activation_requires_acknowledgement',
        'Critical workflow activation requires explicit acknowledgement.',
        409,
        ['risk' => $createCheck['report']?->toArray()]
    );
    exit;
}

$newId = $repo->create($data, $entity, (int) $user->id);
if ($newId <= 0) {
    JsonResponse::error('create_failed', 'Unable to create workflow', 500);
    exit;
}

$audit->record('workflow.create', 'workflow', $newId, (int) $user->id, [
    'label' => (string) ($data['label'] ?? ''),
], $entity);

$workflow = $repo->fetch($newId, $entity);
if ($workflow !== null && is_array($workflow['definition'] ?? null)) {
    $versions->createSnapshot($newId, $workflow['definition'], $entity, (int) $user->id, 'Initial version', true);
    syncSchedulesFromDefinition($schedulesRepo, $newId, $entity, $workflow['definition']);
}
JsonResponse::success(['workflow' => $workflow], 201);

/**
 * Sync schedules from a workflow definition into llx_knot_schedule.
 *
 * Reads `definition.workflow.schedules[]` first; falls back to extracting
 * the first `trigger.cron` node config (cronExpression + timezone) when
 * the schedules array is missing — this keeps imported JSON workflows
 * runnable without forcing the author to also list schedules separately.
 *
 * @param array<string, mixed> $definition
 */
function syncSchedulesFromDefinition(ScheduleRepository $schedules, int $workflowId, int $entity, array $definition): void
{
    $entries = [];
    $wfMeta = is_array($definition['workflow'] ?? null) ? $definition['workflow'] : [];
    if (is_array($wfMeta['schedules'] ?? null)) {
        foreach ($wfMeta['schedules'] as $entry) {
            if (!is_array($entry)) continue;
            $cron = trim((string) ($entry['cronExpression'] ?? $entry['cron'] ?? ''));
            if ($cron === '') continue;
            $entries[] = [
                'cronExpression' => $cron,
                'timezone' => (string) ($entry['timezone'] ?? 'UTC'),
                'isActive' => array_key_exists('isActive', $entry) ? (bool) $entry['isActive'] : true,
            ];
        }
    }

    if ($entries === []) {
        $nodes = is_array($definition['nodes'] ?? null) ? $definition['nodes'] : [];
        foreach ($nodes as $node) {
            if (!is_array($node) || ($node['type'] ?? '') !== 'trigger.cron') continue;
            $config = is_array($node['config'] ?? null) ? $node['config'] : [];
            $cron = trim((string) ($config['cronExpression'] ?? $config['cron'] ?? $config['expression'] ?? ''));
            if ($cron === '') continue;
            $entries[] = [
                'cronExpression' => $cron,
                'timezone' => (string) ($config['timezone'] ?? 'UTC'),
                'isActive' => true,
            ];
            break;
        }
    }

    foreach ($entries as $entry) {
        $schedules->save(null, $workflowId, $entry, $entity);
    }
}

/**
 * @param array<int, array<string, mixed>> $workflows
 * @return array<int, array<string, mixed>>
 */
function enrichWorkflowTags(array $workflows, WorkflowTagRepository $tags, int $entity): array
{
    foreach ($workflows as &$workflow) {
        $workflow['tags'] = $tags->listForWorkflow((int) ($workflow['id'] ?? 0), $entity);
        $workflow['favorite'] = in_array('favorite', $workflow['tags'], true);
    }
    unset($workflow);

    return $workflows;
}

/**
 * @param array<int, array<string, mixed>> $workflows
 * @return array<int, array<string, mixed>>
 */
function enrichWorkflowRisk(
    array $workflows,
    WorkflowRepository $repo,
    WorkflowRiskAnalyzer $analyzer,
    int $entity,
): array {
    if ($workflows === []) {
        return [];
    }

    $ids = array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $workflows);
    $definitions = $repo->fetchDefinitionsByIds($ids, $entity);

    foreach ($workflows as &$workflow) {
        $id = (int) ($workflow['id'] ?? 0);
        $definition = $definitions[$id] ?? ['nodes' => []];
        $workflow['riskWorstLevel'] = $analyzer->analyze($definition)->worstLevel;
    }
    unset($workflow);

    return $workflows;
}

/**
 * @param array<string, mixed> $payload
 */
function knotPayloadBool(array $payload, string $snakeKey, string $camelKey): bool
{
    if (array_key_exists($snakeKey, $payload)) {
        return !empty($payload[$snakeKey]);
    }
    if (array_key_exists($camelKey, $payload)) {
        return !empty($payload[$camelKey]);
    }

    return false;
}

/**
 * @param array<string, mixed> $payload
 */
function knotPayloadHas(array $payload, string $snakeKey, string $camelKey): bool
{
    return array_key_exists($snakeKey, $payload) || array_key_exists($camelKey, $payload);
}
