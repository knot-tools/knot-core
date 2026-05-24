<?php

declare(strict_types=1);

namespace Knot\Extension;

/**
 * Validates `knot-extension.json` manifests.
 *
 * The manifest is the contract between Knot Core and an add-on
 * (Pro Pack, Enterprise, third-party). It declares what the
 * extension is, what version range of Knot/Dolibarr it requires,
 * how to license it, and which connector classes it brings.
 *
 * Schema (V2.3.5):
 * {
 *   "id": "knot-stripe-pro",                  // kebab-case, unique
 *   "label": "Knot Stripe Pro",               // human label
 *   "version": "1.0.0",                       // semver
 *   "author": "Knot Team",
 *   "category": "core" | "pro" | "enterprise" | "third-party" | "premium",
 *   "license": {
 *     "type": "free" | "commercial" | "trial",
 *     "validation": "none" | "local" | "dolistore" | "license",
 *     "productId": "12345"                    // required if validation = dolistore
 *   },
 *   "requires": {
 *     "knot": ">=2.3.5",                      // semver range
 *     "dolibarr": ">=17.0"
 *   },
 *   "connectors": [                           // FQCN, must implement ConnectorInterface
 *     "Knot\\Extension\\StripePro\\SubscriptionAction"
 *   ],
 *   "namespace": "Knot\\Extension\\StripePro\\"
 * }
 *
 * The validator never `require()`s the connector classes — it only
 * checks the structure. Loading happens later in ExtensionRegistry
 * once the manifest passed validation.
 */
final class ManifestSchema
{
    public const ALLOWED_CATEGORIES = ['core', 'pro', 'enterprise', 'third-party', 'premium'];
    public const ALLOWED_LICENSE_TYPES = ['free', 'commercial', 'trial'];
    public const ALLOWED_LICENSE_VALIDATIONS = ['none', 'local', 'dolistore', 'license'];

    /**
     * UI extension section (ADR-20).
     * Sections allowed for the sidebar grouping. Match the natural
     * grouping of the existing native items in
     * `core/tpl/knot-leftnav.tpl.php`.
     */
    public const ALLOWED_UI_MENU_SECTIONS = ['dashboard', 'marketplace', 'operations', 'catalog', 'admin'];

    /** Default icon when an extension does not declare one. */
    public const DEFAULT_UI_MENU_ICON = 'puzzle';

    /** Default ordering bucket — extensions land at the end of their section. */
    public const DEFAULT_UI_MENU_POSITION = 1000;

    /** Default sidebar section when an extension does not specify one. */
    public const DEFAULT_UI_MENU_SECTION = 'operations';

    /** Allowed values for sidebar insertion anchor within a section. */
    public const ALLOWED_UI_MENU_PLACEMENTS = ['start', 'end'];

    /** Default placement — after the last native item of the target section. */
    public const DEFAULT_UI_MENU_PLACEMENT = 'end';

    /**
     * Validate a decoded manifest array. Returns an array with:
     *   - valid: bool
     *   - errors: string[] (empty when valid)
     *   - normalised: array (the manifest with defaults applied) or null
     *
     * @param mixed $manifest
     * @return array{valid: bool, errors: array<int, string>, normalised: ?array<string, mixed>}
     */
    public static function validate($manifest): array
    {
        $errors = [];

        if (!is_array($manifest)) {
            return ['valid' => false, 'errors' => ['manifest must be a JSON object'], 'normalised' => null];
        }

        $id = $manifest['id'] ?? null;
        if (!is_string($id) || $id === '' || !preg_match('/^[a-z][a-z0-9-]{1,63}$/', $id)) {
            $errors[] = 'id must be kebab-case (lowercase a-z, 0-9, -, length 2-64)';
        }

        $label = $manifest['label'] ?? null;
        if (!is_string($label) || trim($label) === '') {
            $errors[] = 'label must be a non-empty string';
        }

        $version = $manifest['version'] ?? null;
        if (!is_string($version) || !self::isSemver($version)) {
            $errors[] = 'version must be valid semver (e.g. 1.0.0 or 1.2.3-beta1)';
        }

        $author = $manifest['author'] ?? null;
        if (!is_string($author) || trim($author) === '') {
            $errors[] = 'author must be a non-empty string';
        }

        $category = $manifest['category'] ?? 'third-party';
        if (!in_array($category, self::ALLOWED_CATEGORIES, true)) {
            $errors[] = 'category must be one of: ' . implode(', ', self::ALLOWED_CATEGORIES);
        }

        $license = $manifest['license'] ?? null;
        $licenseNorm = ['type' => 'free', 'validation' => 'none', 'productId' => null, 'manifestSignature' => ''];
        if ($license === null) {
            // accept missing for free extensions
        } elseif (!is_array($license)) {
            $errors[] = 'license must be an object';
        } else {
            $licenseType = $license['type'] ?? 'free';
            if (!in_array($licenseType, self::ALLOWED_LICENSE_TYPES, true)) {
                $errors[] = 'license.type must be one of: ' . implode(', ', self::ALLOWED_LICENSE_TYPES);
            } else {
                $licenseNorm['type'] = $licenseType;
            }

            $validationRaw = $license['validation'] ?? 'none';
            if (!in_array($validationRaw, self::ALLOWED_LICENSE_VALIDATIONS, true)) {
                $errors[] = 'license.validation must be one of: ' . implode(', ', self::ALLOWED_LICENSE_VALIDATIONS);
            } else {
                $licenseNorm['validation'] = $validationRaw === 'license' ? 'dolistore' : $validationRaw;
            }

            if ($licenseNorm['validation'] === 'dolistore') {
                $productId = $license['productId'] ?? null;
                if (!is_string($productId) && !is_int($productId)) {
                    $errors[] = 'license.productId is required when validation = dolistore';
                } else {
                    $licenseNorm['productId'] = (string) $productId;
                }
            } elseif (isset($license['productId'])) {
                $licenseNorm['productId'] = (string) $license['productId'];
            }

            if (isset($license['manifestSignature'])) {
                $ms = $license['manifestSignature'];
                if (!is_string($ms)) {
                    $errors[] = 'license.manifestSignature must be a string when provided';
                } else {
                    $msTrim = strtolower(trim($ms));
                    if ($msTrim !== '') {
                        if (!preg_match('/^[a-f0-9]{128}$/', $msTrim)) {
                            $errors[] = 'license.manifestSignature must be 128 hexadecimal characters';
                        } else {
                            $licenseNorm['manifestSignature'] = $msTrim;
                        }
                    }
                }
            }

            // commercial extensions cannot ship with validation=none in production:
            // surface a clear warning so the dev makes a deliberate choice.
            if ($licenseNorm['type'] !== 'free' && $licenseNorm['validation'] === 'none') {
                $errors[] = 'license.validation cannot be "none" for non-free extensions (use "local" or "dolistore")';
            }
        }

        $requires = $manifest['requires'] ?? [];
        if (!is_array($requires)) {
            $errors[] = 'requires must be an object';
            $requires = [];
        }
        $requiresNorm = [
            'knot' => isset($requires['knot']) && is_string($requires['knot']) ? $requires['knot'] : '*',
            'dolibarr' => isset($requires['dolibarr']) && is_string($requires['dolibarr']) ? $requires['dolibarr'] : '*',
        ];
        foreach (['knot', 'dolibarr'] as $key) {
            if (!self::isVersionRange($requiresNorm[$key])) {
                $errors[] = "requires.$key must be a valid version range (e.g. '*', '>=2.3.5', '^1.0', '~2.1.0')";
            }
        }

        $connectors = $manifest['connectors'] ?? [];
        if (!is_array($connectors)) {
            $errors[] = 'connectors must be an array of FQCN strings';
            $connectors = [];
        }
        $connectorsNorm = [];
        foreach ($connectors as $idx => $fqcn) {
            if (!is_string($fqcn) || $fqcn === '') {
                $errors[] = "connectors[$idx] must be a non-empty FQCN string";
                continue;
            }
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)+$/', $fqcn)) {
                $errors[] = "connectors[$idx] is not a valid PHP FQCN: '$fqcn'";
                continue;
            }
            $connectorsNorm[] = $fqcn;
        }

        $namespace = $manifest['namespace'] ?? null;
        if ($namespace !== null && !is_string($namespace)) {
            $errors[] = 'namespace must be a string when provided';
            $namespace = null;
        }

        // ADR-20: optional UI extension section (sidebar menu item +
        // runtime bundle + onboarding hooks). Absent = no UI surface,
        // extension still loads its connectors as usual.
        $uiNorm = null;
        if (array_key_exists('ui', $manifest) && $manifest['ui'] !== null) {
            $uiResult = self::validateUi($manifest['ui']);
            foreach ($uiResult['errors'] as $uiErr) {
                $errors[] = $uiErr;
            }
            if ($uiResult['errors'] === []) {
                $uiNorm = $uiResult['normalised'];
            }
        }

        if ($errors !== []) {
            return ['valid' => false, 'errors' => $errors, 'normalised' => null];
        }

        return [
            'valid' => true,
            'errors' => [],
            'normalised' => [
                'id' => $id,
                'label' => $label,
                'version' => $version,
                'author' => $author,
                'category' => $category,
                'license' => $licenseNorm,
                'requires' => $requiresNorm,
                'connectors' => $connectorsNorm,
                'namespace' => $namespace,
                'ui' => $uiNorm,
            ],
        ];
    }

    /**
     * Validate the `ui` section of a manifest (ADR-20). Returns
     * `{errors, normalised}`. The normalised payload is null when
     * any error is collected. Defaults applied: menu.icon = "puzzle",
     * menu.section = "operations", menu.position = 1000,
     * onboarding.adminSetupRequired = false.
     *
     * @param mixed $ui
     * @return array{errors: array<int, string>, normalised: ?array<string, mixed>}
     */
    public static function validateUi($ui): array
    {
        $errors = [];

        if (!is_array($ui)) {
            return ['errors' => ['ui must be an object'], 'normalised' => null];
        }

        $menu = $ui['menu'] ?? null;
        if (!is_array($menu)) {
            $errors[] = 'ui.menu is required when ui is present';
            return ['errors' => $errors, 'normalised' => null];
        }

        $menuLabel = $menu['label'] ?? null;
        if (!is_string($menuLabel) || trim($menuLabel) === '') {
            $errors[] = 'ui.menu.label must be a non-empty string';
        }

        $menuLabelLang = $menu['labelLang'] ?? null;
        if (!is_string($menuLabelLang) || trim($menuLabelLang) === '') {
            $errors[] = 'ui.menu.labelLang must be a non-empty string (e.g. "knotmigration@knotmigration")';
        }

        $menuMode = $menu['mode'] ?? null;
        if (!is_string($menuMode) || !preg_match('/^[a-z][a-z0-9-]{1,63}$/', $menuMode)) {
            $errors[] = 'ui.menu.mode must be kebab-case (lowercase a-z, 0-9, -, length 2-64)';
        }

        $menuSection = $menu['section'] ?? self::DEFAULT_UI_MENU_SECTION;
        if (!in_array($menuSection, self::ALLOWED_UI_MENU_SECTIONS, true)) {
            $errors[] = 'ui.menu.section must be one of: ' . implode(', ', self::ALLOWED_UI_MENU_SECTIONS);
        }

        $menuIcon = $menu['icon'] ?? self::DEFAULT_UI_MENU_ICON;
        if (!is_string($menuIcon) || trim($menuIcon) === '') {
            $errors[] = 'ui.menu.icon must be a non-empty string when provided';
        }

        $menuPosition = $menu['position'] ?? self::DEFAULT_UI_MENU_POSITION;
        if (!is_int($menuPosition) || $menuPosition < 0) {
            $errors[] = 'ui.menu.position must be a non-negative integer when provided';
        }

        $menuPlacement = $menu['placement'] ?? self::DEFAULT_UI_MENU_PLACEMENT;
        if (!in_array($menuPlacement, self::ALLOWED_UI_MENU_PLACEMENTS, true)) {
            $errors[] = 'ui.menu.placement must be one of: ' . implode(', ', self::ALLOWED_UI_MENU_PLACEMENTS);
        }

        $bundle = $ui['bundle'] ?? null;
        if (!is_array($bundle)) {
            $errors[] = 'ui.bundle is required when ui is present';
            return ['errors' => $errors, 'normalised' => null];
        }

        $bundleJs = $bundle['js'] ?? null;
        if (!is_string($bundleJs) || trim($bundleJs) === '') {
            $errors[] = 'ui.bundle.js must be a non-empty relative path (e.g. "dist/knot-extension.js")';
        }

        $bundleCss = $bundle['css'] ?? null;
        if ($bundleCss !== null && (!is_string($bundleCss) || trim($bundleCss) === '')) {
            $errors[] = 'ui.bundle.css must be a non-empty string when provided';
        }

        $bundleGlobalEntry = $bundle['globalEntry'] ?? null;
        if (!is_string($bundleGlobalEntry) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $bundleGlobalEntry)) {
            $errors[] = 'ui.bundle.globalEntry must be a valid JS identifier (e.g. "KnotMigrationExtension")';
        }

        $requiredPermission = $ui['requiredPermission'] ?? null;
        if ($requiredPermission !== null) {
            if (!is_string($requiredPermission) || !preg_match('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)?$/', $requiredPermission)) {
                $errors[] = 'ui.requiredPermission must follow Dolibarr permission shape "module.perm" or "module.perm.subperm"';
            }
        }

        $ctaIfMissing = $ui['ctaIfMissing'] ?? null;
        $ctaIfMissingNorm = null;
        if ($ctaIfMissing !== null) {
            if (!is_array($ctaIfMissing)) {
                $errors[] = 'ui.ctaIfMissing must be an object when provided';
            } else {
                $ctaLabel = $ctaIfMissing['label'] ?? null;
                $ctaUrl = $ctaIfMissing['url'] ?? null;
                if (!is_string($ctaLabel) || trim($ctaLabel) === '') {
                    $errors[] = 'ui.ctaIfMissing.label must be a non-empty string';
                }
                if (!is_string($ctaUrl) || !preg_match('#^https?://#', $ctaUrl)) {
                    $errors[] = 'ui.ctaIfMissing.url must be an http(s) URL';
                }
                if (is_string($ctaLabel) && trim($ctaLabel) !== '' && is_string($ctaUrl) && preg_match('#^https?://#', $ctaUrl)) {
                    $ctaIfMissingNorm = ['label' => $ctaLabel, 'url' => $ctaUrl];
                }
            }
        }

        // ADR-20 Phase 6g §L2 — optional `ui.navigation` declaration.
        //
        // When present, this is a hierarchical declaration of the
        // sub-navigation an extension renders inside its own surface
        // (e.g. Knot Migration's 4 sections × 12 items sidebar).
        // Core does not RENDER it directly — it forwards the
        // structure to the extension at mount time so the extension
        // can stamp out its sidebar without re-declaring the routes
        // in its bundle. Centralising the declaration here also
        // means future Cmd+K search can list those items without
        // booting the extension.
        //
        // Shape (validated below):
        //   navigation: [
        //     { key, labelKey, items: [
        //       { key, labelKey, icon, hash, badge? } ] }
        //   ]
        //
        // Absence = no in-extension navigation declared, Core does
        // not pass anything to the mount context.
        $navigationNorm = null;
        $navigationRaw = $ui['navigation'] ?? null;
        if ($navigationRaw !== null) {
            $navResult = self::validateUiNavigation($navigationRaw);
            if ($navResult['errors'] !== []) {
                $errors = array_merge($errors, $navResult['errors']);
            } else {
                $navigationNorm = $navResult['normalised'];
            }
        }

        $onboarding = $ui['onboarding'] ?? null;
        $onboardingNorm = ['adminSetupRequired' => false, 'adminSetupUrl' => null, 'ctaIfPermissionMissingForAdmin' => null];
        if ($onboarding !== null) {
            if (!is_array($onboarding)) {
                $errors[] = 'ui.onboarding must be an object when provided';
            } else {
                $adminRequired = $onboarding['adminSetupRequired'] ?? false;
                if (!is_bool($adminRequired)) {
                    $errors[] = 'ui.onboarding.adminSetupRequired must be a boolean';
                } else {
                    $onboardingNorm['adminSetupRequired'] = $adminRequired;
                }

                $adminUrl = $onboarding['adminSetupUrl'] ?? null;
                if ($adminUrl !== null) {
                    if (!is_string($adminUrl) || trim($adminUrl) === '') {
                        $errors[] = 'ui.onboarding.adminSetupUrl must be a non-empty string when provided';
                    } else {
                        $onboardingNorm['adminSetupUrl'] = $adminUrl;
                    }
                }

                $ctaAdmin = $onboarding['ctaIfPermissionMissingForAdmin'] ?? null;
                if ($ctaAdmin !== null) {
                    if (!is_string($ctaAdmin) || trim($ctaAdmin) === '') {
                        $errors[] = 'ui.onboarding.ctaIfPermissionMissingForAdmin must be a non-empty string when provided';
                    } else {
                        $onboardingNorm['ctaIfPermissionMissingForAdmin'] = $ctaAdmin;
                    }
                }
            }
        }

        if ($errors !== []) {
            return ['errors' => $errors, 'normalised' => null];
        }

        return [
            'errors' => [],
            'normalised' => [
                'menu' => [
                    'label' => $menuLabel,
                    'labelLang' => $menuLabelLang,
                    'mode' => $menuMode,
                    'section' => $menuSection,
                    'icon' => $menuIcon,
                    'position' => $menuPosition,
                    'placement' => $menuPlacement,
                ],
                'bundle' => [
                    'js' => $bundleJs,
                    'css' => $bundleCss,
                    'globalEntry' => $bundleGlobalEntry,
                ],
                'requiredPermission' => $requiredPermission,
                'ctaIfMissing' => $ctaIfMissingNorm,
                'onboarding' => $onboardingNorm,
                'navigation' => $navigationNorm,
            ],
        ];
    }

    /**
     * Validate `ui.navigation`. The shape is:
     *
     *   navigation: array<int, {
     *     key: string,                 // kebab-case, unique within manifest
     *     labelKey: string,            // i18n key (extension translation file)
     *     items: array<int, {
     *       key: string,               // kebab-case, unique within section
     *       labelKey: string,
     *       icon: string,              // free-form (extension owns icon set)
     *       hash: string,              // must start with "#"
     *       badge?: string,            // optional pill text (i18n key)
     *     }>
     *   }>
     *
     * Returns the normalised structure (defaults filled in) or an
     * `errors` array. Keys are deduplicated case-sensitively; the
     * first occurrence wins so a typo does not silently drop the
     * valid entry.
     *
     * @param mixed $raw
     * @return array{errors: array<int,string>, normalised: ?array<int, array<string, mixed>>}
     */
    private static function validateUiNavigation($raw): array
    {
        $errors = [];
        if (!is_array($raw) || array_is_list($raw) === false) {
            return [
                'errors' => ['ui.navigation must be a JSON array of sections'],
                'normalised' => null,
            ];
        }

        $normalised = [];
        $seenSectionKeys = [];
        foreach ($raw as $sectionIndex => $section) {
            if (!is_array($section)) {
                $errors[] = sprintf('ui.navigation[%d] must be an object', $sectionIndex);
                continue;
            }
            $sectionKey = $section['key'] ?? null;
            if (!is_string($sectionKey) || !preg_match('/^[a-z][a-z0-9-]{1,63}$/', $sectionKey)) {
                $errors[] = sprintf('ui.navigation[%d].key must be kebab-case (lowercase a-z, 0-9, -, length 2-64)', $sectionIndex);
                continue;
            }
            if (isset($seenSectionKeys[$sectionKey])) {
                $errors[] = sprintf('ui.navigation[%d].key "%s" is duplicated', $sectionIndex, $sectionKey);
                continue;
            }
            $seenSectionKeys[$sectionKey] = true;

            $sectionLabelKey = $section['labelKey'] ?? null;
            if (!is_string($sectionLabelKey) || trim($sectionLabelKey) === '') {
                $errors[] = sprintf('ui.navigation[%d].labelKey must be a non-empty string', $sectionIndex);
                continue;
            }

            $rawItems = $section['items'] ?? null;
            if (!is_array($rawItems) || array_is_list($rawItems) === false || $rawItems === []) {
                $errors[] = sprintf('ui.navigation[%d].items must be a non-empty array', $sectionIndex);
                continue;
            }

            $items = [];
            $seenItemKeys = [];
            foreach ($rawItems as $itemIndex => $item) {
                if (!is_array($item)) {
                    $errors[] = sprintf('ui.navigation[%d].items[%d] must be an object', $sectionIndex, $itemIndex);
                    continue;
                }
                $itemKey = $item['key'] ?? null;
                if (!is_string($itemKey) || !preg_match('/^[a-z][a-z0-9-]{1,63}$/', $itemKey)) {
                    $errors[] = sprintf('ui.navigation[%d].items[%d].key must be kebab-case', $sectionIndex, $itemIndex);
                    continue;
                }
                if (isset($seenItemKeys[$itemKey])) {
                    $errors[] = sprintf('ui.navigation[%d].items[%d].key "%s" is duplicated within the section', $sectionIndex, $itemIndex, $itemKey);
                    continue;
                }
                $seenItemKeys[$itemKey] = true;

                $itemLabelKey = $item['labelKey'] ?? null;
                if (!is_string($itemLabelKey) || trim($itemLabelKey) === '') {
                    $errors[] = sprintf('ui.navigation[%d].items[%d].labelKey must be a non-empty string', $sectionIndex, $itemIndex);
                    continue;
                }

                $itemIcon = $item['icon'] ?? null;
                if (!is_string($itemIcon) || trim($itemIcon) === '') {
                    $errors[] = sprintf('ui.navigation[%d].items[%d].icon must be a non-empty string', $sectionIndex, $itemIndex);
                    continue;
                }

                $itemHash = $item['hash'] ?? null;
                if (!is_string($itemHash) || strncmp($itemHash, '#', 1) !== 0) {
                    $errors[] = sprintf('ui.navigation[%d].items[%d].hash must be a string starting with "#"', $sectionIndex, $itemIndex);
                    continue;
                }

                $badge = $item['badge'] ?? null;
                if ($badge !== null && (!is_string($badge) || trim($badge) === '')) {
                    $errors[] = sprintf('ui.navigation[%d].items[%d].badge must be a non-empty string when provided', $sectionIndex, $itemIndex);
                    continue;
                }

                $items[] = [
                    'key' => $itemKey,
                    'labelKey' => $itemLabelKey,
                    'icon' => $itemIcon,
                    'hash' => $itemHash,
                    'badge' => $badge,
                ];
            }

            $normalised[] = [
                'key' => $sectionKey,
                'labelKey' => $sectionLabelKey,
                'items' => $items,
            ];
        }

        if ($errors !== []) {
            return ['errors' => $errors, 'normalised' => null];
        }

        return ['errors' => [], 'normalised' => $normalised];
    }

    /**
     * Strict semver match: MAJOR.MINOR.PATCH with optional prerelease/build.
     */
    public static function isSemver(string $version): bool
    {
        return (bool) preg_match(
            '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/',
            $version
        );
    }

    /**
     * Accept npm/composer-style ranges. Wildcards: '*', '>=', '<=', '>', '<',
     * '=', '^', '~'. Multiple constraints separated by ' ' or ','.
     */
    public static function isVersionRange(string $range): bool
    {
        $range = trim($range);
        if ($range === '' || $range === '*') {
            return true;
        }
        $parts = preg_split('/[\s,]+/', $range) ?: [];
        foreach ($parts as $part) {
            if (!preg_match('/^(>=|<=|>|<|=|\^|~)?(\d+)(\.\d+)?(\.\d+)?(?:-[0-9A-Za-z.-]+)?$/', $part)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Test whether $version satisfies $range. Supports '*', '>=', '<=', '>',
     * '<', '=', '^', '~'. AND-combined when multiple constraints.
     */
    public static function satisfies(string $version, string $range): bool
    {
        if (!self::isSemver($version)) {
            return false;
        }
        $range = trim($range);
        if ($range === '' || $range === '*') {
            return true;
        }
        $parts = preg_split('/[\s,]+/', $range) ?: [];
        foreach ($parts as $part) {
            if (!self::satisfiesSingle($version, $part)) {
                return false;
            }
        }
        return true;
    }

    private static function satisfiesSingle(string $version, string $constraint): bool
    {
        if (!preg_match('/^(>=|<=|>|<|=|\^|~)?(.+)$/', $constraint, $m)) {
            return false;
        }
        $op = $m[1] !== '' ? $m[1] : '=';
        $target = self::padToSemver($m[2]);
        if (!self::isSemver($target)) {
            return false;
        }

        if ($op === '^') {
            // ^1.2.3 := >=1.2.3 <2.0.0 (or 0.x special-case left to caller)
            [$major] = explode('.', $target);
            return version_compare($version, $target, '>=')
                && version_compare($version, ((int) $major + 1) . '.0.0', '<');
        }
        if ($op === '~') {
            // ~1.2.3 := >=1.2.3 <1.3.0
            [$major, $minor] = explode('.', $target);
            return version_compare($version, $target, '>=')
                && version_compare($version, $major . '.' . ((int) $minor + 1) . '.0', '<');
        }
        return version_compare($version, $target, $op);
    }

    private static function padToSemver(string $v): string
    {
        $parts = explode('.', preg_replace('/[-+].*$/', '', $v) ?? $v);
        while (count($parts) < 3) {
            $parts[] = '0';
        }
        return implode('.', array_slice($parts, 0, 3));
    }
}
