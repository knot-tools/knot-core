<?php

declare(strict_types=1);

namespace Knot\Tests\Security;

use Knot\Security\UrlPolicy;
use PHPUnit\Framework\TestCase;

final class UrlPolicyTest extends TestCase
{
    public function testRejectsNonHttpSchemes(): void
    {
        $policy = new UrlPolicy();
        self::assertFalse($policy->isAllowed('file:///etc/passwd'));
        self::assertFalse($policy->isAllowed('ftp://example.com/'));
        self::assertFalse($policy->isAllowed('gopher://example.com/'));
    }

    public function testRejectsLocalhostHostnames(): void
    {
        $policy = new UrlPolicy();
        self::assertFalse($policy->isAllowed('http://localhost/api'));
        self::assertFalse($policy->isAllowed('http://127.0.0.1/api'));
        self::assertFalse($policy->isAllowed('http://0.0.0.0/api'));
    }

    public function testRejectsCloudMetadataHostnames(): void
    {
        $policy = new UrlPolicy();
        self::assertFalse($policy->isAllowed('http://metadata.google.internal/computeMetadata/v1/'));
        self::assertFalse($policy->isAllowed('http://169.254.169.254/latest/meta-data/'));
    }

    public function testRejectsPrivateIPRanges(): void
    {
        $policy = new UrlPolicy();
        self::assertFalse($policy->isAllowed('http://10.0.0.1/'));
        self::assertFalse($policy->isAllowed('http://192.168.1.1/'));
        self::assertFalse($policy->isAllowed('http://172.16.0.1/'));
    }

    public function testAllowsPublicHttps(): void
    {
        $policy = new UrlPolicy();
        // Public Cloudflare DNS — stable IP for the test, will not be private.
        self::assertTrue($policy->isAllowed('https://1.1.1.1/'));
    }

    public function testDenylistOverridesPublicHost(): void
    {
        $policy = new UrlPolicy([], ['1.1.1.1']);
        self::assertFalse($policy->isAllowed('https://1.1.1.1/'));
    }

    public function testAllowlistBypassesIpRangeCheckForInternalHost(): void
    {
        // Hostname that resolves to a private IP via DNS would be allowed
        // when explicitly allow-listed. We can't rely on actual DNS here,
        // so we use an IP literal that would otherwise be blocked.
        $policy = new UrlPolicy(['10.0.0.1']);
        self::assertTrue($policy->isAllowed('http://10.0.0.1/'));
    }

    public function testDenylistBeatsAllowlist(): void
    {
        $policy = new UrlPolicy(['internal.example.com'], ['internal.example.com']);
        self::assertFalse($policy->isAllowed('https://internal.example.com/'));
    }

    public function testWildcardDenylistMatchesSubdomains(): void
    {
        $policy = new UrlPolicy([], ['*.evil.com']);
        self::assertFalse($policy->isAllowed('https://api.evil.com/'));
        self::assertFalse($policy->isAllowed('https://deeper.api.evil.com/'));
    }

    public function testRejectsUrlWithoutHost(): void
    {
        $policy = new UrlPolicy();
        self::assertFalse($policy->isAllowed('http:///path-only'));
        self::assertFalse($policy->isAllowed('not-a-url'));
    }

    public function testResolveExposesIpForDnsRebindingPin(): void
    {
        // 1.1.1.1 is an IP literal so resolve() returns it unchanged.
        $policy = new UrlPolicy();
        $resolution = $policy->resolve('https://1.1.1.1/');
        self::assertNotNull($resolution);
        self::assertSame('1.1.1.1', $resolution['host']);
        self::assertSame('1.1.1.1', $resolution['ip']);
        self::assertSame(443, $resolution['port']);
    }

    public function testResolveDefaultsPortPerScheme(): void
    {
        $policy = new UrlPolicy();
        self::assertSame(80, $policy->resolve('http://1.1.1.1/')['port'] ?? null);
        self::assertSame(443, $policy->resolve('https://1.1.1.1/')['port'] ?? null);
        self::assertSame(8443, $policy->resolve('https://1.1.1.1:8443/')['port'] ?? null);
    }

    public function testResolveReturnsNullForBlockedUrl(): void
    {
        $policy = new UrlPolicy();
        self::assertNull($policy->resolve('http://localhost/api'));
        self::assertNull($policy->resolve('http://10.0.0.1/'));
        self::assertNull($policy->resolve('file:///etc/passwd'));
    }

    public function testRejectsHardcodedMetadataAndLoopbackHosts(): void
    {
        $policy = new UrlPolicy();
        self::assertFalse($policy->isAllowed('http://metadata.azure.com/'));
        self::assertFalse($policy->isAllowed('http://[::1]/api'));
    }

    public function testRejectsHardcodedCloudMetadataIps(): void
    {
        $policy = new UrlPolicy();
        self::assertFalse($policy->isAllowed('http://169.254.170.2/'));
    }

    public function testAllowlistExactHostnameMatch(): void
    {
        $policy = new UrlPolicy(['1.1.1.1']);
        self::assertTrue($policy->isAllowed('https://1.1.1.1/path'));
    }

    public function testDnsFailureIsDenied(): void
    {
        $policy = new UrlPolicy();
        self::assertFalse($policy->isAllowed('http://this-host-should-not-resolve.knot-invalid/'));
    }

    public function testFromGlobalsReadsDolibarrConstants(): void
    {
        if (!defined('MAIN_KNOT_HTTP_DENYLIST')) {
            define('MAIN_KNOT_HTTP_DENYLIST', 'blocked.example.com');
        }
        $policy = UrlPolicy::fromGlobals();
        self::assertFalse($policy->isAllowed('https://blocked.example.com/'));
    }

    public function testSkipsEmptyDenylistEntries(): void
    {
        $policy = new UrlPolicy([], ['', '  ']);
        self::assertTrue($policy->isAllowed('https://1.1.1.1/'));
    }

    public function testResolveReturnsNullForEmptyHost(): void
    {
        $policy = new UrlPolicy();
        self::assertNull($policy->resolve('http:///path-only'));
    }

    public function testFromGlobalsParsesCommaSeparatedDenylist(): void
    {
        if (!defined('MAIN_KNOT_HTTP_DENYLIST')) {
            define('MAIN_KNOT_HTTP_DENYLIST', 'blocked.example.com, evil.example.com');
        }
        $policy = UrlPolicy::fromGlobals();
        self::assertFalse($policy->isAllowed('https://evil.example.com/'));
    }
}
