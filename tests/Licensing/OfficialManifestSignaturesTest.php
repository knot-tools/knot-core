<?php

declare(strict_types=1);

namespace Knot\Tests\Licensing;

use Knot\KnownSkus;
use Knot\Licensing\OfficialManifestSignatures;
use PHPUnit\Framework\TestCase;

final class OfficialManifestSignaturesTest extends TestCase
{
    public function testPrimaryMapUsesFirstDigestOnly(): void
    {
        $map = OfficialManifestSignatures::map();
        $primary = OfficialManifestSignatures::primaryMap();

        foreach ($primary as $extensionId => $digest) {
            self::assertSame($map[$extensionId][0], $digest, $extensionId);
        }
    }

    public function testEveryPinIs128Hex(): void
    {
        foreach (OfficialManifestSignatures::map() as $extensionId => $digests) {
            self::assertNotSame([], $digests, $extensionId . ' must have at least one pin');
            foreach ($digests as $digest) {
                self::assertMatchesRegularExpression(
                    '/^[a-f0-9]{128}$/',
                    $digest,
                    $extensionId . ' pin must be 128 hex chars',
                );
            }
        }
    }

    public function testProPackHasTransitionPinAfter20260522Resign(): void
    {
        $digests = OfficialManifestSignatures::map()[KnownSkus::PRO_PACK] ?? [];
        self::assertGreaterThanOrEqual(2, count($digests), 'Pro Pack should keep a deprecated transition pin');
    }

    public function testMigration014ManifestRemainsAcceptedAsTransitionPin(): void
    {
        $digests = OfficialManifestSignatures::map()[KnownSkus::MIGRATION] ?? [];
        self::assertContains(
            '8f2e6cb7d1531425c67069189a1a604b5eea883eb5621d74e0de24f08179b255a0ee0e54185c09c732fa0948fe50764075822eea0d685f124632b42ff4d93604',
            $digests,
            '0.21.4 manifest must stay pinned while sites upgrade to 0.21.5',
        );
    }

    public function testProPack014ManifestRemainsAcceptedAsTransitionPin(): void
    {
        $digests = OfficialManifestSignatures::map()[KnownSkus::PRO_PACK] ?? [];
        self::assertContains(
            '1e6f32da1704926175928048d68dfdea768a1005ae3f140ad45aeff0461509d12c66c5b4404e2bd3990adb439ab5e6b98d0185629a4cc4e390e5ed2deafae904',
            $digests,
            '0.1.4 manifest must stay pinned while sites upgrade to 0.1.5',
        );
    }
}
