<?php

/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

namespace Knot\Updates;

use RuntimeException;

/**
 * Fetches Knot Core releases metadata from a public HTTPS JSON descriptor.
 *
 * Default source is pinned to knot-tools GitHub Pages / raw payloads so GPL
 * instances can upgrade without invoking the Knot license backend when the ZIP
 * is published as a GitHub Release asset under the Knot organisation.
 */
final class GithubReleasesClient
{
    public const PUBLIC_MANIFEST_PRIMARY =
        'https://raw.githubusercontent.com/knot-tools/knot-core/main/releases.json';

    /**
     * @return array<string, mixed> decoded releases.json
     */
    public function fetchManifest(?string $url = null): array
    {
        $effective = trim((string) ($url ?? self::PUBLIC_MANIFEST_PRIMARY));
        if (
            stripos($effective, 'https://') !== 0
            && stripos($effective, 'http://localhost') !== 0
            && stripos($effective, 'http://127.0.0.1') !== 0
        ) {
            throw new RuntimeException('releases.json URL must start with HTTPS (or localhost in dev).');
        }

        return $this->fetchJsonSecure($effective);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchJsonSecure(string $url): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('cURL extension is required to fetch Knot releases metadata.');
        }
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Cannot initialise releases fetch client.');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'User-Agent: Knot-Core-GithubReleases/' . \Knot\Version::current(),
            ],
        ]);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($body === false || $errno !== 0) {
            throw new RuntimeException('Network error fetching Knot releases descriptor: ' . $err);
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('releases.json HTTP ' . $status);
        }

        $decoded = json_decode((string) $body, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException('releases.json must decode into an associative array.');
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array{
     *     zip_url: string,
     *     zip_sha256: string,
     *     signature_hex: string,
     *     signature_payload: array<string, mixed>|null,
     *     version: string
     * }
     */
    public static function inferLatestArtifact(array $manifest): array
    {
        $latestRaw = $manifest['latest'] ?? null;
        $latest = is_array($latestRaw) ? $latestRaw : [];
        $zipUrl = (string) ($latest['zip_url'] ?? '');
        $sha256 = (string) ($latest['zip_sha256'] ?? '');
        $signPayloadRaw = $latest['signature_payload'] ?? null;
        $signPayload = is_array($signPayloadRaw) ? $signPayloadRaw : null;

        return [
            'zip_url' => trim($zipUrl),
            'zip_sha256' => trim(strtolower($sha256)),
            'signature_hex' => trim((string) ($latest['signature_hex'] ?? '')),
            'signature_payload' => $signPayload,
            'version' => (string) ($latest['version'] ?? ''),
        ];
    }

    /** @param array<string, mixed>|null $signPayload */
    public static function verifyReleaseIntegrity(
        string $absoluteZipPath,
        string $expectedShaHex,
        ?array $signPayload,
        string $signatureHex,
    ): void {
        ReleaseVerifier::assertZipSha256($absoluteZipPath, strtolower($expectedShaHex));
        ReleaseVerifier::assertOptionalDetachedSignature($signPayload, $signatureHex);
    }
}
