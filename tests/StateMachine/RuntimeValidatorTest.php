<?php

declare(strict_types=1);

namespace Knot\Tests\StateMachine;

use Knot\Errors\KnotError;
use Knot\StateMachine\RuntimeValidator;
use PHPUnit\Framework\TestCase;

final class RuntimeValidatorTest extends TestCase
{
    public function testNegativeReturnRaisesKnotError(): void
    {
        $host = new class {
            /** @var string */
            public $error = '';

            public function fail(\stdClass $user): int
            {
                $this->error = 'invalid transition for unit test';

                return -1;
            }
        };

        $this->expectException(KnotError::class);
        (new RuntimeValidator())->invokeTransition($host, 'fail', new \stdClass());
    }

    public function testMissingMethodRaisesInvalidTransitionFamily(): void
    {
        $host = new \stdClass();

        $this->expectException(KnotError::class);
        (new RuntimeValidator())->invokeTransition($host, 'missing', new \stdClass());
    }

    public function testResolveInvocationBuildsArgsWithoutInvoke(): void
    {
        $user = new \stdClass();
        $host = new class {
            public function setDraft(\stdClass $user): int
            {
                return 1;
            }
        };

        $v = new RuntimeValidator();
        $plan = $v->resolveInvocation($host, 'setDraft', $user);
        self::assertSame('setDraft', $plan['rm']->getName());
        self::assertCount(1, $plan['args']);
        self::assertSame($user, $plan['args'][0]);
    }
}
