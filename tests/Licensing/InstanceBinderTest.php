<?php

declare(strict_types=1);

namespace Knot\Tests\Licensing;

use Knot\Licensing\InstanceBinder;
use PHPUnit\Framework\TestCase;

final class InstanceBinderTest extends TestCase
{
    public function testInstanceIdIsDeterministic(): void
    {
        $binder = new InstanceBinder('Acme', 'https://erp.acme.com', 'salt-abc');
        self::assertSame($binder->compute(), $binder->compute());
    }

    public function testTrailingSlashOnUrlIsIgnored(): void
    {
        $a = new InstanceBinder('Acme', 'https://erp.acme.com', 'salt');
        $b = new InstanceBinder('Acme', 'https://erp.acme.com/', 'salt');
        self::assertSame($a->compute(), $b->compute());
    }

    public function testDifferentSocieteNameProducesDifferentInstanceId(): void
    {
        $a = new InstanceBinder('Acme', 'https://erp.acme.com', 'salt');
        $b = new InstanceBinder('OtherCorp', 'https://erp.acme.com', 'salt');
        self::assertNotSame($a->compute(), $b->compute());
    }

    public function testDifferentSaltProducesDifferentInstanceId(): void
    {
        $a = new InstanceBinder('Acme', 'https://erp.acme.com', 'salt-a');
        $b = new InstanceBinder('Acme', 'https://erp.acme.com', 'salt-b');
        self::assertNotSame($a->compute(), $b->compute());
    }

    public function testMatchesUsesConstantTimeComparison(): void
    {
        $binder = new InstanceBinder('Acme', 'https://erp.acme.com', 'salt');
        self::assertTrue($binder->matches($binder->compute()));
        self::assertFalse($binder->matches('deadbeef'));
    }

    public function testGenerateLocalSaltIs64Hex(): void
    {
        $salt = InstanceBinder::generateLocalSalt();
        self::assertSame(64, strlen($salt));
        self::assertTrue(ctype_xdigit($salt));
    }

    public function testPinnedFingerprintOverridesComputeAndMatches(): void
    {
        $pinned = str_repeat('ab', 32);
        $binder = InstanceBinder::withPinnedFingerprint($pinned, 'salt-docs');
        self::assertSame($pinned, $binder->compute());
        self::assertTrue($binder->matches($pinned));
        self::assertFalse($binder->matches('cd' . substr($pinned, 2)));
    }
}
