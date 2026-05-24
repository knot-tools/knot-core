<?php

declare(strict_types=1);

namespace Knot\Tests\Support;

use Knot\Security\UrlPolicyContract;

/**
 * Test-only UrlPolicy that allows loopback HTTP targets for harness servers.
 */
final class LoopbackUrlPolicy implements UrlPolicyContract
{
    public function resolve(string $url): ?array
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $host = (string) ($parts['host'] ?? '');
        if ($host === '') {
            return null;
        }

        if ($host === '169.254.169.254' || str_contains($host, 'metadata.')) {
            return null;
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);

        return [
            'host' => $host,
            'ip' => '127.0.0.1',
            'port' => $port,
        ];
    }
}
