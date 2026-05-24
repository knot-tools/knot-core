<?php

declare(strict_types=1);

namespace Knot\Tests\Load;

use Knot\Engine\ExecutionBackoff;
use PHPUnit\Framework\TestCase;

/**
 * Lightweight deterministic “load” check — no DB, no network.
 */
final class LightEnqueueLoopTest extends TestCase
{
    public function testBackoffLoopIsStable(): void
    {
        $acc = 0;
        for ($i = 1; $i <= 500; $i++) {
            $acc += ExecutionBackoff::delaySecondsBeforeNextAttempt($i % 7 + 1, 'exponential');
        }
        self::assertGreaterThan(10000, $acc);
    }
}
