<?php

declare(strict_types=1);

namespace Knot\Tests\Security;

use Knot\Connectors\RiskLevels;
use Knot\Security\WorkflowRiskAnalyzer;
use PHPUnit\Framework\TestCase;

final class WorkflowRiskAnalyzerExtendedTest extends TestCase
{
    public function testLogicSetIsSafe(): void
    {
        $report = (new WorkflowRiskAnalyzer())->analyze([
            'nodes' => [
                ['id' => 's1', 'type' => 'logic.set', 'label' => 'Set', 'config' => []],
            ],
        ]);
        self::assertSame(RiskLevels::SAFE, $report->worstLevel);
        self::assertFalse($report->hasCritical());
    }

    public function testActionEmailIsCautionOrHigher(): void
    {
        $report = (new WorkflowRiskAnalyzer())->analyze([
            'nodes' => [
                ['id' => 'e1', 'type' => 'action.email', 'label' => 'Send', 'config' => ['to' => 'ops@example.com']],
            ],
        ]);
        self::assertNotSame(RiskLevels::SAFE, $report->worstLevel);
    }

    public function testAggregatesSideEffectsAcrossNodes(): void
    {
        $report = (new WorkflowRiskAnalyzer())->analyze([
            'nodes' => [
                ['id' => 't1', 'type' => 'trigger.manual', 'config' => []],
                ['id' => 'e1', 'type' => 'action.email', 'config' => []],
            ],
        ]);
        self::assertNotEmpty($report->sideEffects);
    }

    public function testAllNodesListedInReport(): void
    {
        $report = (new WorkflowRiskAnalyzer())->analyze([
            'nodes' => [
                ['id' => 'a', 'type' => 'trigger.manual', 'config' => []],
                ['id' => 'b', 'type' => 'logic.set', 'config' => []],
            ],
        ]);
        self::assertCount(2, $report->nodes);
    }

    public function testIgnoresNonArrayNodeEntries(): void
    {
        $report = (new WorkflowRiskAnalyzer())->analyze([
            'nodes' => ['bad', ['id' => 't1', 'type' => 'trigger.manual', 'config' => []]],
        ]);
        self::assertCount(1, $report->nodes);
    }

    public function testDolibarrUpdateOperationUsesConnectorRisk(): void
    {
        $report = (new WorkflowRiskAnalyzer())->analyze([
            'nodes' => [
                [
                    'id' => 'u1',
                    'type' => 'dolibarr.object',
                    'config' => ['operation' => 'update', 'objectType' => 'societe'],
                ],
            ],
        ]);
        self::assertNotSame(RiskLevels::SAFE, $report->worstLevel);
    }

    public function testReportToArrayIncludesWorstLevel(): void
    {
        $report = (new WorkflowRiskAnalyzer())->analyze(['nodes' => []]);
        $arr = $report->toArray();
        self::assertArrayHasKey('worstLevel', $arr);
        self::assertArrayHasKey('criticalNodes', $arr);
        self::assertArrayHasKey('hasCritical', $arr);
    }
}
