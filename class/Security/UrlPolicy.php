<?php

/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

namespace Knot\Security;

/**
 * Validates outbound URLs for HTTP actions.
 *
 * Multi-layered SSRF defence:
 *
 * 1. **Scheme allow-list** — only `http` and `https` (no `file://`, `ftp://`,
 *    `gopher://`…).
 * 2. **Host literal block-list** — `localhost`, `*.localhost`, common
 *    metadata service hostnames (`metadata.google.internal`).
 * 3. **DNS resolution + IP-range filter** — fail closed when resolution
 *    fails, then reject any private/loopback/reserved range. We also block
 *    the cloud metadata IPs explicitly (AWS / GCP / Azure use 169.254/x).
 * 4. **Configurable allow-list / block-list** — admins can whitelist or
 *    blacklist specific hostnames via the `MAIN_KNOT_HTTP_ALLOWLIST`
 *    (comma-separated) or `MAIN_KNOT_HTTP_DENYLIST` Dolibarr constants.
 *    Allow-list short-circuits everything else (useful for legitimate
 *    internal endpoints), deny-list is checked first to wall off known
 *    bad hosts.
 */
final class UrlPolicy implements UrlPolicyContract
{
    /**
     * Hostnames that always represent the local machine, regardless of how
     * they're spelled. Some are case sensitive (Hosts file overrides).
     *
     * @var array<int, string>
     */
    private const HARDCODED_DENY_HOSTS = [
        'localhost',
        '127.0.0.1',
        '::1',
        '0.0.0.0',
        'metadata.google.internal',
        'metadata.azure.com',
    ];

    /**
     * Cloud metadata IPs that must never be reachable from a workflow.
     *
     * @var array<int, string>
     */
    private const HARDCODED_DENY_IPS = [
        '169.254.169.254', // AWS / Azure / DigitalOcean / Hetzner / OVH
        '169.254.170.2',   // ECS task metadata
        'fd00:ec2::254',   // AWS IPv6 metadata
    ];

    /**
     * @param array<int, string> $allowlistHosts Hostnames that bypass the IP-range check (case-insensitive).
     * @param array<int, string> $denylistHosts  Hostnames to block before any other check (case-insensitive).
     */
    public function __construct(
        private readonly array $allowlistHosts = [],
        private readonly array $denylistHosts = []
    ) {
    }

    /**
     * Build a UrlPolicy from Dolibarr constants. Both lists accept comma-,
     * semicolon-, or newline-separated host names.
     */
    public static function fromGlobals(): self
    {
        $allow = self::splitHostList(self::readGlobal('MAIN_KNOT_HTTP_ALLOWLIST'));
        $deny = self::splitHostList(self::readGlobal('MAIN_KNOT_HTTP_DENYLIST'));
        return new self($allow, $deny);
    }

    /**
     * Decide whether `$url` may be requested. Returns false on any failure
     * (parse error, denied scheme/host, DNS failure, private IP, etc.).
     */
    public function isAllowed(string $url): bool
    {
        return $this->resolve($url) !== null;
    }

    /**
     * Run the same validation as {@see isAllowed()} and, when the URL is
     * allowed, return the resolved IP plus host/port. Callers (typically
     * {@see HttpClient}) can then pass this IP straight to cURL via
     * `CURLOPT_RESOLVE` to defeat DNS rebinding: cURL will not perform a
     * second DNS lookup that an attacker with a low-TTL record could swing
     * onto an internal address.
     *
     * @return array{host:string, ip:string, port:int}|null
     */
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

        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '') {
            return null;
        }

        // Hard deny: localhost / cloud metadata hostnames, regardless of
        // configuration.
        if (in_array($host, self::HARDCODED_DENY_HOSTS, true)) {
            return null;
        }

        // Admin-supplied deny list takes precedence over allow list so even
        // an explicitly allowed wildcard cannot bypass a banned host.
        if ($this->matchesHostList($host, $this->denylistHosts)) {
            return null;
        }

        // Admin-supplied allow list short-circuits the IP / range checks so
        // legitimate internal endpoints (eg. a private gateway pinned by
        // hostname) keep working.
        $allowlistMatch = $this->matchesHostList($host, $this->allowlistHosts);

        // If the host is itself an IP literal we still need the range filter,
        // even when it is allow-listed (the admin should know what they
        // typed but we double-check loopback / private just in case).
        $hostIp = filter_var($host, FILTER_VALIDATE_IP) !== false ? $host : null;

        if ($hostIp === null) {
            $resolved = gethostbyname($host);
            if ($resolved === $host) {
                // gethostbyname returns the input unchanged when DNS fails.
                return null;
            }
            $hostIp = $resolved;
        }

        if (in_array($hostIp, self::HARDCODED_DENY_IPS, true)) {
            return null;
        }

        // Block any private / reserved / loopback range (10.0.0.0/8,
        // 172.16/12, 192.168/16, 169.254/16, fc00::/7…).
        if (filter_var($hostIp, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            if (!$allowlistMatch) {
                return null; // explicit allow-list bypasses the range filter, otherwise deny.
            }
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
        return ['host' => $host, 'ip' => $hostIp, 'port' => $port];
    }

    /**
     * Wildcard-aware host matching. An entry like `*.example.com` matches any
     * direct subdomain; `example.com` matches the exact hostname only.
     *
     * @param array<int, string> $list
     */
    private function matchesHostList(string $host, array $list): bool
    {
        foreach ($list as $entry) {
            $entry = strtolower(trim($entry));
            if ($entry === '') {
                continue;
            }
            if ($entry === $host) {
                return true;
            }
            if (str_starts_with($entry, '*.')) {
                $suffix = substr($entry, 1); // ".example.com"
                if (str_ends_with($host, $suffix)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Read a Dolibarr-style global constant safely.
     */
    private static function readGlobal(string $name): string
    {
        if (function_exists('getDolGlobalString')) {
            return (string) \getDolGlobalString($name);
        }
        if (defined($name)) {
            return (string) constant($name);
        }
        return '';
    }

    /**
     * Parse a CSV / NL-separated list of hostnames into a normalised array.
     *
     * @return array<int, string>
     */
    private static function splitHostList(string $raw): array
    {
        if ($raw === '') {
            return [];
        }
        $parts = preg_split('/[\s,;]+/', $raw) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $p = strtolower(trim($p));
            if ($p !== '') {
                $out[] = $p;
            }
        }
        return $out;
    }
}
