<?php

declare(strict_types=1);

namespace Knot\Tests\Engine;

use Knot\Connectors\Communication\EmailAction;
use Knot\Connectors\Dolibarr\SqlQuery;
use Knot\Connectors\Logic\LoopNode;
use Knot\Connectors\Triggers\CronTrigger;
use Knot\Engine\ExecutionContext;
use Knot\Engine\WorkflowEngine;
use Knot\Engine\WorkflowValidator;
use Knot\Migration\ConnectorMigration;
use PHPUnit\Framework\TestCase;

/**
 * Guards {@see examples/starter/02-relance-facture-impayee.knot.json}: overdue-invoice
 * reminder uses free Core {@see EmailAction} (action.email), not Pro Pack.
 */
final class ShowcaseStarter02WorkflowEngineTest extends TestCase
{
    public function testStarter02UsesFreeCoreEmailConnector(): void
    {
        $definition = $this->loadStarter02Definition();
        $issues = (new WorkflowValidator())->validateAll($definition);
        $errors = array_filter($issues, static fn (array $i): bool => ($i['severity'] ?? '') === 'error');
        self::assertSame([], array_values($errors), 'Starter 02 validation errors: ' . json_encode($errors));

        $emailNode = null;
        foreach ($definition['nodes'] ?? [] as $node) {
            if (is_array($node) && ($node['type'] ?? '') === 'action.email') {
                $emailNode = $node;
                break;
            }
        }
        self::assertIsArray($emailNode, 'Starter 02 must include an action.email node.');
        self::assertFalse(
            ConnectorMigration::isMigrated('action.email'),
            'action.email must remain a free Core connector.'
        );

        $config = is_array($emailNode['config'] ?? null) ? $emailNode['config'] : [];
        self::assertArrayNotHasKey('credentialId', $config, 'action.email uses Dolibarr SMTP, not Knot credentials.');

        $sim = (new EmailAction())->simulate([
            'node' => ['config' => $config],
            'json' => [],
            'loop' => [
                'item' => [
                    'soc_email' => 'billing@example.com',
                    'ref' => 'FA2501-0042',
                    'soc_name' => 'Example Industries',
                    'total_ttc' => '1200.00',
                    'date_lim_reglement' => '2026-04-01',
                ],
            ],
        ]);
        self::assertTrue($sim['_dryRun']);
        self::assertStringContainsString('billing@example.com', (string) ($sim['to'] ?? ''));
        self::assertStringContainsString('FA2501-0042', (string) ($sim['subject'] ?? ''));
    }

    public function testStarter02LoopEmailEdgeUsesIterationHandle(): void
    {
        $definition = $this->loadStarter02Definition();
        $found = false;
        foreach ($definition['edges'] ?? [] as $edge) {
            if (!is_array($edge)) {
                continue;
            }
            if (($edge['source'] ?? '') === 'loop_invoices' && ($edge['target'] ?? '') === 'send_email') {
                self::assertSame('iteration', $edge['sourceHandle'] ?? null);
                $found = true;
            }
        }
        self::assertTrue($found, 'Starter 02 must wire loop_invoices → send_email.');

        foreach ($definition['nodes'] ?? [] as $node) {
            if (!is_array($node) || ($node['id'] ?? '') !== 'sql_overdue') {
                continue;
            }
            $config = is_array($node['config'] ?? null) ? $node['config'] : [];
            self::assertArrayNotHasKey('splitItems', $config);
        }
    }

    public function testStarter02DryRunWalksOneLoopIterationAndEmail(): void
    {
        $definition = $this->loadStarter02Definition();
        $engine = new WorkflowEngine(
            new WorkflowValidator(),
            new ExecutionContext(),
            [
                'trigger.cron' => new CronTrigger(),
                'dolibarr.sql_query' => new SqlQuery(),
                'logic.loop' => new LoopNode(),
                'action.email' => new EmailAction(),
            ]
        );

        $result = $engine->execute($definition, [], ['dryRun' => true]);
        self::assertTrue($result['execution']['dryRun'] ?? false);

        $sql = $result['nodes']['sql_overdue']['json'] ?? null;
        self::assertIsArray($sql);
        self::assertTrue($sql['_dryRun'] ?? false);
        self::assertSame(1, $sql['count'] ?? null);
        self::assertCount(1, $sql['rows'] ?? []);

        $loop = $result['nodes']['loop_invoices']['json'] ?? null;
        self::assertIsArray($loop);
        self::assertSame(1, $loop['count'] ?? null);
        $aggregated = $loop['items'][0] ?? null;
        self::assertIsArray($aggregated);
        self::assertTrue($aggregated['_dryRun'] ?? false);
        self::assertStringContainsString('@example.com', (string) ($aggregated['to'] ?? ''));
        self::assertStringContainsString('FAC-SIM', (string) ($aggregated['subject'] ?? ''));

        $emailSuccess = null;
        $statusByNode = [];
        foreach ($engine->getLogs() as $log) {
            $nodeId = (string) ($log['nodeId'] ?? '');
            $status = (string) ($log['status'] ?? '');
            if ($nodeId === 'send_email' && $status === 'success') {
                $emailSuccess = $log;
            }
            // Prefer non-skipped statuses when the body node is later marked skipped.
            if ($status !== 'skipped' || !isset($statusByNode[$nodeId])) {
                $statusByNode[$nodeId] = $status;
            }
        }
        self::assertIsArray($emailSuccess, 'Loop body must dry-run action.email once.');
        self::assertTrue($emailSuccess['output']['_dryRun'] ?? false);
        self::assertSame('success', $statusByNode['sql_overdue'] ?? null);
        self::assertSame('success', $statusByNode['loop_invoices'] ?? null);
        self::assertSame('success', $statusByNode['send_email'] ?? null);
    }

    /** @return array<string, mixed> */
    private function loadStarter02Definition(): array
    {
        $root = dirname(__DIR__, 2);
        $path = $root . '/examples/starter/02-relance-facture-impayee.knot.json';
        $json = file_get_contents($path);
        self::assertNotFalse($json, 'Starter 02 file missing: ' . $path);
        /** @var array<string, mixed> $envelope */
        $envelope = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $definition = $envelope['workflow']['definition'] ?? null;
        self::assertIsArray($definition);

        return $definition;
    }
}
