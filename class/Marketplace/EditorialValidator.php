<?php

/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

namespace Knot\Marketplace;

/**
 * Strict validation / sanitisation checks for Knot Marketplace editorial payloads (schema v2).
 *
 * Enforces bounded sizes, HTTPS URL host allow-lists on knot.tools CDN surfaces,
 * no inline SVG snippets, XSS-ish patterns in free text, and structured v2 sections.
 */
final class EditorialValidator
{
    private const REQUIRED_VERSION = 2;

    /** @var list<string> */
    private const ALLOWED_HOSTS = [
        'knot.tools',
        'www.knot.tools',
        'license.knot.tools',
        'cdn.knot.tools',
    ];

    private const MAX_HOME_BLOCKS = 12;

    private const MAX_ECOSYSTEM_BLOCKS = 12;

    private const MAX_NEWS_BLOCKS = 16;

    private const MAX_TITLE_LEN = 200;

    private const MAX_BODY_LEN = 4000;

    private const MAX_CTA_LABEL = 96;

    private const MAX_ALT_LEN = 200;

    private const MAX_TAGLINE_LEN = 280;

    private const MAX_SPOTLIGHT_ITEMS = 3;

    private const MIN_SPOTLIGHT_ITEMS = 1;

    private const MAX_COLLECTIONS = 12;

    private const MAX_TABS = 16;

    private const MAX_CATEGORIES = 24;

    private const MAX_CTAS = 4;

    private const MAX_FEATURES_ITEMS = 32;

    private const MAX_LIST_ITEMS = 48;

    private const MAX_CHANGELOG_ENTRIES = 64;

    private const MAX_SCREENSHOTS = 24;

    private const MAX_USE_CASES = 24;

    private const MAX_PRICING_FEATURES = 16;

    private const BADGE_LABEL_MIN = 1;

    private const BADGE_LABEL_MAX = 12;

    private const BADGE_ARIA_MAX = 200;

    /** @var array<string, true> */
    private const BADGE_VARIANTS = [
        'info' => true,
        'success' => true,
        'warning' => true,
        'danger' => true,
        'neutral' => true,
    ];

    /** @var array<string, true> */
    private const ACCENT_TIERS = [
        'pro' => true,
        'enterprise' => true,
        'migration' => true,
        'core' => true,
    ];

    /** @var array<string, true> */
    private const SPOTLIGHT_KINDS = [
        'pack' => true,
        'template' => true,
        'collection' => true,
        'external' => true,
    ];

    /** @var array<string, true> */
    private const COLLECTION_SORTS = [
        'recent' => true,
        'popular' => true,
    ];

    /** @var array<string, true> */
    private const TAB_KINDS = [
        'richtext' => true,
        'features' => true,
        'templates' => true,
        'changelog' => true,
        'list' => true,
        'screenshots' => true,
    ];

    /** @var array<string, true> */
    private const BLOCK_TYPES = [
        'banner' => true,
        'hero' => true,
        'ecosystem' => true,
        'template_grid' => true,
        'templateGrid' => true,
        'highlight' => true,
        'news_list' => true,
        'newsList' => true,
        'featured_templates' => true,
        'featuredTemplates' => true,
        'resources' => true,
        'product_header' => true,
        'productHeader' => true,
        'gallery' => true,
        'video' => true,
        'features' => true,
        'faq' => true,
        'sticky_cta' => true,
        'stickyCta' => true,
        'cta' => true,
        'template_header' => true,
        'templateHeader' => true,
        'workflow_full_preview' => true,
        'workflowFullPreview' => true,
        'how_it_works' => true,
        'howItWorks' => true,
        'prerequisites' => true,
        'required_products' => true,
        'requiredProducts' => true,
        'compare_plans' => true,
        'comparePlans' => true,
        'news_header' => true,
        'newsHeader' => true,
        'rich_text' => true,
        'richText' => true,
        'related_news' => true,
        'relatedNews' => true,
        'use_cases_grid' => true,
        'useCasesGrid' => true,
        'product_card' => true,
        'productCard' => true,
        'workflow_mini' => true,
        'workflowMini' => true,
        'storefront_tabs' => true,
        'storefrontTabs' => true,
    ];

    private const MAX_TEMPLATE_GRID_SLOTS = 24;

    private const MAX_ECOSYSTEM_ITEMS = 12;

    private const MAX_HERO_BULLETS = 16;

    private const MAX_SINGLE_BULLET_LEN = 400;

    private const MAX_FAQ_ITEMS = 24;

    /**
     * Lucide icon names (kebab-case) allowed on editorial icon fields.
     *
     * @var array<string, true>
     */
    private const LUCIDE_ICON_WHITELIST = [
        'alert-triangle' => true,
        'ban' => true,
        'bar-chart-3' => true,
        'book-open' => true,
        'box' => true,
        'chart' => true,
        'check-circle-2' => true,
        'chevron-right' => true,
        'cpu' => true,
        'credit-card' => true,
        'database' => true,
        'external-link' => true,
        'file-invoice' => true,
        'file-signature' => true,
        'file-text' => true,
        'git-branch' => true,
        'globe' => true,
        'info' => true,
        'key-round' => true,
        'layers' => true,
        'library' => true,
        'lock' => true,
        'mail' => true,
        'message-square' => true,
        'newspaper' => true,
        'package' => true,
        'refresh-cw' => true,
        'repeat' => true,
        'scale' => true,
        'search' => true,
        'shield' => true,
        'shopping-bag' => true,
        'shopping-cart' => true,
        'slack' => true,
        'sparkles' => true,
        'store' => true,
        'tasks' => true,
        'user-plus' => true,
        'workflow' => true,
        'zap' => true,
    ];

    /** @var array<string, true> */
    private const KNOWN_ROOT_KEYS_V2 = [
        'meta' => true,
        'version' => true,
        'taxonomy' => true,
        'home' => true,
        'ecosystem' => true,
        'sidebar' => true,
        'productPages' => true,
        'templatePages' => true,
        'newsPages' => true,
    ];

    public static function isKnownBlockType(string $type): bool
    {
        return $type !== '' && isset(self::BLOCK_TYPES[$type]);
    }

    public static function validate(mixed $data): EditorialValidationResult
    {
        if (!is_array($data)) {
            return self::failure(['root_must_be_object']);
        }

        /** @var list<string> $errors */
        $errors = [];

        $version = $data['version'] ?? null;
        if (!is_int($version) || $version !== self::REQUIRED_VERSION) {
            $errors[] = 'version_invalid';
        }

        self::validateMeta($data['meta'] ?? null, $errors);
        self::validateTaxonomy($data['taxonomy'] ?? null, $errors);
        self::validateHome($data['home'] ?? null, $errors);
        self::validateEcosystemSection($data['ecosystem'] ?? null, $errors);
        self::validateProductPagesV2($data['productPages'] ?? null, $errors);
        self::validateTemplatePagesV2($data['templatePages'] ?? null, $errors);
        self::validateSlugKeyedLayouts(
            $data['newsPages'] ?? null,
            'newsPages',
            self::MAX_NEWS_BLOCKS,
            $errors,
        );
        self::rejectUnknownRootKeys($data, $errors);
        self::validateSidebarBadge($data['sidebar'] ?? null, $errors);

        return $errors === [] ? self::success() : self::failure($errors);
    }

    /**
     * @param list<string> $errors
     */
    private static function validateMeta(mixed $meta, array &$errors): void
    {
        if ($meta === null) {
            return;
        }
        if (!is_array($meta)) {
            $errors[] = 'meta_must_be_object';

            return;
        }
        if (array_key_exists('killSwitch', $meta) && !is_bool($meta['killSwitch'])) {
            $errors[] = 'meta.killSwitch_invalid';
        }
        if (array_key_exists('updatedAt', $meta)) {
            $updatedAt = $meta['updatedAt'];
            if (!is_string($updatedAt) || !self::isIso8601Timestamp($updatedAt)) {
                $errors[] = 'meta.updatedAt_invalid';
            }
        }
    }

    /**
     * @param list<string> $errors
     */
    private static function validateTaxonomy(mixed $taxonomy, array &$errors): void
    {
        if ($taxonomy === null) {
            $errors[] = 'taxonomy_required';

            return;
        }
        if (!is_array($taxonomy)) {
            $errors[] = 'taxonomy_must_be_object';

            return;
        }
        $categories = $taxonomy['categories'] ?? null;
        if (!is_array($categories) || $categories === []) {
            $errors[] = 'taxonomy.categories_required';

            return;
        }
        if (count($categories) > self::MAX_CATEGORIES) {
            $errors[] = 'taxonomy.categories_too_many';
        }
        foreach ($categories as $idx => $category) {
            if (!is_array($category)) {
                $errors[] = 'taxonomy.categories.' . $idx . '_invalid';

                continue;
            }
            $path = 'taxonomy.categories.' . $idx;
            self::assertSlug($category['slug'] ?? null, $path . '.slug', $errors);
            self::assertPlainStringRequired(
                $category['label'] ?? null,
                $path . '.label',
                self::MAX_TITLE_LEN,
                $errors,
            );
            self::validateLucideIcon($category['icon'] ?? null, $path . '.icon', $errors, true);
            self::validateColor($category['color'] ?? null, $path . '.color', $errors);
        }
    }

    /**
     * @param list<string> $errors
     */
    private static function validateHome(mixed $home, array &$errors): void
    {
        if ($home === null) {
            $errors[] = 'home_required';

            return;
        }
        if (!is_array($home)) {
            $errors[] = 'home_must_be_object';

            return;
        }

        if (array_key_exists('spotlight', $home)) {
            self::validateSpotlight($home['spotlight'], $errors);
        }

        if (array_key_exists('collections', $home)) {
            self::validateCollections($home['collections'], $errors);
        }

        if (array_key_exists('layout', $home)) {
            self::validateLayoutBlocks(
                $home['layout'],
                'home.layout',
                self::MAX_HOME_BLOCKS,
                $errors,
            );
        }
    }

    /**
     * @param list<string> $errors
     */
    private static function validateSpotlight(mixed $spotlight, array &$errors): void
    {
        if ($spotlight === null) {
            return;
        }
        if (!is_array($spotlight)) {
            $errors[] = 'home.spotlight_must_be_object';

            return;
        }
        if (array_key_exists('title', $spotlight)) {
            self::assertPlainStringOptional(
                $spotlight['title'],
                'home.spotlight.title',
                self::MAX_TITLE_LEN,
                $errors,
            );
        }
        $items = $spotlight['items'] ?? null;
        if (!is_array($items)) {
            $errors[] = 'home.spotlight.items_required';

            return;
        }
        $count = count($items);
        if ($count < self::MIN_SPOTLIGHT_ITEMS || $count > self::MAX_SPOTLIGHT_ITEMS) {
            $errors[] = 'home.spotlight.items_count';
        }
        foreach ($items as $idx => $item) {
            if (!is_array($item)) {
                $errors[] = 'home.spotlight.items.' . $idx . '_invalid';

                continue;
            }
            $path = 'home.spotlight.items.' . $idx;
            $kind = $item['kind'] ?? null;
            if (!is_string($kind) || !isset(self::SPOTLIGHT_KINDS[$kind])) {
                $errors[] = $path . '.kind_invalid';
            }
            $slugRequired = $kind !== 'external';
            if ($slugRequired) {
                self::assertSlug($item['slug'] ?? null, $path . '.slug', $errors);
            } elseif (array_key_exists('slug', $item) && $item['slug'] !== null && $item['slug'] !== '') {
                self::assertSlug($item['slug'], $path . '.slug', $errors);
            }
            if (array_key_exists('tagline', $item)) {
                self::assertPlainStringOptional(
                    $item['tagline'],
                    $path . '.tagline',
                    self::MAX_TAGLINE_LEN,
                    $errors,
                );
            }
            $accent = $item['accent'] ?? null;
            if (!is_string($accent) || !isset(self::ACCENT_TIERS[$accent])) {
                $errors[] = $path . '.accent_invalid';
            }
            if (array_key_exists('ctas', $item)) {
                self::validateCtaList($item['ctas'], $path . '.ctas', $errors);
            }
        }
    }

    /**
     * @param list<string> $errors
     */
    private static function validateCollections(mixed $collections, array &$errors): void
    {
        if ($collections === null) {
            return;
        }
        if (!is_array($collections)) {
            $errors[] = 'home.collections_must_be_array';

            return;
        }
        if ($collections === []) {
            $errors[] = 'home.collections_empty';
        }
        if (count($collections) > self::MAX_COLLECTIONS) {
            $errors[] = 'home.collections_too_many';
        }
        foreach ($collections as $idx => $collection) {
            if (!is_array($collection)) {
                $errors[] = 'home.collections.' . $idx . '_invalid';

                continue;
            }
            $path = 'home.collections.' . $idx;
            self::assertSlug($collection['slug'] ?? null, $path . '.slug', $errors);
            self::assertPlainStringRequired(
                $collection['label'] ?? null,
                $path . '.label',
                self::MAX_TITLE_LEN,
                $errors,
            );
            $query = $collection['query'] ?? null;
            if (!is_array($query)) {
                $errors[] = $path . '.query_required';

                continue;
            }
            $sort = $query['sort'] ?? null;
            if (!is_string($sort) || !isset(self::COLLECTION_SORTS[$sort])) {
                $errors[] = $path . '.query.sort_invalid';
            }
            if (array_key_exists('category', $query) && $query['category'] !== null && $query['category'] !== '') {
                self::assertSlug($query['category'], $path . '.query.category', $errors);
            }
            $limit = $collection['limit'] ?? null;
            if (!is_int($limit) || $limit < 1 || $limit > 24) {
                $errors[] = $path . '.limit_invalid';
            }
        }
    }

    /**
     * @param list<string> $errors
     */
    private static function validateProductPagesV2(mixed $pages, array &$errors): void
    {
        if ($pages === null) {
            return;
        }
        if (!is_array($pages)) {
            $errors[] = 'productPages_must_be_object';

            return;
        }
        foreach ($pages as $slug => $page) {
            if (!is_string($slug) || $slug === '' || !preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $slug)) {
                $errors[] = 'productPages_invalid_slug';

                continue;
            }
            if (!is_array($page)) {
                $errors[] = 'productPages.' . $slug . '_invalid';

                continue;
            }
            $prefix = 'productPages.' . $slug;
            self::validateStructuredPage($page, $prefix, $errors, false);
        }
    }

    /**
     * @param list<string> $errors
     */
    private static function validateTemplatePagesV2(mixed $pages, array &$errors): void
    {
        if ($pages === null) {
            return;
        }
        if (!is_array($pages)) {
            $errors[] = 'templatePages_must_be_object';

            return;
        }
        foreach ($pages as $slug => $page) {
            if (!is_string($slug) || $slug === '' || !preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $slug)) {
                $errors[] = 'templatePages_invalid_slug';

                continue;
            }
            if (!is_array($page)) {
                $errors[] = 'templatePages.' . $slug . '_invalid';

                continue;
            }
            $prefix = 'templatePages.' . $slug;
            self::validateStructuredPage($page, $prefix, $errors, true);
        }
    }

    /**
     * @param array<string, mixed> $page
     * @param list<string> $errors
     */
    private static function validateStructuredPage(
        array $page,
        string $prefix,
        array &$errors,
        bool $templatePage,
    ): void {
        self::validatePageHero($page['hero'] ?? null, $prefix . '.hero', $errors);
        if (array_key_exists('pricing', $page)) {
            self::validatePricing($page['pricing'], $prefix . '.pricing', $errors);
        }
        self::validateTabs($page['tabs'] ?? null, $prefix . '.tabs', $errors);
        if ($templatePage) {
            if (array_key_exists('prerequisites', $page)) {
                self::validatePrerequisites($page['prerequisites'], $prefix . '.prerequisites', $errors);
            }
            if (array_key_exists('useCases', $page)) {
                self::validateUseCases($page['useCases'], $prefix . '.useCases', $errors);
            }
        }
    }

    /**
     * @param list<string> $errors
     */
    private static function validatePageHero(mixed $hero, string $path, array &$errors): void
    {
        if (!is_array($hero)) {
            $errors[] = $path . '_required';

            return;
        }
        self::assertPlainStringRequired(
            $hero['tagline'] ?? null,
            $path . '.tagline',
            self::MAX_TAGLINE_LEN,
            $errors,
        );
        self::assertPlainStringRequired(
            $hero['version'] ?? null,
            $path . '.version',
            32,
            $errors,
        );
        self::assertPlainStringRequired(
            $hero['author'] ?? null,
            $path . '.author',
            self::MAX_TITLE_LEN,
            $errors,
        );
        $tier = $hero['tier'] ?? null;
        if (!is_string($tier) || !isset(self::ACCENT_TIERS[$tier])) {
            $errors[] = $path . '.tier_invalid';
        }
    }

    /**
     * @param list<string> $errors
     */
    private static function validatePricing(mixed $pricing, string $path, array &$errors): void
    {
        if ($pricing === null) {
            return;
        }
        if (!is_array($pricing)) {
            $errors[] = $path . '_invalid';

            return;
        }
        foreach (['label', 'amount', 'period'] as $key) {
            if (!array_key_exists($key, $pricing)) {
                continue;
            }
            self::assertPlainStringOptional($pricing[$key], $path . '.' . $key, self::MAX_TITLE_LEN, $errors);
        }
        if (array_key_exists('href', $pricing) && $pricing['href'] !== null && $pricing['href'] !== '') {
            self::assertAllowedUrl((string) $pricing['href'], $path . '.href', $errors);
        }
        if (array_key_exists('features', $pricing)) {
            $features = $pricing['features'];
            if (!is_array($features)) {
                $errors[] = $path . '.features_invalid';
            } elseif (count($features) > self::MAX_PRICING_FEATURES) {
                $errors[] = $path . '.features_too_many';
            } else {
                foreach ($features as $fi => $feature) {
                    if (!is_string($feature)) {
                        $errors[] = $path . '.features.' . $fi . '_invalid';

                        continue;
                    }
                    self::assertPlainString($feature, $path . '.features.' . $fi, self::MAX_SINGLE_BULLET_LEN, $errors);
                }
            }
        }
    }

    /**
     * @param list<string> $errors
     */
    private static function validateTabs(mixed $tabs, string $path, array &$errors): void
    {
        if (!is_array($tabs)) {
            $errors[] = $path . '_required';

            return;
        }
        if ($tabs === []) {
            $errors[] = $path . '_empty';
        }
        if (count($tabs) > self::MAX_TABS) {
            $errors[] = $path . '_too_many';
        }
        foreach ($tabs as $idx => $tab) {
            if (!is_array($tab)) {
                $errors[] = $path . '.' . $idx . '_invalid';

                continue;
            }
            $tabPath = $path . '.' . $idx;
            self::assertSlug($tab['id'] ?? null, $tabPath . '.id', $errors);
            self::assertPlainStringRequired(
                $tab['label'] ?? null,
                $tabPath . '.label',
                self::MAX_TITLE_LEN,
                $errors,
            );
            $kind = $tab['kind'] ?? null;
            if (!is_string($kind) || !isset(self::TAB_KINDS[$kind])) {
                $errors[] = $tabPath . '.kind_invalid';
            }
            if (!array_key_exists('visible', $tab) || !is_bool($tab['visible'])) {
                $errors[] = $tabPath . '.visible_invalid';
            }
            if (array_key_exists('props', $tab)) {
                self::validateTabProps($tab['props'], $tabPath . '.props', is_string($kind) ? $kind : '', $errors);
            } elseif (($tab['visible'] ?? false) === true) {
                $errors[] = $tabPath . '.props_required';
            }
        }
    }

    /**
     * @param list<string> $errors
     */
    private static function validateTabProps(mixed $props, string $path, string $kind, array &$errors): void
    {
        if ($props === null) {
            return;
        }
        if (!is_array($props)) {
            $errors[] = $path . '_invalid';

            return;
        }
        if ($kind === '') {
            return;
        }

        match ($kind) {
            'richtext' => self::validateRichTextTabProps($props, $path, $errors),
            'features' => self::validateFeatureItems($props['items'] ?? null, $path . '.items', $errors),
            'templates' => self::validateTemplatesTabProps($props, $path, $errors),
            'changelog' => self::validateChangelogProps($props['entries'] ?? null, $path . '.entries', $errors),
            'list' => self::validateListProps($props['items'] ?? null, $path . '.items', $errors),
            'screenshots' => self::validateScreenshotProps($props['images'] ?? null, $path . '.images', $errors),
            default => $errors[] = $path . '_kind_unsupported',
        };
    }

    /**
     * @param array<string, mixed> $props
     * @param list<string> $errors
     */
    private static function validateRichTextTabProps(array $props, string $path, array &$errors): void
    {
        $hasContent = false;
        foreach (['body', 'content', 'html'] as $key) {
            if (!array_key_exists($key, $props)) {
                continue;
            }
            $hasContent = true;
            self::assertPlainStringOptional($props[$key], $path . '.' . $key, self::MAX_BODY_LEN, $errors);
        }
        if (!$hasContent) {
            $errors[] = $path . '.body_required';
        }
    }

    /**
     * @param array<string, mixed> $props
     * @param list<string> $errors
     */
    private static function validateTemplatesTabProps(array $props, string $path, array &$errors): void
    {
        if (array_key_exists('slugs', $props)) {
            $slugs = $props['slugs'];
            if (!is_array($slugs)) {
                $errors[] = $path . '.slugs_invalid';
            } else {
                foreach ($slugs as $si => $slug) {
                    self::assertSlug($slug, $path . '.slugs.' . $si, $errors);
                }
            }
        }
        if (array_key_exists('query', $props)) {
            $query = $props['query'];
            if (!is_array($query)) {
                $errors[] = $path . '.query_invalid';
            } else {
                $sort = $query['sort'] ?? null;
                if ($sort !== null && (!is_string($sort) || !isset(self::COLLECTION_SORTS[$sort]))) {
                    $errors[] = $path . '.query.sort_invalid';
                }
            }
        }
    }

    /**
     * @param list<string> $errors
     */
    private static function validateFeatureItems(mixed $items, string $path, array &$errors): void
    {
        if (!is_array($items) || $items === []) {
            $errors[] = $path . '_required';

            return;
        }
        if (count($items) > self::MAX_FEATURES_ITEMS) {
            $errors[] = $path . '_too_many';
        }
        foreach ($items as $idx => $item) {
            if (!is_array($item)) {
                $errors[] = $path . '.' . $idx . '_invalid';

                continue;
            }
            $itemPath = $path . '.' . $idx;
            self::assertPlainStringRequired(
                $item['title'] ?? null,
                $itemPath . '.title',
                self::MAX_TITLE_LEN,
                $errors,
            );
            if (array_key_exists('description', $item)) {
                self::assertPlainStringOptional(
                    $item['description'],
                    $itemPath . '.description',
                    self::MAX_BODY_LEN,
                    $errors,
                );
            }
            if (array_key_exists('icon', $item)) {
                self::validateLucideIcon($item['icon'], $itemPath . '.icon', $errors);
            }
        }
    }

    /**
     * @param list<string> $errors
     */
    private static function validateChangelogProps(mixed $entries, string $path, array &$errors): void
    {
        if (!is_array($entries) || $entries === []) {
            $errors[] = $path . '_required';

            return;
        }
        if (count($entries) > self::MAX_CHANGELOG_ENTRIES) {
            $errors[] = $path . '_too_many';
        }
        foreach ($entries as $idx => $entry) {
            if (!is_array($entry)) {
                $errors[] = $path . '.' . $idx . '_invalid';

                continue;
            }
            $entryPath = $path . '.' . $idx;
            self::assertPlainStringRequired(
                $entry['version'] ?? null,
                $entryPath . '.version',
                32,
                $errors,
            );
            self::assertPlainStringRequired(
                $entry['date'] ?? null,
                $entryPath . '.date',
                32,
                $errors,
            );
            self::assertPlainStringRequired(
                $entry['notes'] ?? null,
                $entryPath . '.notes',
                self::MAX_BODY_LEN,
                $errors,
            );
        }
    }

    /**
     * @param list<string> $errors
     */
    private static function validateListProps(mixed $items, string $path, array &$errors): void
    {
        if (!is_array($items) || $items === []) {
            $errors[] = $path . '_required';

            return;
        }
        if (count($items) > self::MAX_LIST_ITEMS) {
            $errors[] = $path . '_too_many';
        }
        foreach ($items as $idx => $item) {
            if (!is_array($item)) {
                $errors[] = $path . '.' . $idx . '_invalid';

                continue;
            }
            $itemPath = $path . '.' . $idx;
            self::assertPlainStringRequired(
                $item['label'] ?? null,
                $itemPath . '.label',
                self::MAX_TITLE_LEN,
                $errors,
            );
            if (array_key_exists('value', $item)) {
                self::assertPlainStringOptional(
                    $item['value'],
                    $itemPath . '.value',
                    self::MAX_BODY_LEN,
                    $errors,
                );
            }
        }
    }

    /**
     * @param list<string> $errors
     */
    private static function validateScreenshotProps(mixed $images, string $path, array &$errors): void
    {
        if (!is_array($images) || $images === []) {
            $errors[] = $path . '_required';

            return;
        }
        if (count($images) > self::MAX_SCREENSHOTS) {
            $errors[] = $path . '_too_many';
        }
        foreach ($images as $idx => $image) {
            self::validateImage($image, $path . '.' . $idx, $errors);
        }
    }

    /**
     * @param list<string> $errors
     */
    private static function validatePrerequisites(mixed $prerequisites, string $path, array &$errors): void
    {
        if ($prerequisites === null) {
            return;
        }
        if (!is_array($prerequisites)) {
            $errors[] = $path . '_invalid';

            return;
        }
        self::validateFeatureItems($prerequisites['items'] ?? null, $path . '.items', $errors);
    }

    /**
     * @param list<string> $errors
     */
    private static function validateUseCases(mixed $useCases, string $path, array &$errors): void
    {
        if ($useCases === null) {
            return;
        }
        if (!is_array($useCases)) {
            $errors[] = $path . '_invalid';

            return;
        }
        $items = $useCases['items'] ?? null;
        if (!is_array($items) || $items === []) {
            $errors[] = $path . '.items_required';

            return;
        }
        if (count($items) > self::MAX_USE_CASES) {
            $errors[] = $path . '.items_too_many';
        }
        foreach ($items as $idx => $item) {
            if (!is_array($item)) {
                $errors[] = $path . '.items.' . $idx . '_invalid';

                continue;
            }
            $itemPath = $path . '.items.' . $idx;
            self::assertPlainStringRequired(
                $item['title'] ?? null,
                $itemPath . '.title',
                self::MAX_TITLE_LEN,
                $errors,
            );
            if (array_key_exists('description', $item)) {
                self::assertPlainStringOptional(
                    $item['description'],
                    $itemPath . '.description',
                    self::MAX_BODY_LEN,
                    $errors,
                );
            }
            if (array_key_exists('icon', $item)) {
                self::validateLucideIcon($item['icon'], $itemPath . '.icon', $errors);
            }
        }
    }

    /**
     * @param list<string> $errors
     */
    private static function validateEcosystemSection(mixed $eco, array &$errors): void
    {
        if ($eco === null) {
            return;
        }
        if (!is_array($eco)) {
            $errors[] = 'ecosystem_must_be_object';

            return;
        }
        if (array_key_exists('layout', $eco)) {
            self::validateLayoutBlocks(
                $eco['layout'],
                'ecosystem.layout',
                self::MAX_ECOSYSTEM_BLOCKS,
                $errors,
            );
        }
    }

    /**
     * @param list<string> $errors
     */
    private static function validateSlugKeyedLayouts(
        mixed $pages,
        string $rootKey,
        int $maxBlocks,
        array &$errors,
    ): void {
        if ($pages === null) {
            return;
        }
        if (!is_array($pages)) {
            $errors[] = $rootKey . '_must_be_object';

            return;
        }
        foreach ($pages as $slug => $page) {
            if (!is_string($slug) || $slug === '' || !preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $slug)) {
                $errors[] = $rootKey . '_invalid_slug';

                continue;
            }
            if (!is_array($page)) {
                $errors[] = $rootKey . '.' . $slug . '_invalid';

                continue;
            }
            if (!array_key_exists('layout', $page)) {
                continue;
            }
            $prefix = $rootKey . '.' . $slug . '.layout';
            self::validateLayoutBlocks(
                $page['layout'],
                $prefix,
                $maxBlocks,
                $errors,
            );
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $errors
     */
    private static function rejectUnknownRootKeys(array $data, array &$errors): void
    {
        foreach ($data as $key => $_) {
            if (isset(self::KNOWN_ROOT_KEYS_V2[$key])) {
                continue;
            }
            $errors[] = 'root_unknown_key';
        }
    }

    /**
     * @param list<string> $errors
     */
    private static function validateLayoutBlocks(mixed $layout, string $path, int $maxBlocks, array &$errors): void
    {
        if (!is_array($layout)) {
            $errors[] = $path . '_invalid';

            return;
        }
        if (count($layout) > $maxBlocks) {
            $errors[] = $path . '_too_many_blocks';
        }
        foreach ($layout as $idx => $block) {
            if (!is_array($block)) {
                $errors[] = $path . '.' . $idx . '_invalid';

                continue;
            }
            self::validateBlock($block, $path . '.' . $idx, $errors);
        }
    }

    /**
     * @param array<string, mixed> $block
     * @param list<string> $errors
     */
    private static function validateBlock(array $block, string $path, array &$errors): void
    {
        $id = $block['id'] ?? null;
        if (!is_string($id) || $id === '' || !preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $id)) {
            $errors[] = $path . '.id_invalid';
        }

        $type = $block['type'] ?? null;
        if (!is_string($type) || !isset(self::BLOCK_TYPES[$type])) {
            $errors[] = $path . '.type_invalid';
        }

        if (array_key_exists('icon', $block)) {
            self::validateLucideIcon($block['icon'], $path . '.icon', $errors);
        }

        foreach (['title', 'subtitle', 'kicker', 'eyebrow'] as $scalar) {
            if (!array_key_exists($scalar, $block)) {
                continue;
            }
            self::assertPlainStringOptional($block[$scalar], $path . '.' . $scalar, self::MAX_TITLE_LEN, $errors);
        }

        if (array_key_exists('body', $block)) {
            self::assertPlainStringOptional($block['body'], $path . '.body', self::MAX_BODY_LEN, $errors);
        }

        if (isset($block['cta'])) {
            self::validateCta($block['cta'], $path . '.cta', $errors);
        }

        if (isset($block['image'])) {
            self::validateImage($block['image'], $path . '.image', $errors);
        }

        if (($type ?? '') === 'templateGrid' || ($type ?? '') === 'template_grid') {
            $maxSlots = $block['maxSlots'] ?? null;
            if ($maxSlots !== null && (!is_int($maxSlots) || $maxSlots < 1 || $maxSlots > self::MAX_TEMPLATE_GRID_SLOTS)) {
                $errors[] = $path . '.maxSlots_invalid';
            }
        }

        if (($type ?? '') === 'hero') {
            $bullets = $block['bullets'] ?? null;
            if ($bullets !== null) {
                if (!is_array($bullets)) {
                    $errors[] = $path . '.bullets_invalid';
                } elseif (count($bullets) > self::MAX_HERO_BULLETS) {
                    $errors[] = $path . '.bullets_too_many';
                } else {
                    foreach ($bullets as $bi => $btext) {
                        if (!is_string($btext)) {
                            $errors[] = $path . '.bullets.' . $bi . '_invalid';

                            continue;
                        }
                        $bt = trim($btext);
                        if ($bt === '') {
                            $errors[] = $path . '.bullets.' . $bi . '_empty';

                            continue;
                        }
                        self::assertPlainString($bt, $path . '.bullets.' . $bi, self::MAX_SINGLE_BULLET_LEN, $errors);
                    }
                }
            }
        }

        if (($type ?? '') === 'rich_text' || ($type ?? '') === 'richText') {
            self::validateRichTextExtras($block, $path, $errors);
        }

        if (in_array(($type ?? ''), ['sticky_cta', 'stickyCta', 'cta'], true)) {
            if (isset($block['ctaLabel'])) {
                self::assertPlainStringOptional($block['ctaLabel'], $path . '.ctaLabel', self::MAX_CTA_LABEL, $errors);
            }
            if (isset($block['ctaHref']) && $block['ctaHref'] !== '') {
                self::assertAllowedUrl((string) $block['ctaHref'], $path . '.ctaHref', $errors);
            }
            if (isset($block['secondaryLabel'])) {
                self::assertPlainStringOptional($block['secondaryLabel'], $path . '.secondaryLabel', self::MAX_CTA_LABEL, $errors);
            }
            if (isset($block['secondaryHref']) && (string) $block['secondaryHref'] !== '') {
                self::assertAllowedUrl((string) $block['secondaryHref'], $path . '.secondaryHref', $errors);
            }
        }

        if (($type ?? '') === 'faq') {
            $faqItems = $block['items'] ?? null;
            if (!is_array($faqItems)) {
                $errors[] = $path . '.items_missing';
            } else {
                if ($faqItems === []) {
                    $errors[] = $path . '.items_empty';
                } elseif (count($faqItems) > self::MAX_FAQ_ITEMS) {
                    $errors[] = $path . '.items_too_many';
                } else {
                    foreach ($faqItems as $fi => $row) {
                        if (!is_array($row)) {
                            $errors[] = $path . '.items.' . $fi . '_invalid';

                            continue;
                        }
                        $fid = $row['id'] ?? null;
                        if ($fid !== null && $fid !== '') {
                            if (!is_string($fid) || !preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $fid)) {
                                $errors[] = $path . '.items.' . $fi . '.id_invalid';
                            } else {
                                self::assertPlainStringOptional($fid, $path . '.items.' . $fi . '.id', 80, $errors);
                            }
                        }
                        foreach (['question', 'answer'] as $qk) {
                            $qv = $row[$qk] ?? null;
                            if (!is_string($qv) || trim($qv) === '') {
                                $errors[] = $path . '.items.' . $fi . '.' . $qk . '_required';

                                continue;
                            }
                            self::assertPlainString(
                                trim($qv),
                                $path . '.items.' . $fi . '.' . $qk,
                                self::MAX_BODY_LEN,
                                $errors,
                            );
                        }
                    }
                }
            }
        }

        if (($type ?? '') === 'ecosystem') {
            $items = $block['items'] ?? null;
            if ($items !== null) {
                if (!is_array($items)) {
                    $errors[] = $path . '.items_invalid';
                } else {
                    if (count($items) > self::MAX_ECOSYSTEM_ITEMS) {
                        $errors[] = $path . '.items_too_many';
                    }
                    foreach ($items as $ix => $item) {
                        if (!is_array($item)) {
                            $errors[] = $path . '.items.' . $ix . '_invalid';

                            continue;
                        }
                        self::assertPlainStringOptional(
                            $item['title'] ?? null,
                            $path . '.items.' . $ix . '.title',
                            self::MAX_TITLE_LEN,
                            $errors,
                        );
                        self::assertPlainStringOptional(
                            $item['description'] ?? null,
                            $path . '.items.' . $ix . '.description',
                            self::MAX_BODY_LEN,
                            $errors,
                        );
                        if (isset($item['href'])) {
                            self::assertAllowedUrl((string) $item['href'], $path . '.items.' . $ix . '.href', $errors);
                        }
                    }
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $block
     * @param list<string> $errors
     */
    private static function validateRichTextExtras(array $block, string $path, array &$errors): void
    {
        foreach (['html', 'content', 'excerpt'] as $key) {
            if (!array_key_exists($key, $block)) {
                continue;
            }
            self::assertPlainStringOptional($block[$key], $path . '.' . $key, self::MAX_BODY_LEN, $errors);
        }
        if (!array_key_exists('props', $block)) {
            return;
        }
        $props = $block['props'];
        if ($props === null || $props === []) {
            return;
        }
        if (!is_array($props)) {
            $errors[] = $path . '.props_invalid';

            return;
        }
        foreach ($props as $pk => $pv) {
            if (!is_string($pk) || $pk === '' || !preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $pk)) {
                $errors[] = $path . '.props_key_invalid';

                continue;
            }
            if (is_string($pv)) {
                self::assertPlainStringOptional($pv, $path . '.props.' . $pk, self::MAX_BODY_LEN, $errors);

                continue;
            }
            if (is_array($pv)) {
                foreach ($pv as $subIdx => $cell) {
                    if (!is_string($cell)) {
                        $errors[] = $path . '.props.' . $pk . '.' . $subIdx . '_invalid';

                        continue;
                    }
                    self::assertPlainStringOptional(
                        $cell,
                        $path . '.props.' . $pk . '.' . $subIdx,
                        self::MAX_BODY_LEN,
                        $errors,
                    );
                }

                continue;
            }
            $errors[] = $path . '.props.' . $pk . '_invalid';
        }
    }

    /**
     * @param list<string> $errors
     */
    private static function validateCtaList(mixed $ctas, string $path, array &$errors): void
    {
        if (!is_array($ctas)) {
            $errors[] = $path . '_invalid';

            return;
        }
        if (count($ctas) > self::MAX_CTAS) {
            $errors[] = $path . '_too_many';
        }
        foreach ($ctas as $idx => $cta) {
            self::validateCta($cta, $path . '.' . $idx, $errors);
        }
    }

    /**
     * @param list<string> $errors
     */
    private static function validateLucideIcon(
        mixed $value,
        string $path,
        array &$errors,
        bool $required = false,
    ): void {
        if ($value === null || $value === '') {
            if ($required) {
                $errors[] = $path . '_required';
            }

            return;
        }
        if (!is_string($value)) {
            $errors[] = $path . '_invalid';

            return;
        }
        $norm = strtolower(trim($value));
        if ($norm === '' || !isset(self::LUCIDE_ICON_WHITELIST[$norm])) {
            $errors[] = $path . '_not_whitelisted';
        }
    }

    /**
     * @param list<string> $errors
     */
    private static function validateColor(mixed $value, string $path, array &$errors): void
    {
        if (!is_string($value) || $value === '') {
            $errors[] = $path . '_required';

            return;
        }
        if (preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value) === 1) {
            return;
        }
        if (isset(self::ACCENT_TIERS[$value])) {
            return;
        }
        $errors[] = $path . '_invalid';
    }

    /**
     * @param list<string> $errors
     */
    private static function assertSlug(mixed $value, string $path, array &$errors): void
    {
        if (!is_string($value) || $value === '' || !preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $value)) {
            $errors[] = $path . '_invalid';
        }
    }

    /**
     * @param list<string> $errors
     */
    private static function validateCta(mixed $cta, string $path, array &$errors): void
    {
        if (!is_array($cta)) {
            $errors[] = $path . '_invalid';

            return;
        }
        if (isset($cta['label'])) {
            self::assertPlainStringOptional($cta['label'], $path . '.label', self::MAX_CTA_LABEL, $errors);
        }
        if (isset($cta['href'])) {
            self::assertAllowedUrl((string) $cta['href'], $path . '.href', $errors);
        }
    }

    /**
     * @param list<string> $errors
     */
    private static function validateImage(mixed $image, string $path, array &$errors): void
    {
        if (!is_array($image)) {
            $errors[] = $path . '_invalid';

            return;
        }
        $src = $image['src'] ?? null;
        if (!is_string($src) || $src === '') {
            $errors[] = $path . '.src_required';
        } else {
            self::assertAllowedUrl($src, $path . '.src', $errors);
            if (preg_match('/\.svg(\?|#|$)/i', $src)) {
                $errors[] = $path . '.src_svg_forbidden';
            }
        }
        $alt = $image['alt'] ?? null;
        if (!is_string($alt) || trim($alt) === '') {
            $errors[] = $path . '.alt_required';
        } else {
            self::assertPlainString($alt, $path . '.alt', self::MAX_ALT_LEN, $errors);
        }
    }

    /**
     * @param list<string> $errors
     */
    private static function validateSidebarBadge(mixed $sidebar, array &$errors): void
    {
        if ($sidebar === null) {
            return;
        }
        if (!is_array($sidebar)) {
            $errors[] = 'sidebar_must_be_object';

            return;
        }
        if (!array_key_exists('badge', $sidebar)) {
            return;
        }
        $badge = $sidebar['badge'];
        if ($badge === null) {
            return;
        }
        if (!is_array($badge)) {
            $errors[] = 'sidebar.badge_invalid';

            return;
        }

        $label = $badge['label'] ?? null;
        if (!is_string($label)) {
            $errors[] = 'sidebar.badge.label_invalid';

            return;
        }
        $labelTrim = trim($label);
        $len = mb_strlen($labelTrim, 'UTF-8');
        if ($len < self::BADGE_LABEL_MIN || $len > self::BADGE_LABEL_MAX) {
            $errors[] = 'sidebar.badge.label_length';
        }
        self::assertPlainString($labelTrim, 'sidebar.badge.label', self::BADGE_LABEL_MAX, $errors);

        $variant = $badge['variant'] ?? null;
        if (!is_string($variant) || !isset(self::BADGE_VARIANTS[$variant])) {
            $errors[] = 'sidebar.badge.variant_invalid';
        }

        $aria = $badge['ariaLabel'] ?? null;
        if (!is_string($aria)) {
            $errors[] = 'sidebar.badge.ariaLabel_invalid';
        } else {
            $ariaTrim = trim($aria);
            if ($ariaTrim === '' || mb_strlen($ariaTrim, 'UTF-8') > self::BADGE_ARIA_MAX) {
                $errors[] = 'sidebar.badge.ariaLabel_length';
            }
            self::assertPlainString($ariaTrim, 'sidebar.badge.ariaLabel', self::BADGE_ARIA_MAX, $errors);
        }

        $unread = $badge['hasUnread'] ?? false;
        if (!is_bool($unread)) {
            $errors[] = 'sidebar.badge.hasUnread_invalid';
        }
    }

    /**
     * @param list<string> $errors
     */
    private static function assertPlainStringRequired(mixed $value, string $path, int $maxLen, array &$errors): void
    {
        if (!is_string($value) || trim($value) === '') {
            $errors[] = $path . '_required';

            return;
        }
        self::assertPlainString(trim($value), $path, $maxLen, $errors);
    }

    /**
     * @param list<string> $errors
     */
    private static function assertPlainStringOptional(mixed $value, string $path, int $maxLen, array &$errors): void
    {
        if ($value === null || $value === '') {
            return;
        }
        if (!is_string($value)) {
            $errors[] = $path . '_type';

            return;
        }
        self::assertPlainString($value, $path, $maxLen, $errors);
    }

    /**
     * @param list<string> $errors
     */
    private static function assertPlainString(string $value, string $path, int $maxLen, array &$errors): void
    {
        if (mb_strlen($value, 'UTF-8') > $maxLen) {
            $errors[] = $path . '_too_long';
        }
        if (self::hasForbiddenMarkup($value)) {
            $errors[] = $path . '_unsafe';

            return;
        }
        if (stripos($value, '<svg') !== false) {
            $errors[] = $path . '_svg_forbidden';
        }
    }

    private static function hasForbiddenMarkup(string $value): bool
    {
        $lower = strtolower($value);

        foreach (['<script', '</script', '<iframe', 'javascript:', '<object', '<embed', 'data:text/html'] as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return (bool) preg_match('/on[a-z]+\s*=/i', $value);
    }

    /**
     * @param list<string> $errors
     */
    private static function assertAllowedUrl(string $url, string $path, array &$errors): void
    {
        $trim = trim($url);
        if ($trim === '') {
            $errors[] = $path . '_empty';

            return;
        }
        if (!str_starts_with($trim, 'https://')) {
            $errors[] = $path . '_not_https';

            return;
        }
        if (stripos($trim, '<svg') !== false || preg_match('/\.svg(\?|#|$)/i', $trim)) {
            $errors[] = $path . '_svg_forbidden';
        }

        $hostRaw = parse_url($trim, PHP_URL_HOST);
        if (!is_string($hostRaw) || $hostRaw === '') {
            $errors[] = $path . '_host_invalid';

            return;
        }

        $host = strtolower($hostRaw);
        $ok = false;
        foreach (self::ALLOWED_HOSTS as $allowed) {
            if ($host === $allowed) {
                $ok = true;

                break;
            }
        }
        if (!$ok) {
            $errors[] = $path . '_host_not_allowed';
        }
    }

    private static function isIso8601Timestamp(string $value): bool
    {
        if (
            preg_match(
                '/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}:\d{2}(Z|[+-]\d{2}:\d{2})?)?$/',
                $value,
            ) !== 1
        ) {
            return false;
        }

        return strtotime($value) !== false;
    }

    /**
     * @param list<string> $errors
     */
    private static function failure(array $errors): EditorialValidationResult
    {
        return new EditorialValidationResult(false, array_values(array_unique($errors)));
    }

    private static function success(): EditorialValidationResult
    {
        return new EditorialValidationResult(true);
    }
}
