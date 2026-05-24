<?php

/* Copyright (C) 2026 Knot */

declare(strict_types=1);

// Knot owns its sidebar; pin Dolibarr's mainmenu hint so the top bar still
// highlights the Knot tab. The custom left nav is rendered by
// tpl/knot-leftnav.tpl.php below; Dolibarr's vmenu is hidden via knot-host
// .css, so the leftmenu hint does not need to be accurate.
if (!isset($_GET['mainmenu'])) {
    $_GET['mainmenu'] = 'knot';
}

require '../../../main.inc.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
dol_include_once('/knot/class/autoload.php');

use Knot\Marketplace\KnotMarketplacePresentation;
use Knot\Module\ModuleExpectations;

if (!$user->hasRight('knot', 'admin', 'configure')) {
    accessforbidden();
}

$langs->loadLangs(['admin', 'errors', 'knot@knot']);

$action = GETPOST('action', 'aZ09');
$ajax = GETPOST('ajax', 'aZ09') === '1';
$adminShell = GETPOST('admin', 'int') === 1;
$knotSetupAdminHiddenInline = $adminShell ? '<input type="hidden" name="admin" value="1">' : '';

$knot_setup_redirect_self = static function (): string {
    return $_SERVER['PHP_SELF'] . (GETPOST('admin', 'int') === 1 ? '?admin=1' : '');
};

/**
 * Module list "gear" opens this page without ?admin=1. The Vue onboarding only
 * mounts on the dashboard; send admins there until KNOT_FIRSTRUN_COMPLETED —
 * including right after install (KNOT_SETUP_COMPLETED may still be 0 until the
 * PHP wizard "Terminer" runs). Use Setup in the Knot sidebar (?admin=1) to open
 * this screen anyway.
 */
if (
    !$adminShell
    && !$ajax
    && (string) $action === ''
    && getDolGlobalString('KNOT_FIRSTRUN_COMPLETED') !== '1'
) {
    header('Location: ' . DOL_URL_ROOT . '/custom/knot/workflows/preview.php?mode=dashboard');
    exit;
}

/**
 * Re-insert the Knot left menus from the module descriptor.
 * Useful when modKnot.class.php has been updated but the module
 * is already enabled in Dolibarr (menus are not refreshed automatically).
 */
$refreshMenus = static function (\DoliDB $db, int $entity): int {
    require_once DOL_DOCUMENT_ROOT . '/core/modules/DolibarrModules.class.php';
    require_once DOL_DOCUMENT_ROOT . '/custom/knot/core/modules/modKnot.class.php';

    $db->query("DELETE FROM " . MAIN_DB_PREFIX . "menu WHERE module = 'knot' AND entity = " . $entity);

    $module = new \modKnot($db);
    if (method_exists($module, 'insert_menus')) {
        $module->insert_menus();
    }

    $countSql = "SELECT COUNT(*) AS nb FROM " . MAIN_DB_PREFIX . "menu WHERE module = 'knot' AND entity = " . $entity;
    $res = $db->query($countSql);
    return $res ? (int) ($db->fetch_object($res)->nb ?? 0) : 0;
};

$tablesExpected = [
    'workflow', 'credential', 'execution', 'execution_log',
    'webhook', 'schedule', 'template', 'variable', 'audit_log',
    'workflow_version', 'idempotency', 'approval', 'workflow_tag',
];

$installMissingTables = static function (\DoliDB $db, array $tables): int {
    $installed = 0;
    foreach ($tables as $table) {
        $fullName = MAIN_DB_PREFIX . 'knot_' . $table;
        $res = $db->query("SHOW TABLES LIKE '" . $db->escape($fullName) . "'");
        if ($res && $db->num_rows($res) > 0) {
            continue;
        }

        $sqlFile = DOL_DOCUMENT_ROOT . '/custom/knot/sql/llx_knot_' . $table . '.sql';
        if (!is_readable($sqlFile)) {
            continue;
        }

        $sql = str_replace('llx_', MAIN_DB_PREFIX, (string) file_get_contents($sqlFile));
        if ($db->query($sql)) {
            $installed++;
        }
    }

    return $installed;
};

$enableKnotCron = static function (\DoliDB $db): bool {
    $sql = 'UPDATE ' . MAIN_DB_PREFIX . "cronjob SET status = 1 WHERE module_name = 'knot' AND status = 0";
    return (bool) $db->query($sql);
};

/**
 * Shared "go live" work for Terminer (Activer Knot) and Activer le moteur:
 * DDL/migrations, template cache seed, demo/showcase workflows, menus, cron,
 * Dolibarr introspection cache (documents/knot/dolibarr_descriptors.json).
 */
$runKnotGoLive = static function (
    \DoliDB $db,
    $conf,
    $user
) use (
    $tablesExpected,
    $installMissingTables,
    $refreshMenus,
    $enableKnotCron,
): void {
    $installMissingTables($db, $tablesExpected);

    $migrator = new \Knot\Migration\Migrator($db, dirname(__DIR__));
    $migrator->run();

    $templatesRepo = new \Knot\Repository\TemplateRepository($db);
    $templatesRepo->seed((int) $conf->entity);
    if (getDolGlobalString('KNOT_DEMO_WORKFLOWS_SEEDED') !== '1') {
        $templatesRepo->seedDemoWorkflows((int) $conf->entity, (int) ($user->id ?? 0) ?: null);
        dolibarr_set_const($db, 'KNOT_DEMO_WORKFLOWS_SEEDED', '1', 'chaine', 0, '', $conf->entity);
    }
    if (getDolGlobalString('KNOT_SHOWCASE_WORKFLOWS_SEEDED') !== '1') {
        $templatesRepo->seedShowcaseWorkflows((int) $conf->entity, (int) ($user->id ?? 0) ?: null);
        dolibarr_set_const($db, 'KNOT_SHOWCASE_WORKFLOWS_SEEDED', '1', 'chaine', 0, '', $conf->entity);
    }
    $refreshMenus($db, (int) $conf->entity);
    $enableKnotCron($db);

    (new \Knot\Dolibarr\ObjectFactory())->refreshIntrospection($db);
    // Skip redundant one-shot introspection on the next GET (same as after Terminer).
    if (getDolGlobalString('KNOT_INTROSPECTION_AUTO_AT') === '') {
        dolibarr_set_const($db, 'KNOT_INTROSPECTION_AUTO_AT', (string) time(), 'chaine', 0, '', $conf->entity);
    }
};

/**
 * After setup is complete: turning the engine back on only needs DDL/migrations
 * (idempotent, local) and cron re-enable — not a full marketplace fetch, menu
 * rebuild, or introspection scan (admin can refresh those explicitly).
 */
$runKnotGoLiveLight = static function (\DoliDB $db, $conf) use ($tablesExpected, $installMissingTables, $enableKnotCron): void {
    $installMissingTables($db, $tablesExpected);
    $migrator = new \Knot\Migration\Migrator($db, dirname(__DIR__));
    $migrator->run();
    $enableKnotCron($db);
};

if ($action === 'complete') {
    if (function_exists('checkToken') && !checkToken()) {
        accessforbidden('Invalid CSRF token');
    }

    try {
        dolibarr_set_const($db, 'KNOT_SETUP_COMPLETED', '1', 'chaine', 0, '', $conf->entity);
        dolibarr_set_const($db, 'KNOT_ENGINE_ENABLED', '1', 'chaine', 0, '', $conf->entity);
        if (isset($conf->global) && is_object($conf->global)) {
            $conf->global->KNOT_SETUP_COMPLETED = '1';
            $conf->global->KNOT_ENGINE_ENABLED = '1';
        } elseif (isset($conf->global) && is_array($conf->global)) {
            $conf->global['KNOT_SETUP_COMPLETED'] = '1';
            $conf->global['KNOT_ENGINE_ENABLED'] = '1';
        }

        $runKnotGoLive($db, $conf, $user);

        setEventMessages($langs->trans('KnotSetupCompletedMsg'), null, 'mesgs');

        $dashboard = DOL_URL_ROOT . '/custom/knot/workflows/preview.php?mode=dashboard';
        header('Location: ' . $dashboard);
    } catch (\Throwable $e) {
        setEventMessages($langs->trans('KnotSetupCompleteErrorMsg', $e->getMessage()), null, 'errors');
        header('Location: ' . $knot_setup_redirect_self());
    }
    exit;
}

if ($action === 'cron_enable') {
    if (function_exists('checkToken') && !checkToken()) {
        accessforbidden('Invalid CSRF token');
    }
    $enableKnotCron($db);
    setEventMessages($langs->trans('KnotSetupCronEnabledMsg'), null, 'mesgs');
    header('Location: ' . $knot_setup_redirect_self());
    exit;
}

if ($action === 'cron_test') {
    if (function_exists('checkToken') && !checkToken()) {
        accessforbidden('Invalid CSRF token');
    }
    try {
        $worker = new \Knot\Engine\CronWorker();
        $processed = $worker->run();
        setEventMessages($langs->trans('KnotSetupCronTestMsg', (string) $processed), null, 'mesgs');
    } catch (\Throwable $t) {
        setEventMessages($langs->trans('KnotSetupCronTestFailed', $t->getMessage()), null, 'errors');
    }
    header('Location: ' . $knot_setup_redirect_self());
    exit;
}

if ($action === 'license_cache_invalidate') {
    if (function_exists('checkToken') && !checkToken()) {
        accessforbidden('Invalid CSRF token');
    }
    $extId = (string) GETPOST('extension_id', 'aZ09');
    if (!preg_match('/^[a-z0-9-]{2,64}$/', $extId)) {
        setEventMessages($langs->trans('KnotLicenseCacheBadExtensionId'), null, 'errors');
    } else {
        (new \Knot\Licensing\LicenseCache())->delete($extId);
        setEventMessages($langs->trans('KnotLicenseCacheInvalidated', $extId), null, 'mesgs');
    }
    header('Location: ' . $knot_setup_redirect_self());
    exit;
}

if ($action === 'health_worker_run') {
    if (function_exists('checkToken') && !checkToken()) {
        accessforbidden('Invalid CSRF token');
    }
    try {
        $worker = new \Knot\Engine\HealthWorker();
        $stale = $worker->run();
        $errParts = [];
        if ($worker->error !== '') {
            $errParts[] = $worker->error;
        }
        foreach ($worker->errors as $e) {
            $errParts[] = (string) $e;
        }
        if ($errParts !== []) {
            setEventMessages($langs->trans('KnotHealthWorkerRunError', implode(' ', $errParts)), null, 'errors');
        } else {
            setEventMessages($langs->trans('KnotHealthWorkerRunMsg', (string) $stale), null, 'mesgs');
        }
    } catch (\Throwable $t) {
        setEventMessages($langs->trans('KnotHealthWorkerRunError', $t->getMessage()), null, 'errors');
    }
    header('Location: ' . $knot_setup_redirect_self());
    exit;
}

if ($action === 'refresh_menus') {
    if (function_exists('checkToken') && !checkToken()) {
        accessforbidden('Invalid CSRF token');
    }
    $count = $refreshMenus($db, (int) $conf->entity);
    setEventMessages($langs->trans('KnotMenusRefreshedMsg', $count), null, 'mesgs');
    header('Location: ' . $knot_setup_redirect_self());
    exit;
}

if ($action === 'backup_now') {
    if (function_exists('checkToken') && !checkToken()) {
        accessforbidden('Invalid CSRF token');
    }
    try {
        $result = \Knot\Maintenance\LocalKnotBackup::run($db, 'pre-upgrade');
        $zip = $result['zip'];
        $msg = is_file($zip)
            ? $langs->trans('KnotSetupBackupZipMsg', basename($zip))
            : $langs->trans('KnotSetupBackupSqlMsg', basename($result['dir']));
        setEventMessages($msg, null, 'mesgs');
    } catch (\Throwable $e) {
        setEventMessages($langs->trans('KnotSetupBackupError', $e->getMessage()), null, 'errors');
    }
    header('Location: ' . $knot_setup_redirect_self());
    exit;
}

if ($action === 'refresh_introspection') {
    if (function_exists('checkToken') && !checkToken()) {
        accessforbidden('Invalid CSRF token');
    }
    try {
        $factory = new \Knot\Dolibarr\ObjectFactory();
        $report = $factory->refreshIntrospection($db);
        setEventMessages($langs->trans('KnotSetupIntrospectionRefreshed', (string) ($report['count'] ?? 0)), null, 'mesgs');
    } catch (\Throwable $e) {
        setEventMessages($langs->trans('KnotSetupIntrospectionRefreshFailed', $e->getMessage()), null, 'errors');
    }
    header('Location: ' . $knot_setup_redirect_self());
    exit;
}

if ($action === 'seed_demos') {
    if (function_exists('checkToken') && !checkToken()) {
        accessforbidden('Invalid CSRF token');
    }
    $templatesRepo = new \Knot\Repository\TemplateRepository($db);
    $count = $templatesRepo->seedDemoWorkflows((int) $conf->entity, (int) ($user->id ?? 0) ?: null);
    dolibarr_set_const($db, 'KNOT_DEMO_WORKFLOWS_SEEDED', '1', 'chaine', 0, '', $conf->entity);
    $msg = $count > 0
        ? $langs->trans('KnotSetupSeedDemoRestored', (string) $count)
        : $langs->trans('KnotSetupSeedDemoAllPresent');
    setEventMessages($msg, null, 'mesgs');
    header('Location: ' . $knot_setup_redirect_self());
    exit;
}

if ($action === 'reset') {
    if (function_exists('checkToken') && !checkToken()) {
        accessforbidden('Invalid CSRF token');
    }

    dolibarr_set_const($db, 'KNOT_SETUP_COMPLETED', '0', 'chaine', 0, '', $conf->entity);
    dolibarr_set_const($db, 'KNOT_ENGINE_ENABLED', '0', 'chaine', 0, '', $conf->entity);
    setEventMessages($langs->trans('KnotSetupResetMsg'), null, 'mesgs');

    header('Location: ' . $knot_setup_redirect_self());
    exit;
}

if ($action === 'run_migrations') {
    if (function_exists('checkToken') && !checkToken()) {
        accessforbidden('Invalid CSRF token');
    }
    try {
        $migrator = new \Knot\Migration\Migrator($db, dirname(__DIR__));
        $applied = $migrator->run();
        $count = count($applied);
        if ($count === 0) {
            setEventMessages($langs->trans('KnotSetupMigrationsNone'), null, 'mesgs');
        } else {
            $labels = array_map(
                static fn (array $row): string => ($row['version'] ?? '?') . '/' . ($row['file'] ?? '?'),
                $applied
            );
            setEventMessages(
                $langs->trans('KnotSetupMigrationsApplied', $count, implode(', ', $labels)),
                null,
                'mesgs'
            );
        }
    } catch (\Throwable $e) {
        setEventMessages($langs->trans('KnotSetupMigrationsFailed', $e->getMessage()), null, 'errors');
    }
    header('Location: ' . $knot_setup_redirect_self());
    exit;
}

if ($action === 'engine_off') {
    if (function_exists('checkToken') && !checkToken()) {
        accessforbidden('Invalid CSRF token');
    }
    dolibarr_set_const($db, 'KNOT_ENGINE_ENABLED', '0', 'chaine', 0, '', $conf->entity);
    if (isset($conf->global) && is_object($conf->global)) {
        $conf->global->KNOT_ENGINE_ENABLED = '0';
    } elseif (isset($conf->global) && is_array($conf->global)) {
        $conf->global['KNOT_ENGINE_ENABLED'] = '0';
    }
    setEventMessages($langs->trans('KnotEnginePausedMsg'), null, 'mesgs');
    header('Location: ' . $knot_setup_redirect_self());
    exit;
}

if ($action === 'engine_on') {
    if (function_exists('checkToken') && !checkToken()) {
        accessforbidden('Invalid CSRF token');
    }
    $wasSetupIncomplete = getDolGlobalString('KNOT_SETUP_COMPLETED') !== '1';
    try {
        dolibarr_set_const($db, 'KNOT_ENGINE_ENABLED', '1', 'chaine', 0, '', $conf->entity);
        if ($wasSetupIncomplete) {
            dolibarr_set_const($db, 'KNOT_SETUP_COMPLETED', '1', 'chaine', 0, '', $conf->entity);
        }
        if (isset($conf->global) && is_object($conf->global)) {
            $conf->global->KNOT_ENGINE_ENABLED = '1';
            if ($wasSetupIncomplete) {
                $conf->global->KNOT_SETUP_COMPLETED = '1';
            }
        } elseif (isset($conf->global) && is_array($conf->global)) {
            $conf->global['KNOT_ENGINE_ENABLED'] = '1';
            if ($wasSetupIncomplete) {
                $conf->global['KNOT_SETUP_COMPLETED'] = '1';
            }
        }
        $runKnotGoLive($db, $conf, $user);
        if ($wasSetupIncomplete) {
            setEventMessages($langs->trans('KnotSetupCompletedMsg'), null, 'mesgs');
        }
        setEventMessages($langs->trans('KnotEngineResumedMsg'), null, 'mesgs');
    } catch (\Throwable $e) {
        setEventMessages($langs->trans('KnotSetupEngineActivationError', $e->getMessage()), null, 'errors');
    }
    header('Location: ' . $knot_setup_redirect_self());
    exit;
}

if ($action === 'marketplace_preview_on' || $action === 'marketplace_preview_off') {
    if (function_exists('checkToken') && !checkToken()) {
        accessforbidden('Invalid CSRF token');
    }
    $previousValue = (string) getDolGlobalString('KNOT_MARKETPLACE_PREVIEW_LOCKED', '1');
    $newValue = $action === 'marketplace_preview_on' ? '1' : '0';
    dolibarr_set_const($db, 'KNOT_MARKETPLACE_PREVIEW_LOCKED', $newValue, 'chaine', 0, '', $conf->entity);
    try {
        $auditRepo = new \Knot\Repository\AuditLogRepository($db);
        $auditRepo->record(
            'config.marketplace_preview_changed',
            'config',
            null,
            (int) ($user->id ?? 0) ?: null,
            ['from' => $previousValue, 'to' => $newValue],
            (int) $conf->entity
        );
    } catch (\Throwable $e) {
        // Audit failure must never break the admin workflow.
    }
    setEventMessages(
        $newValue === '1'
            ? $langs->trans('KnotMarketplacePreviewSwitchedToShowcase')
            : $langs->trans('KnotMarketplacePreviewSwitchedToStrict'),
        null,
        'mesgs'
    );
    header('Location: ' . $knot_setup_redirect_self());
    exit;
}

if ($action === 'marketplace_ui_on' || $action === 'marketplace_ui_off') {
    if (function_exists('checkToken') && !checkToken()) {
        accessforbidden('Invalid CSRF token');
    }
    $previousValue = (string) getDolGlobalString('KNOT_MARKETPLACE_UI_ENABLED', '1');
    $newValue = $action === 'marketplace_ui_on' ? '1' : '0';
    dolibarr_set_const($db, 'KNOT_MARKETPLACE_UI_ENABLED', $newValue, 'chaine', 0, '', $conf->entity);
    if (isset($conf->global) && is_object($conf->global)) {
        $conf->global->KNOT_MARKETPLACE_UI_ENABLED = $newValue;
    } elseif (isset($conf->global) && is_array($conf->global)) {
        $conf->global['KNOT_MARKETPLACE_UI_ENABLED'] = $newValue;
    }
    try {
        $auditRepo = new \Knot\Repository\AuditLogRepository($db);
        $auditRepo->record(
            'config.marketplace_ui_changed',
            'config',
            null,
            (int) ($user->id ?? 0) ?: null,
            ['from' => $previousValue, 'to' => $newValue],
            (int) $conf->entity
        );
    } catch (\Throwable $e) {
        // Audit failure must never break the admin workflow.
    }
    setEventMessages(
        $newValue === '1'
            ? $langs->trans('KnotMarketplaceUiSwitchedOn')
            : $langs->trans('KnotMarketplaceUiSwitchedOff'),
        null,
        'mesgs'
    );
    header('Location: ' . $knot_setup_redirect_self());
    exit;
}

$dolOk = version_compare(DOL_VERSION, '20.0.0', '>=');
$phpOk = version_compare(PHP_VERSION, '8.1.0', '>=');

$tablesPresent = 0;
foreach ($tablesExpected as $table) {
    $sql = 'SHOW TABLES LIKE ' . "'" . $db->escape(MAIN_DB_PREFIX . 'knot_' . $table) . "'";
    $res = $db->query($sql);
    if ($res && $db->num_rows($res) > 0) {
        $tablesPresent++;
    }
}
$tablesOk = $tablesPresent === count($tablesExpected);

$cronCount = 0;
$cronEnabled = false;
$cronLastRun = null;
$resCronAgg = $db->query(
    'SELECT COUNT(*) AS c, COALESCE(SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END), 0) AS a, MAX(datelastrun) AS lastrun '
    . 'FROM ' . MAIN_DB_PREFIX . "cronjob WHERE module_name = 'knot'"
);
if ($resCronAgg && $rowCron = $db->fetch_object($resCronAgg)) {
    $cronCount = (int) $rowCron->c;
    $cronActiveLines = (int) $rowCron->a;
    $cronEnabled = $cronCount > 0 && $cronActiveLines === $cronCount;
    $cronLastRun = $rowCron->lastrun;
}
$cronOk = $cronCount > 0 && $cronEnabled;

$cronStaleThresholdSeconds = 15 * 60;
$cronNeverRunYet = false;
$cronStaleOld = false;
$cronStaleAgo = null;
if ($cronEnabled) {
    if ($cronLastRun === null || $cronLastRun === '') {
        $cronNeverRunYet = true;
    } else {
        $lastRunTs = is_numeric($cronLastRun) ? (int) $cronLastRun : (int) dol_stringtotime((string) $cronLastRun, 'tzserver');
        if ($lastRunTs > 0) {
            $cronStaleAgo = time() - $lastRunTs;
            if ($cronStaleAgo > $cronStaleThresholdSeconds) {
                $cronStaleOld = true;
            }
        } else {
            $cronNeverRunYet = true;
        }
    }
}

$cronGlobalDisableRaw = (string) getDolGlobalString('CRON_DISABLE_JOBS');
$cronGlobalDisabledBool = $cronGlobalDisableRaw === '1' || strcasecmp($cronGlobalDisableRaw, 'on') === 0;

/** @var object{status: int, datelastrun: string|null, datenextrun: string|null}|null */
$healthWorkerCronJob = null;
$sqlHwCron = 'SELECT status, datelastrun, datenextrun FROM ' . MAIN_DB_PREFIX . "cronjob "
    . "WHERE module_name = 'knot' AND label = 'KnotHealthWorker' "
    . 'ORDER BY rowid DESC LIMIT 1';
$resHwCron = $db->query($sqlHwCron);
if ($resHwCron && $hwRow = $db->fetch_object($resHwCron)) {
    $healthWorkerCronJob = (object) [
        'status' => (int) $hwRow->status,
        'datelastrun' => isset($hwRow->datelastrun) ? (string) $hwRow->datelastrun : null,
        'datenextrun' => isset($hwRow->datenextrun) ? (string) $hwRow->datenextrun : null,
    ];
}

$menuCount = 0;
$res = $db->query("SELECT COUNT(*) AS nb FROM " . MAIN_DB_PREFIX . "menu WHERE module = 'knot' AND entity = " . (int) $conf->entity);
if ($res) {
    $menuCount = (int) ($db->fetch_object($res)->nb ?? 0);
}
$expectedMenus = ModuleExpectations::MENU_ENTRY_COUNT;
$menusOk = $menuCount >= $expectedMenus;

$rightsCount = 0;
$res = $db->query("SELECT COUNT(*) AS nb FROM " . MAIN_DB_PREFIX . "rights_def WHERE module = 'knot'");
if ($res) {
    $rightsCount = (int) ($db->fetch_object($res)->nb ?? 0);
}
$rightsOk = $rightsCount >= 5;

$opensslOk = extension_loaded('openssl');
$jsonOk = extension_loaded('json');
$mbstringOk = extension_loaded('mbstring');
$curlOk = extension_loaded('curl');

// PHP timezone diagnostics. Most shared hosts ship with UTC as the default
// php.ini value, which surfaces as Dolibarr displaying timestamps shifted
// vs. the user's wall clock. We don't fail the setup over it, but we surface
// a soft warning so the customer knows to set date.timezone in their host's
// PHP settings (cPanel, Plesk, OVH, …) or via .user.ini in their docroot.
$phpTimezone = date_default_timezone_get();
$phpTimezoneIniValue = (string) ini_get('date.timezone');
$phpTimezoneLooksDefault = $phpTimezone === 'UTC';

$setupCompleted = getDolGlobalString('KNOT_SETUP_COMPLETED') === '1';
$engineEnabled = getDolGlobalString('KNOT_ENGINE_ENABLED') === '1';

$introspectionCache = new \Knot\Dolibarr\DescriptorCache();
$introspectionPayload = $introspectionCache->read();
$introspectionCount = $introspectionPayload === null
    ? 0
    : count($introspectionPayload['descriptors'] ?? []);
$introspectionGenerated = $introspectionPayload === null
    ? null
    : (string) ($introspectionPayload['generatedAt'] ?? '');
$blocklistRaw = (string) getDolGlobalString('KNOT_INTROSPECTION_BLOCKLIST', '');
$previewBase = DOL_URL_ROOT . '/custom/knot/workflows/preview.php';
$knotDolibarrSchemasListUrl = DOL_URL_ROOT . '/custom/knot/api/dolibarr_schemas.php?list=1';
$knotStarterWorkflowCount = count(glob(dirname(__DIR__) . '/examples/starter/*.knot.json') ?: []);

if ($setupCompleted && getDolGlobalString('KNOT_INTROSPECTION_AUTO_AT') === '' && (string) $action === '') {
    try {
        (new \Knot\Dolibarr\ObjectFactory())->refreshIntrospection($db);
        dolibarr_set_const($db, 'KNOT_INTROSPECTION_AUTO_AT', (string) time(), 'chaine', 0, '', $conf->entity);
        $introspectionPayload = $introspectionCache->read();
        $introspectionCount = $introspectionPayload === null
            ? 0
            : count($introspectionPayload['descriptors'] ?? []);
        $introspectionGenerated = $introspectionPayload === null
            ? null
            : (string) ($introspectionPayload['generatedAt'] ?? '');
        if ($introspectionCount > 0) {
            setEventMessages(
                $langs->trans('KnotSetupIntrospectionAutoGenerated', (string) $introspectionCount),
                null,
                'mesgs'
            );
        }
    } catch (\Throwable $e) {
        dolibarr_set_const($db, 'KNOT_INTROSPECTION_AUTO_AT', '0', 'chaine', 0, '', $conf->entity);
        setEventMessages($langs->trans('KnotSetupIntrospectionAutoUnavailable', $e->getMessage()), null, 'warnings');
    }
}

$checks = [
    [
        'key' => 'dolibarr',
        'icon' => 'fa-cube',
        'label' => $langs->trans('KnotCheckDolibarr'),
        'detail' => DOL_VERSION,
        'ok' => $dolOk,
    ],
    [
        'key' => 'php',
        'icon' => 'fa-code',
        'label' => $langs->trans('KnotCheckPhp'),
        'detail' => PHP_VERSION,
        'ok' => $phpOk,
    ],
    [
        'key' => 'tables',
        'icon' => 'fa-database',
        'label' => $langs->trans('KnotCheckTables'),
        'detail' => $tablesPresent . ' / ' . count($tablesExpected),
        'ok' => $tablesOk,
    ],
    [
        'key' => 'rights',
        'icon' => 'fa-key',
        'label' => $langs->trans('KnotCheckRights'),
        'detail' => (string) $rightsCount,
        'ok' => $rightsOk,
    ],
    [
        'key' => 'cron',
        'icon' => 'fa-clock',
        'label' => $langs->trans('KnotCheckCron'),
        'detail' => $cronOk ? $langs->trans('KnotCheckRegistered') : $langs->trans('KnotCheckMissing'),
        'ok' => $cronOk,
    ],
    [
        'key' => 'menus',
        'icon' => 'fa-bars',
        'label' => $langs->trans('KnotCheckMenus'),
        'detail' => $menusOk
            ? $langs->trans('KnotSetupMenusRegisteredDetail', $menuCount, $expectedMenus)
            : $langs->trans('KnotSetupMenusMinimumDetail', $menuCount, $expectedMenus),
        'ok' => $menusOk,
    ],
    [
        'key' => 'openssl',
        'icon' => 'fa-shield-alt',
        'label' => $langs->trans('KnotSetupCheckOpenSsl'),
        'detail' => $opensslOk ? $langs->trans('KnotCheckLoaded') : $langs->trans('KnotCheckMissing'),
        'ok' => $opensslOk,
    ],
    [
        'key' => 'curl',
        'icon' => 'fa-globe',
        'label' => $langs->trans('KnotSetupCheckCurl'),
        'detail' => $curlOk ? $langs->trans('KnotCheckLoaded') : $langs->trans('KnotCheckMissing'),
        'ok' => $curlOk,
    ],
    [
        'key' => 'json',
        'icon' => 'fa-file-code',
        'label' => $langs->trans('KnotSetupCheckJson'),
        'detail' => $jsonOk ? $langs->trans('KnotCheckLoaded') : $langs->trans('KnotCheckMissing'),
        'ok' => $jsonOk,
    ],
    [
        'key' => 'mbstring',
        'icon' => 'fa-font',
        'label' => $langs->trans('KnotSetupCheckMbstring'),
        'detail' => $mbstringOk ? $langs->trans('KnotCheckLoaded') : $langs->trans('KnotCheckMissing'),
        'ok' => $mbstringOk,
    ],
];

$totalChecks = count($checks);
$okChecks = 0;
foreach ($checks as $check) {
    if ($check['ok']) {
        $okChecks++;
    }
}
$progress = $totalChecks > 0 ? (int) round(($okChecks / $totalChecks) * 100) : 0;
$allOk = $okChecks === $totalChecks;

$steps = [
    [
        'icon' => 'fa-rocket',
        'title' => $langs->trans('KnotStep1Title'),
        'desc' => $langs->trans('KnotStep1Desc'),
        'done' => $tablesOk && $rightsOk,
    ],
    [
        'icon' => 'fa-shield-alt',
        'title' => $langs->trans('KnotStep2Title'),
        'desc' => $langs->trans('KnotStep2Desc'),
        'done' => $opensslOk,
    ],
    [
        'icon' => 'fa-bolt',
        'title' => $langs->trans('KnotStep3Title'),
        'desc' => $langs->trans('KnotStep3Desc'),
        'done' => $cronOk,
    ],
    [
        'icon' => 'fa-check-double',
        'title' => $langs->trans('KnotStep4Title'),
        'desc' => $langs->trans('KnotStep4Desc'),
        'done' => $setupCompleted,
    ],
];

$knotIconBase = DOL_URL_ROOT . '/custom/knot/img/brand';
$knotHead = '<link rel="icon" type="image/svg+xml" href="' . dol_escape_htmltag($knotIconBase . '/favicon.svg') . '">'
    . '<link rel="icon" type="image/png" sizes="32x32" href="' . dol_escape_htmltag($knotIconBase . '/favicon-32.png') . '">'
    . '<link rel="shortcut icon" href="' . dol_escape_htmltag($knotIconBase . '/favicon.ico') . '">';

llxHeader(
    $knotHead,
    $langs->trans('KnotSetupTitle'),
    '',
    '',
    0,
    0,
    ['/knot/js/knot-app.js'],
    ['/knot/css/knot-host.css', '/knot/css/knot.css']
);

// Anti-flash: align this server-rendered page with the Vue DarkModeToggle.
// Reads the same localStorage key (`knot.theme`) as DarkModeToggle.vue and
// applies the theme before the browser paints, so setup.php stays in sync
// with the rest of the module (default = light unless user opted into dark).
?>
<script>
(function () {
    try {
        var t = localStorage.getItem('knot.theme');
        document.documentElement.setAttribute('data-knot-theme', t === 'dark' ? 'dark' : 'light');
    } catch (e) {
        document.documentElement.setAttribute('data-knot-theme', 'light');
    }
})();
</script>
<?php

$marketplaceUiEnabled = KnotMarketplacePresentation::marketplaceUiEnabled();

$kCopyIdleSnippet = '<i class="fas fa-copy"></i> ' . $langs->trans('KnotSetupCopy');
$kCopyDoneSnippet = '<i class="fas fa-check"></i> ' . $langs->trans('KnotSetupCopied');
$knotSetupOnclickCronCopyMain = htmlspecialchars(
    '(function(b){var i=document.getElementById("knot-cron-url");if(!i)return;i.select();'
    . "document.execCommand('copy');var done="
    . json_encode($kCopyDoneSnippet)
    . ',idle='
    . json_encode($kCopyIdleSnippet)
    . ';b.innerHTML=done;setTimeout(function(){b.innerHTML=idle;},1500);})(this)',
    ENT_QUOTES,
    'UTF-8'
);
$knotSetupOnclickCronCopyCta = htmlspecialchars(
    '(function(b){var i=document.getElementById("knot-cron-url-cta");if(!i)return;i.select();'
    . "document.execCommand('copy');var done="
    . json_encode($kCopyDoneSnippet)
    . ',idle='
    . json_encode($kCopyIdleSnippet)
    . ';b.innerHTML=done;setTimeout(function(){b.innerHTML=idle;},1500);})(this)',
    ENT_QUOTES,
    'UTF-8'
);
include __DIR__ . '/../tpl/knot-leftnav.tpl.php';

?>
<div class="knot-shell">
    <header class="knot-hero">
        <div class="knot-hero__bg" aria-hidden="true"></div>
        <div class="knot-hero__logo" aria-hidden="true">
            <img src="<?php print dol_escape_htmltag(DOL_URL_ROOT . '/custom/knot/img/brand/knot-symbol-512.png'); ?>" alt="" />
        </div>
        <div class="knot-hero__content">
            <div class="knot-hero__badge">
                <span class="knot-hero__dot" aria-hidden="true">🚀</span>
                <?php print dol_escape_htmltag($langs->trans('KnotBadgeBeta')); ?>
            </div>
            <h1 class="knot-hero__title">
                <span class="knot-hero__brand"><?php print dol_escape_htmltag($langs->trans('KnotBrandName')); ?></span>
                <span class="knot-hero__sub"><?php print dol_escape_htmltag($langs->trans('KnotHeroSub')); ?></span>
            </h1>
            <p class="knot-hero__lead"><?php print dol_escape_htmltag($langs->trans('KnotHeroLead')); ?></p>
            <div class="knot-hero__meta">
                <span class="knot-chip"><i class="fas fa-shield-alt"></i> <?php print dol_escape_htmltag($langs->trans('KnotChipLocal')); ?></span>
                <span class="knot-chip"><i class="fas fa-puzzle-piece"></i> <?php print dol_escape_htmltag($langs->trans('KnotChipExtensible')); ?></span>
                <span class="knot-chip"><i class="fas fa-lock"></i> <?php print dol_escape_htmltag($langs->trans('KnotChipEncrypted')); ?></span>
            </div>
            <?php
            // V2.5.0b chantier 4 — beta-tester landing strip on the
            // setup hero so a fresh tester immediately knows where the
            // doc lives, where to file bugs, and what the live health
            // status is. Healthcheck status is computed below from the
            // same checks the wizard already runs.
            $betaDocsUrl = DOL_URL_ROOT . '/custom/knot/docs/beta-testers/README.md';
            $betaIssueUrl = 'mailto:beta@knot.tools?subject=Knot%20beta%20feedback';
            $healthAllOk = $allOk ?? false;
            $healthChipLabelSuffix = dol_escape_htmltag($healthAllOk ? $langs->trans('KnotHealthOk') : $langs->trans('KnotHealthWarning'));
            $healthcheckPrefixEsc = dol_escape_htmltag($langs->trans('KnotSetupBetaHealthcheckPrefix'));
            $healthClass = $healthAllOk ? 'knot-chip--ok' : 'knot-chip--warn';
            ?>
            <div class="knot-hero__meta knot-hero__meta--beta" style="margin-top:10px">
                <a class="knot-chip knot-chip--link" href="<?php print dol_escape_htmltag($betaDocsUrl); ?>" target="_blank" rel="noopener">
                    <i class="fas fa-book"></i> <?php print dol_escape_htmltag($langs->trans('KnotSetupBetaDocsChip')); ?>
                </a>
                <a class="knot-chip knot-chip--link" href="<?php print dol_escape_htmltag($betaIssueUrl); ?>">
                    <i class="fas fa-bug"></i> <?php print dol_escape_htmltag($langs->trans('KnotSetupBetaReportBugChip')); ?>
                </a>
                <span class="knot-chip <?php print $healthClass; ?>">
                    <i class="fas fa-heartbeat"></i> <?php print $healthcheckPrefixEsc; ?> <?php print $healthChipLabelSuffix; ?>
                </span>
            </div>
        </div>
    </header>

    <?php if ($cronNeverRunYet) : ?>
    <div class="knot-banner knot-banner--info" role="status">
        <div class="knot-banner__icon"><i class="fas fa-info-circle"></i></div>
        <div class="knot-banner__body">
            <div class="knot-banner__title"><?php print dol_escape_htmltag($langs->trans('KnotSetupCronBannerFreshTitle')); ?></div>
            <p class="knot-banner__text">
                <?php
                print $langs->trans('KnotSetupCronBannerFreshBody', $langs->trans('KnotSetupCronTestNow'));
                ?>
            </p>
            <a href="#knot-engine-card" class="knot-btn knot-btn--ghost knot-btn--xs"><i class="fas fa-arrow-down"></i> <?php print dol_escape_htmltag($langs->trans('KnotSetupCronBannerFreshCtaDown')); ?></a>
        </div>
    </div>
    <?php elseif ($cronStaleOld) : ?>
    <div class="knot-banner knot-banner--warn" role="alert">
        <div class="knot-banner__icon"><i class="fas fa-exclamation-triangle"></i></div>
        <div class="knot-banner__body">
            <div class="knot-banner__title"><?php print dol_escape_htmltag($langs->trans('KnotSetupCronBannerStaleTitle')); ?></div>
            <p class="knot-banner__text">
                <?php
                print $cronStaleAgo === null
                    ? $langs->trans('KnotSetupCronBannerStaleParaNoAgo')
                    : $langs->trans('KnotSetupCronBannerStaleParaAgo', (string) (int) round($cronStaleAgo / 60));
                ?>
            </p>
            <p class="knot-banner__text">
                <?php print $langs->trans('KnotSetupCronBannerStaleSolution'); ?>
            </p>
            <a href="#knot-cron-url" class="knot-btn knot-btn--ghost knot-btn--xs"><i class="fas fa-arrow-down"></i> <?php print dol_escape_htmltag($langs->trans('KnotSetupCronBannerStaleSeeUrl')); ?></a>
        </div>
    </div>
    <?php endif; ?>

    <section class="knot-grid">
        <article class="knot-card knot-card--progress">
            <div class="knot-card__head">
                <h2 class="knot-card__title"><?php print dol_escape_htmltag($langs->trans('KnotProgressTitle')); ?></h2>
                <span class="knot-card__hint"><?php print dol_escape_htmltag($langs->trans('KnotProgressHint', (string) $okChecks, (string) $totalChecks)); ?></span>
            </div>

            <div class="knot-progress" role="progressbar" aria-valuenow="<?php print $progress; ?>" aria-valuemin="0" aria-valuemax="100">
                <div class="knot-progress__track">
                    <div class="knot-progress__fill<?php print $allOk ? ' is-complete' : ''; ?>" style="width: <?php print $progress; ?>%"></div>
                </div>
                <div class="knot-progress__value"><?php print $progress; ?>%</div>
            </div>

            <ol class="knot-steps">
                <?php foreach ($steps as $i => $step) : ?>
                    <li class="knot-step<?php print $step['done'] ? ' is-done' : ''; ?>">
                        <div class="knot-step__index">
                            <?php if ($step['done']) : ?>
                                <i class="fas fa-check"></i>
                            <?php else : ?>
                                <?php print $i + 1; ?>
                            <?php endif; ?>
                        </div>
                        <div class="knot-step__body">
                            <div class="knot-step__title">
                                <i class="fas <?php print dol_escape_htmltag($step['icon']); ?>"></i>
                                <?php print dol_escape_htmltag($step['title']); ?>
                            </div>
                            <div class="knot-step__desc"><?php print dol_escape_htmltag($step['desc']); ?></div>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ol>
        </article>

        <article class="knot-card knot-card--checks">
            <div class="knot-card__head">
                <h2 class="knot-card__title"><?php print dol_escape_htmltag($langs->trans('KnotChecksTitle')); ?></h2>
                <span class="knot-card__hint"><?php print dol_escape_htmltag($langs->trans('KnotChecksHint')); ?></span>
            </div>

            <ul class="knot-checks">
                <?php foreach ($checks as $check) : ?>
                    <li class="knot-check<?php print $check['ok'] ? ' is-ok' : ' is-warn'; ?>">
                        <div class="knot-check__icon">
                            <i class="fas <?php print dol_escape_htmltag($check['icon']); ?>"></i>
                        </div>
                        <div class="knot-check__text">
                            <div class="knot-check__label"><?php print dol_escape_htmltag($check['label']); ?></div>
                            <div class="knot-check__detail"><?php print dol_escape_htmltag($check['detail']); ?></div>
                        </div>
                        <div class="knot-check__status">
                            <?php if ($check['ok']) : ?>
                                <i class="fas fa-check-circle"></i>
                            <?php else : ?>
                                <i class="fas fa-exclamation-triangle"></i>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </article>
    </section>

    <section id="knot-introspection" class="knot-card knot-card--checks">
        <div class="knot-card__head">
            <h2 class="knot-card__title"><?php print dol_escape_htmltag($langs->trans('KnotSetupIntrospectionTitle')); ?></h2>
            <span class="knot-card__hint"><?php print dol_escape_htmltag($langs->trans('KnotSetupIntrospectionHint')); ?></span>
        </div>
        <p class="knot-engine__hint" style="margin:0 22px 12px;">
            <?php
            print $langs->trans(
                'KnotSetupIntrospectionLead',
                dol_escape_htmltag($previewBase . '?mode=capabilities'),
                dol_escape_htmltag($knotDolibarrSchemasListUrl)
            );
            ?>
        </p>
        <div class="knot-engine__rows" style="padding:0 22px 22px;">
            <div class="knot-engine__row knot-engine__row--stacked">
                <span class="knot-engine__label"><?php print dol_escape_htmltag($langs->trans('KnotSetupIntrospectionCacheLabel')); ?></span>
                <div class="knot-cron-url" style="align-items:center;flex-wrap:wrap;gap:8px;">
                    <?php if ($introspectionCount === 0) : ?>
                        <span class="knot-pill knot-pill--warn"><i class="fas fa-search"></i> <?php print dol_escape_htmltag($langs->trans('KnotSetupIntrospectionCacheEmpty')); ?></span>
                    <?php else : ?>
                        <span class="knot-pill knot-pill--ok"><i class="fas fa-search"></i> <?php print dol_escape_htmltag($langs->trans('KnotSetupIntrospectionObjectsFound', (string) (int) $introspectionCount)); ?></span>
                        <?php if ($introspectionGenerated !== null && $introspectionGenerated !== '') : ?>
                            <span class="knot-engine__hint" style="margin:0;"><?php print dol_escape_htmltag($langs->trans('KnotSetupIntrospectionGeneratedAtPrefix', substr((string) $introspectionGenerated, 0, 16))); ?></span>
                        <?php endif; ?>
                    <?php endif; ?>
                    <form method="POST" action="<?php print dol_escape_htmltag($_SERVER['PHP_SELF']); ?>" class="knot-inline-form">
                        <input type="hidden" name="token" value="<?php print newToken(); ?>"><?php print $knotSetupAdminHiddenInline; ?>
                        <input type="hidden" name="action" value="refresh_introspection">
                        <button type="submit" class="knot-btn knot-btn--ghost knot-btn--xs"><i class="fas fa-sync"></i> <?php print dol_escape_htmltag($langs->trans('KnotSetupIntrospectionRefreshBtn')); ?></button>
                    </form>
                </div>
                <span class="knot-engine__hint">
                    <?php
                    $blockSnippet = '';
                    if ($blocklistRaw !== '') {
                        $blockSnippet = $langs->trans('KnotSetupIntrospectionBlocklistTail', dol_escape_htmltag($blocklistRaw));
                    }
                    print $langs->trans('KnotSetupIntrospectionTechHint') . $blockSnippet;
                    ?>
                </span>
            </div>
        </div>
    </section>

    <section class="knot-grid knot-grid--two">
        <a class="knot-card knot-card--feature knot-card--link" href="<?php print dol_escape_htmltag($previewBase . '?mode=editor'); ?>">
            <div class="knot-feature__icon"><i class="fas fa-stream"></i></div>
            <h3 class="knot-feature__title"><?php print dol_escape_htmltag($langs->trans('KnotFeature1Title')); ?></h3>
            <p class="knot-feature__desc"><?php print dol_escape_htmltag($langs->trans('KnotFeature1Desc')); ?></p>
            <span class="knot-feature__cta"><?php print dol_escape_htmltag($langs->trans('KnotFeatureOpenEditor')); ?> <i class="fas fa-arrow-right"></i></span>
        </a>

        <a class="knot-card knot-card--feature knot-card--link" href="<?php print dol_escape_htmltag($previewBase . '?mode=connectors'); ?>">
            <div class="knot-feature__icon knot-feature__icon--accent"><i class="fas fa-plug"></i></div>
            <h3 class="knot-feature__title"><?php print dol_escape_htmltag($langs->trans('KnotFeature2Title')); ?></h3>
            <p class="knot-feature__desc"><?php print dol_escape_htmltag($langs->trans('KnotFeature2Desc')); ?></p>
            <span class="knot-feature__cta"><?php print dol_escape_htmltag($langs->trans('KnotFeatureOpenCatalog')); ?> <i class="fas fa-arrow-right"></i></span>
        </a>

        <a class="knot-card knot-card--feature knot-card--link" href="<?php print dol_escape_htmltag($previewBase . '?mode=credentials'); ?>">
            <div class="knot-feature__icon knot-feature__icon--success"><i class="fas fa-fingerprint"></i></div>
            <h3 class="knot-feature__title"><?php print dol_escape_htmltag($langs->trans('KnotFeature3Title')); ?></h3>
            <p class="knot-feature__desc"><?php print dol_escape_htmltag($langs->trans('KnotFeature3Desc')); ?></p>
            <span class="knot-feature__cta"><?php print dol_escape_htmltag($langs->trans('KnotFeatureOpenCredentials')); ?> <i class="fas fa-arrow-right"></i></span>
        </a>
    </section>

    <section id="knot-engine-card" class="knot-card knot-card--engine">
        <div class="knot-engine__head">
            <div class="knot-engine__icon"><i class="fas fa-bolt"></i></div>
            <div>
                <h2 class="knot-engine__title"><?php print dol_escape_htmltag($langs->trans('KnotEngineTitle')); ?></h2>
                <p class="knot-engine__desc"><?php print dol_escape_htmltag($langs->trans('KnotEngineExplanation')); ?></p>
            </div>
        </div>
        <div class="knot-engine__rows">
            <div class="knot-engine__row">
                <span class="knot-engine__label"><?php print dol_escape_htmltag($langs->trans('KnotEngineRowModule')); ?></span>
                <span class="knot-pill knot-pill--ok"><i class="fas fa-check"></i> <?php print dol_escape_htmltag($langs->trans('KnotEngineEnabled')); ?></span>
            </div>
            <div class="knot-engine__row">
                <span class="knot-engine__label"><?php print dol_escape_htmltag($langs->trans('KnotEngineRowEngine')); ?></span>
                <?php if ($engineEnabled) : ?>
                    <span class="knot-pill knot-pill--ok"><i class="fas fa-bolt"></i> <?php print dol_escape_htmltag($langs->trans('KnotEngineRunning')); ?></span>
                    <form method="POST" action="<?php print dol_escape_htmltag($_SERVER['PHP_SELF']); ?>" class="knot-inline-form">
                        <input type="hidden" name="token" value="<?php print newToken(); ?>"><?php print $knotSetupAdminHiddenInline; ?>
                        <input type="hidden" name="action" value="engine_off">
                        <button type="submit" class="knot-btn knot-btn--ghost knot-btn--xs"><i class="fas fa-pause"></i> <?php print dol_escape_htmltag($langs->trans('KnotEnginePause')); ?></button>
                    </form>
                <?php else : ?>
                    <span class="knot-pill knot-pill--warn"><i class="fas fa-pause"></i> <?php print dol_escape_htmltag($langs->trans('KnotEnginePaused')); ?></span>
                    <form method="POST" action="<?php print dol_escape_htmltag($_SERVER['PHP_SELF']); ?>" class="knot-inline-form">
                        <input type="hidden" name="token" value="<?php print newToken(); ?>"><?php print $knotSetupAdminHiddenInline; ?>
                        <input type="hidden" name="action" value="engine_on">
                        <button type="submit" class="knot-btn knot-btn--primary knot-btn--xs"><i class="fas fa-play"></i> <?php print dol_escape_htmltag($langs->trans($setupCompleted ? 'KnotEngineResume' : 'KnotEngineActivateFirst')); ?></button>
                    </form>
                <?php endif; ?>
            </div>
            <div class="knot-engine__row">
                <span class="knot-engine__label"><?php print dol_escape_htmltag($langs->trans('KnotSetupSqlMigrationsLabel')); ?></span>
                <span class="knot-engine__hint"><?php print dol_escape_htmltag($langs->trans('KnotSetupSqlMigrationsHint')); ?></span>
                <form method="POST" action="<?php print dol_escape_htmltag($_SERVER['PHP_SELF']); ?>" class="knot-inline-form">
                    <input type="hidden" name="token" value="<?php print newToken(); ?>"><?php print $knotSetupAdminHiddenInline; ?>
                    <input type="hidden" name="action" value="run_migrations">
                    <button type="submit" class="knot-btn knot-btn--ghost knot-btn--xs"><i class="fas fa-database"></i> <?php print dol_escape_htmltag($langs->trans('KnotSetupApplyMigrationsButton')); ?></button>
                </form>
            </div>
            <div class="knot-engine__row">
                <span class="knot-engine__label"><?php print dol_escape_htmltag($langs->trans('KnotEngineRowMenus')); ?></span>
                <?php if ($menusOk) : ?>
                    <span class="knot-pill knot-pill--ok"><i class="fas fa-check"></i> <?php print (int) $menuCount; ?> <span class="knot-engine__hint">(min. <?php print (int) $expectedMenus; ?>)</span></span>
                <?php else : ?>
                    <span class="knot-pill knot-pill--warn"><i class="fas fa-exclamation-triangle"></i> <?php print (int) $menuCount; ?> / <?php print (int) $expectedMenus; ?></span>
                <?php endif; ?>
                <form method="POST" action="<?php print dol_escape_htmltag($_SERVER['PHP_SELF']); ?>" class="knot-inline-form">
                    <input type="hidden" name="token" value="<?php print newToken(); ?>"><?php print $knotSetupAdminHiddenInline; ?>
                    <input type="hidden" name="action" value="refresh_menus">
                    <button type="submit" class="knot-btn knot-btn--ghost knot-btn--xs"><i class="fas fa-sync"></i> <?php print dol_escape_htmltag($langs->trans('KnotEngineRefreshMenus')); ?></button>
                </form>
            </div>
            <div class="knot-engine__row">
                <span class="knot-engine__label"><?php print dol_escape_htmltag($langs->trans('KnotSetupCronjobLabel')); ?></span>
                <?php if ($cronCount > 0 && $cronEnabled) : ?>
                    <span class="knot-pill knot-pill--ok"><i class="fas fa-check"></i> <?php print dol_escape_htmltag($langs->trans('KnotSetupCronStatusEnabled')); ?></span>
                <?php elseif ($cronCount > 0) : ?>
                    <span class="knot-pill knot-pill--warn"><i class="fas fa-pause"></i> <?php print dol_escape_htmltag($langs->trans('KnotSetupCronStatusInactive')); ?></span>
                <?php else : ?>
                    <span class="knot-pill knot-pill--warn"><i class="fas fa-exclamation-triangle"></i> <?php print dol_escape_htmltag($langs->trans('KnotSetupCronStatusMissing')); ?></span>
                <?php endif; ?>
                <?php if ($cronLastRun) : ?>
                    <span class="knot-engine__hint"><?php print dol_escape_htmltag($langs->trans('KnotSetupCronLastRun', dol_print_date(dol_stringtotime((string) $cronLastRun, 'tzserver'), 'dayhour'))); ?></span>
                <?php else : ?>
                    <span class="knot-engine__hint"><?php print dol_escape_htmltag($langs->trans('KnotSetupCronNeverRun')); ?></span>
                <?php endif; ?>
                <?php if ($cronCount > 0 && !$cronEnabled) : ?>
                <form method="POST" action="<?php print dol_escape_htmltag($_SERVER['PHP_SELF']); ?>" class="knot-inline-form">
                    <input type="hidden" name="token" value="<?php print newToken(); ?>"><?php print $knotSetupAdminHiddenInline; ?>
                    <input type="hidden" name="action" value="cron_enable">
                    <button type="submit" class="knot-btn knot-btn--primary knot-btn--xs"><i class="fas fa-play"></i> <?php print dol_escape_htmltag($langs->trans('KnotSetupCronEnableButton')); ?></button>
                </form>
                <?php endif; ?>
                <form method="POST" action="<?php print dol_escape_htmltag($_SERVER['PHP_SELF']); ?>" class="knot-inline-form">
                    <input type="hidden" name="token" value="<?php print newToken(); ?>"><?php print $knotSetupAdminHiddenInline; ?>
                    <input type="hidden" name="action" value="cron_test">
                    <button type="submit" class="knot-btn knot-btn--ghost knot-btn--xs"><i class="fas fa-vial"></i> <?php print dol_escape_htmltag($langs->trans('KnotSetupCronTestNow')); ?></button>
                </form>
            </div>
            <?php
            $cronKey = (string) getDolGlobalString('CRON_KEY');
            $cronUserLogin = (string) getDolGlobalString('CRON_DOLIBARRUSER');
            $cronUserSource = 'configured';
            if ($cronUserLogin === '') {
                // Fallback: pick the first active admin user from llx_user.
                $resAdmin = $db->query("SELECT login FROM " . MAIN_DB_PREFIX . "user WHERE admin = 1 AND statut = 1 ORDER BY rowid ASC LIMIT 1");
                if ($resAdmin && $rowAdmin = $db->fetch_object($resAdmin)) {
                    $cronUserLogin = (string) $rowAdmin->login;
                    $cronUserSource = 'admin';
                }
            }
            if ($cronUserLogin === '') {
                $cronUserLogin = $user->login;
                $cronUserSource = 'current';
            }
            // Dolibarr 17+ ships the web-callable cron under /public/cron/cron_run_jobs_by_url.php.
            // Older versions used cron_run_jobs.php; we keep that as a documented fallback.
            $cronUrlScript = is_readable(DOL_DOCUMENT_ROOT . '/public/cron/cron_run_jobs_by_url.php')
                ? '/public/cron/cron_run_jobs_by_url.php'
                : '/public/cron/cron_run_jobs.php';
            $cronWebUrl = rtrim(DOL_MAIN_URL_ROOT, '/') . $cronUrlScript
                . '?securitykey=' . rawurlencode($cronKey)
                . '&userlogin=' . rawurlencode($cronUserLogin);
            ?>
            <div class="knot-engine__row knot-engine__row--stacked">
                <span class="knot-engine__label"><?php print dol_escape_htmltag($langs->trans('KnotSetupCronScheduleUrl')); ?></span>
                <div class="knot-cron-url">
                    <input id="knot-cron-url" type="text" readonly value="<?php print dol_escape_htmltag($cronWebUrl); ?>" class="knot-cron-url__input" onclick="this.select();">
                    <button type="button" class="knot-btn knot-btn--ghost knot-btn--xs" onclick="<?php print $knotSetupOnclickCronCopyMain; ?>">
                        <?php print $kCopyIdleSnippet; ?>
                    </button>
                </div>
                <span class="knot-engine__hint">
                    <?php
                    $cronUrlUserExplain = '';
                    if ($cronUserSource === 'configured') {
                        $cronUrlUserExplain = $langs->trans('KnotSetupCronUrlUserConfigured');
                    } elseif ($cronUserSource === 'admin') {
                        $cronUrlUserExplain = $langs->trans('KnotSetupCronUrlUserAdmin');
                    } else {
                        $cronUrlUserExplain = $langs->trans('KnotSetupCronUrlUserCurrent');
                    }
                    print $langs->trans('KnotSetupCronUrlHint', $cronUserLogin) . ' ' . $cronUrlUserExplain;
                    ?>
                </span>
            </div>
            <div class="knot-engine__row knot-engine__row--stacked">
                <span class="knot-engine__label"><?php print dol_escape_htmltag($langs->trans('KnotSetupPhpTimezoneLabel')); ?></span>
                <div class="knot-cron-url" style="align-items:center;">
                    <?php if ($phpTimezoneLooksDefault) : ?>
                        <span class="knot-pill knot-pill--warn"><i class="fas fa-exclamation-triangle"></i> <?php print dol_escape_htmltag($phpTimezone); ?></span>
                    <?php else : ?>
                        <span class="knot-pill knot-pill--ok"><i class="fas fa-check"></i> <?php print dol_escape_htmltag($phpTimezone); ?></span>
                    <?php endif; ?>
                    <span class="knot-engine__hint" style="margin:0;">
                        <?php print $langs->trans('KnotSetupPhpServerTimeLabel', dol_escape_htmltag(date('Y-m-d H:i:s T'))); ?>
                    </span>
                </div>
                <?php if ($phpTimezoneLooksDefault) : ?>
                <span class="knot-engine__hint">
                    <?php print $langs->trans('KnotSetupPhpTimezoneUtcHint'); ?>
                </span>
                <?php else : ?>
                <span class="knot-engine__hint">
                    <?php
                    print $langs->trans(
                        'KnotSetupPhpTimezoneOkHint',
                        dol_escape_htmltag($phpTimezoneIniValue !== '' ? $phpTimezoneIniValue : $phpTimezone)
                    );
                    ?>
                </span>
                <?php endif; ?>
            </div>
            <div class="knot-engine__row">
                <span class="knot-engine__label"><?php print dol_escape_htmltag($langs->trans('KnotSetupPreloadedGuidesLabel')); ?></span>
                <span class="knot-pill knot-pill--ok"><i class="fas fa-book"></i> <?php print dol_escape_htmltag($langs->trans('KnotSetupPreloadedGuidesPill', (string) $knotStarterWorkflowCount)); ?></span>
                <form method="POST" action="<?php print dol_escape_htmltag($_SERVER['PHP_SELF']); ?>" class="knot-inline-form">
                    <input type="hidden" name="token" value="<?php print newToken(); ?>"><?php print $knotSetupAdminHiddenInline; ?>
                    <input type="hidden" name="action" value="seed_demos">
                    <button type="submit" class="knot-btn knot-btn--ghost knot-btn--xs"><i class="fas fa-magic"></i> <?php print dol_escape_htmltag($langs->trans('KnotSetupRestoreGuidesBtn')); ?></button>
                </form>
            </div>
            <div class="knot-engine__row">
                <span class="knot-engine__label"><?php print dol_escape_htmltag($langs->trans('KnotSetupKnotBackupLabel')); ?></span>
                <span class="knot-engine__hint"><?php print dol_escape_htmltag($langs->trans('KnotSetupKnotBackupHint')); ?></span>
                <form method="POST" action="<?php print dol_escape_htmltag($_SERVER['PHP_SELF']); ?>" class="knot-inline-form" onsubmit="return confirm('<?php print dol_escape_js($langs->trans('KnotSetupBackupConfirm')); ?>');">
                    <input type="hidden" name="token" value="<?php print newToken(); ?>"><?php print $knotSetupAdminHiddenInline; ?>
                    <input type="hidden" name="action" value="backup_now">
                    <button type="submit" class="knot-btn knot-btn--ghost knot-btn--xs"><i class="fas fa-download"></i> <?php print dol_escape_htmltag($langs->trans('KnotSetupBackupBeforeUpdateBtn')); ?></button>
                </form>
            </div>
            <?php
            // Health metrics snapshot persisted by KnotHealthWorker
            // (KNOT_HEALTH_* constants). Falls back to live SELECT
            // counts when the worker hasn't run yet (fresh install).
            $healthLastRun = (string) getDolGlobalString('KNOT_HEALTH_LAST_RUN_AT');
            $healthQueued = (int) getDolGlobalInt('KNOT_HEALTH_QUEUED');
            $healthRunning = (int) getDolGlobalInt('KNOT_HEALTH_RUNNING');
            $healthSuccess = (int) getDolGlobalInt('KNOT_HEALTH_SUCCESS_24H');
            $healthError = (int) getDolGlobalInt('KNOT_HEALTH_ERROR_24H');
            $healthTimeout = (int) getDolGlobalInt('KNOT_HEALTH_TIMEOUT_24H');
            if ($healthLastRun === '') {
                $resQR = $db->query("SELECT status, COUNT(*) AS n FROM " . MAIN_DB_PREFIX . "knot_execution WHERE entity = " . (int) $conf->entity . " AND status IN ('queued','running') GROUP BY status");
                if ($resQR) {
                    while ($row = $db->fetch_object($resQR)) {
                        if ($row->status === 'queued') {
                            $healthQueued = (int) $row->n;
                        }
                        if ($row->status === 'running') {
                            $healthRunning = (int) $row->n;
                        }
                    }
                }
            }
            $healthBadgeClass = ($healthError > 0 || $healthTimeout > 0)
                ? 'knot-pill--warn'
                : 'knot-pill--ok';
            $healthBadgeKey = ($healthError > 0 || $healthTimeout > 0)
                ? 'KnotSetupHealthBadgeIssues'
                : 'KnotSetupHealthBadgeHealthy';
            $knotFmtSetupCronTs = static function (?string $raw): string {
                if ($raw === null || $raw === '') {
                    return '';
                }
                $ts = is_numeric($raw)
                    ? (int) $raw
                    : (int) dol_stringtotime($raw, 'tzserver');

                return $ts > 0 ? dol_print_date($ts, 'dayhour') : '';
            };
            $hwCronLastFmt = $healthWorkerCronJob !== null
                ? $knotFmtSetupCronTs((string) ($healthWorkerCronJob->datelastrun ?? ''))
                : '';
            $hwCronNextFmt = $healthWorkerCronJob !== null
                ? $knotFmtSetupCronTs((string) ($healthWorkerCronJob->datenextrun ?? ''))
                : '';
            if ($cronGlobalDisabledBool) {
                $healthDolibarrCronLine = $langs->trans('KnotSetupHealthCronGloballyDisabled');
            } elseif ($healthWorkerCronJob === null) {
                $healthDolibarrCronLine = $langs->trans('KnotSetupHealthCronRowMissing');
            } elseif ((int) $healthWorkerCronJob->status !== 1) {
                $healthDolibarrCronLine = $langs->trans('KnotSetupHealthCronJobDisabled');
            } elseif ($hwCronLastFmt === '') {
                $healthDolibarrCronLine = $langs->trans('KnotSetupHealthCronNever');
            } elseif ($hwCronNextFmt !== '') {
                $healthDolibarrCronLine = $langs->trans(
                    'KnotSetupHealthCronLast',
                    $hwCronLastFmt,
                    $hwCronNextFmt
                );
            } else {
                $healthDolibarrCronLine = $langs->trans('KnotSetupHealthCronLastOnly', $hwCronLastFmt);
            }
            $healthLastRunFmt = '';
            if ($healthLastRun !== '') {
                $hlTs = dol_stringtotime($healthLastRun, 'tzserver');
                if ($hlTs <= 0) {
                    $healthLastRunFmt = $healthLastRun;
                } else {
                    $healthLastRunFmt = dol_print_date($hlTs, 'dayhour');
                }
            }
            $healthGapCronVsPersist =
                !$cronGlobalDisabledBool
                && $healthLastRun === ''
                && $healthWorkerCronJob !== null
                && (int) $healthWorkerCronJob->status === 1
                && $hwCronLastFmt !== '';

            // One placeholder per trans() call so overwritten translations in llx_overwrite_trans
            // cannot break sprintf() arity (multi-arg trans uses sprintf in Translate::trans).
            $healthCountersText = sprintf(
                '%s · %s · %s / %s / %s',
                $langs->trans('KnotSetupHealthQueued', (string) $healthQueued),
                $langs->trans('KnotSetupHealthRunning', (string) $healthRunning),
                $langs->trans('KnotSetupHealthSuccess', (string) $healthSuccess),
                $langs->trans('KnotSetupHealthErrors', (string) $healthError),
                $langs->trans('KnotSetupHealthTimeouts', (string) $healthTimeout)
            );

            ?>
            <div class="knot-engine__row knot-engine__row--stacked">
                <span class="knot-engine__label"><?php print dol_escape_htmltag($langs->trans('KnotSetupHealthTitle')); ?></span>
                <div class="knot-cron-url" style="align-items:center;flex-wrap:wrap;gap:8px;">
                    <span class="knot-pill <?php print $healthBadgeClass; ?>"><i class="fas fa-heartbeat"></i> <?php print dol_escape_htmltag($langs->trans($healthBadgeKey)); ?></span>
                    <span class="knot-engine__hint" style="margin:0;"><?php print dol_escape_htmltag($healthCountersText); ?></span>
                    <form method="POST" action="<?php print dol_escape_htmltag($_SERVER['PHP_SELF']); ?>" class="knot-inline-form" data-knot-health-run="1" onsubmit="var b=this.getElementsByTagName('button')[0];if(b)b.disabled=true;">
                        <input type="hidden" name="token" value="<?php print newToken(); ?>"><?php print $knotSetupAdminHiddenInline; ?>
                        <input type="hidden" name="action" value="health_worker_run">
                        <button type="submit" class="knot-btn knot-btn--ghost knot-btn--xs"><i class="fas fa-sync"></i> <?php print dol_escape_htmltag($langs->trans('KnotHealthWorkerRunNow')); ?></button>
                    </form>
                </div>
                <span class="knot-engine__hint"><?php print dol_escape_htmltag($healthDolibarrCronLine); ?></span>
                <?php if ($healthGapCronVsPersist) : ?>
                <span class="knot-engine__hint"><?php print dol_escape_htmltag($langs->trans('KnotSetupHealthCronVsPersistGap')); ?></span>
                <?php endif; ?>
                <?php if ($healthLastRun !== '') : ?>
                <span class="knot-engine__hint">
                    <?php print dol_escape_htmltag($langs->trans('KnotSetupHealthSnapshotLast', $healthLastRunFmt)); ?>
                </span>
                <?php else : ?>
                <span class="knot-engine__hint">
                    <?php print dol_escape_htmltag($langs->trans('KnotSetupHealthSnapshotExplain')); ?>
                </span>
                <?php endif; ?>
            </div>
            <?php
            // V2.3.5 — Extensions (Pro Pack, Enterprise, third-party).
            $knotDisabledRaw = (string) getDolGlobalString('KNOT_EXTENSIONS_DISABLED', '');
            $knotDisabledIds = array_values(array_filter(array_map('trim', explode(',', $knotDisabledRaw))));
            $extensions = [];
            $extLoaded = 0;
            $extIssues = 0;
            try {
                // Must use Bootstrap-wrapped validator so manifests with
                // validation=dolistore get a DolistoreValidator (same as api/connectors.php).
                $extensionRegistry = \Knot\Licensing\Bootstrap::buildExtensionRegistry($db);
                $extensions = array_values($extensionRegistry->discover());
                foreach ($extensions as $extEntry) {
                    if (($extEntry['status'] ?? null) === \Knot\Extension\ExtensionRegistry::STATUS_LOADED) {
                        $extLoaded++;
                    } else {
                        $extIssues++;
                    }
                }
            } catch (\Throwable $e) {
                // Discovery must never white-screen setup; surface empty + implicit logs.
                $extensions = [];
            }
            ?>
            <div class="knot-engine__row knot-engine__row--stacked">
                <span class="knot-engine__label"><?php print dol_escape_htmltag($langs->trans('KnotSetupExtInstalledTitle')); ?></span>
                <div class="knot-cron-url" style="align-items:center;flex-wrap:wrap;gap:8px;">
                    <?php if ($extensions === []) : ?>
                        <span class="knot-pill"><i class="fas fa-puzzle-piece"></i> <?php print dol_escape_htmltag($langs->trans('KnotSetupExtNoneDetected')); ?></span>
                        <span class="knot-engine__hint" style="margin:0;">
                            <?php print $langs->trans('KnotSetupExtNoneHint'); ?>
                        </span>
                    <?php else : ?>
                        <span class="knot-pill knot-pill--ok"><i class="fas fa-puzzle-piece"></i> <?php print dol_escape_htmltag($langs->trans('KnotSetupExtLoadedCount', (string) $extLoaded)); ?></span>
                        <?php if ($extIssues > 0) : ?>
                            <span class="knot-pill knot-pill--warn"><i class="fas fa-exclamation-triangle"></i> <?php print dol_escape_htmltag($langs->trans('KnotSetupExtIssuesCount', (string) $extIssues)); ?></span>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                <?php if ($extensions !== []) : ?>
                <table class="knot-table" style="width:100%;margin-top:8px;border-collapse:collapse;">
                    <thead>
                    <tr>
                        <th style="text-align:left;padding:4px 6px;"><?php print dol_escape_htmltag($langs->trans('KnotSetupExtColId')); ?></th>
                        <th style="text-align:left;padding:4px 6px;"><?php print dol_escape_htmltag($langs->trans('KnotSetupExtColLabel')); ?></th>
                        <th style="text-align:left;padding:4px 6px;"><?php print dol_escape_htmltag($langs->trans('KnotSetupExtColVersion')); ?></th>
                        <th style="text-align:left;padding:4px 6px;"><?php print dol_escape_htmltag($langs->trans('KnotSetupExtColCategory')); ?></th>
                        <th style="text-align:left;padding:4px 6px;"><?php print dol_escape_htmltag($langs->trans('KnotSetupExtColStatus')); ?></th>
                        <th style="text-align:left;padding:4px 6px;"><?php print dol_escape_htmltag($langs->trans('KnotSetupExtColLicense')); ?></th>
                        <th style="text-align:left;padding:4px 6px;"><?php print dol_escape_htmltag($langs->trans('KnotLicenseCacheColumn')); ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($extensions as $extEntry) : ?>
                        <?php
                        $statusKey = (string) ($extEntry['status'] ?? '?');
                        $statusClass = match ($statusKey) {
                            \Knot\Extension\ExtensionRegistry::STATUS_LOADED => 'knot-pill--ok',
                            \Knot\Extension\ExtensionRegistry::STATUS_DISABLED => '',
                            default => 'knot-pill--warn',
                        };
                        $licStatus = (string) ($extEntry['licenseInfo']['status'] ?? '');
                        $licClass = match ($licStatus) {
                            \Knot\Extension\LicenseValidator::STATUS_VALID => 'knot-pill--ok',
                            \Knot\Extension\LicenseValidator::STATUS_NOT_REQUIRED => '',
                            default => 'knot-pill--warn',
                        };
                        $extId = (string) ($extEntry['id'] ?? '?');
                        $isDisabled = in_array($extId, $knotDisabledIds, true);
                        $licValidation = '';
    if (isset($extEntry['license']) && is_array($extEntry['license'])) {
        $licValidation = (string) ($extEntry['license']['validation'] ?? '');
    }
                        $showLicenseCache = $licValidation === 'dolistore'
                            && preg_match('/^[a-z0-9-]{2,64}$/', $extId);
    ?>
                        <tr style="border-top:1px solid #eaeaea;">
                            <td style="padding:6px;font-family:monospace;font-size:12px;"><?php print dol_escape_htmltag($extId); ?></td>
                            <td style="padding:6px;"><?php print dol_escape_htmltag((string) ($extEntry['label'] ?? '')); ?></td>
                            <td style="padding:6px;"><?php print dol_escape_htmltag((string) ($extEntry['version'] ?? '')); ?></td>
                            <td style="padding:6px;"><span class="knot-pill"><?php print dol_escape_htmltag((string) ($extEntry['category'] ?? '')); ?></span></td>
                            <td style="padding:6px;">
                                <span class="knot-pill <?php print $statusClass; ?>"><?php print dol_escape_htmltag($statusKey); ?></span>
                                <?php if (!empty($extEntry['error'])) : ?>
                                    <div class="knot-engine__hint" style="margin-top:4px;font-size:11px;"><?php print dol_escape_htmltag((string) $extEntry['error']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td style="padding:6px;">
                                <span class="knot-pill <?php print $licClass; ?>"><?php print dol_escape_htmltag($licStatus); ?></span>
                                <?php if (!empty($extEntry['licenseInfo']['expiresAt'])) : ?>
                                    <div class="knot-engine__hint" style="margin-top:4px;font-size:11px;"><?php print dol_escape_htmltag($langs->trans('KnotSetupExtLicenseExpires', substr((string) $extEntry['licenseInfo']['expiresAt'], 0, 10))); ?></div>
                                <?php endif; ?>
                                <?php if ($licStatus === \Knot\Extension\LicenseValidator::STATUS_TAMPERED) : ?>
                                    <div class="knot-engine__hint" style="margin-top:4px;font-size:11px;"><?php print dol_escape_htmltag($langs->trans('KnotLicenseManifestOutdated')); ?></div>
                                    <?php if (!empty($extEntry['licenseInfo']['error'])) : ?>
                                        <div class="knot-engine__hint" style="margin-top:2px;font-size:10px;"><?php print dol_escape_htmltag((string) $extEntry['licenseInfo']['error']); ?></div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td style="padding:6px;vertical-align:top;">
                                <?php if ($showLicenseCache) : ?>
                                    <form method="POST" action="<?php print dol_escape_htmltag($_SERVER['PHP_SELF']); ?>" class="knot-inline-form" onsubmit="return confirm('<?php print dol_escape_js($langs->trans('KnotLicenseCacheConfirm', $extId)); ?>');">
                                        <input type="hidden" name="token" value="<?php print newToken(); ?>"><?php print $knotSetupAdminHiddenInline; ?>
                                        <input type="hidden" name="action" value="license_cache_invalidate">
                                        <input type="hidden" name="extension_id" value="<?php print dol_escape_htmltag($extId); ?>">
                                        <button type="submit" class="knot-btn knot-btn--ghost knot-btn--xs"><i class="fas fa-redo"></i> <?php print dol_escape_htmltag($langs->trans('KnotLicenseCacheInvalidate')); ?></button>
                                    </form>
                                    <div class="knot-engine__hint" style="margin-top:4px;font-size:11px;"><?php print dol_escape_htmltag($langs->trans('KnotLicenseCacheHint')); ?></div>
                                <?php else : ?>
                                    <span class="knot-engine__hint"><?php print dol_escape_htmltag($langs->trans('KnotSetupExtDash')); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <span class="knot-engine__hint">
                    <?php print $langs->trans('KnotSetupExtDisableHint'); ?>
                </span>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <?php
    $marketplacePreviewLocked = getDolGlobalString('KNOT_MARKETPLACE_PREVIEW_LOCKED', '1') !== '0';
    $marketplaceUiEnabledSetup = getDolGlobalString('KNOT_MARKETPLACE_UI_ENABLED', '1') !== '0';
    ?>
    <section class="knot-card knot-card--engine">
        <div class="knot-engine__head">
            <div class="knot-engine__icon"><i class="fas fa-store"></i></div>
            <div>
                <h2 class="knot-engine__title"><?php print dol_escape_htmltag($langs->trans('KnotMarketplaceSectionTitle')); ?></h2>
                <p class="knot-engine__desc"><?php print dol_escape_htmltag($langs->trans('KnotMarketplaceSectionExplanation')); ?></p>
            </div>
        </div>
        <div class="knot-engine__rows">
            <div class="knot-engine__row">
                <span class="knot-engine__label"><?php print dol_escape_htmltag($langs->trans('KnotMarketplacePreviewMode')); ?></span>
                <?php if ($marketplacePreviewLocked) : ?>
                    <span class="knot-pill knot-pill--ok"><i class="fas fa-eye"></i> <?php print dol_escape_htmltag($langs->trans('KnotMarketplacePreviewLockedOn')); ?></span>
                    <form method="POST" action="<?php print dol_escape_htmltag($_SERVER['PHP_SELF']); ?>" class="knot-inline-form">
                        <input type="hidden" name="token" value="<?php print newToken(); ?>"><?php print $knotSetupAdminHiddenInline; ?>
                        <input type="hidden" name="action" value="marketplace_preview_off">
                        <button type="submit" class="knot-btn knot-btn--ghost knot-btn--xs"><i class="fas fa-lock"></i> <?php print dol_escape_htmltag($langs->trans('KnotMarketplacePreviewSwitchToStrict')); ?></button>
                    </form>
                <?php else : ?>
                    <span class="knot-pill knot-pill--warn"><i class="fas fa-lock"></i> <?php print dol_escape_htmltag($langs->trans('KnotMarketplacePreviewLockedOff')); ?></span>
                    <form method="POST" action="<?php print dol_escape_htmltag($_SERVER['PHP_SELF']); ?>" class="knot-inline-form">
                        <input type="hidden" name="token" value="<?php print newToken(); ?>"><?php print $knotSetupAdminHiddenInline; ?>
                        <input type="hidden" name="action" value="marketplace_preview_on">
                        <button type="submit" class="knot-btn knot-btn--primary knot-btn--xs"><i class="fas fa-eye"></i> <?php print dol_escape_htmltag($langs->trans('KnotMarketplacePreviewSwitchToShowcase')); ?></button>
                    </form>
                <?php endif; ?>
            </div>
            <span class="knot-engine__hint">
                <?php print dol_escape_htmltag($langs->trans('KnotMarketplacePreviewHint')); ?>
            </span>
            <div class="knot-engine__row" style="margin-top:14px;">
                <span class="knot-engine__label"><?php print dol_escape_htmltag($langs->trans('KnotMarketplaceUiChrome')); ?></span>
                <?php if ($marketplaceUiEnabledSetup) : ?>
                    <span class="knot-pill knot-pill--ok"><i class="fas fa-desktop"></i> <?php print dol_escape_htmltag($langs->trans('KnotMarketplaceUiChromeOn')); ?></span>
                    <form method="POST" action="<?php print dol_escape_htmltag($_SERVER['PHP_SELF']); ?>" class="knot-inline-form">
                        <input type="hidden" name="token" value="<?php print newToken(); ?>"><?php print $knotSetupAdminHiddenInline; ?>
                        <input type="hidden" name="action" value="marketplace_ui_off">
                        <button type="submit" class="knot-btn knot-btn--ghost knot-btn--xs"><i class="fas fa-eye-slash"></i> <?php print dol_escape_htmltag($langs->trans('KnotMarketplaceUiChromeSwitchOff')); ?></button>
                    </form>
                <?php else : ?>
                    <span class="knot-pill knot-pill--warn"><i class="fas fa-ban"></i> <?php print dol_escape_htmltag($langs->trans('KnotMarketplaceUiChromeOff')); ?></span>
                    <form method="POST" action="<?php print dol_escape_htmltag($_SERVER['PHP_SELF']); ?>" class="knot-inline-form">
                        <input type="hidden" name="token" value="<?php print newToken(); ?>"><?php print $knotSetupAdminHiddenInline; ?>
                        <input type="hidden" name="action" value="marketplace_ui_on">
                        <button type="submit" class="knot-btn knot-btn--primary knot-btn--xs"><i class="fas fa-desktop"></i> <?php print dol_escape_htmltag($langs->trans('KnotMarketplaceUiChromeSwitchOn')); ?></button>
                    </form>
                <?php endif; ?>
            </div>
            <span class="knot-engine__hint">
                <?php print dol_escape_htmltag($langs->trans('KnotMarketplaceUiChromeHint')); ?>
            </span>
        </div>
    </section>

    <section class="knot-card knot-card--feature" id="knot-updates-check">
        <div class="knot-card__header">
            <h2 class="knot-card__title"><i class="fas fa-cloud-download-alt"></i> <?php print dol_escape_htmltag($langs->trans('KnotSetupUpdatesTitle')); ?></h2>
        </div>
        <p class="knot-engine__hint"><?php print dol_escape_htmltag($langs->trans('KnotSetupUpdatesHint')); ?></p>
        <div class="knot-cta__actions" style="margin-top:12px;">
            <a class="knot-btn knot-btn--primary" href="<?php print dol_escape_htmltag($previewBase . '?mode=updates&check=1'); ?>">
                <i class="fas fa-sync-alt"></i>
                <?php print dol_escape_htmltag($langs->trans('KnotSetupUpdatesCheckBtn')); ?>
            </a>
            <a class="knot-btn knot-btn--ghost" href="<?php print dol_escape_htmltag($previewBase . '?mode=updates'); ?>">
                <i class="fas fa-list"></i>
                <?php print dol_escape_htmltag($langs->trans('KnotSetupUpdatesManageLink')); ?>
            </a>
        </div>
    </section>

    <section class="knot-card knot-card--cta">
        <?php if ($setupCompleted) : ?>
            <?php if ($engineEnabled) : ?>
            <div class="knot-cta__state knot-cta__state--success">
                <div class="knot-cta__icon"><i class="fas fa-check-circle"></i></div>
                <div>
                    <h2 class="knot-cta__title"><?php print dol_escape_htmltag($langs->trans('KnotCtaDoneTitle')); ?></h2>
                    <p class="knot-cta__desc"><?php print dol_escape_htmltag($langs->trans('KnotCtaDoneDesc')); ?></p>
                </div>
            </div>
            <?php else : ?>
            <div class="knot-cta__state knot-cta__state--warn">
                <div class="knot-cta__icon"><i class="fas fa-pause-circle"></i></div>
                <div>
                    <h2 class="knot-cta__title"><?php print dol_escape_htmltag($langs->trans('KnotCtaSetupCompleteEnginePausedTitle')); ?></h2>
                    <p class="knot-cta__desc"><?php print dol_escape_htmltag($langs->trans('KnotCtaSetupCompleteEnginePausedDesc')); ?></p>
                </div>
            </div>
            <?php endif; ?>
            <div class="knot-cta__actions">
                <?php if (!$engineEnabled) : ?>
                <a class="knot-btn knot-btn--primary" href="#knot-engine-card">
                    <i class="fas fa-sliders-h"></i>
                    <?php print dol_escape_htmltag($langs->trans('KnotCtaEngineControlsLink')); ?>
                </a>
                <?php endif; ?>
                <a class="knot-btn knot-btn--<?php print $engineEnabled ? 'primary' : 'ghost'; ?>" href="<?php print dol_escape_htmltag($previewBase . '?mode=dashboard'); ?>">
                    <i class="fas fa-tachometer-alt"></i>
                    <?php print dol_escape_htmltag($langs->trans('KnotCtaOpenDashboard')); ?>
                </a>
                <a class="knot-btn knot-btn--ghost" href="<?php print dol_escape_htmltag($previewBase . '?mode=workflows'); ?>">
                    <i class="fas fa-list"></i>
                    <?php print dol_escape_htmltag($langs->trans('KnotCtaOpenWorkflows')); ?>
                </a>
                <form method="POST" action="<?php print dol_escape_htmltag($_SERVER['PHP_SELF']); ?>" class="knot-inline-form">
                    <input type="hidden" name="token" value="<?php print newToken(); ?>"><?php print $knotSetupAdminHiddenInline; ?>
                    <input type="hidden" name="action" value="reset">
                    <button class="knot-btn knot-btn--ghost" type="submit">
                        <i class="fas fa-undo"></i>
                        <?php print dol_escape_htmltag($langs->trans('KnotCtaReset')); ?>
                    </button>
                </form>
            </div>
        <?php else : ?>
            <div class="knot-cta__state">
                <div class="knot-cta__icon knot-cta__icon--pulse"><i class="fas fa-rocket"></i></div>
                <div>
                    <h2 class="knot-cta__title"><?php print dol_escape_htmltag($langs->trans('KnotCtaTitle')); ?></h2>
                    <p class="knot-cta__desc">
                        <?php
                        if ($allOk) {
                            print dol_escape_htmltag($langs->trans('KnotCtaReady'));
                        } else {
                            print dol_escape_htmltag($langs->trans('KnotCtaPending'));
                        }
                        ?>
                    </p>
                    <p class="knot-cta__hint"><?php print dol_escape_htmltag($langs->trans('KnotCtaHint')); ?></p>
                </div>
            </div>

            <div class="knot-cta__cron">
                <div class="knot-cta__cron-head">
                    <i class="fas fa-clock"></i>
                    <strong><?php print dol_escape_htmltag($langs->trans('KnotSetupCtaCronTitle')); ?></strong>
                </div>
                <p class="knot-cta__cron-text">
                    <?php print $langs->trans('KnotSetupCtaCronBody'); ?>
                </p>
                <div class="knot-cron-url">
                    <input id="knot-cron-url-cta" type="text" readonly value="<?php print dol_escape_htmltag($cronWebUrl); ?>" class="knot-cron-url__input" onclick="this.select();">
                    <button type="button" class="knot-btn knot-btn--ghost knot-btn--xs" onclick="<?php print $knotSetupOnclickCronCopyCta; ?>">
                        <?php print $kCopyIdleSnippet; ?>
                    </button>
                </div>
            </div>

            <div class="knot-cta__actions">
                <form method="POST" action="<?php print dol_escape_htmltag($_SERVER['PHP_SELF']); ?>" class="knot-inline-form">
                    <input type="hidden" name="token" value="<?php print newToken(); ?>"><?php print $knotSetupAdminHiddenInline; ?>
                    <input type="hidden" name="action" value="complete">
                    <button class="knot-btn knot-btn--primary" type="submit"<?php print $allOk ? '' : ' disabled'; ?>>
                        <i class="fas fa-check"></i>
                        <?php print dol_escape_htmltag($langs->trans('KnotCtaConfirm')); ?>
                    </button>
                </form>
            </div>
        <?php endif; ?>
    </section>

    <footer class="knot-footer">
        <span class="knot-footer__brand"><?php print dol_escape_htmltag($langs->trans('KnotBrandName')); ?></span>
        <span class="knot-footer__sep">·</span>
        <span class="knot-footer__version">v<?php print dol_escape_htmltag(\Knot\Version::current()); ?></span>
        <span class="knot-footer__sep">·</span>
        <span class="knot-footer__sig"><?php print dol_escape_htmltag($langs->trans('KnotFooterTagline')); ?></span>
    </footer>
</div>
<?php

llxFooter();
$db->close();
