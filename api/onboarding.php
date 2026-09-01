<?php
/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

if (!defined('NOCSRFCHECK')) { define('NOCSRFCHECK', '1'); }
if (!defined('NOTOKENRENEWAL')) { define('NOTOKENRENEWAL', '1'); }

require_once __DIR__ . '/../lib/load_dolibarr.inc.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
dol_include_once('/knot/class/autoload.php');

use Knot\Api\CsrfGuard;
use Knot\Api\JsonResponse;
use Knot\Repository\AuditLogRepository;

JsonResponse::installFatalHandler();

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$entity = (int) $conf->entity;

if ($method === 'GET') {
    if (!$user->hasRight('knot', 'workflow', 'read')) {
        JsonResponse::error('permission_denied', 'Permission denied', 403);
        exit;
    }

    $canSeeCronSecret = ((int) $user->admin) > 0 || $user->hasRight('knot', 'admin', 'configure');

    // V2.5.0b-ux-ops (plan chantier 7.A) — wizard precondition
    // snapshot for the onboarding overlay. We only return the booleans
    // the UI strictly needs to render, never raw constants.
    $cronGlobal = getDolGlobalString('CRON_DISABLE_JOBS');
    $cronGlobalEnabled = $cronGlobal !== '1' && $cronGlobal !== 'on';

    $knotJobsRegistered = 0;
    $knotJobsActive = 0;
    $cronLastRunMaxTs = 0;
    $resCron = $db->query(
        "SELECT status, datelastrun FROM " . MAIN_DB_PREFIX . "cronjob "
        . "WHERE module_name = 'knot' ORDER BY rowid ASC"
    );
    if ($resCron) {
        while ($row = $db->fetch_object($resCron)) {
            $knotJobsRegistered++;
            if ((int) $row->status === 1) {
                $knotJobsActive++;
            }
            $dlr = $row->datelastrun ?? null;
            if ($dlr !== null && $dlr !== '') {
                $ts = is_numeric($dlr)
                    ? (int) $dlr
                    : (int) dol_stringtotime((string) $dlr, 'tzserver');
                if ($ts > $cronLastRunMaxTs) {
                    $cronLastRunMaxTs = $ts;
                }
            }
        }
    }
    $cronJobsEnabled = $knotJobsRegistered > 0
        && $knotJobsActive === $knotJobsRegistered
        && $cronGlobalEnabled;
    $cronStaleThresholdSeconds = 15 * 60;
    $cronRecentRun = $cronJobsEnabled && $cronLastRunMaxTs > 0
        && (time() - $cronLastRunMaxTs) <= $cronStaleThresholdSeconds;
    $cronOk = $cronRecentRun;

    $rightsCount = 0;
    $resR = $db->query("SELECT COUNT(*) AS nb FROM " . MAIN_DB_PREFIX . "rights_def WHERE module = 'knot'");
    if ($resR && $obj = $db->fetch_object($resR)) {
        $rightsCount = (int) $obj->nb;
    }

    global $dolibarr_main_instance_unique_id;
    $encryptionOk = !empty($dolibarr_main_instance_unique_id);
    $encryptionFingerprint = $encryptionOk
        ? substr(hash('sha256', (string) $dolibarr_main_instance_unique_id), 0, 12)
        : null;

    // Detect SMTP availability via Dolibarr's standard MAIN_MAIL_* set.
    $smtpHost = getDolGlobalString('MAIN_MAIL_SMTP_SERVER');
    $smtpPort = getDolGlobalString('MAIN_MAIL_SMTP_PORT');
    $smtpReady = $smtpHost !== '' && $smtpPort !== '';

    $starterPath = DOL_DOCUMENT_ROOT . '/custom/knot/examples/starter';
    $starterAvailable = is_dir($starterPath);
    $starterCount = 0;
    if ($starterAvailable) {
        $glob = @glob($starterPath . '/*.knot.json');
        $starterCount = is_array($glob) ? count($glob) : 0;
    }

    $dolibarrCronModuleEnabled = getDolGlobalString('MAIN_MODULE_CRON') === '1';
    $phpExtRequired = ['curl', 'dom', 'json', 'mbstring', 'openssl', 'simplexml'];
    $phpExtMissing = [];
    foreach ($phpExtRequired as $ext) {
        if (!extension_loaded($ext)) {
            $phpExtMissing[] = $ext;
        }
    }
    $phpExtensionsOk = $phpExtMissing === [];
    $cronKeyConfigured = null;
    if ($canSeeCronSecret) {
        $cronKeyConfigured = getDolGlobalString('CRON_KEY') !== '';
    }

    $cronWebUrl = null;
    $cronUrlUserLogin = null;
    if ($canSeeCronSecret) {
        $cronKey = (string) getDolGlobalString('CRON_KEY');
        $cronUserLogin = (string) getDolGlobalString('CRON_DOLIBARRUSER');
        if ($cronUserLogin === '') {
            $resAdmin = $db->query(
                'SELECT login FROM ' . MAIN_DB_PREFIX . 'user WHERE admin = 1 AND statut = 1 ORDER BY rowid ASC LIMIT 1'
            );
            if ($resAdmin && $rowAdmin = $db->fetch_object($resAdmin)) {
                $cronUserLogin = (string) $rowAdmin->login;
            }
        }
        if ($cronUserLogin === '') {
            $cronUserLogin = (string) $user->login;
        }
        $cronUrlUserLogin = $cronUserLogin;
        if ($cronKey !== '') {
            $cronUrlScript = is_readable(DOL_DOCUMENT_ROOT . '/public/cron/cron_run_jobs_by_url.php')
                ? '/public/cron/cron_run_jobs_by_url.php'
                : '/public/cron/cron_run_jobs.php';
            $cronWebUrl = rtrim(DOL_MAIN_URL_ROOT, '/') . $cronUrlScript
                . '?securitykey=' . rawurlencode($cronKey)
                . '&userlogin=' . rawurlencode($cronUserLogin);
        }
    }

    JsonResponse::success([
        'completed' => getDolGlobalString('KNOT_FIRSTRUN_COMPLETED') === '1',
        'isAdmin' => ((int) $user->admin) > 0 || $user->hasRight('knot', 'admin', 'configure'),
        'cron' => [
            'ok' => $cronOk,
            'globalEnabled' => $cronGlobalEnabled,
            'jobsEnabled' => $cronJobsEnabled,
            'recentRun' => $cronRecentRun,
            'knotJobsRegistered' => $knotJobsRegistered,
            'knotJobsActive' => $knotJobsActive,
            'webUrl' => $cronWebUrl,
            'userLogin' => $cronUrlUserLogin,
        ],
        'prerequisites' => [
            'dolibarrCronModule' => $dolibarrCronModuleEnabled,
            'phpExtensionsOk' => $phpExtensionsOk,
            'phpExtensionsMissing' => $phpExtMissing,
            'cronKeyConfigured' => $cronKeyConfigured,
        ],
        'rights' => [
            'count' => $rightsCount,
            'expected' => 5,
        ],
        'encryption' => [
            'ok' => $encryptionOk,
            'fingerprint' => $encryptionFingerprint,
        ],
        'smtp' => [
            'configured' => $smtpReady,
            'host' => $smtpHost ?: null,
        ],
        'starter' => [
            'available' => $starterAvailable,
            'count' => $starterCount,
        ],
    ]);
    exit;
}

if ($method !== 'POST') {
    JsonResponse::error('method_not_allowed', 'Method not allowed', 405);
    exit;
}

$canRunOnboarding = ((int) $user->admin) > 0 || $user->hasRight('knot', 'admin', 'configure');
if (!$canRunOnboarding) {
    JsonResponse::error('permission_denied', 'Admin or Knot configure permission required', 403);
    exit;
}

if (!CsrfGuard::verify()) {
    JsonResponse::error('csrf_invalid', 'Invalid CSRF token', 403);
    exit;
}

$action = (string) GETPOST('action', 'aZ09');
$audit = new AuditLogRepository($db);

if ($action === 'complete') {
    dolibarr_set_const($db, 'KNOT_FIRSTRUN_COMPLETED', '1', 'chaine', 0, '', $entity);
    $audit->record('onboarding.completed', 'config', null, (int) $user->id, [
        'via' => 'wizard',
    ], $entity);
    JsonResponse::success(['completed' => true]);
    exit;
}

if ($action === 'reset') {
    dolibarr_set_const($db, 'KNOT_FIRSTRUN_COMPLETED', '0', 'chaine', 0, '', $entity);
    $audit->record('onboarding.reset', 'config', null, (int) $user->id, [], $entity);
    JsonResponse::success(['completed' => false]);
    exit;
}

if ($action === 'enable_knot_cron') {
    $sql = 'UPDATE ' . MAIN_DB_PREFIX . "cronjob SET status = 1 WHERE module_name = 'knot' AND status = 0";
    if (!$db->query($sql)) {
        JsonResponse::error('database_error', 'Failed to enable Knot cron jobs', 500);
        exit;
    }
    $audit->record('onboarding.enable_knot_cron', 'config', null, (int) $user->id, [], $entity);
    JsonResponse::success(['enabled' => true]);
    exit;
}

if ($action === 'import_starters') {
    $starterPath = DOL_DOCUMENT_ROOT . '/custom/knot/examples/starter';
    if (!is_dir($starterPath)) {
        JsonResponse::error('not_found', 'Starter templates directory missing', 404);
        exit;
    }
    $rawBody = (string) file_get_contents('php://input');
    $payload = json_decode($rawBody, true);
    $selected = is_array($payload['selected'] ?? null) ? $payload['selected'] : null;

    $repo = new \Knot\Repository\WorkflowRepository($db);
    $imported = [];
    $skipped = [];
    $files = (array) glob($starterPath . '/*.knot.json');
    sort($files);
    foreach ($files as $file) {
        $basename = basename((string) $file);
        if ($selected !== null && !in_array($basename, $selected, true)) {
            continue;
        }
        $raw = (string) @file_get_contents((string) $file);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !is_array($decoded['workflow'] ?? null)) {
            $skipped[] = $basename;
            continue;
        }
        $wf = $decoded['workflow'];
        $newId = $repo->create([
            'label' => (string) ($wf['label'] ?? $basename),
            'description' => (string) ($wf['description'] ?? ''),
            'status' => 'draft',
            'definition' => is_array($wf['definition'] ?? null) ? $wf['definition'] : [],
        ], $entity, (int) $user->id);
        if ($newId > 0) {
            $imported[] = ['id' => $newId, 'file' => $basename, 'label' => (string) ($wf['label'] ?? $basename)];
            $audit->record('workflow.import_starter', 'workflow', $newId, (int) $user->id, [
                'file' => $basename,
            ], $entity);
        } else {
            $skipped[] = $basename;
        }
    }
    JsonResponse::success([
        'imported' => $imported,
        'skipped' => $skipped,
    ], 201);
    exit;
}

JsonResponse::error('invalid_action', 'Unknown action', 400);
