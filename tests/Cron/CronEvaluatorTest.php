<?php

declare(strict_types=1);

namespace Knot\Tests\Cron;

use DateTimeImmutable;
use DateTimeZone;
use Knot\Cron\CronEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Behavioral coverage for the in-house POSIX cron evaluator.
 *
 * Each test pins the "now" anchor with an absolute UTC timestamp computed via
 * DateTimeImmutable so the assertions stay portable across hosts that may run
 * the suite in a non-UTC timezone.
 */
final class CronEvaluatorTest extends TestCase
{
    public function testEveryFiveMinutesAlignsToNextSlot(): void
    {
        $after = $this->ts('2026-05-01 10:02:00', 'UTC');
        $next = CronEvaluator::nextRunAfter('*/5 * * * *', 'UTC', $after);
        self::assertSame($this->ts('2026-05-01 10:05:00', 'UTC'), $next);
    }

    public function testEveryFiveMinutesAlreadyOnSlotPicksFollowingSlot(): void
    {
        $after = $this->ts('2026-05-01 10:05:00', 'UTC');
        $next = CronEvaluator::nextRunAfter('*/5 * * * *', 'UTC', $after);
        self::assertSame($this->ts('2026-05-01 10:10:00', 'UTC'), $next);
    }

    public function testDailyAt2230RollsToNextDayWhenAlreadyPast(): void
    {
        $after = $this->ts('2026-05-01 23:00:00', 'UTC');
        $next = CronEvaluator::nextRunAfter('30 22 * * *', 'UTC', $after);
        self::assertSame($this->ts('2026-05-02 22:30:00', 'UTC'), $next);
    }

    public function testFirstOfMonthMidnightRollsAcrossMonthEnd(): void
    {
        $after = $this->ts('2026-05-15 12:00:00', 'UTC');
        $next = CronEvaluator::nextRunAfter('0 0 1 * *', 'UTC', $after);
        self::assertSame($this->ts('2026-06-01 00:00:00', 'UTC'), $next);
    }

    public function testSundayUsingZeroAndSevenAreEquivalent(): void
    {
        $after = $this->ts('2026-05-01 12:00:00', 'UTC');
        $withZero = CronEvaluator::nextRunAfter('5 4 * * 0', 'UTC', $after);
        $withSeven = CronEvaluator::nextRunAfter('5 4 * * 7', 'UTC', $after);
        self::assertSame($withZero, $withSeven);
        self::assertSame((int) (new DateTimeImmutable('@' . $withZero))->format('w'), 0);
    }

    public function testListAndRangeMixedField(): void
    {
        $after = $this->ts('2026-05-01 11:00:00', 'UTC');
        // minute = 1-5,9 -> next minute candidates after 11:00 are 11:01..11:05 and 11:09
        $next = CronEvaluator::nextRunAfter('1-5,9 * * * *', 'UTC', $after);
        self::assertSame($this->ts('2026-05-01 11:01:00', 'UTC'), $next);
    }

    public function testStepInHourField(): void
    {
        $after = $this->ts('2026-05-01 11:00:00', 'UTC');
        // every 6 hours at minute 0: 0,6,12,18 -> next after 11:00 is 12:00
        $next = CronEvaluator::nextRunAfter('0 */6 * * *', 'UTC', $after);
        self::assertSame($this->ts('2026-05-01 12:00:00', 'UTC'), $next);
    }

    public function testDomAndDowOredVixieBehavior(): void
    {
        // Every Monday at 09:00 OR every 1st of month at 09:00.
        // 2026-05-01 was a Friday: next firing is 2026-05-04 (Monday).
        $after = $this->ts('2026-05-01 12:00:00', 'UTC');
        $next = CronEvaluator::nextRunAfter('0 9 1 * 1', 'UTC', $after);
        self::assertSame($this->ts('2026-05-04 09:00:00', 'UTC'), $next);

        // Late in the same month, the 1st-of-next-month branch wins.
        $after = $this->ts('2026-05-26 12:00:00', 'UTC');
        $next = CronEvaluator::nextRunAfter('0 9 1 * 1', 'UTC', $after);
        self::assertSame($this->ts('2026-06-01 09:00:00', 'UTC'), $next);
    }

    public function testTimezoneAffectsBoundaryEvaluation(): void
    {
        // 22:00 local America/Martinique (UTC-4, no DST) on 2026-05-01 == 02:00 UTC on 2026-05-02
        $after = $this->ts('2026-05-01 23:00:00', 'UTC');
        $next = CronEvaluator::nextRunAfter('0 22 * * *', 'America/Martinique', $after);
        self::assertSame($this->ts('2026-05-02 02:00:00', 'UTC'), $next);
    }

    public function testInvalidExpressionFallsBackToOneMinute(): void
    {
        $after = $this->ts('2026-05-01 10:00:00', 'UTC');
        // 6 fields instead of 5 -> fallback +60s
        $next = CronEvaluator::nextRunAfter('* * * * * *', 'UTC', $after);
        self::assertSame($after + 60, $next);
    }

    public function testEmptyExpressionFallsBackToOneMinute(): void
    {
        $after = $this->ts('2026-05-01 10:00:00', 'UTC');
        $next = CronEvaluator::nextRunAfter('   ', 'UTC', $after);
        self::assertSame($after + 60, $next);
    }

    public function testOutOfRangeFieldFallsBackToOneMinute(): void
    {
        $after = $this->ts('2026-05-01 10:00:00', 'UTC');
        // 60 > 59 -> fallback
        $next = CronEvaluator::nextRunAfter('60 * * * *', 'UTC', $after);
        self::assertSame($after + 60, $next);
    }

    public function testInvalidTimezoneFallsBackToUtc(): void
    {
        $after = $this->ts('2026-05-01 10:00:00', 'UTC');
        $invalid = CronEvaluator::nextRunAfter('30 22 * * *', 'Mars/Olympus_Mons', $after);
        $utc = CronEvaluator::nextRunAfter('30 22 * * *', 'UTC', $after);
        self::assertSame($utc, $invalid);
    }

    public function testQuestionMarkBehavesLikeWildcard(): void
    {
        $after = $this->ts('2026-05-01 10:00:00', 'UTC');
        $star = CronEvaluator::nextRunAfter('0 12 * * *', 'UTC', $after);
        $quest = CronEvaluator::nextRunAfter('0 12 ? * *', 'UTC', $after);
        self::assertSame($star, $quest);
    }

    public function testStepWithExplicitRange(): void
    {
        $after = $this->ts('2026-05-01 10:00:00', 'UTC');
        // 0-30/10 -> 0,10,20,30. Next firing after 10:00 is 10:10.
        $next = CronEvaluator::nextRunAfter('0-30/10 * * * *', 'UTC', $after);
        self::assertSame($this->ts('2026-05-01 10:10:00', 'UTC'), $next);
    }

    public function testFebruaryTwentyNineLeapYearMatches(): void
    {
        // 2028 is a leap year. Looking for "every Feb 29 at 12:00".
        $after = $this->ts('2027-03-01 00:00:00', 'UTC');
        $next = CronEvaluator::nextRunAfter('0 12 29 2 *', 'UTC', $after);
        self::assertSame($this->ts('2028-02-29 12:00:00', 'UTC'), $next);
    }

    public function testCursorAdvancesAcrossMonthBoundary(): void
    {
        // Last minute of March 2026 -> next is April 1 at 00:00.
        $after = $this->ts('2026-03-31 23:59:00', 'UTC');
        $next = CronEvaluator::nextRunAfter('0 0 * * *', 'UTC', $after);
        self::assertSame($this->ts('2026-04-01 00:00:00', 'UTC'), $next);
    }

    private function ts(string $human, string $tz): int
    {
        return (new DateTimeImmutable($human, new DateTimeZone($tz)))->getTimestamp();
    }
}
