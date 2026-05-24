<?php

declare(strict_types=1);

namespace Knot\Tests\Security;

use Knot\Connectors\RiskLevels;
use Knot\Security\WorkflowRiskAnalyzer;
use PHPUnit\Framework\TestCase;

final class WorkflowRiskAnalyzerTest extends TestCase
{
    public function testEmptyDefinitionIsSafe(): void
    {
        $report = (new WorkflowRiskAnalyzer())->analyze([
            'schemaVersion' => '1.0',
            'nodes' => [],
            'edges' => [],
        ]);
        self::assertSame(RiskLevels::SAFE, $report->worstLevel);
        self::assertFalse($report->hasCritical());
        self::assertSame([], $report->criticalNodes);
    }

    public function testManualTriggerIsSafe(): void
    {
        $report = (new WorkflowRiskAnalyzer())->analyze([
            'nodes' => [
                [
                    'id' => 't1',
                    'type' => 'trigger.manual',
                    'label' => 'Start',
                    'config' => [],
                ],
            ],
        ]);
        self::assertSame(RiskLevels::SAFE, $report->worstLevel);
    }

    public function testDolibarrDeleteOperationIsCritical(): void
    {
        $report = (new WorkflowRiskAnalyzer())->analyze([
            'nodes' => [
                [
                    'id' => 'obj1',
                    'type' => 'dolibarr.object',
                    'label' => 'Delete invoice',
                    'config' => [
                        'operation' => 'delete',
                        'objectType' => 'facture',
                    ],
                ],
            ],
        ]);
        self::assertTrue($report->hasCritical());
        self::assertSame(RiskLevels::CRITICAL, $report->worstLevel);
        self::assertCount(1, $report->criticalNodes);
        self::assertSame('obj1', $report->criticalNodes[0]['nodeId']);
        self::assertStringContainsString('delete', $report->criticalNodes[0]['riskReason']);
    }

    public function testDolibarrFetchStillBoundedByConnectorMetaCritical(): void
    {
        $report = (new WorkflowRiskAnalyzer())->analyze([
            'nodes' => [
                [
                    'id' => 'obj1',
                    'type' => 'dolibarr.object',
                    'label' => 'Read',
                    'config' => ['operation' => 'fetch'],
                ],
            ],
        ]);
        self::assertSame(RiskLevels::CRITICAL, $report->worstLevel);
        self::assertTrue($report->hasCritical());
    }

    public function testDiscoveryUnverifiedEscalatesWithinCriticalConnector(): void
    {
        $report = (new WorkflowRiskAnalyzer())->analyze([
            'nodes' => [
                [
                    'id' => 'obj1',
                    'type' => 'dolibarr.object',
                    'label' => 'Expert',
                    'config' => [
                        'operation' => 'fetch',
                        'objectRegistryMode' => 'discovery_unverified',
                    ],
                ],
            ],
        ]);
        self::assertSame(RiskLevels::CRITICAL, $report->worstLevel);
    }
}
