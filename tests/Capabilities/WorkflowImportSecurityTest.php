<?php

declare(strict_types=1);

namespace Knot\Tests\Capabilities;

use Knot\Capabilities\WorkflowImportAnalyzer;
use Knot\Engine\WorkflowValidator;
use Knot\Marketplace\TierGate;
use Knot\Marketplace\WorkflowTierAuditor;
use Knot\Tests\Marketplace\Fixtures\StubExtensionRegistry;
use PHPUnit\Framework\TestCase;

/**
 * G-P2-07 — negative paths for bulk import and validation (no HTTP stack).
 */
final class WorkflowImportSecurityTest extends TestCase
{
    public function testWorkflowValidatorRejectsInvalidJsonGraph(): void
    {
        $validator = new WorkflowValidator(['logic.set', 'trigger.manual']);
        $issues = $validator->validateAll([
            'schemaVersion' => '1.0',
            'workflow' => ['name' => 'bad'],
            'nodes' => [
                [
                    'id' => 'n1',
                    'type' => 'logic.set',
                    'data' => ['type' => 'logic.set', 'config' => []],
                ],
                [
                    'id' => 'n1',
                    'type' => 'logic.set',
                    'data' => ['type' => 'logic.set', 'config' => []],
                ],
            ],
            'edges' => [],
        ]);

        $errors = array_values(array_filter($issues, static fn (array $i): bool => ($i['severity'] ?? '') === 'error'));
        self::assertNotEmpty($errors);
    }

    public function testImportAnalyzerWarnsOnUnknownDolibarrObject(): void
    {
        $manifest = [
            'objects' => ['list' => []],
            'features' => ['state_machine_formal' => true],
        ];

        $items = [[
            'label' => 'Unknown object',
            'workflow' => [
                'label' => 'Unknown object',
                'definition' => [
                    'nodes' => [
                        [
                            'id' => 'a',
                            'type' => 'dolibarr.object',
                            'config' => ['objectType' => 'nonexistent_module_type'],
                        ],
                    ],
                    'edges' => [],
                ],
            ],
        ]];

        $warnings = WorkflowImportAnalyzer::analyze($items, $manifest);
        self::assertNotEmpty($warnings);
        self::assertSame('OBJECT_UNKNOWN', $warnings[0]['code'] ?? '');
    }

    public function testTierAuditorBlocksProConnectorInDefinition(): void
    {
        $auditor = new WorkflowTierAuditor(new TierGate(new StubExtensionRegistry([])));
        $result = $auditor->audit([
            'nodes' => [
                ['id' => 'x', 'type' => 'action.stripe'],
            ],
        ]);

        self::assertTrue($result['blocked']);
        self::assertCount(1, $result['missing']);
        self::assertSame('action.stripe', $result['missing'][0]['connectorId']);
    }

    public function testImportAnalyzerReturnsNoWarningsForValidMinimalPropalFetchShape(): void
    {
        $manifest = [
            'objects' => [
                'list' => [
                    ['slug' => 'propal', 'module_status' => 'active', 'module_required' => 'propal'],
                ],
            ],
            'features' => ['state_machine_formal' => true],
        ];

        $items = [[
            'workflow' => [
                'label' => 'Fetch propal',
                'definition' => [
                    'nodes' => [
                        [
                            'id' => 'n1',
                            'type' => 'dolibarr.object',
                            'data' => [
                                'type' => 'dolibarr.object',
                                'config' => [
                                    'operation' => 'fetch',
                                    'objectType' => 'propal',
                                    'id' => '1',
                                ],
                            ],
                        ],
                    ],
                    'edges' => [],
                ],
            ],
        ]];

        $warnings = WorkflowImportAnalyzer::analyze($items, $manifest);
        self::assertSame([], $warnings);
    }
}
