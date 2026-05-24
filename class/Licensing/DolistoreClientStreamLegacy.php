<?php

declare(strict_types=1);

namespace Knot\Licensing;

use RuntimeException;

/**
 * Stream-based HTTP POST only loaded on PHP 8.3 and below (see DolistoreClient::postWithStream).
 *
 * @internal
 */
final class DolistoreClientStreamLegacy
{
    /**
     * @return array{status: int, body: string}
     */
    public static function postJson(string $url, string $body, int $timeoutSeconds, bool $insecureTls): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n"
                    . "Accept: application/json\r\n"
                    . "User-Agent: Knot-LicenseValidator/2.5.0a\r\n",
                'content' => $body,
                'timeout' => $timeoutSeconds,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => !$insecureTls,
                'verify_peer_name' => !$insecureTls,
            ],
        ]);
        $resp = @file_get_contents($url, false, $context);
        if ($resp === false) {
            throw new RuntimeException('Stream transport error contacting license backend');
        }
        $headerLines = $http_response_header;
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
}
