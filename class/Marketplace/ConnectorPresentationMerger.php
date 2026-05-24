<?php

/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

namespace Knot\Marketplace;

/**
 * Whitelist merges remote presentation blobs into palette rows keyed by manifest extension id + FQCN.
 */
final class ConnectorPresentationMerger
{
    /** @var array<string, true> */
    private const ALLOWED_CATEGORIES = [
        'trigger' => true,
        'logic' => true,
        'communication' => true,
        'notification' => true,
        'ai' => true,
        'dolibarr' => true,
        'saas' => true,
        'universal' => true,
        'other' => true,
    ];

    /**
     * @param array<int, array<string, mixed>> $snippetConnectors
     * @return array<string, array<string, mixed>>
     */
    public static function buildSnippetLookup(array $snippetConnectors): array
    {
        /** @var array<string, array<string, mixed>> $byKey */
        $byKey = [];
        foreach ($snippetConnectors as $entry) {
            $eid = strtolower(trim((string) ($entry['extensionId'] ?? '')));
            $fqcn = trim((string) ($entry['fqcn'] ?? ''));
            if ($eid === '' || $fqcn === '') {
                continue;
            }
            $byKey[self::compositeKey($eid, $fqcn)] = $entry;
        }

        return $byKey;
    }

    /**
     * @param array<int, array<string, mixed>> $paletteRows Rows from ConnectorRegistry palette builder.
     * @param array<int, array<string, mixed>> $snippetConnectors Remote whitelist entries (extensionId+fqcn).
     * @param string $isoLang Prefer `fr`, `en`, ... (first two chars honoured).
     * @return array<int, array<string, mixed>>
     */
    public static function mergePaletteRows(array $paletteRows, array $snippetConnectors, string $isoLang = 'en'): array
    {
        $langNorm = strtolower(substr(trim($isoLang !== '' ? $isoLang : 'en'), 0, 8));
        if ($langNorm === '') {
            $langNorm = 'en';
        }

        $byKey = self::buildSnippetLookup($snippetConnectors);

        foreach ($paletteRows as $idx => $row) {
            $paletteRows[$idx] = self::applyRowOverrides($row, $byKey, $langNorm);
        }

        return $paletteRows;
    }

    /**
     * Applies presentation overrides keyed by lowercase extensionManifestId+fqcn to a flattened metadata map.
     *
     * Only safe keys are merged (label/category/description/order).
     *
     * @param array<string, mixed> $metadata
     * @param array<string, array<string, mixed>> $byKey
     * @return array<string, mixed>
     */
    public static function enrichMetadata(array $metadata, array $byKey, string $extManifestId, string $fqcn, string $isoLang): array
    {
        $manifestIdLc = strtolower(trim($extManifestId));
        $fqcnTrim = trim($fqcn);
        if ($manifestIdLc === '' || $fqcnTrim === '') {
            return $metadata;
        }
        $combo = self::compositeKey($manifestIdLc, $fqcnTrim);

        return self::applyMetadataOverridesFromEntry($metadata, $byKey[$combo] ?? null, strtolower(substr($isoLang !== '' ? $isoLang : 'en', 0, 8)));
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, array<string, mixed>> $byKey
     *
     * @return array<string, mixed>
     */
    private static function applyRowOverrides(
        array $row,
        array $byKey,
        string $langNorm,
    ): array {
        $extIdLc = strtolower(trim((string) ($row['extensionManifestId'] ?? '')));
        $fqcnTrim = trim((string) ($row['fqcn'] ?? ''));
        if ($extIdLc === '' || $fqcnTrim === '') {
            return $row;
        }
        $composite = self::compositeKey($extIdLc, $fqcnTrim);
        if (!isset($byKey[$composite])) {
            return $row;
        }

        return self::applyMetadataOverridesFromEntry($row, $byKey[$composite], $langNorm);
    }

    /**
     * @param array<string, mixed>|null $entry
     * @param array<string, mixed> $target Any row/metadata map exposing label/description/category/etc.
     * @return array<string, mixed>
     */
    private static function applyMetadataOverridesFromEntry(array $target, ?array $entry, string $langNorm): array
    {
        if ($entry === null || $entry === []) {
            return $target;
        }
        if (
            isset($entry['paletteCategory'])
            && is_string($entry['paletteCategory'])
            && isset(self::ALLOWED_CATEGORIES[strtolower(trim($entry['paletteCategory']))])
        ) {
            $target['category'] = strtolower(trim($entry['paletteCategory']));
        }
        $labelResolved = self::resolveI18nString($entry['displayLabel'] ?? null, $langNorm);
        if ($labelResolved !== null && $labelResolved !== '') {
            $target['label'] = $labelResolved;
        }
        $blurbResolved = self::resolveI18nString($entry['blurb'] ?? null, $langNorm);
        if ($blurbResolved !== null && $blurbResolved !== '') {
            $target['description'] = $blurbResolved;
        }
        if (isset($entry['sortOrder']) && is_numeric($entry['sortOrder'])) {
            $target['snippetSortOrder'] = (int) $entry['sortOrder'];
        }

        return $target;
    }

    private static function compositeKey(string $extIdLc, string $fqcn): string
    {
        return strtolower($extIdLc) . '|' . trim($fqcn);
    }

    /**
     * @param mixed $value map<string,string>|string|null
     */
    private static function resolveI18nString(mixed $value, string $langNorm): ?string
    {
        if (is_string($value)) {
            return $value !== '' ? $value : null;
        }
        if (!is_array($value)) {
            return null;
        }
        foreach ([$langNorm, $langNorm === 'fr' ? 'en' : 'fr', 'en'] as $try) {
            if (isset($value[$try]) && is_string($value[$try]) && trim($value[$try]) !== '') {
                return $value[$try];
            }
        }
        foreach ($value as $v) {
            if (is_string($v) && trim($v) !== '') {
                return $v;
            }
        }

        return null;
    }
}
