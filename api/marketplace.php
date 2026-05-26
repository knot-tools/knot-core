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
use Knot\KnownSkus;
use Knot\Licensing\Bootstrap;
use Knot\Licensing\LicenseCache;
use Knot\Marketplace\CatalogCache;
use Knot\Marketplace\CatalogClientFactory;
use Knot\Marketplace\ConnectorPresentationCache;
use Knot\Marketplace\EditorialMerger;
use Knot\Marketplace\EditorialValidator;
use Knot\Marketplace\KnotMarketplacePresentation;
use Knot\Marketplace\MarketplaceStatsReader;
use Knot\Marketplace\SidebarBadge;
use Knot\Marketplace\TemplateClient;
use Knot\Marketplace\TierGate;
use Knot\Migration\ConnectorMigration;
use Knot\Repository\KnotConfigRepository;
use Knot\Repository\TemplateRepository;
use Knot\Repository\WorkflowRepository;

JsonResponse::installFatalHandler();

ApiAuth::installCrashHandler();

/**
 * V2.5.0a — Unified Marketplace endpoint.
 *
 * GET /api/marketplace.php
 *  → returns the data needed by the unified MarketplaceView (3 tabs):
 *    {
 *      packs: [
 *        { slug, label, description, category, priceMonthlyCents,
 *          priceYearlyCents, currency, trialDays, refundWindowDays,
 *          buyUrl, status, licenseStatus, licenseExpiresAt, version,
 *          installed (bool) }
 *      ],
 *      packsMeta: { fromCache, stale, error },
 *      migration: {
 *        impacted, summary, migratedConnectorIds, proPackProductSlug
 *      },
 *      backendUrl: "https://license.knot.tools"
 *    }
 *
 * Aggregates three data sources in a single round-trip so the frontend
 * does not have to orchestrate three calls and stays snappy on cold load:
 *   1. Public catalog from license.knot.tools (CatalogClient, cached
 *      6h via CatalogCache → llx_knot_config).
 *   2. Local extension status from ExtensionRegistry + LicenseCache.
 *   3. Workflow migration scan from ConnectorMigration.
 *
 * The "Templates" tab is rendered on the client by re-using the
 * existing /api/templates.php endpoint — kept separate because the
 * payload is large (full workflow JSON) and not always needed.
 */

ApiAuth::requireRight('knot', 'workflow', 'read');

$langs->load('knot@knot');
if (!KnotMarketplacePresentation::marketplaceUiEnabled()) {
    JsonResponse::error('marketplace_ui_disabled', $langs->trans('KnotMarketplaceUiDisabledApi'), 403);
    exit;
}

$entity = (int) $conf->entity;

$configRepo = new KnotConfigRepository($db);
$catalogClient = CatalogClientFactory::create(null, $db);
$catalogCache = new CatalogCache($configRepo);
$catalogLang = CatalogCache::normalizeCatalogLang(
    trim((string) GETPOST('lang', 'alphanohtml')) !== ''
        ? (string) GETPOST('lang', 'alphanohtml')
        : ((is_object($langs) && isset($langs->defaultlang)) ? (string) $langs->defaultlang : 'en')
);

$fallbackPath = __DIR__ . '/../data/marketplace/editorial-fallback.json';
$fallbackBundle = [];
if (is_readable($fallbackPath)) {
    /** @var mixed $decodedFallback */
    $decodedFallback = json_decode((string) file_get_contents($fallbackPath), true);
    if (is_array($decodedFallback)) {
        $fallbackBundle = $decodedFallback;
    }
}
/** @var array<string, mixed> $fallbackLangPayload */
$fallbackLangPayload = (isset($fallbackBundle[$catalogLang]) && is_array($fallbackBundle[$catalogLang]))
    ? $fallbackBundle[$catalogLang]
    : ((isset($fallbackBundle['en']) && is_array($fallbackBundle['en'])) ? $fallbackBundle['en'] : []);

$templateClient = new TemplateClient(
    new TemplateRepository($db),
    $configRepo,
    $catalogClient,
);

// Admin-only manual refresh: drops both the extensions cache (6h) and
// triggers a synchronous template refresh so admins do not have to
// wait for the next TTL window when they edit/archive a row in
// license.knot.tools/admin/products.
$action = (string) GETPOST('action', 'aZ09');
if ($action === 'refresh') {
    if (!$user->hasRight('knot', 'admin', 'configure')) {
        JsonResponse::error('permission_denied', 'Admin permission required', 403);
        exit;
    }
    $catalogCache->invalidate();
    (new ConnectorPresentationCache($configRepo))->invalidate();
    $templateClient->forceRefresh($entity);
}

// Extensions catalog (cached 6h in llx_knot_config). We pin the kind
// filter to "extension" so the Pro Pack and friends are returned but
// not the workflow templates — those are served separately below
// because they live in their own table (entity-aware cache).
$catalogResult = $catalogCache->get($catalogClient, $catalogLang);

$remoteEditorial = $catalogResult['editorial'];
if (EditorialMerger::remoteBlockedByKillSwitch(is_array($remoteEditorial) ? $remoteEditorial : null)) {
    $remoteEditorial = null;
}

$mergedEditorial = EditorialMerger::merge($fallbackLangPayload, is_array($remoteEditorial) ? $remoteEditorial : null);
$editorialValidated = EditorialValidator::validate($mergedEditorial);
$editorialForClient = $editorialValidated->isValid()
    ? $mergedEditorial
    : $fallbackLangPayload;
if (!$editorialValidated->isValid()) {
    $fallbackOnly = EditorialValidator::validate($fallbackLangPayload);
    if (!$fallbackOnly->isValid()) {
        $editorialForClient = [];
    }
}
$sidebarBadge = SidebarBadge::fromEditorial(
    $editorialForClient === [] ? null : $editorialForClient,
);
$catalog = array_values(array_filter(
    $catalogResult['products'],
    static fn (array $p): bool => ($p['kind'] ?? 'extension') === 'extension'
));

// Templates: live mirror persisted in llx_knot_template per entity.
// When CatalogCache performed a cold network GET this invocation, reuse the
// normalized full catalog so TemplateClient skips a redundant ?kind=template
// telemetry hit against license.knot.tools.
$reuseCatalogForTemplates = (($catalogResult['live_catalog_fetched'] ?? false) === true)
    ? $catalogResult['products']
    : null;
$templatesResult = $templateClient->all($entity, $reuseCatalogForTemplates);

$registry = Bootstrap::buildExtensionRegistry($db);
$licenseCache = new LicenseCache();
$tierGate = new TierGate($registry);

$installedBySlug = [];
foreach ($registry->discover() as $ext) {
    $extId = (string) ($ext['id'] ?? '');
    if ($extId === '') {
        continue;
    }
    $cached = null;
    try {
        $cached = $licenseCache->read($extId);
    } catch (\Throwable) {
        $cached = null;
    }
    $installedBySlug[$extId] = [
        'status' => (string) ($ext['status'] ?? ''),
        'licenseStatus' => $ext['licenseInfo']['status'] ?? null,
        'licenseExpiresAt' => $ext['licenseInfo']['expiresAt'] ?? null,
        'version' => $ext['version'] ?? null,
        'cachedVerdict' => $cached !== null ? [
            'instanceId' => $cached['instanceId'] ?? null,
            'plan' => $cached['plan'] ?? null,
            'expiresAt' => $cached['expiresAt'] ?? null,
            'lastSuccessfulRefresh' => $cached['lastSuccessfulRefresh'] ?? null,
        ] : null,
    ];
}

$packs = [];
foreach ($catalog as $pack) {
    $slug = (string) $pack['slug'];
    $local = $installedBySlug[$slug] ?? null;
    $licenseStatus = $local['licenseStatus'] ?? null;
    $packs[] = $pack + [
        'installed' => $local !== null,
        'licenseActive' => $licenseStatus === \Knot\Extension\LicenseValidator::STATUS_VALID,
        'status' => $local['status'] ?? 'not_installed',
        'licenseStatus' => $licenseStatus,
        'licenseExpiresAt' => $local['licenseExpiresAt'] ?? null,
        'version' => $local['version'] ?? null,
        'cachedVerdict' => $local['cachedVerdict'] ?? null,
    ];
    unset($installedBySlug[$slug]);
}
// Surface installed third-party extensions that are not in the public
// catalog so the UI can still display them as "not for sale".
foreach ($installedBySlug as $slug => $local) {
    $licenseStatus = $local['licenseStatus'] ?? null;
    $packs[] = [
        'slug' => $slug,
        'label' => $slug,
        'description' => null,
        'category' => 'third-party',
        'priceMonthlyCents' => null,
        'priceYearlyCents' => null,
        'currency' => 'eur',
        'trialDays' => 0,
        'refundWindowDays' => 0,
        'buyUrl' => null,
        'installed' => true,
        'licenseActive' => $licenseStatus === \Knot\Extension\LicenseValidator::STATUS_VALID,
        'status' => $local['status'] ?? 'unknown',
        'licenseStatus' => $licenseStatus,
        'licenseExpiresAt' => $local['licenseExpiresAt'] ?? null,
        'version' => $local['version'] ?? null,
        'cachedVerdict' => $local['cachedVerdict'] ?? null,
    ];
}

// SECURITY — Strip workflow `definition` from any template the
// instance is not entitled to instantiate. Without this, even a
// `locked` flag in the JSON could be bypassed by inspecting the
// network tab and copying the JSON manually. Defence in depth: the
// frontend MUST also disable the "Use template" button and the
// instantiation endpoint MUST refuse cross-tier requests.
//
// V2.5.0d — `KNOT_MARKETPLACE_PREVIEW_LOCKED` toggle:
//  - '1' (default): showcase mode. Locked templates are returned with
//                   `locked: true` + `definition: null` so the UI can
//                   still surface them with a padlock + buy CTA.
//  - '0': strict mode. Locked templates are dropped from the response
//        entirely. The user does not even know they exist.
$showcase = function_exists('getDolGlobalString')
    ? getDolGlobalString('KNOT_MARKETPLACE_PREVIEW_LOCKED', '1') !== '0'
    : true;
$rawTemplates = $templatesResult['templates'];
$templates = [];
foreach ($rawTemplates as $tpl) {
    $tier = (string) ($tpl['tier'] ?? 'free');
    $allowed = $tierGate->canUseTier($tier);
    if (!$allowed) {
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
    $templates[] = $tpl;
}
$templatesResult['templates'] = $templates;

$statsReader = new MarketplaceStatsReader($db);
$engagementCounts = $statsReader->installCountsBySlug($entity);
$popularSlugs = array_flip($statsReader->popularSlugs($entity, 30, 12));

foreach ($packs as $idx => $packRow) {
    $slugKey = (string) ($packRow['slug'] ?? '');
    $count = $slugKey !== '' && isset($engagementCounts[$slugKey]) ? (int) $engagementCounts[$slugKey] : 0;
    $packs[$idx]['installCount'] = $count;
    $packs[$idx]['popular'] = $slugKey !== '' && isset($popularSlugs[$slugKey]);
    $packs[$idx]['featured'] = false;
}

foreach ($templatesResult['templates'] as $idx => $tplRow) {
    $slugKey = (string) ($tplRow['slug'] ?? '');
    $count = $slugKey !== '' && isset($engagementCounts[$slugKey]) ? (int) $engagementCounts[$slugKey] : 0;
    $templatesResult['templates'][$idx]['installCount'] = $count;
    $templatesResult['templates'][$idx]['popular'] = $slugKey !== '' && isset($popularSlugs[$slugKey]);
    $templatesResult['templates'][$idx]['featured'] = false;
}

$workflowsRepo = new WorkflowRepository($db);
$migration = new ConnectorMigration($workflowsRepo);
$availableConnectorIds = array_keys(
    (new ConnectorRegistry())->allWithExtensions(Bootstrap::buildExtensionRegistry($db))
);
$impacted = $migration->scanImpactedWorkflows($entity, $availableConnectorIds);
$nodesTotal = 0;
$idsImpacted = [];
foreach ($impacted as $row) {
    $nodesTotal += count($row['impactedNodes'] ?? []);
    foreach ($row['distinctConnectorIds'] ?? [] as $cid) {
        $idsImpacted[$cid] = true;
    }
}

JsonResponse::success([
    'packs' => $packs,
    'packsMeta' => [
        'fromCache' => (bool) $catalogResult['fromCache'],
        'stale' => (bool) $catalogResult['stale'],
        'error' => $catalogResult['error'],
        'lang' => $catalogLang,
    ],
    'templates' => $templatesResult['templates'],
    'templatesMeta' => $templatesResult['meta'],
    'migration' => [
        'migratedConnectorIds' => ConnectorMigration::migratedConnectorIds(),
        'impacted' => $impacted,
        'summary' => [
            'impactedWorkflows' => count($impacted),
            'impactedNodesTotal' => $nodesTotal,
            'connectorIdsImpacted' => array_keys($idsImpacted),
        ],
        'proPackProductSlug' => KnownSkus::PRO_PACK,
    ],
    'backendUrl' => CatalogClientFactory::resolveBaseUrl(),
    'editorial' => $editorialForClient,
    'sidebarBadge' => $sidebarBadge,
]);
