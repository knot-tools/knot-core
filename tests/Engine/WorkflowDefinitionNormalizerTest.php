<?php

declare(strict_types=1);

namespace Knot\Tests\Engine;

use Knot\Engine\WorkflowDefinitionNormalizer;
use PHPUnit\Framework\TestCase;

final class WorkflowDefinitionNormalizerTest extends TestCase
{
    public function testNormalizesSqlConfigKeyAlias(): void
    {
        $definition = [
            'nodes' => [
                [
                    'id' => 'sql1',
                    'type' => 'dolibarr.sql_query',
                    'config' => ['sql' => 'SELECT 1'],
                ],
            ],
        ];

        $out = (new WorkflowDefinitionNormalizer())->normalize($definition);
        self::assertSame('SELECT 1', $out['nodes'][0]['config']['query']);
        self::assertArrayNotHasKey('sql', $out['nodes'][0]['config']);
    }

    public function testDoesNotOverwriteExistingQuery(): void
    {
        $definition = [
            'nodes' => [
                [
                    'id' => 'sql1',
                    'type' => 'dolibarr.sql_query',
                    'config' => ['query' => 'SELECT 2', 'sql' => 'SELECT 1'],
                ],
            ],
        ];

        $out = (new WorkflowDefinitionNormalizer())->normalize($definition);
        self::assertSame('SELECT 2', $out['nodes'][0]['config']['query']);
    }
}
