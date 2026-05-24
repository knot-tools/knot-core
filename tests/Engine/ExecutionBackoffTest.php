<?php

declare(strict_types=1);

namespace Knot\Tests\Engine;

use Knot\Engine\ExecutionBackoff;
use PHPUnit\Framework\TestCase;

final class ExecutionBackoffTest extends TestCase
{
    public function testExponentialGrowth(): void
    {
        self::assertSame(60, ExecutionBackoff::delaySecondsBeforeNextAttempt(1, 'exponential'));
        self::assertSame(120, ExecutionBackoff::delaySecondsBeforeNextAttempt(2, 'exponential'));
        self::assertSame(240, ExecutionBackoff::delaySecondsBeforeNextAttempt(3, 'exponential'));
    }

    public function testLinearAndFixed(): void
    {
        self::assertSame(120, ExecutionBackoff::delaySecondsBeforeNextAttempt(1, 'linear'));
        self::assertSame(300, ExecutionBackoff::delaySecondsBeforeNextAttempt(99, 'fixed'));
    }

    public function testUnknownStrategyFallsBackToExponential(): void
    {
        self::assertSame(60, ExecutionBackoff::delaySecondsBeforeNextAttempt(1, 'custom-unknown'));
    }

    public function testCapsAtOneHour(): void
    {
        self::assertSame(3600, ExecutionBackoff::delaySecondsBeforeNextAttempt(20, 'exponential'));
    }
}
