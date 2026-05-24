<?php

declare(strict_types=1);

namespace Knot\Tests\Engine;

use Knot\Engine\CronWorker;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class CronWorkerExecutionRetrySettingsTest extends TestCase
{
    /**
     * @return array{maxAttempts: int, backoffStrategy: string}
     */
    private function retrySettings(array $workflow): array
    {
        $worker = new CronWorker();
        $method = new ReflectionMethod(CronWorker::class, 'executionRetrySettings');

        /** @var array{maxAttempts: int, backoffStrategy: string} $result */
        $result = $method->invoke($worker, $workflow);

        return $result;
    }

    public function testDefaultsWhenWorkflowBlockMissing(): void
    {
        $cfg = $this->retrySettings(['definition' => ['nodes' => []]]);
        self::assertSame(3, $cfg['maxAttempts']);
        self::assertSame('exponential', $cfg['backoffStrategy']);
    }

    public function testReadsExecutionMaxAttemptsFromDefinition(): void
    {
        $cfg = $this->retrySettings([
            'definition' => [
                'workflow' => ['executionMaxAttempts' => 12],
            ],
        ]);
        self::assertSame(12, $cfg['maxAttempts']);
    }

    public function testClampsMaxAttemptsToFifty(): void
    {
        $cfg = $this->retrySettings([
            'definition' => [
                'workflow' => ['executionMaxAttempts' => 999],
            ],
        ]);
        self::assertSame(50, $cfg['maxAttempts']);
    }

    public function testClampsMaxAttemptsMinimumToOne(): void
    {
        $cfg = $this->retrySettings([
            'definition' => [
                'workflow' => ['executionMaxAttempts' => 0],
            ],
        ]);
        self::assertSame(1, $cfg['maxAttempts']);
    }

    public function testAcceptsLinearBackoff(): void
    {
        $cfg = $this->retrySettings([
            'definition' => [
                'workflow' => ['executionBackoffStrategy' => 'LINEAR'],
            ],
        ]);
        self::assertSame('linear', $cfg['backoffStrategy']);
    }

    public function testAcceptsFixedBackoff(): void
    {
        $cfg = $this->retrySettings([
            'definition' => [
                'workflow' => ['executionBackoffStrategy' => 'fixed'],
            ],
        ]);
        self::assertSame('fixed', $cfg['backoffStrategy']);
    }

    public function testUnknownBackoffFallsBackToExponential(): void
    {
        $cfg = $this->retrySettings([
            'definition' => [
                'workflow' => ['executionBackoffStrategy' => 'jitter'],
            ],
        ]);
        self::assertSame('exponential', $cfg['backoffStrategy']);
    }
}
