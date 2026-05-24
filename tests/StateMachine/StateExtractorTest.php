<?php

declare(strict_types=1);

namespace Knot\Tests\StateMachine;

use Knot\StateMachine\StateExtractor;
use PHPUnit\Framework\TestCase;

final class StubDocWithStatuses
{
    public const STATUS_A = 1;

    public const STATUS_B = 2;

    public const IGNORE = 'x';

    public int $statut = 2;
}

final class StateExtractorTest extends TestCase
{
    public function testExtractAndResolveLogicalState(): void
    {
        $ex = new StateExtractor();
        $map = $ex->extractStatusConstants(StubDocWithStatuses::class);

        self::assertSame(['STATUS_A' => 1, 'STATUS_B' => 2], $map);

        $instance = new StubDocWithStatuses();
        self::assertSame('STATUS_B', $ex->resolveLogicalState($instance, $map));
        self::assertSame(2, $ex->readStatusValue($instance));
    }
}
