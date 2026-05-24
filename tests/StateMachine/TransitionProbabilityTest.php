<?php

declare(strict_types=1);

namespace Knot\Tests\StateMachine;

use Knot\StateMachine\TransitionProbability;
use PHPUnit\Framework\TestCase;

final class TransitionProbabilityTest extends TestCase
{
    public function testDraftValidateIsHigh(): void
    {
        self::assertSame('high', TransitionProbability::rank('STATUS_DRAFT', 'validate'));
    }

    public function testPaidValidateIsLow(): void
    {
        self::assertSame('low', TransitionProbability::rank('STATUS_PAID', 'validate'));
    }
}
