<?php

declare(strict_types=1);

namespace Knot\Security;

use RuntimeException;

/**
 * Tiny cURL wrapper used by every HTTP-based connector.
 *
 *  - applies UrlPolicy to block local/private targets,
 *  - enforces a sane timeout / max body size,
 *  - returns a structured array `{status, headers, body, json}`,
 *  - never logs auth headers (caller is in charge of masking).
 */
class HttpClient
{
    public function __construct(
        private readonly ?UrlPolicyContract $urlPolicy = null,
        private readonly int $defaultTimeoutSeconds = 15,
        private readonly int $maxBodyBytes = 5_000_000
    ) {
    }

    /**
     * Execute an HTTP request.
     *
     * @param array<string, string> $headers  Header map (key => value)
     * @param string|null           $body     Raw request body (null for GET/HEAD)
     * @return array{status:int, headers:array<string,string>, body:string, json:mixed}
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null, ?int $timeoutSeconds = null): array
    {
        $policy = $this->urlPolicy ?? UrlPolicy::fromGlobals();
        $resolution = $policy->resolve($url);
        if ($resolution === null) {
            throw new RuntimeException('URL is not allowed by Knot UrlPolicy: ' . $url);
        }

        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP cURL extension is required to perform HTTP requests.');
        }

        // Manual redirect handling — we must re-validate every Location with
        // UrlPolicy so an attacker cannot redirect us into a private/metadata
        // endpoint by chaining 30x responses.
        $maxRedirects = 5;
        $currentUrl = $url;
        $upperMethod = strtoupper($method);

        for ($i = 0; $i <= $maxRedirects; $i++) {
            $ch = curl_init();
            $rawHeaders = [];
            foreach ($headers as $key => $value) {
                $rawHeaders[] = $key . ': ' . $value;
            }

            $captured = [];
            curl_setopt_array($ch, [
                CURLOPT_URL => $currentUrl,
                CURLOPT_CUSTOMREQUEST => $upperMethod,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => $timeoutSeconds ?? $this->defaultTimeoutSeconds,
                CURLOPT_USERAGENT => 'Knot/0.1 (+https://knot.tools)',
                CURLOPT_HTTPHEADER => $rawHeaders,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HEADERFUNCTION => static function ($_, string $line) use (&$captured): int {
                    $trim = trim($line);
                    if ($trim !== '' && str_contains($trim, ':')) {
                        [$k, $v] = explode(':', $trim, 2);
                        $captured[strtolower(trim($k))] = trim($v);
                    }
                    return strlen($line);
                },
            ]);

            // DNS rebinding defence: pin cURL to the exact IP that UrlPolicy
            // already validated. cURL would otherwise re-resolve the host and
            // an attacker controlling a low-TTL record could swing the second
            // lookup onto a private/metadata IP. Skip pinning when the URL is
            // already an IP literal (CURLOPT_RESOLVE expects a hostname).
            if ($resolution['host'] !== $resolution['ip']) {
                curl_setopt($ch, CURLOPT_RESOLVE, [
                    sprintf('%s:%d:%s', $resolution['host'], $resolution['port'], $resolution['ip']),
                ]);
            }

            if ($body !== null && !in_array($upperMethod, ['GET', 'HEAD'], true)) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }

            $rawBody = curl_exec($ch);
            if ($rawBody === false) {
                $error = curl_error($ch);
                throw new RuntimeException('HTTP request failed: ' . $error);
            }

            $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

            // Follow 3xx manually after re-validating the destination, and
            // re-resolve the next hop so cURL stays pinned to the IP we just
            // validated (defends against DNS rebinding across a redirect).
            if ($statusCode >= 300 && $statusCode < 400 && isset($captured['location']) && $i < $maxRedirects) {
                $next = $this->resolveRedirectTarget($currentUrl, $captured['location']);
                $nextResolution = $policy->resolve($next);
                if ($nextResolution === null) {
                    throw new RuntimeException('Redirect target is not allowed by Knot UrlPolicy: ' . $next);
                }
                $currentUrl = $next;
                $resolution = $nextResolution;
                continue;
            }

            $bodyString = (string) $rawBody;
            if (strlen($bodyString) > $this->maxBodyBytes) {
                $bodyString = substr($bodyString, 0, $this->maxBodyBytes);
            }

            $json = null;
            if (isset($captured['content-type']) && str_contains($captured['content-type'], 'json')) {
                $decoded = json_decode($bodyString, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $json = $decoded;
                }
            }

            return [
                'status' => $statusCode,
                'headers' => $captured,
                'body' => $bodyString,
                'json' => $json,
            ];
        }

        throw new RuntimeException('HTTP request failed: too many redirects.');
    }

    /**
     * Resolve a possibly-relative `Location` header against the current URL.
     */
    private function resolveRedirectTarget(string $currentUrl, string $location): string
    {
        if (preg_match('/^[a-z][a-z0-9+\-.]*:\/\//i', $location) === 1) {
            return $location;
        }
        $parts = parse_url($currentUrl);
        $scheme = (string) ($parts['scheme'] ?? 'https');
        $host = (string) ($parts['host'] ?? '');
        if ($host === '') {
            return $location;
        }
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $base = $scheme . '://' . $host . $port;
        if (str_starts_with($location, '/')) {
            return $base . $location;
        }
        $path = (string) ($parts['path'] ?? '/');
        $dir = substr($path, 0, (int) strrpos($path, '/') + 1);
        return $base . $dir . $location;
    }
}
