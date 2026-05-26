<?php

/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

namespace Knot\Marketplace;

use Knot\Repository\KnotConfigRepository;

/**
 * Resolves the Marketplace sidebar chroma-dot payload from persisted catalog cache rows.
 *
 * Validates the enclosing editorial envelope before exposing badge fields — if the licence
 * document or merge result is malformed, callers get {@code null} so the SPA can hide chrome.
 */
final class SidebarBadge
{
    /**
     * Hydrate sidebar badge chrome from persisted cache JSON keyed per language (see {@see CatalogCache}).
     *
     * @return array{
     *   label: string,
     *   variant: string,
     *   ariaLabel: string,
     *   hasUnread: bool
     * }|null
     */
    public static function fromConfig(KnotConfigRepository $config, string $isoLang): ?array
    {
        $key = CatalogCache::configKeyForLang($isoLang);
        $raw = $config->get($key);
        if ($raw === null || $raw === '') {
            return null;
        }
        /** @var mixed $decoded */
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }
        /** @var mixed $editorial */
        $editorial = $decoded['editorial'] ?? null;
        if (!is_array($editorial)) {
            return null;
        }

        /** @var array<string, mixed> $editorialTyped */
        $editorialTyped = $editorial;

        return self::fromEditorial($editorialTyped);
    }

    /**
     * @param array<string, mixed>|null $editorial Merged or remote editorial subtree (single language root).
     *
     * @return array{
     *   label: string,
     *   variant: string,
     *   ariaLabel: string,
     *   hasUnread: bool
     * }|null
     */
    public static function fromEditorial(?array $editorial): ?array
    {
        if ($editorial === null) {
            return null;
        }
        $result = EditorialValidator::validate($editorial);
        if (!$result->isValid()) {
            return null;
        }
        /** @var mixed $badge */
        $badge = $editorial['sidebar']['badge'] ?? null;
        if (!is_array($badge)) {
            return null;
        }

        $label = trim((string) ($badge['label'] ?? ''));

        $variant = (string) ($badge['variant'] ?? '');

        $ariaLabel = trim((string) ($badge['ariaLabel'] ?? ''));

        $hasUnread = isset($badge['hasUnread']) && $badge['hasUnread'] === true;

        if ($label === '' || $variant === '') {
            return null;
        }

        if ($ariaLabel === '') {
            $ariaLabel = $label;
        }

        return [
            'label' => $label,
            'variant' => $variant,
            'ariaLabel' => $ariaLabel,
            'hasUnread' => $hasUnread,
        ];
    }
}
