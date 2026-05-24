<?php

/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

namespace Knot\Updates;

use Knot\Licensing\InstallationIdentity;

/**
 * V2.5.0b — Phase 7d notify-only update client.
 *
 * Talks to the public {@code /api/products/{slug}/latest} endpoint
 * exposed by `license.knot.tools` (see
 * {@see \Knot\License\Api\ProductLatestController}) and normalises the
 * payload into the shape consumed by {@see UpdateChecker} :
 *
 * ```
 * [
 *   'slug' => 'knot-migration',
 *   'version' => '0.7.0',
 *   'channel' => 'beta',
 *   'publishedAt' => '2026-05-16T12:34:56+00:00',
 *   'zipSize' => 4_532_111,
 *   'zipSha256' => '…64hex…',
 *   'signatureKid' => 'rel-2026-04',
 * ]
 * ```
 *
 * Never throws on network errors — callers expect to render the rest
 * of the admin UI even when `license.knot.tools` is briefly
 * unreachable, exactly like {@see \Knot\Marketplace\CatalogClient}.
 * Failures are surfaced via {@see lastError()}.
 *
 * Notify-only contract: the client deliberately does NOT expose the
 * download URL. Runtime apply is handled by `api/updates_apply.php` (Core and
 * licensable extension slugs).
 */
class UpdateClient
{
    public const DEFAULT_BASE_URL = 'https://license.knot.tools';
    public const DEFAULT_TIMEOUT_S = 6;

    protected ?string $lastError = null;

    public function __construct(
        private readonly string $baseUrl = self::DEFAULT_BASE_URL,
        private readonly int $timeoutSeconds = self::DEFAULT_TIMEOUT_S,
        private readonly string $releaseChannel = 'beta',
    ) {
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    public function baseUrl(): string
    {
        return rtrim($this->baseUrl, '/');
    }

    /**
     * Fetch the latest release manifest for $slug. Returns null on any
     * failure (network, HTTP error, malformed JSON) — callers should
     * pair this with {@see UpdateStatusCache} to keep serving the
     * last good copy when the central server is down.
     *
     * @return array{
     *     slug: string,
     *     version: string,
     *     channel: string,
     *     publishedAt: string,
     *     zipSize: int,
     *     zipSha256: string,
     *     signatureKid: string
     * }|null
     */
    public function fetchLatest(string $slug): ?array
    {
        $this->lastError = null;
        $slug = trim($slug);
        if ($slug === '' || !preg_match('/^[a-z0-9][a-z0-9\-]*$/', $slug)) {
            $this->lastError = 'invalid_slug';
            return null;
        }
        $url = rtrim($this->baseUrl, '/') . '/api/products/' . rawurlencode($slug) . '/latest';
        $channel = strtolower(trim($this->releaseChannel));
        if ($channel !== '') {
            $url .= '?channel=' . rawurlencode($channel);
        }

        $ch = curl_init($url);
        if ($ch === false) {
            $this->lastError = 'curl_init_failed';
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'User-Agent: ' . InstallationIdentity::knotCoreUserAgent('UpdateCheck'),
            ],
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        $errstr = curl_error($ch);

        if ($body === false || $errno !== 0) {
            $this->lastError = 'curl_error:' . $errno . ':' . $errstr;
            return null;
        }
        if ($status === 404) {
            $this->lastError = 'unknown_product';
            return null;
        }
        if ($status < 200 || $status >= 300) {
            $this->lastError = 'http_' . $status;
            return null;
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded) || !isset($decoded['version'], $decoded['product_slug'])) {
            $this->lastError = 'invalid_payload';
            return null;
        }

        return [
            'slug' => (string) $decoded['product_slug'],
            'version' => (string) $decoded['version'],
            'channel' => (string) ($decoded['channel'] ?? 'stable'),
            'publishedAt' => (string) ($decoded['published_at'] ?? ''),
            'zipSize' => (int) ($decoded['zip_size_bytes'] ?? 0),
            'zipSha256' => (string) ($decoded['zip_sha256'] ?? ''),
            'signatureKid' => (string) ($decoded['signature_kid'] ?? ''),
        ];
    }
}
