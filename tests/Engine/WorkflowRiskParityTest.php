<?php

declare(strict_types=1);

namespace Knot\Tests\Engine;

use Knot\Connectors\RiskLevels;
use Knot\Security\WorkflowRiskAnalyzer;
use PHPUnit\Framework\TestCase;

/**
 * Ensures WorkflowRiskAnalyzer stays aligned with tests/fixtures/risk-parity-workflows.json
 * (same matrix as frontend resolveNodeRisk parity test).
 */
final class WorkflowRiskParityTest extends TestCase
{
    public function testFixtureParityWithWorkflowRiskAnalyzer(): void
    {
        $path = dirname(__DIR__) . '/fixtures/risk-parity-workflows.json';
        self::assertFileExists($path);
        $raw = file_get_contents($path);
        self::assertNotFalse($raw);
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        $cases = $data['cases'] ?? null;
        self::assertIsArray($cases);
        self::assertGreaterThanOrEqual(20, count($cases), 'fixture must keep >= 20 parity rows');

        $analyzer = new WorkflowRiskAnalyzer();

        foreach ($cases as $row) {
            self::assertIsArray($row);
            $caseId = (string) ($row['id'] ?? '');
            $type = (string) ($row['type'] ?? '');
            $config = is_array($row['config'] ?? null) ? $row['config'] : [];
            $expected = (string) ($row['expectedRiskLevel'] ?? '');
            self::assertNotSame('', $caseId);
            self::assertContains($expected, RiskLevels::ALL, $caseId);

            $report = $analyzer->analyze([
                'nodes' => [
                    [
                        'id' => 'n-parity',
                        'type' => $type,
                        'label' => $caseId,
                        'config' => $config,
                    ],
                ],
            ]);

            self::assertSame(
                $expected,
                $report->worstLevel,
                sprintf('case %s (%s): expected %s', $caseId, $type, $expected)
            );
        }
    }
}
