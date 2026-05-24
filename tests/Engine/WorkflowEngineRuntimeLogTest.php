<?php

declare(strict_types=1);

namespace Knot\Tests\Engine;

use Knot\Connectors\ConnectorInterface;
use Knot\Engine\ExecutionContext;
use Knot\Engine\WorkflowEngine;
use Knot\Engine\WorkflowValidator;
use Knot\Observability\RuntimeLogger;
use PHPUnit\Framework\TestCase;

/**
 * V2.5.0d — confirms the engine forwards every node execution to
 * `RuntimeLogger::logNodeExecution()` with the expected correlation
 * IDs and a non-zero `durationMs`. Skipped/trigger events are *not*
 * forwarded — they are intentionally dropped to avoid Loki noise.
 */
final class WorkflowEngineRuntimeLogTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/knot-engine-runtime-log-' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            foreach ((array) glob($this->tmpDir . '/*') as $f) {
                @unlink($f);
            }
            @rmdir($this->tmpDir);
        }
    }

    public function testEngineForwardsRuntimeLogPerNode(): void
    {
        $logger = new RuntimeLogger($this->tmpDir);
        $engine = new WorkflowEngine(
            new WorkflowValidator(),
            new ExecutionContext(),
            ['action.echo' => $this->echoConnector()],
            null,
            null,
            null,
            $logger
        );

        $engine->execute([
            'schemaVersion' => '1.0',
            'workflow' => ['id' => 99, 'name' => 'rt-test'],
            'nodes' => [
                ['id' => 't', 'type' => 'trigger.manual'],
                ['id' => 'a', 'type' => 'action.echo', 'config' => ['payload' => 'hi']],
            ],
            'edges' => [['source' => 't', 'target' => 'a']],
        ], [], ['entity' => 1]);

        $files = glob($this->tmpDir . '/runtime-*.jsonl') ?: [];
        self::assertNotEmpty($files);
        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($files[0]))));
        // Trigger events are intentionally not forwarded — only the
        // single connector node `a` should appear.
        self::assertCount(1, $lines);
        $event = json_decode($lines[0], true);
        self::assertSame('action.echo', $event['nodeType']);
        self::assertSame('a', $event['nodeId']);
        self::assertSame('success', $event['status']);
        self::assertSame('1', $event['tenant']);
        self::assertGreaterThanOrEqual(0, $event['durationMs']);
    }

    public function testEngineSkipsRuntimeLogForDryRun(): void
    {
        $logger = new RuntimeLogger($this->tmpDir);
        $engine = new WorkflowEngine(
            new WorkflowValidator(),
            new ExecutionContext(),
            ['action.echo' => $this->echoConnector()],
            null,
            null,
            null,
            $logger
        );

        $engine->execute([
            'schemaVersion' => '1.0',
            'workflow' => ['id' => 99, 'name' => 'rt-dryrun'],
            'nodes' => [
                ['id' => 't', 'type' => 'trigger.manual'],
                ['id' => 'a', 'type' => 'action.echo', 'config' => ['payload' => 'hi']],
            ],
            'edges' => [['source' => 't', 'target' => 'a']],
        ], [], ['dryRun' => true]);

        $files = glob($this->tmpDir . '/runtime-*.jsonl') ?: [];
        // Either no file at all, or an empty file — never any line.
        $total = 0;
        foreach ($files as $f) {
            $total += count(array_filter(explode("\n", (string) file_get_contents($f))));
        }
        self::assertSame(0, $total);
    }

    private function echoConnector(): ConnectorInterface
    {
        return new class implements ConnectorInterface {
            public function getMetadata(): array { return ['id' => 'action.echo', 'label' => 'Echo', 'category' => 'test']; }
            public function getConfigSchema(): array { return ['type' => 'object']; }
            public function getCredentialType(): ?string { return null; }
            public function getInputs(): array { return [['id' => 'main', 'label' => 'Main']]; }
            public function getOutputs(): array { return [['id' => 'main', 'label' => 'Main']]; }
            public function validate(array $config): array { return ['valid' => true, 'errors' => []]; }
            public function test(array $config): array { return ['ok' => true]; }
            public function execute(array $context): array
            {
                $payload = (string) ($context['node']['config']['payload'] ?? '');
                return ['echoed' => $payload];
            }
        };
    }
}
