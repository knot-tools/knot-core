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

use Knot\Api\ApiAuth;
use Knot\Api\JsonResponse;
use Knot\Connectors\ConnectorRegistry;
use Knot\Extension\ExtensionRegistry;
use Knot\KnownSkus;
use Knot\Licensing\Bootstrap;
use Knot\Migration\ConnectorMigration;
use Knot\Repository\WorkflowRepository;

JsonResponse::installFatalHandler();

ApiAuth::installCrashHandler();

/**
 * V2.5.0b — Marketplace UI migration assistant endpoint.
 *
 * GET /api/migration_scan.php
 *  → returns every workflow of the current Dolibarr entity that
 *    references at least one connector that was moved from Knot
 *    Core to the Pro Pack in V2.5.0b. Powers:
 *      - the orange banner in the editor (when an impacted workflow
 *        is opened)
 *      - the dedicated `/admin/setup.php?mode=pro-pack-migration` view
 *
 * GET /api/migration_scan.php?workflow_id=42
 *  → restricts the scan to a single workflow, returns the same
 *    shape but with at most one entry. Used by the editor banner.
 *
 * Response shape:
 *  {
 *    success: true,
 *    data: {
 *      migratedConnectorIds: ["action.stripe", ...],
 *      impacted: [
 *        {
 *          workflowId: 42,
 *          ref: "WF-2026-001",
 *          label: "Send Stripe invoice on order paid",
 *          status: "active",
 *          updatedAt: "2026-04-01 12:00:00",
 *          impactedNodes: [
 *            {nodeId: "n_3", connectorId: "action.stripe"}
 *          ],
 *          distinctConnectorIds: ["action.stripe"]
 *        }
 *      ],
 *      summary: {
 *        scannedWorkflows: 87,
 *        impactedWorkflows: 4,
 *        impactedNodesTotal: 7,
 *        connectorIdsImpacted: ["action.stripe", "action.shopify"]
 *      }
 *    }
 *  }
 */

ApiAuth::requireRight('knot', 'workflow', 'read');

$entity = (int) $conf->entity;
$workflowsRepo = new WorkflowRepository($db);
$migration = new ConnectorMigration($workflowsRepo);
$extensions = Bootstrap::buildExtensionRegistry($db);
$availableConnectorIds = array_keys((new ConnectorRegistry())->allWithExtensions($extensions));
$proPackExtension = $extensions->discover()['knot-pro-pack'] ?? null;
$proPackLoaded = is_array($proPackExtension)
    && ($proPackExtension['status'] ?? '') === ExtensionRegistry::STATUS_LOADED;

$workflowId = (int) GETPOST('workflow_id', 'int');
if ($workflowId > 0) {
    // Single-workflow path: reuse the canonical scanner then filter
    // to the requested id. Filtering after the scan keeps the code
    // path identical so a regression in the global scan is also
    // caught by the editor banner test.
    $all = $migration->scanImpactedWorkflows($entity, $availableConnectorIds);
    $impacted = array_values(array_filter(
        $all,
        static fn (array $row): bool => ((int) ($row['workflowId'] ?? 0)) === $workflowId
    ));
    $scannedTotal = count($all);
} else {
    $impacted = $migration->scanImpactedWorkflows($entity, $availableConnectorIds);
    $scannedTotal = null; // not exposed in global mode to avoid an extra COUNT
}

$nodesTotal = 0;
$idsImpacted = [];
foreach ($impacted as $row) {
    $nodesTotal += count($row['impactedNodes'] ?? []);
    foreach ($row['distinctConnectorIds'] ?? [] as $cid) {
        $idsImpacted[$cid] = true;
    }
}

JsonResponse::success([
    'migratedConnectorIds' => ConnectorMigration::migratedConnectorIds(),
    'impacted' => $impacted,
    'summary' => [
        'scannedWorkflows' => $scannedTotal,
        'impactedWorkflows' => count($impacted),
        'impactedNodesTotal' => $nodesTotal,
        'connectorIdsImpacted' => array_keys($idsImpacted),
    ],
    'proPackProductSlug' => KnownSkus::PRO_PACK,
    'proPackLoaded' => $proPackLoaded,
]);
