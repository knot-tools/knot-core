<?php

/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

namespace Knot\Marketplace;

use Knot\Licensing\InstallationIdentity;

/**
 * V2.5.0a — Marketplace catalog client.
 *
 * Talks to the public {@code /api/catalog.json} endpoint exposed by
 * `license.knot.tools` (CatalogController on the Slim backend) and
 * normalises the response into a frontend-friendly shape:
 *
 * ```
 * [
 *   [
 *     'slug' => 'knot-pro-pack',
 *     'label' => 'Knot Pro Pack',
 *     'description' => '...',
 *     'category' => 'pro',
 *     'priceMonthlyCents' => 1900,
 *     'priceYearlyCents' => 19000,
 *     'currency' => 'eur',
 *     'trialDays' => 14,
 *     'refundWindowDays' => 14,
 *     'buyUrl' => 'https://knot.tools/pricing#knot-pro-pack',
 *   ],
 *   ...
 * ]
 * ```
 *
 * This class never throws on network errors — callers (especially the
 * `api/marketplace.php` aggregator) want to render the rest of the UI
 * even when `license.knot.tools` is briefly unreachable. Failures are
 * surfaced via {@see lastError()} for debug.
 */
class CatalogClient
{
    public const DEFAULT_BASE_URL = 'https://license.knot.tools';
    public const DEFAULT_TIMEOUT_S = 6;

    private ?string $lastError = null;

    public function __construct(
        private readonly string $baseUrl = self::DEFAULT_BASE_URL,
        private readonly int $timeoutSeconds = self::DEFAULT_TIMEOUT_S,
        private readonly ?string $deploymentToken = null,
        private readonly ?string $deploymentNonce = null,
    ) {
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Fetch the live catalog. Returns an empty array on any failure
     * (network, HTTP error, malformed JSON). Callers should pair this
     * with {@see CatalogCache} to keep serving the last good copy.
     *
     * @param string|null $kind Optional filter forwarded as `?kind=` to
     *                          the public endpoint. Accepts `extension`
     *                          or `template`; any other value is ignored
     *                          server-side so it is safe to pass through.
     * @return array<int, array<string, mixed>>
     */
    public function fetch(?string $kind = null): array
    {
        $this->lastError = null;
        $url = rtrim($this->baseUrl, '/') . '/api/catalog.json';
        if ($kind !== null && $kind !== '') {
            $url .= '?kind=' . rawurlencode($kind);
        }

        $ch = curl_init($url);
        if ($ch === false) {
            $this->lastError = 'curl_init_failed';
            return [];
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => array_merge([
                'Accept: application/json',
                'User-Agent: ' . InstallationIdentity::knotCoreUserAgent('Marketplace'),
            ], $this->deploymentHeaderLines()),
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        $errstr = curl_error($ch);

        if ($body === false || $errno !== 0) {
            $this->lastError = 'curl_error:' . $errno . ':' . $errstr;
            return [];
        }
        if ($status < 200 || $status >= 300) {
            $this->lastError = 'http_' . $status;
            return [];
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded) || !isset($decoded['products']) || !is_array($decoded['products'])) {
            $this->lastError = 'invalid_payload';
            return [];
        }

        return $this->normalise($decoded['products']);
    }

    /**
     * @return list<string>
     */
    private function deploymentHeaderLines(): array
    {
        if (
            $this->deploymentToken !== null && $this->deploymentToken !== ''
            && $this->deploymentNonce !== null && $this->deploymentNonce !== ''
        ) {
            return [
                'X-Knot-Deployment-Token: ' . $this->deploymentToken,
                'X-Knot-Deployment-Nonce: ' . $this->deploymentNonce,
            ];
        }

        return [];
    }

    /**
     * Normalise the raw products array into a frontend-friendly shape.
     *
     * The license backend serves snake_case while the Knot Core frontend
     * expects camelCase. We also explicitly default tier/status/kind so
     * the UI never has to deal with missing optional fields.
     *
     * @param array<int, mixed> $rawProducts
     * @return array<int, array<string, mixed>>
     */
    public function normalise(array $rawProducts): array
    {
        $out = [];
        foreach ($rawProducts as $row) {
            if (!is_array($row)) {
                continue;
            }
            $slug = (string) ($row['slug'] ?? '');
            if ($slug === '') {
                continue;
            }
            $pricing = is_array($row['pricing'] ?? null) ? $row['pricing'] : [];
            $definition = null;
            if (isset($row['definition']) && is_array($row['definition'])) {
                $definition = $row['definition'];
            }

            $out[] = [
                'slug' => $slug,
                'label' => (string) ($row['label'] ?? $slug),
                'description' => isset($row['description'])
                    ? (string) $row['description']
                    : null,
                'kind' => in_array($row['kind'] ?? '', ['extension', 'template'], true)
                    ? (string) $row['kind']
                    : 'extension',
                'tier' => in_array($row['tier'] ?? '', ['free', 'beta', 'pro', 'enterprise'], true)
                    ? (string) $row['tier']
                    : 'pro',
                'category' => (string) ($row['category'] ?? 'pro'),
                'icon' => isset($row['icon'])
                    ? (string) $row['icon']
                    : null,
                'templateCategory' => isset($row['template_category'])
                    ? (string) $row['template_category']
                    : null,
                'priceMonthlyCents' => isset($pricing['monthly_eur_cents'])
                    ? (int) $pricing['monthly_eur_cents']
                    : null,
                'priceYearlyCents' => isset($pricing['yearly_eur_cents'])
                    ? (int) $pricing['yearly_eur_cents']
                    : null,
                'currency' => 'eur',
                'trialDays' => (int) ($pricing['trial_days'] ?? 0),
                'refundWindowDays' => (int) ($pricing['refund_window_days'] ?? 0),
                'buyUrl' => 'https://knot.tools/pricing#' . $slug,
                'definition' => $definition,
            ];
        }
        return $out;
    }
}
