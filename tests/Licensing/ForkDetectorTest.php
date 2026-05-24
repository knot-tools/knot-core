<?php

declare(strict_types=1);

namespace Knot\Tests\Licensing;

use Knot\Licensing\ForkDetector;
use Knot\Licensing\ManifestSignatureMatch;
use PHPUnit\Framework\TestCase;

final class ForkDetectorTest extends TestCase
{
    public function testReturnsFalseForUnknownExtensionId(): void
    {
        $detector = new ForkDetector(['knot-pro-pack' => 'official-sig']);
        self::assertFalse($detector->isFork('knot-third-party-thing', 'random'));
        self::assertSame(
            ManifestSignatureMatch::NOT_OFFICIAL,
            $detector->classify('knot-third-party-thing', 'random'),
        );
    }

    public function testFlagsKnownIdWithMismatchedSignature(): void
    {
        $official = str_repeat('b', 128);
        $forged = str_repeat('a', 128);
        $detector = new ForkDetector(['knot-pro-pack' => $official]);
        self::assertTrue($detector->isFork('knot-pro-pack', $forged));
        self::assertSame(
            ManifestSignatureMatch::REJECTED,
            $detector->classify('knot-pro-pack', $forged),
        );
    }

    public function testAcceptsKnownIdWithMatchingSignature(): void
    {
        $official = str_repeat('c', 128);
        $detector = new ForkDetector(['knot-pro-pack' => $official]);
        self::assertFalse($detector->isFork('knot-pro-pack', $official));
        self::assertSame(
            ManifestSignatureMatch::PRIMARY,
            $detector->classify('knot-pro-pack', $official),
        );
    }

    public function testAcceptsDeprecatedTransitionSignature(): void
    {
        $current = str_repeat('d', 128);
        $legacy = str_repeat('e', 128);
        $detector = new ForkDetector([
            'knot-pro-pack' => [$current, $legacy],
        ]);
        self::assertFalse($detector->isFork('knot-pro-pack', $legacy));
        self::assertSame(
            ManifestSignatureMatch::DEPRECATED,
            $detector->classify('knot-pro-pack', $legacy),
        );
    }

    public function testMissingSignatureClassifiedWhenExtensionIsOfficial(): void
    {
        $detector = new ForkDetector(['knot-pro-pack' => ['current-sig']]);
        self::assertSame(
            ManifestSignatureMatch::MISSING,
            $detector->classify('knot-pro-pack', ''),
        );
    }

    public function testOfficialIdsExposed(): void
    {
        $detector = new ForkDetector(['knot-pro-pack' => 'a', 'knot-enterprise' => 'b']);
        self::assertSame(['knot-pro-pack', 'knot-enterprise'], $detector->officialIds());
    }
}
