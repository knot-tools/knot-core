<?php

/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

namespace Knot\Marketplace;

use Knot\Repository\KnotConfigRepository;

/**
 * Cache-aside for {@see ConnectorPresentationSnippetClient}.
 */
final class ConnectorPresentationCache
{
    public const CONFIG_KEY = 'marketplace.connector_presentation_cache';

    public const TTL_SECONDS = 21600;

    public function __construct(private readonly KnotConfigRepository $config)
    {
    }

    /**
     * @return array{
     *   connectors: array<int, array<string, mixed>>,
     *   snippetVersion: int,
     *   fromCache: bool,
     *   stale: bool,
     *   error: ?string
     * }
     */
    public function get(ConnectorPresentationSnippetClient $client): array
    {
        if (!KnotMarketplacePresentation::connectorMetadataFetchEnabled()) {
            return [
                'connectors' => [],
                'snippetVersion' => 0,
                'fromCache' => false,
                'stale' => false,
                'error' => 'fetch_disabled_by_operator',
            ];
        }

        $now = time();
        $cached = $this->readCached();

        if ($cached !== null && ($now - $cached['fetchedAt']) < self::TTL_SECONDS) {
            return [
                'connectors' => $cached['connectors'],
                'snippetVersion' => $cached['snippetVersion'],
                'fromCache' => true,
                'stale' => false,
                'error' => null,
            ];
        }

        $live = $client->fetch();
        if ($live['connectors'] !== []) {
            $this->writeCached($live, $now);

            return [
                'connectors' => $live['connectors'],
                'snippetVersion' => $live['snippetVersion'],
                'fromCache' => false,
                'stale' => false,
                'error' => null,
            ];
        }

        if ($cached !== null) {
            return [
                'connectors' => $cached['connectors'],
                'snippetVersion' => $cached['snippetVersion'],
                'fromCache' => true,
                'stale' => true,
                'error' => $client->lastError(),
            ];
        }

        return [
            'connectors' => [],
            'snippetVersion' => 0,
            'fromCache' => false,
            'stale' => false,
            'error' => $client->lastError(),
        ];
    }

    public function invalidate(): void
    {
        $this->config->delete(self::CONFIG_KEY);
    }

    /**
     * @return array{connectors: array<int, array<string, mixed>>, snippetVersion: int, fetchedAt: int}|null
     */
    private function readCached(): ?array
    {
        $raw = $this->config->get(self::CONFIG_KEY);
        if ($raw === null || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['connectors'], $decoded['fetchedAt'])) {
            return null;
        }
        if (!is_array($decoded['connectors'])) {
            return null;
        }

        return [
            'connectors' => $decoded['connectors'],
            'snippetVersion' => isset($decoded['snippetVersion']) && is_numeric($decoded['snippetVersion'])
                ? (int) $decoded['snippetVersion']
                : 0,
            'fetchedAt' => (int) $decoded['fetchedAt'],
        ];
    }

    /**
     * @param array{
     *   snippetVersion: int,
     *   fetchedRecommendation?: bool,
     *   connectors: array<int, array<string, mixed>>
     * } $payload
     */
    private function writeCached(array $payload, int $now): void
    {
        $package = json_encode([
            'connectors' => $payload['connectors'],
            'snippetVersion' => $payload['snippetVersion'],
            'fetchedAt' => $now,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($package === false) {
            return;
        }
        $this->config->set(self::CONFIG_KEY, $package);
    }
}
