<?php

declare(strict_types=1);

namespace Knot\Licensing;

use RuntimeException;

/**
 * HTTP client for the Knot licence backend (license.knot.tools).
 *
 * Endpoint: POST {base}/api/license/check
 *   body: { activation_code, instance_fingerprint }
 *   200 : { verdict: { payload, signature: { value_hex, ... } } }
 *
 * Legacy Dolistore-shaped responses (top-level signature/payload) are still
 * accepted when a dev harness serves the old contract.
 */
final class DolistoreClient implements DolistoreClientContract
{
    public const FALLBACK_BASE_URL = 'https://license.knot.tools';

    public function __construct(
        private readonly string $baseUrl = self::FALLBACK_BASE_URL,
        private readonly int $timeoutSeconds = 10,
        private readonly bool $insecureTls = false,
        private readonly string $releaseChannel = 'beta',
    ) {
    }

    /**
     * GET /api/products/{slug}/signature — latest release detached signature metadata.
     *
     * @return array{
     *     product_slug: string,
     *     version: string,
     *     zip_sha256: string,
     *     signature_payload: array<string, mixed>|null,
     *     signature: array{kid: string, algorithm: string, value_hex: string}
     * }
     * @throws RuntimeException When transport fails or response is unusable.
     */
    public function fetchProductSignature(string $productSlug, ?string $channel = null): array
    {
        $slug = strtolower(trim($productSlug));
        if ($slug === '') {
            throw new RuntimeException('productSlug is required for product signature fetch');
        }

        $url = rtrim($this->baseUrl, '/') . '/api/products/' . rawurlencode($slug) . '/signature';
        $channelNorm = strtolower(trim((string) ($channel ?? $this->releaseChannel)));
        if ($channelNorm !== '') {
            $url .= '?channel=' . rawurlencode($channelNorm);
        }

        $response = $this->getJson($url);
        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded) || $response['status'] !== 200) {
            $msg = is_array($decoded)
                ? trim((string) ($decoded['error'] ?? ''))
                : '';
            throw new RuntimeException(
                $msg !== '' ? $msg : 'License backend denied product signature fetch.',
            );
        }

        $signature = is_array($decoded['signature'] ?? null) ? $decoded['signature'] : [];
        $payloadRaw = $decoded['signature_payload'] ?? null;

        return [
            'product_slug' => (string) ($decoded['product_slug'] ?? $slug),
            'version' => (string) ($decoded['version'] ?? ''),
            'zip_sha256' => (string) ($decoded['zip_sha256'] ?? ''),
            'signature_payload' => is_array($payloadRaw) ? $payloadRaw : null,
            'signature' => [
                'kid' => (string) ($signature['kid'] ?? ''),
                'algorithm' => (string) ($signature['algorithm'] ?? 'ed25519'),
                'value_hex' => (string) ($signature['value_hex'] ?? ''),
            ],
        ];
    }

    /**
     * @param array{
     *     activationCode: string,
     *     instanceFingerprint: string,
     *     deploymentToken?: string,
     *     deploymentNonce?: string,
     * } $params
     * @return array{
     *     valid: bool,
     *     expiresAt: ?string,
     *     plan: ?string,
     *     issuedTo: ?string,
     *     signature: string,
     *     signedAt: string,
     *     payload: array<string, mixed>
     * }
     * @throws RuntimeException When the call fails (network/HTTP).
     */
    public function check(array $params): array
    {
        $activationCode = trim($params['activationCode']);
        $fingerprint = trim($params['instanceFingerprint']);
        if ($activationCode === '' || $fingerprint === '') {
            throw new RuntimeException('activationCode and instanceFingerprint are required for license check');
        }

        $payload = [
            'activation_code' => $activationCode,
            'instance_fingerprint' => $fingerprint,
        ];
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new RuntimeException('Cannot encode license check request body');
        }

        $url = $this->licenseCheckUrl();
        $response = $this->postJson($url, $body, $params);
        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded)) {
            throw new RuntimeException('License backend returned non-JSON payload');
        }

        return $this->normalizeCheckResponse($decoded);
    }

    /**
     * POST {@see self::licenseDownloadTokenUrl()}
     *
     * Does not throw on HTTP 4xx/5xx; transport failures still throw.
     *
     * @param array{
     *     activationCode: string,
     *     instanceFingerprint: string,
     *     productSlug: string,
     *     deploymentToken?: string,
     *     deploymentNonce?: string,
     * } $params
     * @return array{status: int, body: string}
     */
    public function licenseDownloadTokenRequest(array $params): array
    {
        $activationCode = trim($params['activationCode']);
        $fingerprint = trim($params['instanceFingerprint']);
        $slug = strtolower(trim($params['productSlug']));
        if ($activationCode === '' || $fingerprint === '' || $slug === '') {
            throw new RuntimeException(
                'activationCode, instanceFingerprint and productSlug are required for license download token',
            );
        }

        $payload = [
            'activation_code' => $activationCode,
            'instance_fingerprint' => $fingerprint,
            'product_slug' => $slug,
        ];
        $channel = strtolower(trim($this->releaseChannel));
        if ($channel !== '') {
            $payload['channel'] = $channel;
        }
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new RuntimeException('Cannot encode license download token request body');
        }

        return $this->postDownloadTokenHttp($this->licenseDownloadTokenUrl(), $body, $params);
    }

    /**
     * @param array<string, mixed> $decoded
     * @return array{
     *     valid: bool,
     *     expiresAt: ?string,
     *     plan: ?string,
     *     issuedTo: ?string,
     *     signature: string,
     *     signedAt: string,
     *     payload: array<string, mixed>
     * }
     */
    private function normalizeCheckResponse(array $decoded): array
    {
        if (isset($decoded['verdict']) && is_array($decoded['verdict'])) {
            return $this->normalizeFromVerdictEnvelope($decoded['verdict']);
        }

        if (!isset($decoded['signature'], $decoded['payload'])) {
            throw new RuntimeException('License backend response missing verdict or signature/payload');
        }

        $respPayload = is_array($decoded['payload']) ? $decoded['payload'] : [];
        $signature = $this->extractSignatureHex($decoded['signature']);

        return [
            'valid' => $this->payloadIndicatesValid($respPayload),
            'expiresAt' => $this->extractExpiresAt($respPayload),
            'plan' => isset($respPayload['plan']) ? (string) $respPayload['plan'] : null,
            'issuedTo' => $this->extractIssuedTo($respPayload),
            'signature' => $signature,
            'signedAt' => (string) ($decoded['signedAt'] ?? ($respPayload['issued_at'] ?? gmdate('c'))),
            'payload' => $respPayload,
        ];
    }

    /**
     * @param array<string, mixed> $verdict
     * @return array{
     *     valid: bool,
     *     expiresAt: ?string,
     *     plan: ?string,
     *     issuedTo: ?string,
     *     signature: string,
     *     signedAt: string,
     *     payload: array<string, mixed>
     * }
     */
    private function normalizeFromVerdictEnvelope(array $verdict): array
    {
        $respPayload = is_array($verdict['payload'] ?? null) ? $verdict['payload'] : [];
        $sigBlock = is_array($verdict['signature'] ?? null) ? $verdict['signature'] : [];
        $signature = (string) ($sigBlock['value_hex'] ?? '');
        if ($signature === '') {
            throw new RuntimeException('License backend verdict missing signature.value_hex');
        }

        return [
            'valid' => $this->payloadIndicatesValid($respPayload),
            'expiresAt' => $this->extractExpiresAt($respPayload),
            'plan' => isset($respPayload['plan']) ? (string) $respPayload['plan'] : null,
            'issuedTo' => $this->extractIssuedTo($respPayload),
            'signature' => $signature,
            'signedAt' => (string) ($respPayload['issued_at'] ?? gmdate('c')),
            'payload' => $respPayload,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function payloadIndicatesValid(array $payload): bool
    {
        if (isset($payload['valid'])) {
            return (bool) $payload['valid'];
        }
        $status = strtolower((string) ($payload['status'] ?? ''));

        return in_array($status, ['active', 'grace'], true);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractExpiresAt(array $payload): ?string
    {
        $expiresAt = $payload['expires_at'] ?? $payload['expiresAt'] ?? null;
        if (is_string($expiresAt) && $expiresAt !== '') {
            return $expiresAt;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractIssuedTo(array $payload): ?string
    {
        if (isset($payload['issuedTo']) && $payload['issuedTo'] !== '') {
            return (string) $payload['issuedTo'];
        }
        if (isset($payload['product_slug']) && $payload['product_slug'] !== '') {
            return (string) $payload['product_slug'];
        }

        return null;
    }

    /**
     * @param mixed $signatureField
     */
    private function extractSignatureHex(mixed $signatureField): string
    {
        if (is_array($signatureField) && isset($signatureField['value_hex'])) {
            return (string) $signatureField['value_hex'];
        }
        if (is_string($signatureField) && $signatureField !== '') {
            $decoded = base64_decode($signatureField, true);
            if ($decoded !== false && strlen($decoded) === SODIUM_CRYPTO_SIGN_BYTES) {
                return sodium_bin2hex($decoded);
            }

            return $signatureField;
        }

        throw new RuntimeException('License backend response missing usable signature');
    }

    private function licenseCheckUrl(): string
    {
        $base = rtrim($this->baseUrl, '/');
        if (str_ends_with($base, '/api')) {
            return $base . '/license/check';
        }

        return $base . '/api/license/check';
    }

    private function licenseDownloadTokenUrl(): string
    {
        $base = rtrim($this->baseUrl, '/');
        if (str_ends_with($base, '/api')) {
            return $base . '/license/download-token';
        }

        return $base . '/api/license/download-token';
    }

    /**
     * @param array<string, mixed> $requestParams
     * @return array{status: int, body: string}
     */
    private function postDownloadTokenHttp(string $url, string $body, array $requestParams): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException(
                'PHP cURL extension is required for Knot license download token proxy requests.',
            );
        }

        return $this->postDownloadTokenWithCurl($url, $body, $requestParams);
    }

    /**
     * @param array<string, mixed> $requestParams
     * @return array{status: int, body: string}
     */
    private function postDownloadTokenWithCurl(string $url, string $body, array $requestParams): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Cannot initialise cURL');
        }
        $ua = InstallationIdentity::knotCoreUserAgent('LicenseDownload');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => array_merge([
                'Content-Type: application/json',
                'Accept: application/json',
                'User-Agent: ' . $ua,
            ], $this->deploymentHeaderLinesFromParams($requestParams)),
            CURLOPT_SSL_VERIFYPEER => !$this->insecureTls,
            CURLOPT_SSL_VERIFYHOST => $this->insecureTls ? 0 : 2,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $resp = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($resp === false || $errno !== 0) {
            throw new RuntimeException('cURL transport error: ' . $error);
        }

        return ['status' => $status, 'body' => (string) $resp];
    }

    /**
     * @param array<string, mixed> $requestParams
     *
     * @return array{status: int, body: string}
     */
    private function postJson(string $url, string $body, array $requestParams): array
    {
        if (function_exists('curl_init')) {
            return $this->postWithCurl($url, $body, $requestParams);
        }

        return $this->postWithStream($url, $body, $requestParams);
    }

    /**
     * @param array<string, mixed> $requestParams
     * @return array{status: int, body: string}
     */
    private function postWithCurl(string $url, string $body, array $requestParams): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Cannot initialise cURL');
        }
        $ua = InstallationIdentity::knotCoreUserAgent('LicenseValidator');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => array_merge([
                'Content-Type: application/json',
                'Accept: application/json',
                'User-Agent: ' . $ua,
            ], $this->deploymentHeaderLinesFromParams($requestParams)),
            CURLOPT_SSL_VERIFYPEER => !$this->insecureTls,
            CURLOPT_SSL_VERIFYHOST => $this->insecureTls ? 0 : 2,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $responseBody = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($responseBody === false || $errno !== 0) {
            throw new RuntimeException('cURL transport error: ' . $error);
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException("License backend HTTP $status");
        }

        return ['status' => $status, 'body' => (string) $responseBody];
    }

    /**
     * @param array<string, mixed> $requestParams
     * @return array{status: int, body: string}
     */
    private function postWithStream(string $url, string $body, array $requestParams): array
    {
        if (\PHP_VERSION_ID >= 80400) {
            return $this->postWithStreamUsingHttpResponseHeaders($url, $body, $requestParams);
        }

        return DolistoreClientStreamLegacy::postJson($url, $body, $this->timeoutSeconds, $this->insecureTls);
    }

    /**
     * @param array<string, mixed> $requestParams
     * @return array{status: int, body: string}
     */
    private function postWithStreamUsingHttpResponseHeaders(string $url, string $body, array $requestParams): array
    {
        $context = $this->streamContextForJsonPost($body, $requestParams);
        $resp = @file_get_contents($url, false, $context);
        if ($resp === false) {
            throw new RuntimeException('Stream transport error contacting license backend');
        }
        $last = http_get_last_response_headers();
        $headerLines = \is_array($last) ? $last : [];

        return $this->finishStreamHttpResponse($resp, $headerLines);
    }

    /**
     * @param array<string, mixed> $requestParams
     *
     * @return resource
     */
    private function streamContextForJsonPost(string $body, array $requestParams)
    {
        $ua = InstallationIdentity::knotCoreUserAgent('LicenseValidator');
        $extra = '';
        foreach ($this->deploymentHeaderLinesFromParams($requestParams) as $line) {
            $extra .= $line . "\r\n";
        }

        return stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n"
                    . "Accept: application/json\r\n"
                    . 'User-Agent: ' . $ua . "\r\n"
                    . $extra,
                'content' => $body,
                'timeout' => $this->timeoutSeconds,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => !$this->insecureTls,
                'verify_peer_name' => !$this->insecureTls,
            ],
        ]);
    }

    /**
     * @param list<string> $headerLines
     *
     * @return array{status: int, body: string}
     */
    private function finishStreamHttpResponse(string $resp, array $headerLines): array
    {
        $status = 0;
        foreach ($headerLines as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m)) {
                $status = (int) $m[1];
            }
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException("License backend HTTP $status");
        }

        return ['status' => $status, 'body' => $resp];
    }

    /**
     * @return array{status: int, body: string}
     */
    private function getJson(string $url): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP cURL extension is required for Knot license GET requests.');
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Cannot initialise cURL');
        }
        $ua = InstallationIdentity::knotCoreUserAgent('LicenseSignature');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'User-Agent: ' . $ua,
            ],
            CURLOPT_SSL_VERIFYPEER => !$this->insecureTls,
            CURLOPT_SSL_VERIFYHOST => $this->insecureTls ? 0 : 2,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $resp = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($resp === false || $errno !== 0) {
            throw new RuntimeException('cURL transport error: ' . $error);
        }

        return ['status' => $status, 'body' => (string) $resp];
    }

    /**
     * @param array<string, mixed> $params
     * @return list<string>
     */
    private function deploymentHeaderLinesFromParams(array $params): array
    {
        $lines = [];
        if (isset($params['deploymentToken']) && $params['deploymentToken'] !== '') {
            $lines[] = 'X-Knot-Deployment-Token: ' . (string) $params['deploymentToken'];
        }
        if (isset($params['deploymentNonce']) && $params['deploymentNonce'] !== '') {
            $lines[] = 'X-Knot-Deployment-Nonce: ' . (string) $params['deploymentNonce'];
        }

        return $lines;
    }
}
