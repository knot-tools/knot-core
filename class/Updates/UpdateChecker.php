<?php

/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

namespace Knot\Updates;

/**
 * V2.5.0b — Phase 7d notify-only update orchestrator.
 *
 * Aggregates "what's installed" (Knot Core + every discovered
 * extension) with "what's the latest" (read from {@see UpdateLatestSource}
 * through {@see UpdateStatusCache} with cache-aside semantics) and
 * produces a single array consumed by `api/updates.php` and the
 * frontend update badge.
 *
 * Notify-only by design: the checker NEVER triggers a download or an
 * install. It only reports `hasUpdate` based on a strict
 * `version_compare($installed, $latest, '<')`. The eventual one-click
 * install path will live in a separate `Knot\Updates\Installer`
 * service and is intentionally out of scope here.
 *
 * Multi-entity aware: the cache layer uses {@see KnotConfigRepository}
 * which already filters by `entity`, so each Dolibarr entity gets
 * its own check cadence.
 */
final class UpdateChecker
{
    public function __construct(
        private readonly UpdateLatestSource $source,
        private readonly UpdateStatusCache $cache,
    ) {
    }

    /**
     * @param array<int, array{slug: string, version: string}> $installed
     * @param bool $forceRefresh When true, bypass the 24h cache and hit live sources.
     * @return array{
     *     checkedAt: int,
     *     hasAnyUpdate: bool,
     *     entries: array<int, array{
     *         slug: string,
     *         installedVersion: string,
     *         latestVersion: ?string,
     *         channel: ?string,
     *         publishedAt: ?string,
     *         hasUpdate: bool,
     *         source: string,
     *         error: ?string
     *     }>
     * }
     */
    public function check(array $installed, bool $forceRefresh = false): array
    {
        $now = time();
        $entries = [];
        $hasAny = false;

        foreach ($installed as $row) {
            $slug = trim($row['slug']);
            $installedVersion = trim($row['version']);
            if ($slug === '' || $installedVersion === '') {
                continue;
            }

            $entry = $this->resolveOne($slug, $installedVersion, $now, $forceRefresh);
            $entries[] = $entry;
            if ($entry['hasUpdate']) {
                $hasAny = true;
            }
        }

        return [
            'checkedAt' => $now,
            'hasAnyUpdate' => $hasAny,
            'entries' => $entries,
        ];
    }

    /**
     * @return array{
     *     slug: string,
     *     installedVersion: string,
     *     latestVersion: ?string,
     *     channel: ?string,
     *     publishedAt: ?string,
     *     hasUpdate: bool,
     *     source: string,
     *     error: ?string
     * }
     */
    private function resolveOne(string $slug, string $installedVersion, int $now, bool $forceRefresh): array
    {
        $cached = $this->cache->read($slug);

        if (!$forceRefresh && $this->cache->isFresh($cached, $now) && $cached !== null) {
            return $this->buildEntry($slug, $installedVersion, $cached, source: 'cache', error: null);
        }

        $resolved = $this->source->fetchLatest($slug);
        if ($resolved['payload'] !== null) {
            $payload = $resolved['payload'];
            $this->cache->write($slug, $payload, $now);

            return $this->buildEntry(
                $slug,
                $installedVersion,
                $payload + ['fetchedAt' => $now],
                source: $resolved['source'],
                error: null,
            );
        }

        $error = $resolved['error'];
        if ($cached !== null) {
            return $this->buildEntry($slug, $installedVersion, $cached, source: 'cache_stale', error: $error);
        }

        return [
            'slug' => $slug,
            'installedVersion' => $installedVersion,
            'latestVersion' => null,
            'channel' => null,
            'publishedAt' => null,
            'hasUpdate' => false,
            'source' => 'unavailable',
            'error' => $error,
        ];
    }

    /**
     * @param array{version: string, channel: string, publishedAt: string, fetchedAt?: int} $payload
     * @return array{
     *     slug: string,
     *     installedVersion: string,
     *     latestVersion: string,
     *     channel: string,
     *     publishedAt: string,
     *     hasUpdate: bool,
     *     source: string,
     *     error: ?string
     * }
     */
    private function buildEntry(string $slug, string $installedVersion, array $payload, string $source, ?string $error): array
    {
        $latestVersion = (string) $payload['version'];
        return [
            'slug' => $slug,
            'installedVersion' => $installedVersion,
            'latestVersion' => $latestVersion,
            'channel' => $payload['channel'],
            'publishedAt' => $payload['publishedAt'],
            'hasUpdate' => self::compareVersions($installedVersion, $latestVersion),
            'source' => $source,
            'error' => $error,
        ];
    }

    /**
     * True when $installed is strictly older than $latest. Wraps
     * PHP's `version_compare` so the caller does not have to
     * remember its argument order.
     */
    public static function compareVersions(string $installed, string $latest): bool
    {
        return version_compare($installed, $latest, '<');
    }
}
