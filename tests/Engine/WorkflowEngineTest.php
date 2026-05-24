<?php

declare(strict_types=1);

namespace Knot\Tests\Engine;

use Knot\Connectors\ConnectorInterface;
use Knot\Connectors\DryRunAware;
use Knot\Connectors\Logic\StopAndErrorNode;
use Knot\Engine\ExecutionContext;
use Knot\Engine\WorkflowEngine;
use Knot\Engine\WorkflowValidator;
use Knot\Errors\ExecutionError;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class WorkflowEngineTest extends TestCase
{
    public function testRunsLinearWorkflowAndCapturesLogs(): void
    {
        $engine = $this->engine([
            'action.echo' => $this->echoConnector(),
        ]);

        $result = $engine->execute([
            'schemaVersion' => '1.0',
            'workflow' => ['id' => 1, 'name' => 'Linear'],
            'nodes' => [
                ['id' => 't', 'type' => 'trigger.manual'],
                ['id' => 'a', 'type' => 'action.echo', 'config' => ['payload' => 'hello']],
            ],
            'edges' => [['source' => 't', 'target' => 'a']],
        ], ['user' => 'tester']);

        self::assertSame('hello', $result['nodes']['a']['json']['echoed']);
        $logs = $engine->getLogs();
        self::assertCount(2, $logs);
        self::assertSame('success', $logs[0]['status']);
        self::assertSame('success', $logs[1]['status']);
    }

    public function testDryRunRoutesToSimulateAndMarksOutput(): void
    {
        $engine = $this->engine([
            'action.echo' => $this->echoConnector(),
        ]);

        $result = $engine->execute([
            'schemaVersion' => '1.0',
            'workflow' => ['id' => 1, 'name' => 'Dry'],
            'nodes' => [
                ['id' => 't', 'type' => 'trigger.manual'],
                ['id' => 'a', 'type' => 'action.echo', 'config' => ['payload' => 'live']],
            ],
            'edges' => [['source' => 't', 'target' => 'a']],
        ], [], ['dryRun' => true]);

        self::assertTrue($result['execution']['dryRun']);
        self::assertSame('dry-run', $result['execution']['mode']);
        $output = $result['nodes']['a']['json'];
        self::assertTrue($output['_dryRun']);
        self::assertStringContainsString('would echo', $output['message']);
    }

    public function testContinueOnFailRoutesErrorBranch(): void
    {
        $engine = $this->engine([
            'action.boom' => $this->boomConnector(),
            'action.echo' => $this->echoConnector(),
        ]);

        $result = $engine->execute([
            'schemaVersion' => '1.0',
            'workflow' => ['id' => 1, 'name' => 'Soft fail'],
            'nodes' => [
                ['id' => 't', 'type' => 'trigger.manual'],
                ['id' => 'b', 'type' => 'action.boom', 'config' => ['continueOnFail' => true]],
                ['id' => 'next', 'type' => 'action.echo', 'config' => ['payload' => 'after-error']],
            ],
            'edges' => [
                ['source' => 't', 'target' => 'b'],
                ['source' => 'b', 'target' => 'next', 'sourceHandle' => 'error'],
            ],
        ]);

        self::assertSame('error', $engine->getLogs()[1]['status']);
        self::assertSame('after-error', $result['nodes']['next']['json']['echoed']);
    }

    public function testHasNodeErrorsWhenConnectorFailsWithoutThrowing(): void
    {
        $engine = $this->engine([
            'action.boom' => $this->boomConnector(),
            'action.echo' => $this->echoConnector(),
        ]);

        $engine->execute([
            'schemaVersion' => '1.0',
            'workflow' => ['id' => 1, 'name' => 'Soft fail no route'],
            'nodes' => [
                ['id' => 't', 'type' => 'trigger.manual'],
                ['id' => 'b', 'type' => 'action.boom', 'config' => ['continueOnFail' => true]],
                ['id' => 'next', 'type' => 'action.echo', 'config' => ['payload' => 'skipped']],
            ],
            'edges' => [
                ['source' => 't', 'target' => 'b'],
                ['source' => 'b', 'target' => 'next'],
            ],
        ]);

        self::assertTrue($engine->hasNodeErrors());
        self::assertSame('boom', $engine->firstNodeErrorMessage());
        self::assertSame('skipped', $engine->getLogs()[2]['status']);
    }

    public function testHasNodeErrorsIsFalseOnCleanRun(): void
    {
        $engine = $this->engine([
            'action.echo' => $this->echoConnector(),
        ]);

        $engine->execute([
            'schemaVersion' => '1.0',
            'workflow' => ['id' => 1, 'name' => 'Clean'],
            'nodes' => [
                ['id' => 't', 'type' => 'trigger.manual'],
                ['id' => 'a', 'type' => 'action.echo', 'config' => ['payload' => 'ok']],
            ],
            'edges' => [['source' => 't', 'target' => 'a']],
        ]);

        self::assertFalse($engine->hasNodeErrors());
        self::assertNull($engine->firstNodeErrorMessage());
    }

    public function testStopAndErrorNodeAbortsExecution(): void
    {
        $engine = $this->engine([
            'logic.stop_error' => new StopAndErrorNode(),
        ]);

        $this->expectException(ExecutionError::class);
        $this->expectExceptionMessage('halt');

        $engine->execute([
            'schemaVersion' => '1.0',
            'workflow' => ['id' => 1, 'name' => 'Halt'],
            'nodes' => [
                ['id' => 't', 'type' => 'trigger.manual'],
                ['id' => 's', 'type' => 'logic.stop_error', 'config' => ['message' => 'halt']],
            ],
            'edges' => [['source' => 't', 'target' => 's']],
        ]);
    }

    public function testCredentialIsInjectedIntoContextWhenResolverProvided(): void
    {
        $captured = ['secret' => null];
        $connector = new class ($captured) implements ConnectorInterface {
            /** @param array{secret:?string} $captured */
            public function __construct(private array &$captured)
            {
            }
            public function getMetadata(): array { return ['id' => 'action.spy', 'label' => 'Spy', 'category' => 'test']; }
            public function getConfigSchema(): array { return ['type' => 'object']; }
            public function getCredentialType(): ?string { return 'spy'; }
            public function getInputs(): array { return [['id' => 'main', 'label' => 'Main']]; }
            public function getOutputs(): array { return [['id' => 'main', 'label' => 'Main']]; }
            public function validate(array $config): array { return ['valid' => true, 'errors' => []]; }
            public function execute(array $context): array
            {
                $this->captured['secret'] = $context['credential']['secrets']['apiKey'] ?? null;
                return ['ok' => true];
            }
            public function test(array $config): array { return ['success' => true, 'errors' => []]; }
        };

        $resolver = new class implements \Knot\Credentials\CredentialResolverInterface {
            public function resolve(int $credentialId): ?array
            {
                return $credentialId === 42
                    ? ['id' => 42, 'ref' => 'KNOT-CRED-1-XXXX', 'label' => 'Test', 'type' => 'spy', 'connectorType' => 'spy', 'expiresAt' => null, 'secrets' => ['apiKey' => 'k-secret']]
                    : null;
            }
        };

        $engine = new WorkflowEngine(
            new WorkflowValidator(),
            new ExecutionContext(),
            ['action.spy' => $connector],
            null,
            $resolver
        );

        $engine->execute([
            'schemaVersion' => '1.0',
            'workflow' => ['id' => 1],
            'nodes' => [
                ['id' => 't', 'type' => 'trigger.manual'],
                ['id' => 's', 'type' => 'action.spy', 'credentialId' => 42],
            ],
            'edges' => [['source' => 't', 'target' => 's']],
        ]);

        self::assertSame('k-secret', $captured['secret']);
    }

    public function testRealIterationLoopFansOutBodyForEachItem(): void
    {
        $hits = [];
        $collector = new class ($hits) implements ConnectorInterface {
            /** @param array<int, mixed> $hits */
            public function __construct(private array &$hits)
            {
            }
            public function getMetadata(): array { return ['id' => 'action.collect', 'label' => 'Collect', 'category' => 'test']; }
            public function getConfigSchema(): array { return ['type' => 'object']; }
            public function getCredentialType(): ?string { return null; }
            public function getInputs(): array { return [['id' => 'main', 'label' => 'Main']]; }
            public function getOutputs(): array { return [['id' => 'main', 'label' => 'Main']]; }
            public function validate(array $config): array { return ['valid' => true, 'errors' => []]; }
            public function execute(array $context): array
            {
                $value = $context['json']['value'] ?? $context['json'];
                $this->hits[] = $value;
                return ['doubled' => is_numeric($value) ? ((int) $value * 2) : $value];
            }
            public function test(array $config): array { return ['success' => true, 'errors' => []]; }
        };

        $engine = $this->engine([
            'logic.loop' => new \Knot\Connectors\Logic\LoopNode(),
            'action.collect' => $collector,
            'action.echo' => $this->echoConnector(),
        ]);

        $result = $engine->execute([
            'schemaVersion' => '1.0',
            'workflow' => ['id' => 1, 'name' => 'Loop'],
            'nodes' => [
                ['id' => 't', 'type' => 'trigger.manual'],
                ['id' => 'loop', 'type' => 'logic.loop', 'config' => ['realIteration' => true, 'itemsPath' => '{{$json.numbers}}']],
                ['id' => 'body', 'type' => 'action.collect'],
                ['id' => 'after', 'type' => 'action.echo', 'config' => ['payload' => 'after-loop']],
            ],
            'edges' => [
                ['source' => 't', 'target' => 'loop'],
                ['source' => 'loop', 'target' => 'body', 'sourceHandle' => 'iteration'],
                ['source' => 'loop', 'target' => 'after', 'sourceHandle' => 'done'],
            ],
        ], ['numbers' => [1, 2, 3]]);

        self::assertSame([1, 2, 3], $hits);
        $loopOutput = $result['nodes']['loop']['json'];
        self::assertSame(3, $loopOutput['count']);
        self::assertSame('iteration', $loopOutput['loopMode']);
        self::assertSame([['doubled' => 2], ['doubled' => 4], ['doubled' => 6]], $loopOutput['items']);
        self::assertSame('after-loop', $result['nodes']['after']['json']['echoed']);
    }

    public function testPinnedOutputBypassesConnector(): void
    {
        $called = ['count' => 0];
        $connector = new class ($called) implements ConnectorInterface {
            /** @param array{count:int} $called */
            public function __construct(private array &$called)
            {
            }
            public function getMetadata(): array { return ['id' => 'action.counter', 'label' => 'Counter', 'category' => 'test']; }
            public function getConfigSchema(): array { return ['type' => 'object']; }
            public function getCredentialType(): ?string { return null; }
            public function getInputs(): array { return [['id' => 'main', 'label' => 'Main']]; }
            public function getOutputs(): array { return [['id' => 'main', 'label' => 'Main']]; }
            public function validate(array $config): array { return ['valid' => true, 'errors' => []]; }
            public function execute(array $context): array { $this->called['count']++; return ['actual' => true]; }
            public function test(array $config): array { return ['success' => true, 'errors' => []]; }
        };

        $engine = $this->engine(['action.counter' => $connector]);
        $result = $engine->execute([
            'schemaVersion' => '1.0',
            'workflow' => ['id' => 1],
            'nodes' => [
                ['id' => 't', 'type' => 'trigger.manual'],
                ['id' => 'a', 'type' => 'action.counter', 'pinnedOutput' => ['cached' => 'value']],
            ],
            'edges' => [['source' => 't', 'target' => 'a']],
        ]);

        self::assertSame(0, $called['count']);
        self::assertTrue($result['nodes']['a']['pinned']);
        self::assertSame('value', $result['nodes']['a']['json']['cached']);
    }

    /**
     * @param array<string, ConnectorInterface> $connectors
     */
    private function engine(array $connectors): WorkflowEngine
    {
        return new WorkflowEngine(new WorkflowValidator(), new ExecutionContext(), $connectors);
    }

    private function echoConnector(): ConnectorInterface
    {
        return new class implements ConnectorInterface, DryRunAware {
            public function getMetadata(): array { return ['id' => 'action.echo', 'label' => 'Echo', 'category' => 'test']; }
            public function getConfigSchema(): array { return ['type' => 'object']; }
            public function getCredentialType(): ?string { return null; }
            public function getInputs(): array { return [['id' => 'main', 'label' => 'Main']]; }
            public function getOutputs(): array { return [['id' => 'main', 'label' => 'Main']]; }
            public function validate(array $config): array { return ['valid' => true, 'errors' => []]; }
            public function execute(array $context): array
            {
                $payload = (string) ($context['node']['config']['payload'] ?? '');
                return ['echoed' => $payload];
            }
            public function simulate(array $context): array
            {
                $payload = (string) ($context['node']['config']['payload'] ?? '');
                return ['_dryRun' => true, 'message' => '[DRY-RUN] would echo ' . $payload];
            }
            public function test(array $config): array { return ['success' => true, 'errors' => []]; }
        };
    }

    private function boomConnector(): ConnectorInterface
    {
        return new class implements ConnectorInterface {
            public function getMetadata(): array { return ['id' => 'action.boom', 'label' => 'Boom', 'category' => 'test']; }
            public function getConfigSchema(): array { return ['type' => 'object']; }
            public function getCredentialType(): ?string { return null; }
            public function getInputs(): array { return [['id' => 'main', 'label' => 'Main']]; }
            public function getOutputs(): array { return [['id' => 'main', 'label' => 'Main']]; }
            public function validate(array $config): array { return ['valid' => true, 'errors' => []]; }
            public function execute(array $context): array { throw new RuntimeException('boom'); }
            public function test(array $config): array { return ['success' => true, 'errors' => []]; }
        };
    }
}
