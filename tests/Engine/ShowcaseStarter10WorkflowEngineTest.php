<?php

declare(strict_types=1);

namespace Knot\Tests\Engine;

use Knot\Connectors\Communication\EmailAction;
use Knot\Connectors\Logic\IfElseNode;
use Knot\Connectors\Logic\LoopNode;
use Knot\Connectors\Logic\SetNode;
use Knot\Connectors\Logic\SwitchNode;
use Knot\Engine\ExecutionContext;
use Knot\Engine\WorkflowEngine;
use Knot\Engine\WorkflowValidator;
use PHPUnit\Framework\TestCase;

/**
 * Golden path for {@see examples/starter/10-complex-routing-email-report.knot.json}:
 * nested If/Switch/Loop then action.email (dry-run — no SMTP required).
 */
final class ShowcaseStarter10WorkflowEngineTest extends TestCase
{
    public function testStarter10DryRunCompletesRoutingAndSimulatesEmailReport(): void
    {
        $definition = $this->loadStarter10Definition();
        $engine = new WorkflowEngine(
            new WorkflowValidator(),
            new ExecutionContext(),
            [
                'logic.set' => new SetNode(),
                'logic.if' => new IfElseNode(),
                'logic.switch' => new SwitchNode(),
                'logic.loop' => new LoopNode(),
                'action.email' => new EmailAction(),
            ]
        );

        $result = $engine->execute($definition, [], ['dryRun' => true]);

        self::assertTrue($result['execution']['dryRun'] ?? false);

        $items = $result['nodes']['loop_lines']['json']['items'] ?? null;
        self::assertIsArray($items);
        self::assertCount(5, $items);

        $email = $result['nodes']['send_report']['json'] ?? null;
        self::assertIsArray($email);
        self::assertTrue($email['_dryRun'] ?? false);
        self::assertSame('knot-admin@example.com', $email['to'] ?? null);
        self::assertStringContainsString('Complex routing report', (string) ($email['subject'] ?? ''));
        self::assertStringContainsString('5', (string) ($email['subject'] ?? ''));

        $statusByNode = [];
        foreach ($engine->getLogs() as $log) {
            $statusByNode[(string) ($log['nodeId'] ?? '')] = (string) ($log['status'] ?? '');
        }
        self::assertSame('success', $statusByNode['send_report'] ?? null);
        self::assertSame('skipped', $statusByNode['stub_empty_cart'] ?? null);
    }

    /** @return array<string, mixed> */
    private function loadStarter10Definition(): array
    {
        $root = dirname(__DIR__, 2);
        $path = $root . '/examples/starter/10-complex-routing-email-report.knot.json';
        $json = file_get_contents($path);
        self::assertNotFalse($json, 'Starter 10 file missing: ' . $path);
        /** @var array<string, mixed> $envelope */
        $envelope = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $definition = $envelope['workflow']['definition'] ?? null;
        self::assertIsArray($definition);

        return $definition;
    }
}
