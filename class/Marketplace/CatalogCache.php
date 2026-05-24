<?php

/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

namespace Knot\Marketplace;

use Knot\Repository\KnotConfigRepository;

/**
 * V2.5.0a — Marketplace catalog cache.
 *
 * Persists the last successful response from {@see CatalogClient::fetch()}
 * in `llx_knot_config` (key `marketplace.catalog_cache`) so that the
 * marketplace UI keeps rendering even when `license.knot.tools` is briefly
 * unreachable. Refreshed on every read older than {@see TTL_SECONDS}.
 *
 * The cached entry shape is intentionally tiny — slug + label +
 * description + pricing — to stay well below the LONGTEXT cap and to
 * make the UI render snappy even on the first cold load.
 */
final class CatalogCache
{
    public const CONFIG_KEY = 'marketplace.catalog_cache';
    public const TTL_SECONDS = 21600; // 6h

    public function __construct(private readonly KnotConfigRepository $config)
    {
    }

    /**
     * Get the catalog using cache-aside semantics:
     *   - return cached copy when fresh
     *   - on cache miss / staleness, fetch via $client and persist
     *   - on fetch failure when stale, return the stale copy with
     *     {@see fromCache: true, stale: true} markers so the UI can
     *     surface a soft warning
     *
     * @return array{
     *     products: array<int, array<string, mixed>>,
     *     fromCache: bool,
     *     stale: bool,
     *     error: ?string,
     *     live_catalog_fetched: bool
     * }
     */
    public function get(CatalogClient $client): array
    {
        $now = time();
        $cached = $this->readCached();

        if ($cached !== null && ($now - $cached['fetchedAt']) < self::TTL_SECONDS) {
            return [
                'products' => $cached['products'],
                'fromCache' => true,
                'stale' => false,
                'error' => null,
                'live_catalog_fetched' => false,
            ];
        }

        $live = $client->fetch();
        if ($live !== []) {
            $this->writeCached($live, $now);
            return [
                'products' => $live,
                'fromCache' => false,
                'stale' => false,
                'error' => null,
                'live_catalog_fetched' => true,
            ];
        }

        if ($cached !== null) {
            return [
                'products' => $cached['products'],
                'fromCache' => true,
                'stale' => true,
                'error' => $client->lastError(),
                'live_catalog_fetched' => false,
            ];
        }

        return [
            'products' => [],
            'fromCache' => false,
            'stale' => false,
            'error' => $client->lastError(),
            'live_catalog_fetched' => false,
        ];
    }

    /**
     * Drop the cached entry so the next {@see get()} call goes
     * straight to the network. Used by the admin "refresh catalog"
     * action exposed through `api/marketplace.php?action=refresh`.
     */
    public function invalidate(): void
    {
        $this->config->delete(self::CONFIG_KEY);
    }

    /**
     * @return array{products: array<int, array<string, mixed>>, fetchedAt: int}|null
     */
    private function readCached(): ?array
    {
        $raw = $this->config->get(self::CONFIG_KEY);
        if ($raw === null || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['products'], $decoded['fetchedAt'])) {
            return null;
        }
        if (!is_array($decoded['products'])) {
            return null;
        }
        return [
            'products' => $decoded['products'],
            'fetchedAt' => (int) $decoded['fetchedAt'],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $products
     */
    private function writeCached(array $products, int $now): void
    {
        $payload = json_encode([
            'products' => $products,
            'fetchedAt' => $now,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            return;
        }
        $this->config->set(self::CONFIG_KEY, $payload);
    }
}
