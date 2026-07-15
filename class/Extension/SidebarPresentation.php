<?php

declare(strict_types=1);

namespace Knot\Extension;

/**
 * Builds the left-sidebar entries contributed by Knot extensions
 * (ADR-20). Pure transformation layer kept out of the
 * `knot-leftnav.tpl.php` partial so it stays unit-testable
 * without a running Dolibarr.
 *
 * The PHP partial collects:
 *   - the array returned by {@see ExtensionRegistry::active()};
 *   - a permission probe (closure that wraps `$user->hasRight()`);
 *   - whether the current user is a Dolibarr administrator;
 *   - a translator (closure wrapping `$langs->trans()` and lazy
 *     loading of the extension's lang domain);
 *   - the base URL of `workflows/preview.php`.
 *
 * The output is the list of menu items to inject into the existing
 * `$items` array, paired with helpers to merge them at the correct
 * position inside each native section.
 */
final class SidebarPresentation
{
    /**
     * Native menu keys grouped by section. Matches the order rendered
     * by `core/tpl/knot-leftnav.tpl.php`. Used to find the right
     * insertion point when adding an extension item to a section.
     *
     * @var array<string, string>
     */
    public const NATIVE_SECTION_MAP = [
        'home' => 'home',
        'knot-core' => 'dashboard',
        'dashboard' => 'dashboard',
        'observability' => 'dashboard',
        'workflows' => 'operations',
        'editor' => 'operations',
        'executions' => 'operations',
        'inbox' => 'operations',
        'assistant' => 'operations',
        'doctor' => 'operations',
        'compatibility' => 'operations',
        'connectors' => 'catalog',
        'credentials' => 'catalog',
        'book' => 'catalog',
        'marketplace' => 'marketplace',
        'variables' => 'catalog',
        'audit' => 'catalog',
        'capabilities' => 'catalog',
        'suite-health' => 'admin',
        'updates' => 'admin',
        'setup' => 'admin',
    ];

    /**
     * Build the list of extension items to render. Each entry is
     * shaped like the native items consumed by the partial, with two
     * extra keys: `_section` and `_position` (used by
     * {@see mergeWithNativeItems()}), plus `_extId` and `_isCtaAdmin`
     * (used by the partial to add `data-knot-ext-*` attributes and
     * apply the CTA-admin style class).
     *
     * Permission gating follows ADR-20 §"Onboarding & Setup hooks":
     *   - user with the required permission: item is rendered;
     *   - admin without the permission AND extension declares
     *     `onboarding.ctaIfPermissionMissingForAdmin`: item is
     *     rendered with `_isCtaAdmin = true`;
     *   - any other case where the permission is missing: item is
     *     omitted silently.
     *
     * @param array<string, array<string, mixed>> $activeExtensions Output of {@see ExtensionRegistry::active()}.
     * @param callable                            $hasRight Closure `fn(string $perm): bool` wrapping `$user->hasRight()`.
     * @param bool                                $isAdmin Whether the current user is a Dolibarr admin.
     * @param callable                            $translate Closure `fn(string $key, ?string $domain): string`.
     * @param string                              $previewUrl Base URL of `workflows/preview.php` (no query string).
     *
     * @return array<int, array{key: string, icon: string, label: string, url: string, _section: string, _position: int, _placement: string, _extId: string, _isCtaAdmin: bool, _isPremium: bool, _mode: string}>
     */
    public static function buildExtensionItems(
        array $activeExtensions,
        callable $hasRight,
        bool $isAdmin,
        callable $translate,
        string $previewUrl
    ): array {
        $items = [];
        foreach ($activeExtensions as $extId => $extension) {
            $ui = $extension['ui'] ?? null;
            if (!is_array($ui)) {
                continue;
            }
            $menu = $ui['menu'] ?? null;
            $bundle = $ui['bundle'] ?? null;
            if (!is_array($menu) || !is_array($bundle)) {
                continue;
            }
            $requiredPerm = $ui['requiredPermission'] ?? null;
            $isCtaAdmin = false;
            if ($requiredPerm !== null) {
                $granted = (bool) $hasRight((string) $requiredPerm);
                if (!$granted) {
                    $ctaForAdmin = $ui['onboarding']['ctaIfPermissionMissingForAdmin'] ?? null;
                    if (!$isAdmin || $ctaForAdmin === null) {
                        continue;
                    }
                    $isCtaAdmin = true;
                }
            }

            $icon = (string) ($menu['icon'] ?? ManifestSchema::DEFAULT_UI_MENU_ICON);
            if ($icon === '') {
                $icon = ManifestSchema::DEFAULT_UI_MENU_ICON;
            }
            // Accept short names ("package") or fully qualified
            // ("fa-package"). The native partial expects the
            // `fa-…` form; normalise here so the template stays
            // dumb.
            if (strncmp($icon, 'fa-', 3) !== 0) {
                $icon = 'fa-' . $icon;
            }

            $label = self::resolveLabel($menu, $translate);
            $mode = (string) $menu['mode'];

            // ADR-20 Phase 6g §D10: paid add-ons (manifest
            // `category: "premium"`) get a gold-tinted treatment in
            // the sidebar so the operator immediately spots which
            // entries belong to the paid tier. The flag is computed
            // here (presentation layer) so the partial stays dumb
            // and the test surface stays unit-friendly.
            $category = $extension['category'] ?? null;
            $isPremium = is_string($category) && $category === 'premium';

            $navChildren = self::buildNavigationChildren(
                $ui['navigation'] ?? null,
                $previewUrl,
                $mode,
                $translate,
                (string) $extId,
            );

            $items[] = [
                'key' => 'ext-' . $extId,
                'icon' => $icon,
                'label' => $label,
                'url' => $previewUrl . '?mode=' . rawurlencode($mode),
                '_section' => (string) ($menu['section'] ?? ManifestSchema::DEFAULT_UI_MENU_SECTION),
                '_position' => (int) ($menu['position'] ?? ManifestSchema::DEFAULT_UI_MENU_POSITION),
                '_placement' => (string) ($menu['placement'] ?? ManifestSchema::DEFAULT_UI_MENU_PLACEMENT),
                '_extId' => (string) $extId,
                '_isCtaAdmin' => $isCtaAdmin,
                '_isPremium' => $isPremium,
                '_mode' => $mode,
                '_navChildren' => $navChildren,
            ];
        }

        return $items;
    }

    /**
     * Flatten `ui.navigation` into child sidebar rows (option B).
     * Labels resolve via the extension lang domain when possible.
     *
     * @param mixed    $navigationRaw
     * @param callable $translate Closure `fn(string $key, ?string $domain): string`
     *
     * @return list<array{key: string, navKey: string, icon: string, label: string, url: string, hash: string, _extId: string, _mode: string, _isNavChild: true}>
     */
    public static function buildNavigationChildren(
        mixed $navigationRaw,
        string $previewUrl,
        string $mode,
        callable $translate,
        string $extId,
    ): array {
        if (!is_array($navigationRaw) || $navigationRaw === []) {
            return [];
        }

        $children = [];
        foreach ($navigationRaw as $section) {
            if (!is_array($section)) {
                continue;
            }
            $items = $section['items'] ?? null;
            if (!is_array($items)) {
                continue;
            }
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $itemKey = (string) ($item['key'] ?? '');
                $labelKey = (string) ($item['labelKey'] ?? '');
                $hash = (string) ($item['hash'] ?? '');
                if ($itemKey === '' || $labelKey === '' || $hash === '' || $hash[0] !== '#') {
                    continue;
                }
                // Deferred / unshipped entries — keep in extension manifest for
                // future slices but do not expose ghosts in the Core leftnav.
                // Settings is suite-level (Core Configuration), not a submenu.
                if (
                    $itemKey === 'workspace'
                    || $itemKey === 'settings'
                    || !empty($item['disabled'])
                    || !empty($item['hidden'])
                ) {
                    continue;
                }
                $icon = self::normalizeNavIcon((string) ($item['icon'] ?? 'circle'));
                $label = (string) $translate($labelKey, null);
                // Dolibarr langs do not know Vue i18n keys (`nav.discovery`).
                // Prefer a readable humanized key until the extension hydrates
                // labels from its own locale bundle (see installCoreNavLabels).
                if ($label === '' || $label === $labelKey) {
                    $label = self::humanizeNavKey($itemKey);
                }
                $children[] = [
                    'key' => 'ext-' . $extId . '-' . $itemKey,
                    'navKey' => $itemKey,
                    'icon' => $icon,
                    'label' => $label,
                    'url' => $previewUrl . '?mode=' . rawurlencode($mode) . $hash,
                    'hash' => $hash,
                    '_extId' => $extId,
                    '_mode' => $mode,
                    '_isNavChild' => true,
                ];
            }
        }

        return $children;
    }

    /**
     * Turn a kebab/snake nav key into a short English label for the
     * server-rendered leftnav when no Dolibarr lang entry exists.
     */
    public static function humanizeNavKey(string $key): string
    {
        $key = trim($key);
        if ($key === '') {
            return '';
        }
        $spaced = str_replace(['-', '_'], ' ', $key);

        return ucwords($spaced);
    }

    /**
     * Map Lucide-style manifest icon names to Font Awesome 5 classes
     * used by Dolibarr's leftnav (`fas …`). Already-prefixed `fa-*`
     * values pass through.
     */
    public static function normalizeNavIcon(string $icon): string
    {
        $icon = trim($icon);
        if ($icon === '') {
            return 'fa-circle';
        }
        if (strncmp($icon, 'fa-', 3) === 0) {
            return $icon;
        }

        /** @var array<string, string> $lucideToFa */
        static $lucideToFa = [
            'switch' => 'fa-exchange-alt',
            'settings' => 'fa-cog',
            'lifebuoy' => 'fa-life-ring',
            'life-buoy' => 'fa-life-ring',
            'check' => 'fa-check',
            'home' => 'fa-home',
            'compass' => 'fa-compass',
            'map' => 'fa-map',
            'copy' => 'fa-copy',
            'history' => 'fa-history',
            'stethoscope' => 'fa-stethoscope',
            'upload' => 'fa-upload',
            'layers' => 'fa-layer-group',
            'route' => 'fa-route',
            'circle' => 'fa-circle',
        ];

        if (isset($lucideToFa[$icon])) {
            return $lucideToFa[$icon];
        }

        return 'fa-' . $icon;
    }

    /**
     * Merge extension items into the native list at the correct
     * position. For each extension item, find the last native item
     * belonging to the same section and insert immediately after it.
     * Multiple extensions targeting the same section are inserted in
     * ascending `_position` order (default 1000) then by extension id
     * for determinism.
     *
     * Items targeting a section that has no native item (e.g. an
     * unknown future section) are appended at the very end.
     *
     * @param array<int, array<string, mixed>> $nativeItems
     * @param array<int, array<string, mixed>> $extensionItems
     * @return array<int, array<string, mixed>>
     */
    public static function mergeWithNativeItems(array $nativeItems, array $extensionItems): array
    {
        if ($extensionItems === []) {
            return $nativeItems;
        }

        $sortExtensions = static function (array $list): array {
            usort($list, static function (array $a, array $b): int {
                $pa = (int) ($a['_position'] ?? ManifestSchema::DEFAULT_UI_MENU_POSITION);
                $pb = (int) ($b['_position'] ?? ManifestSchema::DEFAULT_UI_MENU_POSITION);
                if ($pa !== $pb) {
                    return $pa <=> $pb;
                }
                return strcmp((string) ($a['_extId'] ?? ''), (string) ($b['_extId'] ?? ''));
            });
            return $list;
        };

        // Group extension items by target section and placement anchor.
        $startBySection = [];
        $endBySection = [];
        foreach ($extensionItems as $ext) {
            $section = (string) ($ext['_section'] ?? ManifestSchema::DEFAULT_UI_MENU_SECTION);
            $placement = (string) ($ext['_placement'] ?? ManifestSchema::DEFAULT_UI_MENU_PLACEMENT);
            if ($placement === 'start') {
                $startBySection[$section][] = $ext;
            } else {
                $endBySection[$section][] = $ext;
            }
        }
        foreach ($startBySection as $section => $list) {
            $startBySection[$section] = $sortExtensions($list);
        }
        foreach ($endBySection as $section => $list) {
            $endBySection[$section] = $sortExtensions($list);
        }

        // Walk native items, tracking the first and last index that
        // belongs to each section.
        $merged = [];
        $sectionFirstIndex = [];
        $sectionLastIndex = [];
        foreach ($nativeItems as $item) {
            $merged[] = $item;
            $key = (string) ($item['key'] ?? '');
            $section = self::NATIVE_SECTION_MAP[$key] ?? null;
            if ($section === null) {
                continue;
            }
            $idx = count($merged) - 1;
            if (!isset($sectionFirstIndex[$section])) {
                $sectionFirstIndex[$section] = $idx;
            }
            $sectionLastIndex[$section] = $idx;
        }

        $result = [];
        $injectedStartSections = [];
        $injectedEndSections = [];
        foreach ($merged as $idx => $item) {
            $key = (string) ($item['key'] ?? '');
            $section = self::NATIVE_SECTION_MAP[$key] ?? null;

            if (
                $section !== null
                && ($sectionFirstIndex[$section] ?? -1) === $idx
                && isset($startBySection[$section])
            ) {
                foreach ($startBySection[$section] as $ext) {
                    $result[] = $ext;
                }
                $injectedStartSections[$section] = true;
            }

            $result[] = $item;

            if ($section === null) {
                continue;
            }
            if (($sectionLastIndex[$section] ?? -1) !== $idx) {
                continue;
            }
            if (!isset($endBySection[$section])) {
                continue;
            }
            foreach ($endBySection[$section] as $ext) {
                $result[] = $ext;
            }
            $injectedEndSections[$section] = true;
        }

        // Append extension items whose section has no native anchor.
        foreach ($startBySection as $section => $list) {
            if (isset($injectedStartSections[$section])) {
                continue;
            }
            foreach ($list as $ext) {
                $result[] = $ext;
            }
        }
        foreach ($endBySection as $section => $list) {
            if (isset($injectedEndSections[$section])) {
                continue;
            }
            foreach ($list as $ext) {
                $result[] = $ext;
            }
        }

        return $result;
    }

    /**
     * Resolve the visible label from `menu.labelLang` (preferred)
     * with a fallback to `menu.label`. The `labelLang` value may be
     * `"Key@domain"` (Dolibarr convention): the translator closure
     * is responsible for loading the `domain` lang file before
     * looking up `Key`.
     *
     * @param array<string, mixed> $menu
     */
    private static function resolveLabel(array $menu, callable $translate): string
    {
        $labelLang = $menu['labelLang'] ?? null;
        if (is_string($labelLang) && $labelLang !== '') {
            $domain = null;
            $key = $labelLang;
            $atPos = strrpos($labelLang, '@');
            if ($atPos !== false) {
                $key = substr($labelLang, 0, $atPos);
                $domain = substr($labelLang, $atPos + 1) ?: null;
            }
            $resolved = (string) $translate($key, $domain);
            // Dolibarr returns the key untouched when the translation
            // is missing: fall back to the literal label in that case.
            if ($resolved !== '' && $resolved !== $key) {
                return $resolved;
            }
        }

        $fallback = $menu['label'] ?? null;
        return is_string($fallback) ? $fallback : '';
    }
}
