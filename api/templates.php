<?php
/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

if (!defined('NOCSRFCHECK')) { define('NOCSRFCHECK', '1'); }
if (!defined('NOTOKENRENEWAL')) { define('NOTOKENRENEWAL', '1'); }

require_once __DIR__ . '/../lib/load_dolibarr.inc.php';
dol_include_once('/knot/class/autoload.php');

use Knot\Api\ApiAuth;
use Knot\Api\CsrfGuard;
use Knot\Api\JsonResponse;
use Knot\Licensing\Bootstrap;
use Knot\Marketplace\CatalogClientFactory;
use Knot\Marketplace\ConnectorPresentationCache;
use Knot\Marketplace\KnotMarketplacePresentation;
use Knot\Marketplace\TemplateClient;
use Knot\Marketplace\TierGate;
use Knot\Repository\AuditLogRepository;
use Knot\Repository\KnotConfigRepository;
use Knot\Repository\TemplateRepository;
use Knot\Repository\WorkflowRepository;

JsonResponse::installFatalHandler();

ApiAuth::installCrashHandler();

/**
 * V2.5.0c — Marketplace v2: templates served from license.knot.tools
 * via the local cache mirror in `llx_knot_template`.
 *
 * GET    /api/templates.php                  list cached templates (auto-refresh if stale)
 * GET    /api/templates.php?action=refresh   force refresh from license (admin only)
 * POST   /api/templates.php  body { templateId } | { slug }
 *                                            instantiate as a draft workflow
 */

$entity = (int) $conf->entity;
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$repo = new TemplateRepository($db);
$configRepo = new KnotConfigRepository($db);

$langs->load('knot@knot');
if (!KnotMarketplacePresentation::marketplaceUiEnabled()) {
    JsonResponse::error('marketplace_ui_disabled', $langs->trans('KnotMarketplaceUiDisabledApi'), 403);
    exit;
}

$client = new TemplateClient($repo, $configRepo, CatalogClientFactory::create(null, $db));
$tierGate = new TierGate(Bootstrap::buildExtensionRegistry($db));

/**
 * Strip workflow `definition` from any template the instance is not
 * entitled to instantiate, mirroring api/marketplace.php. The same
 * payload shape lets the frontend render a "Locked" badge regardless
 * of the endpoint a caller hits.
 *
 * V2.5.0d — `KNOT_MARKETPLACE_PREVIEW_LOCKED` toggle:
 *  - '1' (default): showcase mode. Locked templates are returned with
 *                   `locked: true` + `definition: null`.
 *  - '0': strict mode. Locked templates are dropped entirely.
 *
 * @param array<int, array<string, mixed>> $templates
 * @return array<int, array<string, mixed>>
 */
$showcase = function_exists('getDolGlobalString')
    ? getDolGlobalString('KNOT_MARKETPLACE_PREVIEW_LOCKED', '1') !== '0'
    : true;
$gateTemplates = static function (array $templates) use ($tierGate, $showcase): array {
    $out = [];
    foreach ($templates as $tpl) {
        $tier = (string) ($tpl['tier'] ?? 'free');
        if (!$tierGate->canUseTier($tier)) {
            if (!$showcase) {
                continue;
            }
            $tpl['definition'] = null;
            $tpl['locked'] = true;
            $tpl['lockedReason'] = $tierGate->tierStatus($tier);
        } else {
            $tpl['locked'] = false;
            $tpl['lockedReason'] = null;
        }
        $out[] = $tpl;
    }
    return $out;
};

if ($method === 'GET') {
    if (!$user->hasRight('knot', 'workflow', 'read')) {
        JsonResponse::error('permission_denied', 'Permission denied', 403);
        exit;
    }
    $action = (string) GETPOST('action', 'aZ09');

    if ($action === 'refresh') {
        if (!$user->hasRight('knot', 'admin', 'configure')) {
            JsonResponse::error('permission_denied', 'Admin permission required', 403);
            exit;
        }
        (new ConnectorPresentationCache($configRepo))->invalidate();
        $result = $client->forceRefresh($entity);
        $list = $client->all($entity);
        JsonResponse::success([
            'refreshed' => $result['count'],
            'error' => $result['error'],
            'templates' => $gateTemplates($list['templates']),
            'meta' => $list['meta'],
        ]);
        exit;
    }

    $list = $client->all($entity);
    JsonResponse::success([
        'templates' => $gateTemplates($list['templates']),
        'meta' => $list['meta'],
    ]);
    exit;
}

if ($method !== 'POST') {
    JsonResponse::error('method_not_allowed', 'Method not allowed', 405);
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

$rawBody = (string) file_get_contents('php://input');
$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    $payload = [];
}

$slug = isset($payload['slug']) ? trim((string) $payload['slug']) : '';
$templateId = (int) ($payload['templateId'] ?? GETPOST('template_id', 'int') ?? 0);

$template = null;
if ($slug !== '') {
    $template = $repo->findBySlug($slug, $entity);
    if ($template === null) {
        // Cold cache — try a refresh once before giving up so a
        // freshly published template (or a brand new install) does
        // not require the user to wait six hours.
        $client->forceRefresh($entity);
        $template = $repo->findBySlug($slug, $entity);
    }
} elseif ($templateId > 0) {
    $template = $repo->find($templateId, $entity);
}

if ($template === null) {
    JsonResponse::error('not_found', 'Template not found', 404);
    exit;
}

// HARD GATE — A template the instance is not licensed for must never
// be instantiable, even via direct API call. This is the second line
// of defence after the response-stripping in api/marketplace.php and
// api/templates.php GET (the frontend is a third line). All three
// must agree because a user with `knot.workflow.write` could still
// craft a POST manually if only the UI was hardened.
$tier = (string) ($template['tier'] ?? 'free');
if (!$tierGate->canUseTier($tier)) {
    $tierStatus = $tierGate->tierStatus($tier);
    JsonResponse::error(
        'license_required',
        sprintf(
            'A valid %s licence is required to use this template (current status: %s).',
            $tier,
            (string) ($tierStatus['status'] ?? 'unknown')
        ),
        403,
        ['tier' => $tier, 'tierStatus' => $tierStatus]
    );
    exit;
}

$workflowsRepo = new WorkflowRepository($db);
$auditRepo = new AuditLogRepository($db);

$workflowId = $workflowsRepo->create([
    'label' => (string) ($payload['label'] ?? $template['label']),
    'description' => (string) ($template['description'] ?? ''),
    'status' => 'draft',
    'definition' => $template['definition'],
], $entity, (int) $user->id);

if ($workflowId <= 0) {
    JsonResponse::error('create_failed', 'Unable to instantiate template', 500);
    exit;
}

$auditRepo->record('workflow.from_template', 'workflow', $workflowId, (int) $user->id, [
    'templateRef' => (string) $template['ref'],
    'templateSlug' => (string) ($template['slug'] ?? ''),
], $entity);

JsonResponse::success([
    'workflow' => $workflowsRepo->fetch($workflowId, $entity),
    'fromTemplate' => $template['ref'],
    'fromTemplateSlug' => $template['slug'] ?? null,
], 201);
