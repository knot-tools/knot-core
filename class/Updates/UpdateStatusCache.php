<?php

/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

namespace Knot\Updates;

use Knot\Repository\KnotConfigRepository;

/**
 * V2.5.0b — Phase 7d notify-only update cache.
 *
 * Persists the last successful response from {@see UpdateClient::fetchLatest()}
 * in `llx_knot_config` (key `updates.cache.{slug}`) so that the admin
 * UI keeps rendering update hints even when `license.knot.tools` is
 * briefly unreachable. The cache is refreshed on every read older
 * than {@see TTL_SECONDS}.
 *
 * Cache shape per entry:
 *   {
 *     "slug": "knot",
 *     "version": "2.9.1",
 *     "channel": "beta",
 *     "publishedAt": "2026-05-16T12:34:56+00:00",
 *     "fetchedAt": 1715000000
 *   }
 *
 * The `fetchedAt` field is kept locally only — never echoed back to
 * the central server.
 */
final class UpdateStatusCache
{
    public const CONFIG_KEY_PREFIX = 'updates.cache.';
    public const TTL_SECONDS = 86_400; // 24h

    public function __construct(private readonly KnotConfigRepository $config)
    {
    }

    /**
     * @return array{
     *     slug: string,
     *     version: string,
     *     channel: string,
     *     publishedAt: string,
     *     fetchedAt: int
     * }|null
     */
    public function read(string $slug): ?array
    {
        $raw = $this->config->get(self::CONFIG_KEY_PREFIX . $slug);
        if ($raw === null || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['version'], $decoded['fetchedAt'])) {
            return null;
        }
        return [
            'slug' => (string) ($decoded['slug'] ?? $slug),
            'version' => (string) $decoded['version'],
            'channel' => (string) ($decoded['channel'] ?? 'stable'),
            'publishedAt' => (string) ($decoded['publishedAt'] ?? ''),
            'fetchedAt' => (int) $decoded['fetchedAt'],
        ];
    }

    /**
     * @param array{slug?: string, version: string, channel?: string, publishedAt?: string} $payload
     */
    public function write(string $slug, array $payload, int $now): void
    {
        $row = [
            'slug' => $slug,
            'version' => (string) $payload['version'],
            'channel' => (string) ($payload['channel'] ?? 'stable'),
            'publishedAt' => (string) ($payload['publishedAt'] ?? ''),
            'fetchedAt' => $now,
        ];
        $this->config->set(
            self::CONFIG_KEY_PREFIX . $slug,
            (string) json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );
    }

    /**
     * @param array{
     *     slug: string,
     *     version: string,
     *     channel: string,
     *     publishedAt: string,
     *     fetchedAt: int
     * }|null $cached
     */
    public function isFresh(?array $cached, int $now): bool
    {
        if ($cached === null) {
            return false;
        }
        return ($now - (int) $cached['fetchedAt']) < self::TTL_SECONDS;
    }
}
