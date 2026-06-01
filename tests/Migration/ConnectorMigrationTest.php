<?php

declare(strict_types=1);

namespace Knot\Tests\Migration;

use Knot\Connectors\ConnectorRegistry;
use Knot\Migration\ConnectorMigration;
use Knot\Repository\WorkflowRepository;
use PHPUnit\Framework\TestCase;

/**
 * Guard against accidental drift between:
 *  - the canonical migration list in {@see ConnectorMigration::MIGRATED_TO_PRO_PACK}
 *  - the actual state of {@see ConnectorRegistry::all()} after V2.5.0b
 *
 * If a connector listed as migrated is still registered in Core, the
 * test fails. Conversely, if Core grows a brand-new SaaS connector
 * that should also be Pro Pack-only, the operator MUST add it to the
 * MIGRATED_TO_PRO_PACK list explicitly — there is no "auto-detect"
 * here on purpose: the list is a billing contract.
 */
final class ConnectorMigrationTest extends TestCase
{
    public function testMigratedListContainsAllExpectedIds(): void
    {
        // Snapshot of the V2.5.0b-frozen list. If it ever drifts,
        // update both the constant and this test in the same commit.
        $expected = [
            'action.airtable',
            'action.ai_anthropic',
            'action.ai_gemini',
            'action.ai_mistral',
            'action.ai_ollama',
            'action.ai_openai',
            'action.github',
            'action.gitlab',
            'action.gmail',
            'action.google_calendar',
            'action.google_drive',
            'action.google_sheets',
            'action.http',
            'action.notion',
            'action.ovh_sms',
            'action.prestashop',
            'action.shopify',
            'action.sftp',
            'action.slack',
            'action.discord',
            'action.stripe',
            'action.telegram',
            'action.twilio_sms',
            'action.whatsapp_cloud',
            'action.whatsapp_twilio',
            'action.woocommerce',
            'notification.alert_fanout',
            'trigger.shopify_webhook',
            'trigger.stripe_webhook',
        ];
        sort($expected);
        $actual = ConnectorMigration::migratedConnectorIds();
        sort($actual);
        self::assertSame($expected, $actual, 'Migration list drifted from the Pro Pack contract.');
    }

    public function testMigratedListHasExactlyTwentyNineUniqueIds(): void
    {
        $ids = ConnectorMigration::migratedConnectorIds();
        self::assertCount(29, $ids, 'Exactly 29 connectors are covered by the Pro Pack migration contract.');
        self::assertSame(count($ids), count(array_unique($ids)), 'Migrated connector ids must be unique.');
    }

    /**
     * Hard-remove invariant: none of the migrated ids may resurface in
     * Core's ConnectorRegistry. Re-registering any of them would silently
     * give Pro Pack features away for free.
     *
     * @dataProvider migratedIdsProvider
     */
    public function testMigratedConnectorIsNotRegisteredInCore(string $migratedId): void
    {
        $registered = array_keys((new ConnectorRegistry())->all());
        self::assertNotContains(
            $migratedId,
            $registered,
            sprintf(
                'Connector "%s" was migrated to knot-pro-pack but is still registered in Knot Core.',
                $migratedId
            )
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function migratedIdsProvider(): array
    {
        $cases = [];
        foreach (ConnectorMigration::migratedConnectorIds() as $id) {
            $cases[$id] = [$id];
        }
        return $cases;
    }

    public function testIsMigratedHelper(): void
    {
        self::assertTrue(ConnectorMigration::isMigrated('action.stripe'));
        self::assertTrue(ConnectorMigration::isMigrated('action.ai_openai'));
        self::assertTrue(ConnectorMigration::isMigrated('action.ai_ollama'));
        self::assertTrue(ConnectorMigration::isMigrated('action.http'));
        self::assertTrue(ConnectorMigration::isMigrated('notification.alert_fanout'));
        self::assertFalse(ConnectorMigration::isMigrated('logic.if'));
        self::assertFalse(ConnectorMigration::isMigrated(''));
    }

    public function testScanImpactedWorkflowsReturnsEmptyWhenNoMatch(): void
    {
        $repo = $this->makeRepoStub(workflows: [
            ['id' => 1, 'ref' => 'WF-001', 'label' => 'Email only', 'status' => 'active', 'updatedAt' => '2026-04-01'],
        ], definitions: [
            1 => ['nodes' => [
                ['id' => 'n_1', 'connector' => 'logic.if'],
                ['id' => 'n_2', 'connector' => 'action.email'],
            ]],
        ]);
        $migration = new ConnectorMigration($repo);
        self::assertSame([], $migration->scanImpactedWorkflows(1));
    }

    public function testScanImpactedWorkflowsDetectsHits(): void
    {
        $repo = $this->makeRepoStub(workflows: [
            ['id' => 7, 'ref' => 'WF-007', 'label' => 'Stripe invoice', 'status' => 'active', 'updatedAt' => '2026-04-02'],
            ['id' => 8, 'ref' => 'WF-008', 'label' => 'Daily Notion sync', 'status' => 'active', 'updatedAt' => '2026-04-02'],
        ], definitions: [
            7 => ['nodes' => [
                ['id' => 'n_1', 'connector' => 'trigger.manual'],
                ['id' => 'n_2', 'connector' => 'action.stripe'],
                ['id' => 'n_3', 'connector' => 'action.stripe'],
            ]],
            8 => ['nodes' => [
                ['id' => 'n_1', 'connector' => 'trigger.cron'],
                ['id' => 'n_2', 'connector' => 'action.notion'],
            ]],
        ]);

        $impacted = (new ConnectorMigration($repo))->scanImpactedWorkflows(1);
        self::assertCount(2, $impacted);

        $byId = [];
        foreach ($impacted as $row) {
            $byId[$row['workflowId']] = $row;
        }
        self::assertCount(2, $byId[7]['impactedNodes']);
        self::assertSame(['action.stripe'], $byId[7]['distinctConnectorIds']);
        self::assertCount(1, $byId[8]['impactedNodes']);
        self::assertSame(['action.notion'], $byId[8]['distinctConnectorIds']);
    }

    public function testScanImpactedWorkflowsIgnoresMigratedConnectorsAlreadyAvailable(): void
    {
        $repo = $this->makeRepoStub(workflows: [
            ['id' => 7, 'ref' => 'WF-007', 'label' => 'Stripe invoice', 'status' => 'active', 'updatedAt' => '2026-04-02'],
        ], definitions: [
            7 => ['nodes' => [
                ['id' => 'n_2', 'connector' => 'action.stripe'],
            ]],
        ]);

        $impacted = (new ConnectorMigration($repo))->scanImpactedWorkflows(1, ['action.stripe', 'logic.if']);
        self::assertSame([], $impacted);
    }

    /**
     * `WorkflowRepository` is final; PHPUnit's createMock() works around
     * that by reflectively producing a non-final test double rather than
     * trying to extend the repo at compile time.
     *
     * @param array<int, array<string, mixed>> $workflows
     * @param array<int, array<string, mixed>> $definitions Map workflowId → decoded definition
     */
    private function makeRepoStub(array $workflows, array $definitions): WorkflowRepository
    {
        $repo = $this->createMock(WorkflowRepository::class);
        $repo->method('list')->willReturn($workflows);
        $repo->method('fetch')->willReturnCallback(
            static function (int $workflowId) use ($definitions): ?array {
                if (!isset($definitions[$workflowId])) {
                    return null;
                }
                return [
                    'id'              => $workflowId,
                    'json_definition' => json_encode($definitions[$workflowId]),
                ];
            }
        );
        return $repo;
    }
}
