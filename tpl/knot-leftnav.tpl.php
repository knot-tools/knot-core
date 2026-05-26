<?php
/**
 * Knot — left navigation partial.
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 *
 * Renders a vertical Knot-styled nav that replaces Dolibarr's default left
 * menu on every Knot page. Expects the host script to have already loaded
 * Dolibarr (`$langs`, `DOL_URL_ROOT`) and to optionally provide a
 * `$knotActive` string (one of: dashboard | observability | workflows | editor | executions
 * | connectors | credentials | book | inbox | assistant | doctor | setup) used to highlight the current entry.
 * `$marketplaceUiEnabled` is optional — when omitted and `KnotMarketplacePresentation` is loadable it is inferred from **`KNOT_MARKETPLACE_UI_ENABLED`**.
 */

if (!defined('DOL_DOCUMENT_ROOT')) {
    return;
}

$base = DOL_URL_ROOT . '/custom/knot';
$preview = $base . '/workflows/preview.php';
$setup = $base . '/admin/setup.php?admin=1';
$active = isset($knotActive) ? (string) $knotActive : '';
$engineEnabled = function_exists('getDolGlobalString') && getDolGlobalString('KNOT_ENGINE_ENABLED') === '1';

$items = [
    [
        'key' => 'dashboard',
        'icon' => 'fa-tachometer-alt',
        'label' => $langs->trans('KnotDashboard'),
        'url' => $preview . '?mode=dashboard',
    ],
    [
        'key' => 'marketplace',
        'icon' => 'fa-store',
        'label' => $langs->trans('KnotMarketplaceTitle'),
        'url' => $preview . '?mode=marketplace',
        // Gold sidebar surface like premium extensions; badge remains editorial-driven.
        '_premiumStyle' => true,
    ],
    [
        'key' => 'observability',
        'icon' => 'fa-chart-line',
        'label' => $langs->trans('KnotObservability'),
        'url' => $preview . '?mode=observability',
    ],
    [
        'key' => 'workflows',
        'icon' => 'fa-stream',
        'label' => $langs->trans('KnotWorkflows'),
        'url' => $preview . '?mode=workflows',
    ],
    [
        'key' => 'editor',
        'icon' => 'fa-plus-circle',
        'label' => $langs->trans('KnotNewWorkflow'),
        'url' => $preview . '?mode=editor',
    ],
    [
        'key' => 'executions',
        'icon' => 'fa-history',
        'label' => $langs->trans('KnotExecutions'),
        'url' => $preview . '?mode=executions',
    ],
    [
        'key' => 'connectors',
        'icon' => 'fa-plug',
        'label' => $langs->trans('KnotFeature2Title'),
        'url' => $preview . '?mode=connectors',
    ],
    [
        'key' => 'credentials',
        'icon' => 'fa-key',
        'label' => $langs->trans('KnotFeature3Title'),
        'url' => $preview . '?mode=credentials',
    ],
    [
        'key' => 'book',
        'icon' => 'fa-book-open',
        'label' => $langs->trans('KnotNavBook'),
        'url' => $preview . '?mode=book',
    ],
    [
        'key' => 'inbox',
        'icon' => 'fa-inbox',
        'label' => $langs->trans('KnotNavInbox'),
        'url' => $preview . '?mode=inbox',
    ],
    [
        'key' => 'assistant',
        'icon' => 'fa-robot',
        'label' => $langs->trans('KnotNavAssistant'),
        'url' => $preview . '?mode=assistant',
    ],
    [
        'key' => 'variables',
        'icon' => 'fa-code',
        'label' => $langs->trans('KnotNavVariables'),
        'url' => $preview . '?mode=variables',
    ],
    [
        'key' => 'audit',
        'icon' => 'fa-clipboard-list',
        'label' => $langs->trans('KnotNavAudit'),
        'url' => $preview . '?mode=audit',
    ],
    [
        'key' => 'updates',
        'icon' => 'fa-cloud-download-alt',
        'label' => $langs->trans('KnotNavUpdates'),
        'url' => $preview . '?mode=updates',
    ],
    [
        'key' => 'doctor',
        'icon' => 'fa-stethoscope',
        'label' => $langs->trans('KnotNavDoctor'),
        'url' => $preview . '?mode=doctor',
    ],
    [
        'key' => 'compatibility',
        'icon' => 'fa-code-branch',
        'label' => $langs->trans('KnotCompatibility'),
        'url' => $preview . '?mode=compatibility',
    ],
    [
        'key' => 'capabilities',
        'icon' => 'fa-info-circle',
        'label' => $langs->trans('KnotCapabilities'),
        'url' => $preview . '?mode=capabilities',
    ],
    [
        'key' => 'setup',
        'icon' => 'fa-cog',
        'label' => $langs->trans('KnotSetup'),
        'url' => $setup,
    ],
];

if (!isset($marketplaceUiEnabled) && class_exists(\Knot\Marketplace\KnotMarketplacePresentation::class)) {
    $marketplaceUiEnabled = \Knot\Marketplace\KnotMarketplacePresentation::marketplaceUiEnabled();
}
if (!(bool) ($marketplaceUiEnabled ?? true)) {
    $items = array_values(array_filter(
        $items,
        static fn (array $item): bool => ($item['key'] ?? '') !== 'marketplace'
    ));
}

// ADR-20: inject sidebar items contributed by Knot extensions
// (Knot Migration, future add-ons). Discovery is best-effort: any
// failure (autoload missing, registry exception, no Bootstrap) must
// never break the native nav rendering.
if (class_exists(\Knot\Extension\ExtensionRegistry::class) && class_exists(\Knot\Extension\SidebarPresentation::class)) {
    try {
        $knotExtRegistry = null;
        $knotExtDb = $db ?? null;
        if (
            $knotExtDb instanceof \DoliDB
            && class_exists(\Knot\Licensing\Bootstrap::class)
            && method_exists(\Knot\Licensing\Bootstrap::class, 'buildExtensionRegistry')
        ) {
            $knotExtRegistry = \Knot\Licensing\Bootstrap::buildExtensionRegistry($knotExtDb);
        }
        if (!$knotExtRegistry instanceof \Knot\Extension\ExtensionRegistry) {
            // Fallback when Bootstrap is unavailable (CLI, test, partial
            // include from a script that did not load the licensing
            // stack). Extensions with `validation=local`/`dolistore`
            // will still pass through LicenseValidator's default.
            $knotExtRegistry = new \Knot\Extension\ExtensionRegistry();
        }

        $knotExtUser = $user ?? null;
        $knotExtIsAdmin = is_object($knotExtUser) && (bool) ($knotExtUser->admin ?? false);

        $hasRightProbe = static function (string $perm) use ($knotExtUser): bool {
            if (!is_object($knotExtUser) || !method_exists($knotExtUser, 'hasRight')) {
                return false;
            }
            $parts = explode('.', $perm);
            $module = $parts[0] ?? '';
            $right = $parts[1] ?? '';
            $sub = $parts[2] ?? null;
            if ($module === '' || $right === '') {
                return false;
            }
            try {
                return $sub === null
                    ? (bool) $knotExtUser->hasRight($module, $right)
                    : (bool) $knotExtUser->hasRight($module, $right, $sub);
            } catch (\Throwable $e) {
                return false;
            }
        };

        $translateProbe = static function (string $key, ?string $domain) use ($langs): string {
            if (!is_object($langs)) {
                return $key;
            }
            if ($domain !== null && $domain !== '' && method_exists($langs, 'load')) {
                try {
                    $langs->load($domain);
                } catch (\Throwable $e) {
                    // ignore — fall back to raw key
                }
            }
            try {
                return (string) $langs->trans($key);
            } catch (\Throwable $e) {
                return $key;
            }
        };

        $extensionItems = \Knot\Extension\SidebarPresentation::buildExtensionItems(
            $knotExtRegistry->active(),
            $hasRightProbe,
            $knotExtIsAdmin,
            $translateProbe,
            $preview
        );
        $items = \Knot\Extension\SidebarPresentation::mergeWithNativeItems($items, $extensionItems);
    } catch (\Throwable $e) {
        error_log('[knot leftnav] extension discovery failed: ' . $e->getMessage());
    }
}

$knotMarketplaceSidebarBadge = null;
if (class_exists(\Knot\Marketplace\SidebarBadge::class) && class_exists(\Knot\Repository\KnotConfigRepository::class)) {
    try {
        $knotNavLang = is_object($langs ?? null) && isset($langs->defaultlang)
            ? (string) $langs->defaultlang
            : 'en_US';
        $knotNavConfig = isset($db) && $db instanceof \DoliDB
            ? new \Knot\Repository\KnotConfigRepository($db)
            : null;
        if ($knotNavConfig instanceof \Knot\Repository\KnotConfigRepository) {
            $knotMarketplaceSidebarBadge = \Knot\Marketplace\SidebarBadge::fromConfig(
                $knotNavConfig,
                $knotNavLang,
            );
        }
    } catch (\Throwable $e) {
        error_log('[knot leftnav] marketplace sidebar badge failed: ' . $e->getMessage());
    }
}

?>
<aside class="knot-nav" aria-label="Knot navigation">
    <div class="knot-nav__brand">
        <span class="knot-nav__brand-mark" aria-hidden="true">
            <img
                src="<?php print dol_escape_htmltag(DOL_URL_ROOT . '/custom/knot/img/brand/knot-logo-256.png'); ?>"
                srcset="<?php print dol_escape_htmltag(DOL_URL_ROOT . '/custom/knot/img/brand/knot-logo-256.png'); ?> 1x, <?php print dol_escape_htmltag(DOL_URL_ROOT . '/custom/knot/img/brand/knot-logo-512.png'); ?> 2x"
                alt=""
                width="36"
                height="36"
                loading="eager"
            />
        </span>
        <div class="knot-nav__brand-text">
            <span class="knot-nav__brand-name"><?php print dol_escape_htmltag($langs->trans('KnotBrandName')); ?></span>
            <span class="knot-nav__brand-sub"><?php print dol_escape_htmltag($langs->trans('KnotBrandTagline')); ?></span>
        </div>
    </div>

    <nav class="knot-nav__list">
        <?php foreach ($items as $item): ?>
            <?php
                $isExtension = isset($item['_extId']);
                $extraClass = $isExtension ? ' knot-nav__item--ext' : '';
                if (!empty($item['_isCtaAdmin'])) {
                    $extraClass .= ' knot-nav__item--ext-cta';
                }
                if (!empty($item['_isPremium']) || !empty($item['_premiumStyle'])) {
                    $extraClass .= ' knot-nav__item--ext-premium';
                }
                $extAttr = $isExtension ? ' data-knot-ext-id="' . dol_escape_htmltag((string) $item['_extId']) . '"' : '';
                $ctaAttr = !empty($item['_isCtaAdmin']) ? ' data-knot-ext-cta="admin-setup"' : '';
                $tierAttr = !empty($item['_isPremium']) ? ' data-knot-ext-tier="premium"' : '';

                // ADR-20 Phase 6g §D10: extensions compare against
                // `_mode` (e.g. "migration") because $knotActive is
                // driven by the `mode` URL parameter while their
                // own item key is prefixed with `ext-` for DOM
                // uniqueness. Native items keep the legacy
                // `key === $active` semantics so existing pages
                // (dashboard, observability, ...) are unchanged.
                $isActive = $isExtension
                    ? ($active !== '' && $active === (string) ($item['_mode'] ?? ''))
                    : ($active === $item['key']);
            ?>
            <a
                class="knot-nav__item<?php print $isActive ? ' is-active' : ''; ?><?php print $extraClass; ?>"
                href="<?php print dol_escape_htmltag($item['url']); ?>"<?php print $extAttr . $ctaAttr . $tierAttr; ?>
            >
                <span class="knot-nav__icon"><i class="fas <?php print dol_escape_htmltag($item['icon']); ?>"></i></span>
                <span class="knot-nav__label"><?php print dol_escape_htmltag($item['label']); ?></span>
                <?php if (($item['key'] ?? '') === 'marketplace' && is_array($knotMarketplaceSidebarBadge ?? null)): ?>
                    <?php
                        $mpBadgeVariant = preg_replace('/[^a-z]/', '', (string) ($knotMarketplaceSidebarBadge['variant'] ?? 'primary'));
                        if ($mpBadgeVariant === '') {
                            $mpBadgeVariant = 'primary';
                        }
                        $mpBadgeLabel = (string) ($knotMarketplaceSidebarBadge['label'] ?? '');
                        $mpBadgeAria = (string) ($knotMarketplaceSidebarBadge['ariaLabel'] ?? $mpBadgeLabel);
                    ?>
                    <span
                        class="knot-nav__pro-badge knot-nav__pro-badge--<?php print dol_escape_htmltag($mpBadgeVariant); ?>"
                        aria-label="<?php print dol_escape_htmltag($mpBadgeAria); ?>"
                    ><?php print dol_escape_htmltag($mpBadgeLabel); ?></span>
                    <?php if (!empty($knotMarketplaceSidebarBadge['hasUnread'])): ?>
                        <span class="knot-nav__unread-dot" aria-hidden="true"></span>
                    <?php endif; ?>
                <?php elseif (!empty($item['_isPremium'])): ?>
                    <span class="knot-nav__pro-badge" aria-label="<?php print dol_escape_htmltag($langs->trans('KnotExtensionPremiumBadge') !== 'KnotExtensionPremiumBadge' ? $langs->trans('KnotExtensionPremiumBadge') : 'Premium add-on'); ?>">Pro</span>
                <?php endif; ?>
                <?php if ($isActive): ?>
                    <span class="knot-nav__active-dot" aria-hidden="true"></span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="knot-nav__status">
        <span class="knot-nav__status-dot<?php print $engineEnabled ? ' is-on' : ' is-off'; ?>"></span>
        <span class="knot-nav__status-label">
            <?php print dol_escape_htmltag($engineEnabled ? $langs->trans('KnotEngineRunning') : $langs->trans('KnotEnginePaused')); ?>
        </span>
        <a class="knot-nav__status-link" href="<?php print dol_escape_htmltag($setup); ?>">
            <i class="fas fa-bolt"></i>
        </a>
    </div>
    <button
        type="button"
        class="knot-nav__collapse"
        data-knot-nav-collapse
        aria-expanded="true"
        title="<?php print dol_escape_htmltag($langs->trans('KnotNavCollapse')); ?>"
    >
        <i class="fas fa-chevron-left" aria-hidden="true"></i>
    </button>
</aside>
