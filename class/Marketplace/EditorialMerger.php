<?php

/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

namespace Knot\Marketplace;

/**
 * Overlay remote (license-backed) editorial on top of bundled fallback content.
 *
 * Block lists are keyed by stable {@code id} fields: newer properties from the patch
 * win on a key-by-key basis for matched blocks ( {@see array_merge} semantics ).
 */
final class EditorialMerger
{
    /**
     * True when remote editorial embeds {@code meta.killSwitch: true} — Core skips merge and
     * uses bundled fallback only (defence in depth beside license server EditorialLoader).
     *
     * @param array<string, mixed>|null $overlay Editorial fragment from CatalogCache (may embed meta).
     */
    public static function remoteBlockedByKillSwitch(?array $overlay): bool
    {
        if ($overlay === null || $overlay === []) {
            return false;
        }

        $meta = $overlay['meta'] ?? null;

        return is_array($meta) && array_key_exists('killSwitch', $meta) && $meta['killSwitch'] === true;
    }

    /**
     * @param array<string, mixed> $fallback Language-specific fallback root (already unwrapped).
     * @param array<string, mixed>|null $overlay Remote editorial fragment for the same language.
     *
     * @return array<string, mixed>
     */
    public static function merge(array $fallback, ?array $overlay): array
    {
        if ($overlay === null || $overlay === []) {
            return $fallback;
        }

        /** @var array<string, mixed> $out */
        $out = $fallback;

        if (isset($overlay['version']) && is_int($overlay['version'])) {
            $out['version'] = $overlay['version'];
        }

        if (isset($overlay['home']) && is_array($overlay['home'])) {
            $baseHome = is_array($fallback['home'] ?? null) ? $fallback['home'] : [];
            /** @var array<string, mixed> $baseHome */
            /** @var array<string, mixed> $overlayHome */
            $overlayHome = $overlay['home'];
            $out['home'] = self::preferDiscoveryOverLegacyLayout(
                self::mergeSectionWithLayout($baseHome, $overlayHome),
            );
        }

        if (isset($overlay['ecosystem']) && is_array($overlay['ecosystem'])) {
            $baseEco = is_array($fallback['ecosystem'] ?? null) ? $fallback['ecosystem'] : [];
            /** @var array<string, mixed> $baseEco */
            /** @var array<string, mixed> $ecoOverlay */
            $ecoOverlay = $overlay['ecosystem'];
            $out['ecosystem'] = self::mergeSectionWithLayout($baseEco, $ecoOverlay);
        }

        if (isset($overlay['productPages']) && is_array($overlay['productPages'])) {
            $basePages = is_array($fallback['productPages'] ?? null) ? $fallback['productPages'] : [];
            /** @var array<string, mixed> $basePages */
            /** @var array<string, mixed> $pagesOverlay */
            $pagesOverlay = $overlay['productPages'];
            $out['productPages'] = self::mergeSlugKeyedPages($basePages, $pagesOverlay);
        }

        if (isset($overlay['templatePages']) && is_array($overlay['templatePages'])) {
            $basePages = is_array($fallback['templatePages'] ?? null) ? $fallback['templatePages'] : [];
            /** @var array<string, mixed> $basePages */
            /** @var array<string, mixed> $pagesOverlay */
            $pagesOverlay = $overlay['templatePages'];
            $out['templatePages'] = self::mergeSlugKeyedPages($basePages, $pagesOverlay);
        }

        if (isset($overlay['newsPages']) && is_array($overlay['newsPages'])) {
            $basePages = is_array($fallback['newsPages'] ?? null) ? $fallback['newsPages'] : [];
            /** @var array<string, mixed> $basePages */
            /** @var array<string, mixed> $pagesOverlay */
            $pagesOverlay = $overlay['newsPages'];
            $out['newsPages'] = self::mergeSlugKeyedPages($basePages, $pagesOverlay);
        }

        if (isset($overlay['sidebar']) && is_array($overlay['sidebar'])) {
            $baseSidebar = is_array($fallback['sidebar'] ?? null) ? $fallback['sidebar'] : [];
            $out['sidebar'] = self::mergeSidebar($baseSidebar, $overlay['sidebar']);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $patch
     *
     * @return array<string, mixed>
     */
    private static function mergeSidebar(array $base, array $patch): array
    {
        $out = array_merge($base, $patch);
        if (
            isset($base['badge'], $patch['badge'])
            && is_array($base['badge'])
            && is_array($patch['badge'])
        ) {
            $out['badge'] = array_merge($base['badge'], $patch['badge']);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $fallback
     * @param array<string, mixed> $overlay
     *
     * @return array<string, mixed>
     */
    private static function mergeSectionWithLayout(array $fallback, array $overlay): array
    {
        $merged = array_merge($fallback, $overlay);
        if (!isset($overlay['layout'], $fallback['layout']) || !is_array($overlay['layout']) || !is_array($fallback['layout'])) {
            return $merged;
        }
        $merged['layout'] = self::mergeLayouts($fallback['layout'], $overlay['layout']);

        return $merged;
    }

    /**
     * v2 discovery (spotlight / collections) replaces legacy block {@code layout} lists.
     *
     * @param array<string, mixed> $home
     *
     * @return array<string, mixed>
     */
    private static function preferDiscoveryOverLegacyLayout(array $home): array
    {
        $spotlightItems = $home['spotlight']['items'] ?? null;
        $hasSpotlight = is_array($spotlightItems) && $spotlightItems !== [];
        $collections = $home['collections'] ?? null;
        $hasCollections = is_array($collections) && $collections !== [];

        if (($hasSpotlight || $hasCollections) && isset($home['layout'])) {
            unset($home['layout']);
        }

        return $home;
    }

    /**
     * @param array<int|string, mixed> $fallbackBlocks
     * @param array<int|string, mixed> $overlayBlocks
     *
     * @return array<int, array<string, mixed>>
     */
    private static function mergeLayouts(array $fallbackBlocks, array $overlayBlocks): array
    {
        /** @var array<string, array<string, mixed>> $byPatchId */
        $byPatchId = [];
        foreach ($overlayBlocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            $id = isset($block['id']) ? (string) $block['id'] : '';
            if ($id === '') {
                continue;
            }
            $byPatchId[$id] = $block;
        }

        /** @var array<int, array<string, mixed>> $out */
        $out = [];
        $seen = [];

        foreach ($fallbackBlocks as $fb) {
            if (!is_array($fb)) {
                continue;
            }
            /** @var array<string, mixed> $fbTyped */
            $fbTyped = $fb;
            $id = isset($fbTyped['id']) ? (string) $fbTyped['id'] : '';
            if ($id !== '' && isset($byPatchId[$id])) {
                /** @var array<string, mixed> $patchTyped */
                $patchTyped = $byPatchId[$id];
                $patchType = isset($patchTyped['type']) && is_string($patchTyped['type']) ? trim((string) $patchTyped['type']) : '';
                if ($patchType !== '' && !EditorialValidator::isKnownBlockType($patchType)) {
                    $out[] = $fbTyped;
                } else {
                    $mergedBlock = array_merge($fbTyped, $patchTyped);
                    if (isset($fbTyped['items'], $patchTyped['items']) && is_array($fbTyped['items']) && is_array($patchTyped['items'])) {
                        $mergedBlock['items'] = self::mergeEcosystemItems($fbTyped['items'], $patchTyped['items']);
                    }
                    $out[] = $mergedBlock;
                }
                $seen[$id] = true;
            } else {
                $out[] = $fbTyped;
                if ($id !== '') {
                    $seen[$id] = true;
                }
            }
        }

        foreach ($overlayBlocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            $id = isset($block['id']) ? (string) $block['id'] : '';
            if ($id === '' || isset($seen[$id])) {
                continue;
            }
            /** @var array<string, mixed> $blockTyped */
            $blockTyped = $block;
            $ovlType = isset($blockTyped['type']) && is_string($blockTyped['type'])
                ? trim((string) $blockTyped['type'])
                : '';
            if ($ovlType === '' || !EditorialValidator::isKnownBlockType($ovlType)) {
                continue;
            }
            $out[] = $blockTyped;
        }

        return $out;
    }

    /**
     * @param array<int|string, mixed> $fallbackItems
     * @param array<int|string, mixed> $patchItems
     *
     * @return array<int, array<string, mixed>>
     */
    private static function mergeEcosystemItems(array $fallbackItems, array $patchItems): array
    {
        /** @var array<string, array<string, mixed>> $byKey */
        $byKey = [];
        foreach ($patchItems as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = isset($row['title']) ? trim((string) $row['title']) : '';
            if ($key === '') {
                continue;
            }
            $norm = strtolower($key);
            $byKey[$norm] = $row;
        }

        /** @var array<int, array<string, mixed>> $out */
        $out = [];

        foreach ($fallbackItems as $row) {
            if (!is_array($row)) {
                continue;
            }
            /** @var array<string, mixed> $rowTyped */
            $rowTyped = $row;
            $key = isset($rowTyped['title']) ? strtolower(trim((string) $rowTyped['title'])) : '';
            if ($key !== '' && isset($byKey[$key])) {
                $out[] = array_merge($rowTyped, $byKey[$key]);

                continue;
            }
            $out[] = $rowTyped;
        }

        foreach ($patchItems as $row) {
            if (!is_array($row)) {
                continue;
            }
            /** @var array<string, mixed> $rowTyped */
            $rowTyped = $row;
            $title = isset($rowTyped['title']) ? strtolower(trim((string) $rowTyped['title'])) : '';
            if ($title === '') {
                continue;
            }
            $already = false;
            foreach ($out as $exist) {
                $t = strtolower(trim((string) ($exist['title'] ?? '')));
                if ($t === $title) {
                    $already = true;

                    break;
                }
            }
            if (!$already) {
                $out[] = $rowTyped;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $fallbackBySlug
     * @param array<string, mixed> $overlayBySlug
     *
     * @return array<string, mixed>
     */
    private static function mergeSlugKeyedPages(array $fallbackBySlug, array $overlayBySlug): array
    {
        /** @var array<string, mixed> $out */
        $out = [];

        foreach ($fallbackBySlug as $slug => $page) {
            if (!isset($overlayBySlug[$slug]) || !is_array($overlayBySlug[$slug])) {
                if (is_array($page)) {
                    $out[$slug] = $page;
                }

                continue;
            }
            if (!is_array($page)) {
                $out[$slug] = $overlayBySlug[$slug];

                continue;
            }
            $out[$slug] = self::mergeSectionWithLayout($page, $overlayBySlug[$slug]);
        }

        foreach ($overlayBySlug as $slug => $patch) {
            if (isset($out[$slug]) || !is_array($patch)) {
                continue;
            }
            $out[$slug] = $patch;
        }

        return $out;
    }
}
