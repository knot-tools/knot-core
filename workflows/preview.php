<?php
/**
 * Knot — Vue editor host page.
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 *
 * Boots the compiled Vue bundle on `<div id="knot-app">`. Used for the
 * editor, the workflows list, the executions list and the dashboard
 * (the active view is selected via `?mode=`).
 */

declare(strict_types=1);

// Knot owns its sidebar (rendered by tpl/knot-leftnav.tpl.php below).
// We still hint Dolibarr to highlight the Knot tab in the top bar.
if (!isset($_GET['mainmenu'])) { $_GET['mainmenu'] = 'knot'; }

require_once __DIR__ . '/../lib/load_dolibarr.inc.php';
dol_include_once('/knot/class/autoload.php');

use Knot\Marketplace\KnotMarketplacePresentation;

if (!$user->hasRight('knot', 'workflow', 'read')) {
    accessforbidden();
}

$langs->loadLangs(['knot@knot']);

$marketplaceUiEnabled = KnotMarketplacePresentation::marketplaceUiEnabled();

$marketplaceDependentModes = ['marketplace', 'templates'];
// ADR-20 slice 5+: legacy "pro-pack-migration" redirects to the Pro Pack hub.
$allowedModesFull = ['home', 'suite-health', 'editor', 'workflows', 'executions', 'queue', 'execution', 'dashboard', 'observability', 'connectors', 'credentials', 'inbox', 'assistant', 'book', 'diff', 'doctor', 'compatibility', 'capabilities', 'templates', 'variables', 'audit', 'updates', 'marketplace'];
$allowedModes = $marketplaceUiEnabled
    ? $allowedModesFull
    : array_values(array_diff($allowedModesFull, $marketplaceDependentModes));

// ADR-20 slice 3: discover active UI-bearing extensions so we can
// (a) extend $allowedModes with their `ui.menu.mode`, (b) inject
// their bundle JS/CSS at the bottom of the page, and (c) ship the
// `window.KNOT_EXTENSIONS` payload consumed by `frontend/src/lib/
// knotCore.ts`. Discovery is best-effort: any failure logs and
// degrades to Core-only behaviour.
$knotExtensionsPayload = [];
$knotExtensionAssets = [];
$knotExtensionModes = [];
try {
    if (class_exists(\Knot\Licensing\Bootstrap::class)) {
        $extRegistry = \Knot\Licensing\Bootstrap::buildExtensionRegistry($db);
        foreach ($extRegistry->active() as $extId => $extension) {
            $ui = $extension['ui'] ?? null;
            if (!is_array($ui)) {
                continue;
            }
            $extMode = (string) ($ui['menu']['mode'] ?? '');
            if ($extMode === '') {
                continue;
            }
            // Native mode always wins on collision. Extension-only
            // modes (migration, pro-pack-migration) are registered
            // below via $knotExtensionModes; safety net for overlap.
            if (in_array($extMode, $allowedModesFull, true)) {
                error_log(sprintf(
                    '[knot extension] extension "%s" declares mode "%s" but Core already owns it; extension hidden until rename',
                    $extId,
                    $extMode
                ));
                continue;
            }
            $requiredPerm = $ui['requiredPermission'] ?? null;
            $hasPermission = true;
            if ($requiredPerm !== null) {
                $permParts = explode('.', (string) $requiredPerm);
                $module = $permParts[0] ?? '';
                $right = $permParts[1] ?? '';
                $sub = $permParts[2] ?? null;
                $hasPermission = false;
                if ($module !== '' && $right !== '' && method_exists($user, 'hasRight')) {
                    try {
                        $hasPermission = $sub === null
                            ? (bool) $user->hasRight($module, $right)
                            : (bool) $user->hasRight($module, $right, $sub);
                    } catch (\Throwable $e) {
                        $hasPermission = false;
                    }
                }
            }
            $licenseStatus = (string) ($extension['licenseInfo']['status'] ?? 'unknown');
            $licenseExpiresAt = $extension['licenseInfo']['expiresAt'] ?? null;
            $extensionStatus = (string) ($extension['status'] ?? 'loaded');
            $extPath = (string) ($extension['path'] ?? '');
            $extFolder = $extPath !== '' ? basename($extPath) : $extId;
            $bundleJs = (string) ($ui['bundle']['js'] ?? '');
            $bundleCss = isset($ui['bundle']['css']) && $ui['bundle']['css'] !== null
                ? (string) $ui['bundle']['css']
                : null;
            $extRoot = $extPath !== ''
                ? rtrim($extPath, DIRECTORY_SEPARATOR)
                : DOL_DOCUMENT_ROOT . '/custom/' . $extFolder;
            $jsRelative = ltrim($bundleJs, '/');
            $jsDiskPath = $extRoot . '/' . $jsRelative;
            $jsVer = file_exists($jsDiskPath)
                ? rawurlencode((string) filemtime($jsDiskPath))
                : rawurlencode((string) ($extension['version'] ?? '0'));
            $jsUrl = DOL_URL_ROOT . '/custom/' . $extFolder . '/' . $jsRelative . '?v=' . $jsVer;
            $cssUrl = null;
            if ($bundleCss !== null) {
                $cssRelative = ltrim($bundleCss, '/');
                $cssDiskPath = $extRoot . '/' . $cssRelative;
                $cssVer = file_exists($cssDiskPath)
                    ? rawurlencode((string) filemtime($cssDiskPath))
                    : rawurlencode((string) ($extension['version'] ?? '0'));
                $cssUrl = DOL_URL_ROOT . '/custom/' . $extFolder . '/' . $cssRelative . '?v=' . $cssVer;
            }
            // Key names below mirror the ADR-20 §4.2 contract
            // (`KnotExtensionContext`): `requiresPermission`,
            // `userHasPermission`, `licenseExpiresAt`, `status`.
            // The legacy `requiredPermission` / `hasPermission` keys
            // are still emitted for one release cycle to preserve
            // backward compatibility with bundles built before the
            // rename; remove them in a follow-up once all in-tree
            // consumers (Knot Core + Pro Pack + Migration) read the
            // canonical names.
            $navigationPayload = null;
            $navigationRaw = $ui['navigation'] ?? null;
            if (is_array($navigationRaw) && $navigationRaw !== []) {
                $navigationPayload = $navigationRaw;
            }
            $knotExtensionsPayload[] = [
                'id' => $extId,
                'label' => (string) ($extension['label'] ?? $extId),
                'version' => (string) ($extension['version'] ?? '0.0.0'),
                'mode' => $extMode,
                'bundleJs' => $jsUrl,
                'bundleCss' => $cssUrl,
                'globalEntry' => (string) ($ui['bundle']['globalEntry'] ?? ''),
                'status' => $extensionStatus,
                'requiresPermission' => $requiredPerm,
                'requiredPermission' => $requiredPerm,
                'userHasPermission' => $hasPermission,
                'hasPermission' => $hasPermission,
                'onboarding' => [
                    'adminSetupRequired' => (bool) ($ui['onboarding']['adminSetupRequired'] ?? false),
                    'adminSetupUrl' => $ui['onboarding']['adminSetupUrl'] ?? null,
                    'ctaIfPermissionMissingForAdmin' => $ui['onboarding']['ctaIfPermissionMissingForAdmin'] ?? null,
                ],
                'licenseStatus' => $licenseStatus,
                'licenseExpiresAt' => is_string($licenseExpiresAt) ? $licenseExpiresAt : null,
                'isAdmin' => ((int) ($user->admin ?? 0)) > 0,
                // ADR unified sidebar option B — forward ui.navigation for Core leftnav.
                'navigation' => $navigationPayload,
            ];
            $knotExtensionAssets[] = ['js' => $jsUrl, 'css' => $cssUrl];
            $knotExtensionModes[] = $extMode;
        }
    }
} catch (\Throwable $e) {
    error_log('[knot extension] preview.php discovery failed: ' . $e->getMessage());
}

if ($knotExtensionModes !== []) {
    $allowedModes = array_values(array_unique(array_merge($allowedModes, $knotExtensionModes)));
}

$rawTab = strtolower((string) GETPOST('tab', 'aZ09'));
$rawMode = (string) GETPOST('mode', 'alphanohtml');
if ($rawMode === 'pro-pack-migration') {
    header(
        'Location: ' . dol_buildpath('/knot/workflows/preview.php', 1) . '?mode=pro-pack&tab=connectors',
        true,
        302
    );
    exit;
}
if ($rawMode === 'marketplace' && $rawTab === 'migration') {
    $proPackHubAvailable = in_array('pro-pack', $knotExtensionModes, true);
    if ($proPackHubAvailable) {
        header(
            'Location: ' . dol_buildpath('/knot/workflows/preview.php', 1) . '?mode=pro-pack&tab=connectors',
            true,
            302
        );
    } else {
        header(
            'Location: ' . dol_buildpath('/knot/workflows/preview.php', 1) . '?mode=marketplace',
            true,
            302
        );
    }
    exit;
}

// Slice 3 accepts dashes in mode names to allow kebab-case ids
// declared by extensions (ManifestSchema enforces the shape).
// We still validate with a strict regex before trusting the value.
$mode = '';
if ($rawMode !== '' && preg_match('/^[a-z][a-z0-9-]{0,63}$/', $rawMode) === 1) {
    $mode = $rawMode;
}
if (!$marketplaceUiEnabled && in_array($mode, $marketplaceDependentModes, true)) {
    header('Location: ' . dol_buildpath('/knot/workflows/preview.php', 1) . '?mode=dashboard', true, 302);
    exit;
}
if ($mode !== '' && !in_array($mode, $allowedModes, true)) {
    header('Location: ' . dol_buildpath('/knot/workflows/preview.php', 1) . '?mode=dashboard', true, 302);
    exit;
}
if ($mode === '') {
    $mode = 'editor';
}

$executionTabGet = (string) GETPOST('execution_tab', 'aZ09');
$executionTabAttr = '';
if (in_array($executionTabGet, ['history', 'queue'], true)) {
    $executionTabAttr = $executionTabGet;
}
if ($mode === 'queue') {
    $executionTabAttr = 'queue';
}

$workflowId = (int) GETPOST('workflow_id', 'int');
$executionId = (int) GETPOST('execution_id', 'int');

$apiBase = dol_buildpath('/knot/api', 1);
$csrfToken = function_exists('newToken') ? newToken() : '';
$engineEnabled = getDolGlobalString('KNOT_ENGINE_ENABLED') === '1';
$setupCompleted = getDolGlobalString('KNOT_SETUP_COMPLETED') === '1';
$firstrunCompleted = getDolGlobalString('KNOT_FIRSTRUN_COMPLETED') === '1';
$setupUrl = dol_buildpath('/knot/admin/setup.php', 1) . '?admin=1';
// Dolibarr superadmin / admin users have $user->admin > 0; some installs rely on
// Knot's configure right without a global "admin" flag — allow onboarding for both.
$knotUserAdmin = ((int) $user->admin) > 0 || $user->hasRight('knot', 'admin', 'configure');

// Inject a Knot-specific favicon for the browser tab while the user navigates
// inside the module. We layer four sizes + a maskable SVG so the icon stays
// crisp on both desktop and mobile shortcuts; Dolibarr's global favicon
// remains untouched outside of Knot pages.
$knotIconBase = dol_buildpath('/knot/img/brand', 1);
$knotHead = '<link rel="icon" type="image/svg+xml" href="' . dol_escape_htmltag($knotIconBase . '/favicon.svg') . '">'
    . '<link rel="icon" type="image/png" sizes="32x32" href="' . dol_escape_htmltag($knotIconBase . '/favicon-32.png') . '">'
    . '<link rel="icon" type="image/png" sizes="64x64" href="' . dol_escape_htmltag($knotIconBase . '/favicon-64.png') . '">'
    . '<link rel="shortcut icon" href="' . dol_escape_htmltag($knotIconBase . '/favicon.ico') . '">'
    . '<link rel="apple-touch-icon" href="' . dol_escape_htmltag($knotIconBase . '/knot-logo-256.png') . '">';
if ($mode === 'marketplace') {
    $csp = implode(
        '; ',
        [
            "default-src 'self'",
            "base-uri 'self'",
            "form-action 'self'",
            "font-src 'self' data: https:",
            "img-src 'self' data: https:",
            "style-src 'self' 'unsafe-inline'",
            "script-src 'self' 'unsafe-inline'",
            "connect-src 'self' https://license.knot.tools https://cdn.knot.tools https://knot.tools https://www.knot.tools",
            'upgrade-insecure-requests',
        ],
    );
    $knotHead .= '<meta http-equiv="Content-Security-Policy" content="' . dol_escape_htmltag($csp) . '">';
}

// Cache buster for CSS: mtime of dist/knot-app.css so a redeploy invalidates
// any prior CSS even when the semver version did not change.
$knotCssPath = dol_buildpath('/knot/dist/knot-app.css', 0);
$knotCssVer = file_exists($knotCssPath) ? rawurlencode((string) filemtime($knotCssPath)) : '0';
$knotHostCssPath = dol_buildpath('/knot/css/knot-host.css', 0);
$knotHostCssVer = file_exists($knotHostCssPath) ? rawurlencode((string) filemtime($knotHostCssPath)) : '0';
$knotTokensCssPath = dol_buildpath('/knot/css/knot-tokens.css', 0);
$knotTokensCssVer = file_exists($knotTokensCssPath) ? rawurlencode((string) filemtime($knotTokensCssPath)) : '0';
$knotCssBase = dol_buildpath('/knot', 1);
// Inject Knot styles in $knotHead: Dolibarr's llxHeader CSS array appends ?lang=…
// after URLs that already use ?v=…, producing an invalid query string and stale cache keys.
$knotHead .= '<link rel="stylesheet" href="' . dol_escape_htmltag($knotCssBase . '/css/knot-tokens.css?v=' . $knotTokensCssVer) . '">';
$knotHead .= '<link rel="stylesheet" href="' . dol_escape_htmltag($knotCssBase . '/css/knot-host.css?v=' . $knotHostCssVer) . '">';
$knotHead .= '<link rel="stylesheet" href="' . dol_escape_htmltag($knotCssBase . '/dist/knot-app.css?v=' . $knotCssVer) . '">';

llxHeader(
    $knotHead,
    'Knot — ' . ucfirst($mode),
    '',
    '',
    0,
    0,
    [],
    []
);

$knotActive = ($mode === 'queue') ? 'executions' : $mode;
if ($mode === 'execution') {
    $knotActive = 'executions';
}
if ($mode === 'editor' && $workflowId > 0) {
    $knotActive = 'workflows';
}
include __DIR__ . '/../tpl/knot-leftnav.tpl.php';

?>
<style id="knot-host-layout-guard">
:root {
    --knot-mc-sidebar-w: 240px;
    --knot-nav-width: var(--knot-mc-sidebar-w);
    --knot-content-gap: 16px;
    --knot-host-margin-left: calc(var(--knot-nav-width) + var(--knot-content-gap));
}
body.knot-host-page #id-container,
body:has(.knot-nav) #id-container {
    display: block !important;
    margin-top: 0 !important;
    padding-top: 0 !important;
    margin-left: 0 !important;
    padding-left: 0 !important;
}
body.knot-host-page #id-right,
body:has(.knot-nav) #id-right {
    display: block !important;
    float: none !important;
    margin-top: 0 !important;
    padding-top: 0 !important;
    margin-left: 0 !important;
    margin-right: 0 !important;
    padding-left: var(--knot-host-margin-left) !important;
    padding-right: var(--knot-content-gap) !important;
    box-sizing: border-box;
    width: 100% !important;
    max-width: 100% !important;
}
body.knot-host-page .fiche,
body:has(.knot-nav) .fiche {
    margin: 0 !important;
    padding: 0 !important;
    box-sizing: border-box;
    width: 100% !important;
    max-width: 100% !important;
}
body.knot-host-page #knot-app,
body.knot-host-page .knot-engine-banner,
body.knot-host-page .knot-shell,
body:has(.knot-nav) #knot-app,
body:has(.knot-nav) .knot-engine-banner,
body:has(.knot-nav) .knot-shell {
    margin-left: 0 !important;
    margin-right: 0 !important;
    width: 100% !important;
    max-width: 100% !important;
    box-sizing: border-box;
}
/* Must mirror css/knot-host.css @media (max-width: 880px): this inline guard
   otherwise wins the cascade and keeps rail padding when the nav stacks. */
@media (max-width: 880px) {
    body.knot-host-page #id-right,
    body:has(.knot-nav) #id-right {
        padding-left: 0 !important;
        padding-right: 0 !important;
    }
}
</style>
<script>document.body.classList.add('knot-host-page');</script>
<script>
(function () {
    try {
        var t = localStorage.getItem('knot.theme');
        document.documentElement.setAttribute('data-knot-theme', t === 'dark' ? 'dark' : 'light');
    } catch (e) {
        document.documentElement.setAttribute('data-knot-theme', 'light');
    }
})();
window.KNOT_API_BASE = <?php print json_encode($apiBase); ?>;
window.KNOT_CSRF_TOKEN = <?php print json_encode($csrfToken); ?>;
window.DOL_URL_ROOT = <?php print json_encode(DOL_URL_ROOT); ?>;
window.KNOT_BASE_URL = <?php print json_encode(dol_buildpath('/knot/workflows/preview.php', 1)); ?>;
window.KNOT_MARKETPLACE_UI_ENABLED = <?php print $marketplaceUiEnabled ? 'true' : 'false'; ?>;
window.KNOT_ENGINE_ENABLED = <?php print $engineEnabled ? 'true' : 'false'; ?>;
window.KNOT_FIRSTRUN_COMPLETED = <?php print $firstrunCompleted ? 'true' : 'false'; ?>;
window.KNOT_USER_ADMIN = <?php print $knotUserAdmin ? 'true' : 'false'; ?>;
window.KNOT_ENTITY = <?php print (int) $conf->entity; ?>;
window.KNOT_SETUP_URL = <?php print json_encode($setupUrl); ?>;
window.KNOT_LOCALE = <?php print json_encode(($langs->defaultlang ?? 'fr_FR')); ?>;
window.KNOT_VERSION = <?php print json_encode(class_exists('Knot\\Version') ? \Knot\Version::current() : '2.0.0'); ?>;
window.KNOT_EXTENSIONS = <?php print json_encode($knotExtensionsPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
</script>
<?php foreach ($knotExtensionAssets as $knotExtAsset): ?>
    <?php if ($knotExtAsset['css'] !== null): ?>
<link rel="stylesheet" href="<?php print dol_escape_htmltag((string) $knotExtAsset['css']); ?>">
    <?php endif; ?>
<?php endforeach; ?>
<style>
.knot-engine-banner {
    display: flex; align-items: center; gap: 14px;
    padding: 10px 18px;
    background: linear-gradient(90deg, rgba(245, 158, 11, 0.12), rgba(245, 158, 11, 0.04));
    border: 1px solid rgba(245, 158, 11, 0.35);
    border-radius: 10px;
    margin: 8px 12px 0;
    color: #b45309;
    font-size: 13px;
}
.knot-engine-banner__icon { font-size: 20px; }
.knot-engine-banner__body { display: flex; flex-direction: column; gap: 2px; flex: 1; }
.knot-engine-banner__body strong { font-weight: 700; }
.knot-engine-banner__cta {
    text-decoration: none;
    background: #f59e0b;
    color: #fff;
    padding: 6px 12px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 12.5px;
}
.knot-engine-banner__cta:hover { background: #d97706; color: #fff; }
</style>
<?php if (!$engineEnabled): ?>
<div class="knot-engine-banner" role="alert">
    <div class="knot-engine-banner__icon">⏸</div>
    <div class="knot-engine-banner__body">
        <strong><?php print dol_escape_htmltag($langs->trans('KnotEnginePaused')); ?></strong>
        <span><?php print dol_escape_htmltag($langs->trans('KnotEngineDisabledBanner')); ?></span>
    </div>
    <a class="knot-engine-banner__cta" href="<?php print dol_escape_htmltag($setupUrl); ?>">
        <?php print dol_escape_htmltag($langs->trans('KnotEngineResume')); ?> →
    </a>
</div>
<?php endif; ?>
<?php
// Editor needs a fixed viewport-tall canvas; lists/dashboards grow with content.
$isEditor = ($mode === 'editor');
$reservedPx = $engineEnabled ? 160 : 220;
$appStyle = $isEditor
    ? sprintf('height: calc(100vh - %dpx); min-height: 580px;', $reservedPx)
    : sprintf('min-height: calc(100vh - %dpx);', $reservedPx);
// Viewport-fixed toasts (Teleport to body) sit below Dolibarr menu + bookmarks.
$chromeTopPx = $engineEnabled ? 88 : 104;
$appStyle .= sprintf(' --knot-dolibarr-chrome-top: %dpx;', $chromeTopPx);
?>
<div
    id="knot-app"
    data-mode="<?php print dol_escape_htmltag($mode); ?>"
    style="<?php print $appStyle; ?>"
    <?php if ($workflowId > 0): ?>data-workflow-id="<?php print (int) $workflowId; ?>"<?php endif; ?>
    <?php if ($executionId > 0): ?>data-execution-id="<?php print (int) $executionId; ?>"<?php endif; ?>
    <?php if ($executionTabAttr !== ''): ?>data-execution-tab="<?php print dol_escape_htmltag($executionTabAttr); ?>"<?php endif; ?>
></div>

<?php
// Use file mtime as cache buster so every redeploy invalidates browser cache,
// even when the semver module version did not change. Falls back to the module
// version when the file is missing on disk (defensive — should not happen).
$knotDistPath = dol_buildpath('/knot/dist/knot-app.js', 0);
$knotAssetVersion = file_exists($knotDistPath)
    ? (string) filemtime($knotDistPath)
    : (class_exists('Knot\\Version') ? \Knot\Version::current() : '2.0.0');
$knotAssetVersion = rawurlencode($knotAssetVersion);
?>
<script src="<?php print dol_escape_htmltag(dol_buildpath('/knot/js/knot-app.js', 1)); ?>?v=<?php print $knotAssetVersion; ?>" defer></script>
<script src="<?php print dol_escape_htmltag(dol_buildpath('/knot/dist/knot-app.js', 1)); ?>?v=<?php print $knotAssetVersion; ?>" defer></script>
<?php foreach ($knotExtensionAssets as $knotExtAsset): ?>
<script src="<?php print dol_escape_htmltag((string) $knotExtAsset['js']); ?>" defer></script>
<?php endforeach; ?>
<?php

llxFooter();
$db->close();
