<?php

declare(strict_types=1);

namespace Knot\Tests\Licensing;

use Knot\Licensing\Bootstrap;
use Knot\Licensing\InstanceBinder;
use Knot\Tests\Repository\InMemoryConfigDb;
use PHPUnit\Framework\TestCase;

final class BootstrapTest extends TestCase
{
    public function testBuildExtensionRegistryAlwaysReturnsRegistryInstance(): void
    {
        $db = new InMemoryConfigDb();
        $registry = Bootstrap::buildExtensionRegistry($db);
        self::assertInstanceOf(\Knot\Extension\ExtensionRegistry::class, $registry);
    }

    public function testBuildLicenseValidatorReturnsValidator(): void
    {
        $db = new InMemoryConfigDb();
        $validator = Bootstrap::buildLicenseValidator($db);
        self::assertInstanceOf(\Knot\Extension\LicenseValidator::class, $validator);
    }

    public function testBuildDolistoreValidatorReturnsValidatorWhenPinnedKeysPresent(): void
    {
        // V2.5.0a pins LIC + REL public keys in PinnedPublicKeys, so the
        // dolistore branch is now available out of the box (sodium loaded
        // + at least one pinned hex key). Bootstrap must wire a real
        // DolistoreValidator pointing at the configured backend URL.
        $db = new InMemoryConfigDb();
        $validator = Bootstrap::buildDolistoreValidator($db);
        if (!extension_loaded('sodium')) {
            self::markTestSkipped('libsodium extension required to wire the dolistore branch.');
        }
        self::assertInstanceOf(\Knot\Licensing\DolistoreValidator::class, $validator);
    }

    public function testLocalSaltIsGeneratedOnceAndPersisted(): void
    {
        $db = new InMemoryConfigDb();
        $first = Bootstrap::localSalt($db);
        $second = Bootstrap::localSalt($db);
        self::assertSame($first, $second, 'Subsequent calls must reuse the persisted salt');
        self::assertSame(64, strlen($first));
        self::assertTrue(ctype_xdigit($first));
    }

    public function testLocalSaltIsCompatibleWithInstanceBinder(): void
    {
        $db = new InMemoryConfigDb();
        $salt = Bootstrap::localSalt($db);
        $binder = new InstanceBinder('Acme', 'https://erp.acme.com', $salt);
        self::assertSame($binder->compute(), $binder->compute());
    }

    public function testBuildAuditWriterReturnsWriter(): void
    {
        $db = new InMemoryConfigDb();
        $writer = Bootstrap::buildAuditWriter($db);
        self::assertInstanceOf(\Knot\Licensing\Audit\LicenseAuditWriter::class, $writer);
    }
}
