<?php
/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

if (!defined('NOCSRFCHECK')) { define('NOCSRFCHECK', '1'); }
if (!defined('NOTOKENRENEWAL')) { define('NOTOKENRENEWAL', '1'); }

require '../../../main.inc.php';
dol_include_once('/knot/class/autoload.php');

use Knot\Api\JsonResponse;
use Knot\Dolibarr\DescriptorCache;
use Knot\Dolibarr\ObjectFactory;
use Knot\Licensing\Bootstrap;
use Knot\Observability\RuntimeLogger;
use Knot\Reporting\MetricsCollector;
use Knot\Repository\ExecutionRepository;
use Knot\Repository\WorkflowRepository;
use Knot\Module\ModuleExpectations;
use Knot\Version;

JsonResponse::installFatalHandler();

if (!$user->hasRight('knot', 'workflow', 'read')) {
    JsonResponse::error('permission_denied', 'Permission denied', 403);
    exit;
}

$entity = (int) $conf->entity;

$expectedTables = [
    'workflow', 'credential', 'execution', 'execution_log',
    'webhook', 'schedule', 'template', 'variable', 'audit_log',
    'workflow_version', 'idempotency', 'approval', 'workflow_tag',
];
$tablePresence = [];
$tablesMissing = [];
foreach ($expectedTables as $name) {
    $full = MAIN_DB_PREFIX . 'knot_' . $name;
    $sql = "SHOW TABLES LIKE '" . $db->escape($full) . "'";
    $res = $db->query($sql);
    $present = $res && $db->num_rows($res) > 0;
    $tablePresence[$name] = $present;
    if (!$present) {
        $tablesMissing[] = $name;
    }
}

$cronInfo = ['registered' => false, 'enabled' => false, 'lastRun' => null, 'nextRun' => null, 'globalEnabled' => null];
$res = $db->query(
    "SELECT rowid, status, datelastrun, datenextrun FROM " . MAIN_DB_PREFIX . "cronjob "
    . "WHERE module_name = 'knot' ORDER BY rowid ASC LIMIT 1"
);
if ($res && $row = $db->fetch_object($res)) {
    $cronInfo['registered'] = true;
    $cronInfo['enabled'] = (int) $row->status === 1;
    $cronInfo['lastRun'] = $row->datelastrun !== null ? (string) $row->datelastrun : null;
    $cronInfo['nextRun'] = $row->datenextrun !== null ? (string) $row->datenextrun : null;
}

$cronHealthWorker = [
    'registered' => false,
    'enabled' => false,
    'lastRun' => null,
    'nextRun' => null,
];
$resHc = $db->query(
    "SELECT status, datelastrun, datenextrun FROM " . MAIN_DB_PREFIX . "cronjob "
    . "WHERE module_name = 'knot' AND label = 'KnotHealthWorker' "
    . 'ORDER BY rowid DESC LIMIT 1'
);
if ($resHc && $rw = $db->fetch_object($resHc)) {
    $cronHealthWorker['registered'] = true;
    $cronHealthWorker['enabled'] = (int) $rw->status === 1;
    $cronHealthWorker['lastRun'] = $rw->datelastrun !== null ? (string) $rw->datelastrun : null;
    $cronHealthWorker['nextRun'] = $rw->datenextrun !== null ? (string) $rw->datenextrun : null;
}
$cronGlobal = getDolGlobalString('CRON_DISABLE_JOBS');
$cronInfo['globalEnabled'] = $cronGlobal !== '1' && $cronGlobal !== 'on';

$encryptionOk = true;
$encryptionDetail = 'instance_unique_id present';
global $dolibarr_main_instance_unique_id;
if (empty($dolibarr_main_instance_unique_id)) {
    $encryptionOk = false;
    $encryptionDetail = 'dolibarr_main_instance_unique_id is empty - credentials cannot be encrypted safely';
}

$documentRoot = isset($conf->knot->dir_output) ? $conf->knot->dir_output : (DOL_DATA_ROOT . '/knot');
$writeOk = is_dir($documentRoot) ? is_writable($documentRoot) : @mkdir($documentRoot, 0755, true) || is_writable($documentRoot);

$rightsCount = 0;
$resR = $db->query("SELECT COUNT(*) AS nb FROM " . MAIN_DB_PREFIX . "rights_def WHERE module = 'knot'");
if ($resR && $obj = $db->fetch_object($resR)) {
    $rightsCount = (int) $obj->nb;
}

$menuCount = 0;
$resM = $db->query(
    "SELECT COUNT(*) AS nb FROM " . MAIN_DB_PREFIX . "menu WHERE module = 'knot' AND entity = " . $entity
);
if ($resM && $obj = $db->fetch_object($resM)) {
    $menuCount = (int) $obj->nb;
}

$workflowsRepo = new WorkflowRepository($db);
$executionsRepo = new ExecutionRepository($db);

// V2.5.0d — observability light: queue depth, runtime log status,
// license cache freshness for paid extensions, executions in the
// last 24 h. These are surfaced both as flat fields (`executions24h`,
// `queueDepth`, `runtimeLog`) and as inline checks so the existing
// doctor UI keeps working.
$executionStatusCounts = $executionsRepo->statusCounts($entity);
$queueDepth = (int) ($executionStatusCounts['queued'] ?? 0);
$runningCount = (int) ($executionStatusCounts['running'] ?? 0);
$executions24h = $executionsRepo->countSince($entity, time() - 86400);

$metricsCollector = new MetricsCollector($db);
$failureHeatmapSince = strtotime('-7 days');
$failureHeatmap = $metricsCollector->failureHeatmap($entity, $failureHeatmapSince);

$runtimeLogger = new RuntimeLogger(RuntimeLogger::defaultDirectory());
$runtimeLogDisk = $runtimeLogger->diskUsageStats();
$runtimeLogStatus = [
    'directory' => $runtimeLogger->directory(),
    'writable' => $runtimeLogger->isWritable(),
    'failures' => $runtimeLogger->failureCount(),
    'lastRotation' => (string) getDolGlobalString('KNOT_RUNTIME_LOG_LAST_ROTATION', '') ?: null,
    'totalBytes' => $runtimeLogDisk['totalBytes'],
    'bytesToday' => $runtimeLogDisk['bytesToday'],
    'fileCount' => $runtimeLogDisk['fileCount'],
    'diskFreeBytes' => $runtimeLogDisk['diskFreeBytes'],
    'diskTotalBytes' => $runtimeLogDisk['diskTotalBytes'],
    'diskFreeRatio' => $runtimeLogDisk['diskFreeRatio'],
];

// V2.5.0b-ux-ops (plan chantier 7.I) — surface a warning when JSONL
// runtime logs start eating disk. Two thresholds: > 100 MB total
// rolling 7-day window (warn) and < 5 % free disk on the partition
// (warn). Configurable via Dolibarr globals so an admin running on
// a tight VM can lower the bar.
$runtimeLogQuotaMb = (int) (getDolGlobalString('KNOT_RUNTIME_LOG_QUOTA_MB', '100') ?: '100');
$diskFreeMinRatio = (float) (getDolGlobalString('KNOT_DISK_FREE_MIN_RATIO', '0.05') ?: '0.05');
$runtimeLogQuotaBreach = $runtimeLogStatus['totalBytes'] > ($runtimeLogQuotaMb * 1024 * 1024);
$diskFreeBreach = $runtimeLogStatus['diskFreeRatio'] !== null
    && $runtimeLogStatus['diskFreeRatio'] < $diskFreeMinRatio;

$licenseCache = ['extensions' => [], 'stale' => false];
try {
    $registry = Bootstrap::buildExtensionRegistry($db);
    foreach ($registry->discover() as $ext) {
        $info = is_array($ext['licenseInfo'] ?? null) ? $ext['licenseInfo'] : [];
        $licenseCache['extensions'][] = [
            'id' => (string) ($ext['id'] ?? ''),
            'status' => (string) ($info['status'] ?? 'unknown'),
            'expiresAt' => $info['expiresAt'] ?? null,
            'cachedAt' => $info['cachedAt'] ?? null,
        ];
        // Mark cache stale if a paid extension was last refreshed
        // more than 24 h ago. The license backend is expected to re-
        // sync at every cron tick, so 24 h means something is wrong.
        $cachedAt = $info['cachedAt'] ?? null;
        if ($cachedAt !== null) {
            $cachedTs = is_numeric($cachedAt) ? (int) $cachedAt : (int) strtotime((string) $cachedAt);
            if ($cachedTs > 0 && (time() - $cachedTs) > 86400) {
                $licenseCache['stale'] = true;
            }
        }
    }
} catch (\Throwable $e) {
    $licenseCache['error'] = $e->getMessage();
}

$cronStaleSeconds = null;
$lastRunTs = $cronInfo['lastRun'] ? (int) dol_stringtotime((string) $cronInfo['lastRun'], 'tzserver') : 0;
if ($lastRunTs > 0) {
    $cronStaleSeconds = time() - $lastRunTs;
}

$descriptorCache = new DescriptorCache();
$descriptorCachePath = $descriptorCache->getPath();
$descriptorPayload = $descriptorCache->read();
$descriptorCount = $descriptorPayload !== null && isset($descriptorPayload['descriptors']) && is_array($descriptorPayload['descriptors'])
    ? count($descriptorPayload['descriptors'])
    : 0;
$supportedSlugCount = count((new ObjectFactory())->listObjectsForApi(null, $db));
$descriptorCacheReadable = is_readable($descriptorCachePath);
$setupCompletedForIntrospection = getDolGlobalString('KNOT_SETUP_COMPLETED') === '1';
$introspectionCacheOk = !$setupCompletedForIntrospection
    || ($descriptorCacheReadable && $descriptorCount > 0);
$introspectionDetail = !$setupCompletedForIntrospection
    ? 'Setup not completed — introspection cache is optional until go-live'
    : (
        !$descriptorCacheReadable
            ? 'Missing or unreadable ' . $descriptorCachePath . ' — run Admin → Knot setup → Refresh introspection'
            : (
                $descriptorCount > 0
                    ? $descriptorCount . ' descriptor(s) in cache (' . $descriptorCachePath . ')'
                    : 'Cache file present but contains zero descriptors — run Refresh introspection from admin/setup.php'
            )
    );

$checks = [
    'dolibarr' => [
        'ok' => version_compare(DOL_VERSION, '20.0.0', '>='),
        'detail' => 'Dolibarr ' . DOL_VERSION . ' (>= 20.0.0 required)',
        'severity' => 'error',
    ],
    'php' => [
        'ok' => version_compare(PHP_VERSION, '8.1.0', '>='),
        'detail' => 'PHP ' . PHP_VERSION . ' (>= 8.1.0 required)',
        'severity' => 'error',
    ],
    'openssl' => [
        'ok' => extension_loaded('openssl'),
        'detail' => extension_loaded('openssl') ? 'loaded' : 'OpenSSL extension required for credential encryption',
        'severity' => 'error',
    ],
    'curl' => [
        'ok' => extension_loaded('curl'),
        'detail' => extension_loaded('curl') ? 'loaded' : 'cURL extension required for HTTP node',
        'severity' => 'error',
    ],
    'json' => [
        'ok' => extension_loaded('json'),
        'detail' => extension_loaded('json') ? 'loaded' : 'JSON extension required',
        'severity' => 'error',
    ],
    'mbstring' => [
        'ok' => extension_loaded('mbstring'),
        'detail' => extension_loaded('mbstring') ? 'loaded' : 'mbstring extension recommended',
        'severity' => 'warning',
    ],
    'module' => [
        'ok' => isModEnabled('knot'),
        'detail' => isModEnabled('knot') ? 'Knot module is enabled' : 'Knot module is disabled',
        'severity' => 'error',
    ],
    'tables' => [
        'ok' => count($tablesMissing) === 0,
        'detail' => count($tablesMissing) === 0
            ? count($expectedTables) . ' / ' . count($expectedTables) . ' tables present'
            : 'Missing tables: ' . implode(', ', $tablesMissing),
        'severity' => 'error',
    ],
    'rights' => [
        'ok' => $rightsCount >= 5,
        'detail' => $rightsCount . ' rights registered (5 expected)',
        'severity' => 'warning',
    ],
    'menus' => [
        'ok' => $menuCount >= ModuleExpectations::MENU_ENTRY_COUNT,
        'detail' => $menuCount >= ModuleExpectations::MENU_ENTRY_COUNT
            ? $menuCount . ' menu entries (min. ' . ModuleExpectations::MENU_ENTRY_COUNT . ')'
            : $menuCount . ' menu entries (min. ' . ModuleExpectations::MENU_ENTRY_COUNT . ' expected)',
        'severity' => 'warning',
    ],
    'cron_registered' => [
        'ok' => $cronInfo['registered'],
        'detail' => $cronInfo['registered'] ? 'Knot cronjob is registered in Dolibarr' : 'Cronjob not registered - re-enable the module',
        'severity' => 'error',
    ],
    'cron_enabled' => [
        'ok' => $cronInfo['registered'] && $cronInfo['enabled'],
        'detail' => $cronInfo['enabled']
            ? 'Cronjob is active'
            : 'Cronjob is disabled - executions will stay in queued',
        'severity' => 'error',
    ],
    'cron_global' => [
        'ok' => $cronInfo['globalEnabled'],
        'detail' => $cronInfo['globalEnabled']
            ? 'Dolibarr scheduler enabled (CRON_DISABLE_JOBS != 1)'
            : 'Dolibarr global scheduler is disabled (CRON_DISABLE_JOBS=1)',
        'severity' => 'warning',
    ],
    'encryption' => [
        'ok' => $encryptionOk,
        'detail' => $encryptionDetail,
        'severity' => 'error',
    ],
    'documents_writable' => [
        'ok' => $writeOk,
        'detail' => $writeOk
            ? $documentRoot . ' is writable'
            : $documentRoot . ' is not writable (file connectors will fail)',
        'severity' => 'warning',
    ],
    'engine_enabled' => [
        'ok' => getDolGlobalString('KNOT_ENGINE_ENABLED') === '1',
        'detail' => getDolGlobalString('KNOT_ENGINE_ENABLED') === '1'
            ? 'Engine is enabled'
            : 'Engine is paused (executions are accepted but not processed)',
        'severity' => 'warning',
    ],
    'setup_completed' => [
        'ok' => getDolGlobalString('KNOT_SETUP_COMPLETED') === '1',
        'detail' => getDolGlobalString('KNOT_SETUP_COMPLETED') === '1' ? 'Setup completed' : 'Run admin/setup.php to finalize installation',
        'severity' => 'warning',
    ],
    'introspection_cache' => [
        'ok' => $introspectionCacheOk,
        'detail' => $introspectionDetail,
        'severity' => 'warning',
    ],
    'cron_recent' => [
        'ok' => $cronStaleSeconds !== null && $cronStaleSeconds < 900, // 15 min
        'detail' => $cronStaleSeconds === null
            ? 'Cronjob has never run yet (datelastrun is empty)'
            : 'Last cron run ' . $cronStaleSeconds . 's ago',
        'severity' => 'warning',
    ],
    'queue_depth' => [
        'ok' => $queueDepth < 100,
        'detail' => $queueDepth . ' executions queued (>=100 means the cron is not draining)',
        'severity' => 'warning',
    ],
    'runtime_log_writable' => [
        'ok' => $runtimeLogStatus['writable'],
        'detail' => $runtimeLogStatus['writable']
            ? 'Runtime log directory is writable: ' . $runtimeLogStatus['directory']
            : 'Runtime log directory is not writable: ' . $runtimeLogStatus['directory'],
        'severity' => 'warning',
    ],
    'runtime_log_failures' => [
        'ok' => $runtimeLogStatus['failures'] === 0,
        'detail' => $runtimeLogStatus['failures'] === 0
            ? 'No runtime log write failures recorded'
            : $runtimeLogStatus['failures'] . ' runtime log write failures (check disk space / permissions)',
        'severity' => 'warning',
    ],
    'license_cache_freshness' => [
        'ok' => !$licenseCache['stale'],
        'detail' => $licenseCache['stale']
            ? 'At least one paid extension license cache is older than 24 h - the licence backend may be unreachable'
            : 'License cache is fresh',
        'severity' => 'warning',
    ],
    'runtime_log_quota' => [
        'ok' => !$runtimeLogQuotaBreach,
        'detail' => $runtimeLogQuotaBreach
            ? 'Runtime log directory holds ' . round($runtimeLogStatus['totalBytes'] / (1024 * 1024), 1)
              . ' MB (>' . $runtimeLogQuotaMb . ' MB quota). Lower KNOT_RUNTIME_LOG_RETENTION_DAYS or ship to Loki.'
            : 'Runtime log directory holds ' . round($runtimeLogStatus['totalBytes'] / (1024 * 1024), 1)
              . ' MB (quota ' . $runtimeLogQuotaMb . ' MB)',
        'severity' => 'warning',
    ],
    'disk_free' => [
        'ok' => !$diskFreeBreach,
        'detail' => $runtimeLogStatus['diskFreeRatio'] === null
            ? 'Disk free space could not be measured for ' . $runtimeLogStatus['directory']
            : ($diskFreeBreach
                ? 'Disk free below ' . round($diskFreeMinRatio * 100, 1) . ' % on the runtime log partition'
                : 'Disk free at ' . round((float) $runtimeLogStatus['diskFreeRatio'] * 100, 1) . ' % on the runtime log partition'),
        'severity' => 'warning',
    ],
];

$status = 'ok';
foreach ($checks as $check) {
    if (!$check['ok']) {
        if ($check['severity'] === 'error') {
            $status = 'error';
            break;
        }
        $status = 'warning';
    }
}

$flatChecks = [];
foreach ($checks as $key => $check) {
    $flatChecks[$key] = (bool) $check['ok'];
}

$version = class_exists(Version::class) ? Version::current() : Version::FALLBACK;

$releaseChannel = trim((string) getDolGlobalString('KNOT_RELEASE_CHANNEL', 'beta'));
if ($releaseChannel !== 'beta' && $releaseChannel !== 'stable') {
    $releaseChannel = 'beta';
}

JsonResponse::success([
    'module' => 'knot',
    'version' => $version,
    'status' => $status,
    'checks' => $flatChecks,
    'doctor' => [
        'checks' => $checks,
        'tables' => $tablePresence,
        'tablesMissing' => $tablesMissing,
        'cron' => $cronInfo + ['healthWorker' => $cronHealthWorker],
        'cronStaleSeconds' => $cronStaleSeconds,
        'documentsRoot' => $documentRoot,
        'runtimeLog' => $runtimeLogStatus,
        'licenseCache' => $licenseCache,
        'introspection' => [
            'cachePath' => $descriptorCachePath,
            'cacheReadable' => $descriptorCacheReadable,
            'descriptorCount' => $descriptorCount,
            'supportedSlugCount' => $supportedSlugCount,
        ],
    ],
    'workflows' => $workflowsRepo->countByStatus($entity),
    'executions' => $executionStatusCounts,
    'queueDepth' => $queueDepth,
    'runningCount' => $runningCount,
    'executions24h' => $executions24h,
    'failureHeatmap' => $failureHeatmap,
    'failureHeatmapSince' => $failureHeatmapSince,
    'runtimeLog' => $runtimeLogStatus,
  'setupCompleted' => getDolGlobalString('KNOT_SETUP_COMPLETED') === '1',
  'engineEnabled' => getDolGlobalString('KNOT_ENGINE_ENABLED') === '1',
  'releaseChannel' => $releaseChannel,
  'demoMode' => getDolGlobalString('KNOT_DEMO_MODE') === '1',
]);
