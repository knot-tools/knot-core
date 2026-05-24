<?php

/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

namespace Knot\Marketplace;

use Knot\Licensing\InstallationIdentity;

/**
 * Fetches connector presentation whitelist JSON from MAIN_KNOT_LICENSE_BASE_URL mirror.
 *
 * Responses are HTTPS presentation text only — same pragmatic trust posture as CatalogClient.
 */
final class ConnectorPresentationSnippetClient
{
    public const DEFAULT_PATH = '/api/connector-presentation.json';

    /** @readonly */
    private int $timeoutSeconds;

    private ?string $lastError = null;

    /** Max JSON body bytes read before rejecting (mitigate pathological payloads). */
    private int $maxBytes;

    public function __construct(
        private readonly string $baseUrl = CatalogClient::DEFAULT_BASE_URL,
        private readonly string $path = self::DEFAULT_PATH,
        ?int $timeoutSeconds = null,
        ?int $maxBytes = null,
        private readonly ?string $deploymentToken = null,
        private readonly ?string $deploymentNonce = null,
    ) {
        $this->timeoutSeconds = $timeoutSeconds ?? CatalogClient::DEFAULT_TIMEOUT_S;
        $this->maxBytes = $maxBytes ?? 262_144;
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * @return array{snippetVersion: int, fetchedRecommendation: bool, connectors: array<int, array<string, mixed>>}
     */
    private function emptyResult(): array
    {
        return [
            'snippetVersion' => 0,
            'fetchedRecommendation' => false,
            'connectors' => [],
        ];
    }

    /**
     * @return array{snippetVersion: int, fetchedRecommendation: bool, connectors: array<int, array<string, mixed>>}
     */
    public function fetch(): array
    {
        $this->lastError = null;
        $url = rtrim($this->baseUrl, '/') . $this->path;
        $ch = curl_init($url);
        if ($ch === false) {
            $this->lastError = 'curl_init_failed';

            return $this->emptyResult();
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
                'User-Agent: ' . InstallationIdentity::knotCoreUserAgent('ConnectorPresentation'),
            ], $this->deploymentHeaderLines()),
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        $errstr = curl_error($ch);

        if ($body === false || $errno !== 0) {
            $this->lastError = 'curl_error:' . $errno . ':' . $errstr;

            return $this->emptyResult();
        }
        $rawLen = strlen((string) $body);
        if ($rawLen > $this->maxBytes) {
            $this->lastError = 'payload_too_large';

            return $this->emptyResult();
        }

        if ($status < 200 || $status >= 300) {
            $this->lastError = 'http_' . $status;

            return $this->emptyResult();
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            $this->lastError = 'invalid_payload';

            return $this->emptyResult();
        }
        $version = isset($decoded['snippetVersion']) && is_numeric($decoded['snippetVersion'])
            ? (int) $decoded['snippetVersion'] : 0;
        $recommend = isset($decoded['fetchedRecommendation']) ? (bool) $decoded['fetchedRecommendation'] : false;
        $connectorsRaw = $decoded['connectors'] ?? [];
        if (!is_array($connectorsRaw)) {
            $this->lastError = 'invalid_connectors';

            return $this->emptyResult();
        }
        /** @var array<int, array<string, mixed>> $connectorsNorm */
        $connectorsNorm = [];
        foreach (array_slice($connectorsRaw, 0, 2048, true) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $connectorsNorm[] = $row;
        }

        return [
            'snippetVersion' => max(1, $version),
            'fetchedRecommendation' => $recommend,
            'connectors' => $connectorsNorm,
        ];
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
}
