<?php

/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

namespace Knot\Marketplace;

use Knot\Repository\KnotConfigRepository;
use Knot\Repository\TemplateRepository;

/**
 * V2.5.0c — Marketplace template orchestrator.
 *
 * Wraps {@see CatalogClient::fetch('template')} into a cache-aside
 * pattern that persists results into `llx_knot_template` so the editor
 * stays usable even when license.knot.tools is briefly unreachable.
 *
 * Refresh logic:
 *   1. Look up the last refresh timestamp from `llx_knot_config`
 *      (key {@see CONFIG_KEY_REFRESHED}). If younger than {@see TTL_SECONDS}
 *      → just serve the local cache, no network call.
 *   2. Otherwise fetch the live catalog. On success, upsert all rows
 *      via {@see TemplateRepository::cacheFromLicense()} and prune
 *      stale ones via {@see TemplateRepository::pruneMissing()}.
 *   3. On failure, leave the cache as-is and surface an error in the
 *      `meta` envelope so the UI can show a soft warning.
 *
 * The template cache is kept independent from the extensions catalog
 * cache ({@see CatalogCache}) because templates persist into a real
 * Dolibarr table (entity-aware) while extensions live in a single
 * JSON blob in `llx_knot_config`. Sharing the same TTL across both
 * keeps the UX consistent.
 */
final class TemplateClient
{
    public const CONFIG_KEY_REFRESHED = 'marketplace.templates_refreshed_at';
    public const CONFIG_KEY_LAST_ERROR = 'marketplace.templates_last_error';
    public const TTL_SECONDS = 21600; // 6h, mirror CatalogCache::TTL_SECONDS

    public function __construct(
        private readonly TemplateRepository $repository,
        private readonly KnotConfigRepository $config,
        private readonly CatalogClient $client,
    ) {
    }

    /**
     * Refresh the cache for the given entity if it is older than the
     * TTL, then return the full cached list along with metadata about
     * the refresh attempt.
     *
     * When {@see $reuseNormalizedFullCatalogProducts} is set by an aggregator such as
     * {@code marketplace.php}, it MUST be the full normalized catalog returned during
     * the SAME HTTP invocation by a freshly completed {@see CatalogClient::fetch()} so
     * we do not duplicate a second `/api/catalog.json?kind=template` telemetry hit against
     * {@code license.knot.tools}.
     *
     * @param array<int, array<string, mixed>>|null $reuseNormalizedFullCatalogProducts
     * @return array{
     *   templates: array<int, array<string, mixed>>,
     *   meta: array{fromCache: bool, stale: bool, refreshedAt: ?string, error: ?string}
     * }
     */
    public function all(int $entity, ?array $reuseNormalizedFullCatalogProducts = null): array
    {
        $now = time();
        $refreshedAt = $this->readRefreshedAt();
        $isStale = $refreshedAt === null || ($now - $refreshedAt) >= self::TTL_SECONDS;

        $error = null;
        $fromCache = !$isStale;

        if ($isStale) {
            if ($reuseNormalizedFullCatalogProducts !== null) {
                /** @var array<int, array<string, mixed>> $live */
                $live = array_values(array_filter(
                    $reuseNormalizedFullCatalogProducts,
                    static fn (array $p): bool => (($p['kind'] ?? '') === 'template'),
                ));
                $this->repository->cacheFromLicense($live, $entity);
                $this->repository->pruneMissing(array_map(
                    static fn (array $t): string => (string) ($t['slug'] ?? ''),
                    $live
                ), $entity);
                $this->writeRefreshedAt($now);
                $this->config->delete(self::CONFIG_KEY_LAST_ERROR);
                $fromCache = false;
                $error = null;
            } else {
                $live = $this->client->fetch('template');
                if ($live !== []) {
                    $this->repository->cacheFromLicense($live, $entity);
                    $this->repository->pruneMissing(array_map(
                        static fn (array $t): string => (string) ($t['slug'] ?? ''),
                        $live
                    ), $entity);
                    $this->writeRefreshedAt($now);
                    $this->config->delete(self::CONFIG_KEY_LAST_ERROR);
                    $fromCache = false;
                } else {
                    $error = $this->client->lastError();
                    if ($error !== null) {
                        $this->config->set(self::CONFIG_KEY_LAST_ERROR, $error);
                    }
                    // Cache stays untouched; we serve whatever is there.
                    $fromCache = true;
                }
            }
        }

        return [
            'templates' => $this->repository->listCached($entity),
            'meta' => [
                'fromCache' => $fromCache,
                'stale' => $isStale && $error !== null,
                'refreshedAt' => $refreshedAt !== null ? gmdate('c', $refreshedAt) : null,
                'error' => $error,
            ],
        ];
    }

    /**
     * Force-refresh the cache regardless of TTL. Used by the
     * `?action=refresh_cache` admin endpoint.
     *
     * @return array{count: int, error: ?string}
     */
    public function forceRefresh(int $entity): array
    {
        $live = $this->client->fetch('template');
        if ($live === []) {
            $error = $this->client->lastError();
            return ['count' => 0, 'error' => $error];
        }
        $count = $this->repository->cacheFromLicense($live, $entity);
        $this->repository->pruneMissing(array_map(
            static fn (array $t): string => (string) ($t['slug'] ?? ''),
            $live
        ), $entity);
        $this->writeRefreshedAt(time());
        $this->config->delete(self::CONFIG_KEY_LAST_ERROR);
        return ['count' => $count, 'error' => null];
    }

    private function readRefreshedAt(): ?int
    {
        $raw = $this->config->get(self::CONFIG_KEY_REFRESHED);
        if ($raw === null || $raw === '') {
            return null;
        }
        $ts = (int) $raw;
        return $ts > 0 ? $ts : null;
    }

    private function writeRefreshedAt(int $ts): void
    {
        $this->config->set(self::CONFIG_KEY_REFRESHED, (string) $ts);
    }
}
