<?php

declare(strict_types=1);

namespace Knot\Tests\Engine;

use Knot\Connectors\ConnectorInterface;
use Knot\Connectors\DryRunAware;
use Knot\Engine\ExecutionContext;
use Knot\Engine\WorkflowEngine;
use Knot\Engine\WorkflowValidator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Coverage for the retry / backoff loop inside WorkflowEngine.
 *
 * Sleeps are intentionally tested with very small backoff values (1-2 ms)
 * so the suite stays fast (sub-second).
 */
final class WorkflowEngineRetryTest extends TestCase
{
    public function testFailsThenSucceedsWithinMaxAttempts(): void
    {
        $flaky = new FlakyConnector(succeedOnAttempt: 2);
        $engine = $this->engine(['action.flaky' => $flaky]);

        $result = $engine->execute([
            'schemaVersion' => '1.0',
            'workflow' => ['id' => 1, 'name' => 'Flaky'],
            'nodes' => [
                ['id' => 't', 'type' => 'trigger.manual'],
                [
                    'id' => 'f',
                    'type' => 'action.flaky',
                    'config' => ['retry' => ['maxAttempts' => 3, 'backoffMs' => 1]],
                ],
            ],
            'edges' => [['source' => 't', 'target' => 'f']],
        ]);

        self::assertSame(2, $flaky->attempts);
        self::assertSame(2, $result['nodes']['f']['json']['_retryAttempts']);
        self::assertSame('success', $engine->getLogs()[1]['status']);
    }

    public function testExhaustsAttemptsAndPropagatesLastException(): void
    {
        $always = new FlakyConnector(succeedOnAttempt: 999);
        $engine = $this->engine(['action.flaky' => $always]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('boom on attempt 3');

        try {
            $engine->execute([
                'schemaVersion' => '1.0',
                'workflow' => ['id' => 1, 'name' => 'Always fails'],
                'nodes' => [
                    ['id' => 't', 'type' => 'trigger.manual'],
                    [
                        'id' => 'f',
                        'type' => 'action.flaky',
                        'config' => ['retry' => ['maxAttempts' => 3, 'backoffMs' => 1]],
                    ],
                ],
                'edges' => [['source' => 't', 'target' => 'f']],
            ]);
        } finally {
            self::assertSame(3, $always->attempts);
        }
    }

    public function testExponentialBackoffIncreasesPerAttempt(): void
    {
        $flaky = new FlakyConnector(succeedOnAttempt: 4);
        $engine = $this->engine(['action.flaky' => $flaky]);

        $startedAt = microtime(true);
        $engine->execute([
            'schemaVersion' => '1.0',
            'workflow' => ['id' => 1, 'name' => 'Backoff'],
            'nodes' => [
                ['id' => 't', 'type' => 'trigger.manual'],
                [
                    'id' => 'f',
                    'type' => 'action.flaky',
                    'config' => ['retry' => ['maxAttempts' => 4, 'backoffMs' => 5, 'exponential' => true]],
                ],
            ],
            'edges' => [['source' => 't', 'target' => 'f']],
        ]);
        $elapsedMs = (microtime(true) - $startedAt) * 1000;

        // Backoffs between attempts: 5ms (after 1st fail) + 10ms + 20ms = 35ms minimum.
        // We assert > 30ms to keep the test resilient against scheduler jitter.
        self::assertGreaterThan(30, $elapsedMs);
        self::assertSame(4, $flaky->attempts);
    }

    public function testRetryAttemptsFlagAbsentOnFirstSuccess(): void
    {
        $instant = new FlakyConnector(succeedOnAttempt: 1);
        $engine = $this->engine(['action.flaky' => $instant]);

        $result = $engine->execute([
            'schemaVersion' => '1.0',
            'workflow' => ['id' => 1, 'name' => 'No retry needed'],
            'nodes' => [
                ['id' => 't', 'type' => 'trigger.manual'],
                [
                    'id' => 'f',
                    'type' => 'action.flaky',
                    'config' => ['retry' => ['maxAttempts' => 3, 'backoffMs' => 1]],
                ],
            ],
            'edges' => [['source' => 't', 'target' => 'f']],
        ]);

        self::assertArrayNotHasKey('_retryAttempts', $result['nodes']['f']['json']);
        self::assertSame(1, $instant->attempts);
    }

    public function testDryRunSkipsBackoffSleep(): void
    {
        $always = new FlakyConnector(succeedOnAttempt: 999);
        $engine = $this->engine(['action.flaky' => $always]);

        $startedAt = microtime(true);
        try {
            $engine->execute([
                'schemaVersion' => '1.0',
                'workflow' => ['id' => 1, 'name' => 'Dry retry'],
                'nodes' => [
                    ['id' => 't', 'type' => 'trigger.manual'],
                    [
                        'id' => 'f',
                        'type' => 'action.flaky',
                        'config' => ['retry' => ['maxAttempts' => 3, 'backoffMs' => 250]],
                    ],
                ],
                'edges' => [['source' => 't', 'target' => 'f']],
            ], [], ['dryRun' => true]);
        } catch (RuntimeException) {
            // expected
        }
        $elapsedMs = (microtime(true) - $startedAt) * 1000;

        // 2 backoffs of 250ms would give >500ms in non-dry mode.
        // Dry mode skips usleep() so the run must complete an order of magnitude faster.
        self::assertLessThan(200, $elapsedMs);
    }

    public function testMalformedRetryConfigDefaultsToSingleAttempt(): void
    {
        $always = new FlakyConnector(succeedOnAttempt: 999);
        $engine = $this->engine(['action.flaky' => $always]);

        try {
            $engine->execute([
                'schemaVersion' => '1.0',
                'workflow' => ['id' => 1, 'name' => 'Malformed retry'],
                'nodes' => [
                    ['id' => 't', 'type' => 'trigger.manual'],
                    // retry not an array
                    ['id' => 'f', 'type' => 'action.flaky', 'config' => ['retry' => 'oops']],
                ],
                'edges' => [['source' => 't', 'target' => 'f']],
            ]);
            self::fail('Expected exception');
        } catch (RuntimeException) {
            self::assertSame(1, $always->attempts);
        }
    }

    /**
     * @param array<string, ConnectorInterface> $connectors
     */
    private function engine(array $connectors): WorkflowEngine
    {
        return new WorkflowEngine(new WorkflowValidator(), new ExecutionContext(), $connectors);
    }
}

/**
 * Connector that fails the first N-1 attempts then returns success.
 */
final class FlakyConnector implements ConnectorInterface, DryRunAware
{
    public int $attempts = 0;

    public function __construct(public int $succeedOnAttempt = 1)
    {
    }

    public function getMetadata(): array
    {
        return ['id' => 'action.flaky', 'label' => 'Flaky', 'category' => 'test'];
    }

    public function getConfigSchema(): array
    {
        return ['type' => 'object'];
    }

    public function getCredentialType(): ?string
    {
        return null;
    }

    public function getInputs(): array
    {
        return [['id' => 'main', 'label' => 'Main']];
    }

    public function getOutputs(): array
    {
        return [['id' => 'main', 'label' => 'Main']];
    }

    public function validate(array $config): array
    {
        return ['valid' => true, 'errors' => []];
    }

    public function execute(array $context): array
    {
        $this->attempts++;
        if ($this->attempts < $this->succeedOnAttempt) {
            throw new RuntimeException('boom on attempt ' . $this->attempts);
        }
        return ['ok' => true, 'finalAttempt' => $this->attempts];
    }

    public function simulate(array $context): array
    {
        // Even simulate fails to exercise dry-run retry path without sleeping.
        $this->attempts++;
        throw new RuntimeException('dry boom on attempt ' . $this->attempts);
    }

    public function test(array $config): array
    {
        return ['success' => true, 'errors' => []];
    }
}
