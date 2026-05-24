<?php

declare(strict_types=1);

namespace Knot\Tests\Licensing;

use Knot\Licensing\PinnedPublicKeys;
use PHPUnit\Framework\TestCase;

final class PinnedPublicKeysTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['knot_test_global_strings'] = [];
        if (!function_exists('Knot\\Tests\\Licensing\\__pinned_keys_redefine')) {
            // We cannot redefine getDolGlobalString from the stubs file at
            // runtime; instead the stub already returns its $default — so
            // tests asserting "no override" use the embedded array branch.
        }
    }

    public function testLicenseSigningKeysIsNonEmptyStructuredArray(): void
    {
        $keys = PinnedPublicKeys::licenseSigningKeys();
        self::assertIsArray($keys);
        self::assertNotEmpty($keys, 'V2.5.0a must pin at least one license signing key.');
        foreach ($keys as $entry) {
            self::assertIsArray($entry);
            self::assertArrayHasKey('kid', $entry);
            self::assertArrayHasKey('purpose', $entry);
            self::assertArrayHasKey('publicHex', $entry);
            self::assertArrayHasKey('validFrom', $entry);
            self::assertArrayHasKey('validUntil', $entry);
            self::assertSame('license_signing', $entry['purpose']);
            self::assertTrue(
                PinnedPublicKeys::isValidHexKey((string) $entry['publicHex']),
                sprintf('Pinned licence key %s must be 64 hex chars.', (string) $entry['kid']),
            );
        }
    }

    public function testReleaseSigningKeysIsNonEmptyStructuredArray(): void
    {
        $keys = PinnedPublicKeys::releaseSigningKeys();
        self::assertIsArray($keys);
        self::assertNotEmpty($keys, 'V2.5.0a must pin at least one release signing key.');
        foreach ($keys as $entry) {
            self::assertIsArray($entry);
            self::assertSame('release_signing', $entry['purpose']);
            self::assertTrue(
                PinnedPublicKeys::isValidHexKey((string) $entry['publicHex']),
                sprintf('Pinned release key %s must be 64 hex chars.', (string) $entry['kid']),
            );
        }
    }

    public function testKnownKidsArePinnedForV25a(): void
    {
        $licenceKids = array_column(PinnedPublicKeys::licenseSigningKeys(), 'kid');
        $releaseKids = array_column(PinnedPublicKeys::releaseSigningKeys(), 'kid');
        self::assertContains('lic-2026-04', $licenceKids, 'V2.5.0a expected licence kid lic-2026-04.');
        self::assertContains('rel-2026-04', $releaseKids, 'V2.5.0a expected release kid rel-2026-04.');
    }

    public function testHexHelpersExposeOrderedHexStrings(): void
    {
        $licHex = PinnedPublicKeys::licenseSigningKeysHex();
        $relHex = PinnedPublicKeys::releaseSigningKeysHex();
        self::assertNotEmpty($licHex);
        self::assertNotEmpty($relHex);
        foreach ($licHex as $hex) {
            self::assertIsString($hex);
            self::assertTrue(PinnedPublicKeys::isValidHexKey($hex));
        }
        foreach ($relHex as $hex) {
            self::assertIsString($hex);
            self::assertTrue(PinnedPublicKeys::isValidHexKey($hex));
        }
    }

    public function testFromConstantsReturnsEmbeddedHexWhenNoOverride(): void
    {
        // Default stub returns '' for any getDolGlobalString call, so
        // fromConstants() must surface the embedded LICENSE_SIGNING_KEYS hex.
        $keys = PinnedPublicKeys::fromConstants();
        self::assertNotEmpty($keys);
        self::assertSame(PinnedPublicKeys::licenseSigningKeysHex(), $keys);
    }

    public function testIsValidHexKeyAcceptsExactly64Hex(): void
    {
        $hex = str_repeat('a', 64);
        self::assertTrue(PinnedPublicKeys::isValidHexKey($hex));
    }

    public function testIsValidHexKeyRejectsWrongLength(): void
    {
        self::assertFalse(PinnedPublicKeys::isValidHexKey(str_repeat('a', 63)));
        self::assertFalse(PinnedPublicKeys::isValidHexKey(str_repeat('a', 65)));
        self::assertFalse(PinnedPublicKeys::isValidHexKey(''));
    }

    public function testIsValidHexKeyRejectsNonHex(): void
    {
        $bad = str_repeat('z', 64);
        self::assertFalse(PinnedPublicKeys::isValidHexKey($bad));
    }
}
