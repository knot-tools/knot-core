<?php

/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

namespace Knot\Marketplace;

/**
 * Reads operator toggles controlling remote presentation payloads and SPA marketplace chrome.
 *
 * Defaults keep existing behaviour (`enabled`).
 */
final class KnotMarketplacePresentation
{
    public static function connectorMetadataFetchEnabled(): bool
    {
        if (!function_exists('getDolGlobalString')) {
            return true;
        }
        $raw = trim((string) getDolGlobalString('KNOT_CONNECTOR_METADATA_FETCH', '1'));
        $low = strtolower($raw);

        return !($raw === '' || $low === '0' || $low === 'false' || $low === 'off' || $low === 'no');
    }

    public static function marketplaceUiEnabled(): bool
    {
        if (!function_exists('getDolGlobalString')) {
            return true;
        }
        $raw = trim((string) getDolGlobalString('KNOT_MARKETPLACE_UI_ENABLED', '1'));
        $low = strtolower($raw);

        return !($low === '0' || $low === 'false' || $low === 'off' || $low === 'no');
    }
}
