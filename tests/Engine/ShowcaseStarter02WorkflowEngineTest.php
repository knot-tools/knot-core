<?php

declare(strict_types=1);

namespace Knot\Tests\Engine;

use Knot\Connectors\Communication\EmailAction;
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
