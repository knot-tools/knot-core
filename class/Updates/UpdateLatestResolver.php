<?php

/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

namespace Knot\Updates;

use RuntimeException;

/**
 * Resolves the latest version manifest for update alerts (notify-only).
 *
 * Knot Core (`knot`): GitHub {@code releases.json} is primary; when it fails
 * (private repo, outage), {@code license.knot.tools/api/core/releases.json}
 * then {@code /api/products/knot/latest} are tried for notify-only alerts.
 * Apply still uses GitHub (or {@code MAIN_KNOT_CORE_RELEASES_JSON_URL}) only
 * ({@see api/updates_apply.php}).
 *
 * Commercial extensions: license backend only ({@see UpdateClient}).
 */
final class UpdateLatestResolver implements UpdateLatestSource
{
    public const CORE_SLUG = 'knot';

    public function __construct(
        private readonly UpdateClient $licenseClient,
        private readonly GithubReleasesClient $githubClient = new GithubReleasesClient(),
        private readonly ?string $githubManifestUrl = null,
    ) {
    }

    public function fetchLatest(string $slug): array
    {
        $slug = trim($slug);
        if ($slug === self::CORE_SLUG) {
            return $this->fetchCoreNotifyManifest();
        }

        $latest = $this->licenseClient->fetchLatest($slug);
        if ($latest !== null) {
            return [
                'payload' => $latest,
                'source' => 'live',
                'error' => null,
            ];
        }

        return [
            'payload' => null,
            'source' => 'unavailable',
            'error' => $this->licenseClient->lastError(),
        ];
    }

    /**
     * @return array{
     *     payload: array{
     *         slug: string,
     *         version: string,
     *         channel: string,
     *         publishedAt: string,
     *         zipSize: int,
     *         zipSha256: string,
     *         signatureKid: string
     *     }|null,
     *     source: string,
     *     error: ?string
     * }
     */
    private function fetchCoreNotifyManifest(): array
    {
        $githubError = null;
        try {
            $manifest = $this->githubClient->fetchManifest($this->githubManifestUrl);
            $payload = self::notifyPayloadFromGithubManifest($manifest);
            if ($payload['version'] === '') {
                throw new RuntimeException('releases.json has no usable version.');
            }

            return [
                'payload' => $payload,
                'source' => 'live',
                'error' => null,
            ];
        } catch (\Throwable $e) {
            $githubError = 'github:' . $e->getMessage();
        }

        $licenseReleasesError = null;
        try {
            $licenseManifestUrl = $this->licenseClient->baseUrl() . '/api/core/releases.json';
            $manifest = $this->githubClient->fetchManifest($licenseManifestUrl);
            $payload = self::notifyPayloadFromGithubManifest($manifest);
            if ($payload['version'] !== '') {
                return [
                    'payload' => $payload,
                    'source' => 'live_license_releases',
                    'error' => null,
                ];
            }
            $licenseReleasesError = 'license_releases:empty_version';
        } catch (\Throwable $e) {
            $licenseReleasesError = 'license_releases:' . $e->getMessage();
        }

        $latest = $this->licenseClient->fetchLatest(self::CORE_SLUG);
        if ($latest !== null) {
            return [
                'payload' => $latest,
                'source' => 'live_license',
                'error' => null,
            ];
        }

        $licenseError = $this->licenseClient->lastError() ?? 'unknown';

        return [
            'payload' => null,
            'source' => 'unavailable',
            'error' => $githubError . '; ' . $licenseReleasesError . '; license:' . $licenseError,
        ];
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array{
     *     slug: string,
     *     version: string,
     *     channel: string,
     *     publishedAt: string,
     *     zipSize: int,
     *     zipSha256: string,
     *     signatureKid: string
     * }
     */
    public static function notifyPayloadFromGithubManifest(array $manifest): array
    {
        $latestRaw = $manifest['latest'] ?? null;
        $latest = is_array($latestRaw) ? $latestRaw : [];
        $artifact = GithubReleasesClient::inferLatestArtifact($manifest);
        $version = trim($artifact['version']);
        if ($version === '') {
            $version = trim((string) ($latest['version'] ?? ''));
        }

        return [
            'slug' => self::CORE_SLUG,
            'version' => $version,
            'channel' => trim((string) ($latest['channel'] ?? 'stable')),
            'publishedAt' => trim((string) ($latest['published_at'] ?? '')),
            'zipSize' => 0,
            'zipSha256' => $artifact['zip_sha256'],
            'signatureKid' => '',
        ];
    }
}
