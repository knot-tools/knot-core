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
        if (!self::isAllowedManifestUrl($effective)) {
            throw new RuntimeException('releases.json URL must be an HTTPS Knot release source.');
        }

        return $this->fetchJsonSecure($effective);
    }

    /**
     * Knot release sources only: knot.tools hosts and the knot-tools GitHub
     * organisation. Loopback is accepted in the test harness only.
     */
    public static function isAllowedManifestUrl(string $url): bool
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host']) || isset($parts['user'])) {
            return false;
        }
        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        if ($host === 'localhost' || $host === '127.0.0.1') {
            return getenv('KNOT_TEST') === '1' && ($scheme === 'http' || $scheme === 'https');
        }
        if ($scheme !== 'https' || isset($parts['port'])) {
            return false;
        }
        if ($host === 'raw.githubusercontent.com') {
            $path = (string) ($parts['path'] ?? '');

            return (bool) preg_match('#^/knot-tools/[A-Za-z0-9/_.-]+$#', $path) && !str_contains($path, '..');
        }

        return $host === 'knot.tools' || str_ends_with($host, '.knot.tools');
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
     *     version: string,
     *     notes: string
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
            'notes' => ReleaseNotesExtractor::normalizeNotes($latest['notes'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed>|null $signPayload
     *
     * @throws RuntimeException
     */
    public static function verifyReleaseIntegrity(
        string $absoluteZipPath,
        string $expectedShaHex,
        ?array $signPayload,
        string $signatureHex,
        string $targetVersion,
        string $channel,
        string $installedVersion,
    ): void {
        if (UpdatesApplyPolicy::isDowngrade($targetVersion, $installedVersion)) {
            throw new RuntimeException(sprintf(
                'Release %s is older than the installed version %s.',
                $targetVersion,
                $installedVersion,
            ));
        }
        ReleaseVerifier::assertZipSha256($absoluteZipPath, strtolower($expectedShaHex));
        ReleaseVerifier::assertCoreRelease($absoluteZipPath, $signPayload, $signatureHex, $targetVersion, $channel);
    }
}
