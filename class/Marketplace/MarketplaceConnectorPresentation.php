<?php

/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

namespace Knot\Marketplace;

use Knot\Licensing\InstallationIdentity;
use Knot\Repository\KnotConfigRepository;

/**
 * Coordinates fetching + caching the connector presentation snippet for HTTP entrypoints.
 */
final class MarketplaceConnectorPresentation
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function loadSnippetConnectors(\DoliDB $db): array
    {
        if (!KnotMarketplacePresentation::connectorMetadataFetchEnabled()) {
            return [];
        }
        $configRepo = new KnotConfigRepository($db);
        $cache = new ConnectorPresentationCache($configRepo);
        $identity = new InstallationIdentity($configRepo, $db);
        $client = new ConnectorPresentationSnippetClient(
            CatalogClientFactory::resolveBaseUrl(),
            ConnectorPresentationSnippetClient::DEFAULT_PATH,
            null,
            null,
            $identity->deploymentToken(),
            $identity->deploymentNonce(),
        );

        return $cache->get($client)['connectors'];
    }

    /**
     * @return array{lang: string, connectors: array<int, array<string, mixed>>}
     */
    public static function resolveSnippetForConnectorsRequests(\DoliDB $db): array
    {
        $lang = 'en';
        if (
            isset($GLOBALS['langs'])
            && is_object($GLOBALS['langs'])
            && isset($GLOBALS['langs']->defaultlang)
            && is_string($GLOBALS['langs']->defaultlang)
        ) {
            $parts = preg_split('/[_-]/', $GLOBALS['langs']->defaultlang) ?: [];
            $iso = strtolower((string) ($parts[0] ?? 'en'));
            if ($iso !== '') {
                $lang = $iso;
            }
        }

        return ['lang' => $lang, 'connectors' => self::loadSnippetConnectors($db)];
    }
}
