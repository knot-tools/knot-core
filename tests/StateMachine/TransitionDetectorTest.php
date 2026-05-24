<?php

declare(strict_types=1);

namespace Knot\Tests\StateMachine;

use Knot\Dolibarr\VerbDiscoverer;
use Knot\StateMachine\TransitionDetector;
use PHPUnit\Framework\TestCase;

final class StubVerbHost
{
    public function validate(\stdClass $user): int
    {
        return 1;
    }

    public function setlinks(): void
    {
    }
}

final class TransitionDetectorTest extends TestCase
{
    public function testFiltersNonStatusHelpers(): void
    {
        $detector = new TransitionDetector(new VerbDiscoverer());
        $verbs = $detector->discoverTransitions(StubVerbHost::class);
        $names = array_map(static fn (array $v): string => (string) ($v['name'] ?? ''), $verbs);

        self::assertContains('validate', $names);
        self::assertNotContains('setlinks', $names);
    }
}
