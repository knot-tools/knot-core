<?php

declare(strict_types=1);

namespace Knot\Tests\Capabilities;

use Knot\Capabilities\WorkflowImportAnalyzer;
use PHPUnit\Framework\TestCase;

final class WorkflowImportAnalyzerTest extends TestCase
{
    public function testWarnsOnInactiveModule(): void
    {
        $capabilities = [
            'features' => ['state_machine_formal' => false],
            'objects' => [
                'list' => [
                    [
                        'slug' => 'facture',
                        'module_required' => 'facture',
                        'module_status' => 'inactive',
                    ],
                ],
            ],
        ];
        $items = [
            [
                'workflow' => [
                    'label' => 'T',
                    'definition' => [
                        'nodes' => [
                            [
                                'type' => 'dolibarr.object',
                                'config' => ['objectType' => 'facture', 'operation' => 'fetch'],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $warnings = WorkflowImportAnalyzer::analyze($items, $capabilities);
        $this->assertNotEmpty($warnings);
        $this->assertSame('MODULE_INACTIVE', $warnings[0]['code']);
    }

    public function testUnknownObject(): void
    {
        $capabilities = [
            'features' => ['state_machine_formal' => false],
            'objects' => ['list' => [['slug' => 'societe', 'module_status' => 'active']]],
        ];
        $items = [
            [
                'workflow' => [
                    'label' => 'X',
                    'definition' => [
                        'nodes' => [
                            ['type' => 'dolibarr.object', 'config' => ['objectType' => 'no_such_slug']],
                        ],
                    ],
                ],
            ],
        ];
        $warnings = WorkflowImportAnalyzer::analyze($items, $capabilities);
        $this->assertSame('OBJECT_UNKNOWN', $warnings[0]['code']);
    }
}
