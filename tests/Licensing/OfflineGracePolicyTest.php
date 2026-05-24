<?php

declare(strict_types=1);

namespace Knot\Tests\Licensing;

use Knot\Licensing\OfflineGracePolicy;
use PHPUnit\Framework\TestCase;

final class OfflineGracePolicyTest extends TestCase
{
    public function testWithinGraceWhenRefreshedRecently(): void
    {
        $policy = new OfflineGracePolicy(14);
        $now = strtotime('2026-04-29T00:00:00Z');
        $refresh = '2026-04-28T00:00:00Z';
        self::assertTrue($policy->isWithinGrace($refresh, $now));
    }

    public function testWithinGraceJustBeforeDeadline(): void
    {
        $policy = new OfflineGracePolicy(14);
        $now = strtotime('2026-05-12T23:59:00Z');
        $refresh = '2026-04-29T00:00:00Z';
        self::assertTrue($policy->isWithinGrace($refresh, $now));
    }

    public function testOutsideGraceAfterDeadline(): void
    {
        $policy = new OfflineGracePolicy(14);
        $now = strtotime('2026-05-14T00:00:00Z');
        $refresh = '2026-04-29T00:00:00Z';
        self::assertFalse($policy->isWithinGrace($refresh, $now));
    }

    public function testInvalidIsoTreatedAsOutsideGrace(): void
    {
        $policy = new OfflineGracePolicy(14);
        self::assertFalse($policy->isWithinGrace('not-a-date'));
    }

    public function testRemainingSecondsCountdown(): void
    {
        $policy = new OfflineGracePolicy(14);
        $now = strtotime('2026-04-29T00:00:00Z');
        $refresh = '2026-04-29T00:00:00Z';
        self::assertSame(14 * 86400, $policy->remainingSeconds($refresh, $now));
    }

    public function testRemainingSecondsClampsToZero(): void
    {
        $policy = new OfflineGracePolicy(14);
        $now = strtotime('2026-06-01T00:00:00Z');
        $refresh = '2026-04-29T00:00:00Z';
        self::assertSame(0, $policy->remainingSeconds($refresh, $now));
    }
}
