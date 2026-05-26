<?php

/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

namespace Knot\Marketplace;

use Knot\Repository\KnotConfigRepository;

/**
 * V2.5.0a — Marketplace catalog cache.
 *
 * Persists the last successful response from {@see CatalogClient::fetchCatalog()}
 * under {@see configKeyForLang()} so storefront chrome can stay bilingual while
 * the extensions list keeps loading when `license.knot.tools` flakes.
 *
 * Each row stores products plus optional licence {@code editorial} blobs. TTL uses
 * {@see TTL_SECONDS} plus pseudo-random jitter of ±10% (stored per snapshot) so fleet-wide
 * refetch waves stay smeared without long one-sided tails.
 */
final class CatalogCache
{
    /** @deprecated Use {@see configKeyForLang()} — kept only for invalidate sweeps */
    public const CONFIG_KEY = 'marketplace.catalog_cache';

    public const TTL_SECONDS = 21600; // 6h

    /** Jitter amplitude as a percentage of {@see TTL_SECONDS} (±basis). */
    public const TTL_JITTER_PERCENT = 10;

    public function __construct(private readonly KnotConfigRepository $config)
    {
    }

    /**
     * Normalises a Dolibarr / browser locale string (`fr_FR`, …) down to ISO639-1.
     */
    public static function normalizeCatalogLang(string $raw): string
    {
        $lower = strtolower(trim($raw));
        if ($lower === '') {
            return 'en';
        }
        if (preg_match('/^[a-z]{2}/', $lower, $matches)) {
            return $matches[0];
        }

        return 'en';
    }

    /**
     * Config row key persisted in `llx_knot_config`.
     */
    public static function configKeyForLang(string $iso639): string
    {
        $norm = strtolower(preg_replace('/[^a-z0-9_-]+/', '', $iso639) ?? '');
        if ($norm === '') {
            $norm = 'en';
        }

        return 'marketplace.catalog_cache.' . substr($norm, 0, 8);
    }

    /**
     * @return array{
     *     products: array<int, array<string, mixed>>,
     *     editorial: array<string, mixed>|null,
     *     fromCache: bool,
     *     stale: bool,
     *     error: ?string,
     *     live_catalog_fetched: bool
     * }
     */
    public function get(CatalogClient $client, string $lang = 'en'): array
    {
        $langNorm = self::normalizeCatalogLang($lang);
        $now = time();
        $cached = $this->readCached($langNorm);

        $effectiveTtl = $cached['ttlSeconds'] ?? self::TTL_SECONDS;
        if ($cached !== null && ($now - $cached['fetchedAt']) < $effectiveTtl) {
            return [
                'products' => $cached['products'],
                'editorial' => $cached['editorial'],
                'fromCache' => true,
                'stale' => false,
                'error' => null,
                'live_catalog_fetched' => false,
            ];
        }

        $live = $client->fetchCatalog(null, $langNorm);
        if ($live['products'] !== []) {
            $span = (int) floor(self::TTL_SECONDS * (self::TTL_JITTER_PERCENT / 100));
            $span = max(1, $span);
            $ttlWithJitter = self::TTL_SECONDS + random_int(-$span, $span);
            $this->writeCached(
                $langNorm,
                $live['products'],
                $live['editorial'],
                $now,
                $ttlWithJitter,
            );

            return [
                'products' => $live['products'],
                'editorial' => $live['editorial'],
                'fromCache' => false,
                'stale' => false,
                'error' => null,
                'live_catalog_fetched' => true,
            ];
        }

        if ($cached !== null) {
            return [
                'products' => $cached['products'],
                'editorial' => $cached['editorial'],
                'fromCache' => true,
                'stale' => true,
                'error' => $client->lastError(),
                'live_catalog_fetched' => false,
            ];
        }

        return [
            'products' => [],
            'editorial' => null,
            'fromCache' => false,
            'stale' => false,
            'error' => $client->lastError(),
            'live_catalog_fetched' => false,
        ];
    }

    /**
     * Drop cached rows so the next {@see get()} round-trip hits the network.
     */
    public function invalidate(): void
    {
        $this->config->delete(self::CONFIG_KEY);
        foreach (['en', 'fr', 'de', 'es', 'it', 'nl', 'pt', 'pl'] as $iso) {
            $this->config->delete(self::configKeyForLang($iso));
        }
    }

    /**
     * @return array{
     *   products: array<int, array<string, mixed>>,
     *   editorial: array<string, mixed>|null,
     *   fetchedAt: int,
     *   ttlSeconds: int
     * }|null
     */
    private function readCached(string $langNorm): ?array
    {
        $raw = $this->config->get(self::configKeyForLang($langNorm));
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
        $editorial = null;
        if (array_key_exists('editorial', $decoded)) {
            $editorial = is_array($decoded['editorial']) ? $decoded['editorial'] : null;
        }

        return [
            'products' => $decoded['products'],
            'editorial' => $editorial,
            'fetchedAt' => (int) $decoded['fetchedAt'],
            'ttlSeconds' => isset($decoded['ttlSeconds']) ? (int) $decoded['ttlSeconds'] : self::TTL_SECONDS,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $products
     * @param array<string, mixed>|null $editorial
     */
    private function writeCached(
        string $langNorm,
        array $products,
        ?array $editorial,
        int $now,
        int $ttlSeconds,
    ): void {
        $payload = json_encode([
            'products' => $products,
            'editorial' => $editorial,
            'fetchedAt' => $now,
            'ttlSeconds' => $ttlSeconds,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            return;
        }
        $this->config->set(self::configKeyForLang($langNorm), $payload);
    }
}
