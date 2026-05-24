<?php

/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

namespace Knot\Marketplace;

use Knot\Repository\KnotConfigRepository;

/**
 * Canonical factory for {@see CatalogClient} so HTTP catalog/template fetches
 * honour Dolibarr `MAIN_KNOT_LICENSE_BASE_URL`, matching licensing bootstrap.
 */
final class CatalogClientFactory
{
    /**
     * Resolve the licence backend root URL from Dolibarr globals.
     *
     * Empty configuration falls back to {@see CatalogClient::DEFAULT_BASE_URL}.
     */
    public static function resolveBaseUrl(): string
    {
        if (!function_exists('getDolGlobalString')) {
            return CatalogClient::DEFAULT_BASE_URL;
        }
        $raw = trim((string) getDolGlobalString('MAIN_KNOT_LICENSE_BASE_URL', ''));

        return $raw !== '' ? $raw : CatalogClient::DEFAULT_BASE_URL;
    }

    public static function create(?string $baseUrl = null, ?\DoliDB $db = null): CatalogClient
    {
        $base = $baseUrl ?? self::resolveBaseUrl();
        if ($db === null) {
            return new CatalogClient($base);
        }

        $identity = new \Knot\Licensing\InstallationIdentity(new KnotConfigRepository($db), $db);

        return new CatalogClient(
            $base,
            CatalogClient::DEFAULT_TIMEOUT_S,
            $identity->deploymentToken(),
            $identity->deploymentNonce(),
        );
    }
}
