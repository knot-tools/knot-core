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
}
