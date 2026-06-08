<?php

declare(strict_types=1);

namespace Knot\Tests\Engine;

use Knot\Connectors\Logic\IfElseNode;
use Knot\Engine\DolibarrObjectTypeAliases;
use Knot\Engine\ExpressionResolver;
use Knot\Engine\IfConditionOperator;
use Knot\Engine\WorkflowDefinitionNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Regression scenarios for assistant-generated bill-validate / SQL / if / email flows.
 */
final class WorkflowReliabilityScenariosTest extends TestCase
{
    private ExpressionResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new ExpressionResolver();
    }

    public function testBillValidateExpressionPathsResolve(): void
    {
        $context = [
            'json' => [
                'objectId' => 1631,
                'rows' => [['iban' => 'FR7630006000011234567890189', 'bank_label' => 'Demo']],
            ],
            'nodes' => [
                'read_facture' => [
                    'json' => [
                        'ref' => 'FA2606-0089',
                        'total_ttc' => 500.0,
                        'fk_account' => 2,
                        'fk_soc' => 443,
                    ],
                ],
                'sql_iban' => [
                    'json' => [
                        'rows' => [['iban' => 'FR7630006000011234567890189']],
                        'count' => 1,
                    ],
                ],
                'read_tiers' => [
                    'json' => ['email' => 'client@example.com', 'name' => 'Demo Client'],
                ],
            ],
        ];

        self::assertSame('FA2606-0089', $this->resolver->resolve('{{ $nodes.read_facture.json.ref }}', $context));
        self::assertSame('500', $this->resolver->resolve('{{ $nodes.read_facture.json.total_ttc }}', $context));
        self::assertSame(
            'FR7630006000011234567890189',
            $this->resolver->resolve('{{ $json.rows[0].iban }}', $context)
        );
        self::assertSame(
            'FR7630006000011234567890189',
            $this->resolver->resolve('{{ $nodes.sql_iban.json.rows.0.iban }}', $context)
        );
        self::assertSame('client@example.com', $this->resolver->resolve('{{ $nodes.read_tiers.json.email }}', $context));
    }

    public function testIfNodeAcceptsSymbolicGreaterEqualOperator(): void
    {
        $node = new IfElseNode($this->resolver);
        $context = [
            'json' => ['total_ttc' => 500],
            'node' => [
                'config' => [
                    'mode' => 'all',
                    'conditions' => [
                        [
                            'left' => '{{ $json.total_ttc }}',
                            'operator' => '>=',
                            'right' => '500',
                        ],
                    ],
                ],
            ],
        ];

        $out = $node->execute($context);
        self::assertTrue($out['result']);
        self::assertSame('true', $out['branch']);
    }

    public function testIfNodeUsesNodesPathForThresholdBranch(): void
    {
        $node = new IfElseNode($this->resolver);
        $context = [
            'json' => ['rows' => []],
            'nodes' => [
                'read_facture' => ['json' => ['total_ttc' => 450]],
            ],
            'node' => [
                'config' => [
                    'conditions' => [
                        [
                            'left' => '{{ $nodes.read_facture.json.total_ttc }}',
                            'operator' => 'gte',
                            'right' => '500',
                        ],
                    ],
                ],
            ],
        ];

        $out = $node->execute($context);
        self::assertFalse($out['result']);
        self::assertSame('false', $out['branch']);
    }

    public function testDefinitionNormalizerRepairsAssistantMistakes(): void
    {
        $definition = [
            'schemaVersion' => '1.0',
            'nodes' => [
                [
                    'id' => 'read1',
                    'type' => 'dolibarr.read_object',
                    'config' => ['objectType' => 'invoice', 'objectId' => '{{$json.objectId}}'],
                ],
                [
                    'id' => 'sql1',
                    'type' => 'dolibarr.sql_query',
                    'config' => ['sql' => 'SELECT iban_prefix AS iban FROM llx_bank_account WHERE rowid = 2'],
                ],
                [
                    'id' => 'if1',
                    'type' => 'logic.if',
                    'config' => [
                        'conditions' => [
                            ['left' => '{{$nodes.read1.json.total_ttc}}', 'operator' => '>=', 'right' => '500'],
                        ],
                    ],
                ],
                [
                    'id' => 'mail1',
                    'type' => 'action.email',
                    'config' => [
                        'to' => 'ops@example.com',
                        'subject' => 'Test',
                        'body' => 'Bonjour,\\n\\nIBAN : {{$json.rows[0].iban}}',
                    ],
                ],
            ],
            'edges' => [],
        ];

        $normalized = (new WorkflowDefinitionNormalizer())->normalize($definition);
        $byId = [];
        foreach ($normalized['nodes'] as $node) {
            $byId[$node['id']] = $node;
        }

        self::assertSame('facture', $byId['read1']['config']['objectType']);
        self::assertSame(
            'SELECT iban_prefix AS iban FROM llx_bank_account WHERE rowid = 2',
            $byId['sql1']['config']['query']
        );
        self::assertArrayNotHasKey('sql', $byId['sql1']['config']);
        self::assertSame('greater_equal', $byId['if1']['config']['conditions'][0]['operator']);
        self::assertStringContainsString("\n\n", $byId['mail1']['config']['body']);
        self::assertStringNotContainsString('\\n', $byId['mail1']['config']['body']);
    }

    public function testIfConditionOperatorAliases(): void
    {
        self::assertSame('greater_equal', IfConditionOperator::normalize('>='));
        self::assertSame('greater_equal', IfConditionOperator::normalize('gte'));
        self::assertSame('facture', DolibarrObjectTypeAliases::normalize('invoice'));
        self::assertSame('thirdparty', DolibarrObjectTypeAliases::normalize('customer'));
    }

    public function testNestedBracketIndicesInSqlLoopShape(): void
    {
        $context = [
            'json' => [
                'rows' => [
                    ['ref' => 'FA-1', 'items' => [['sku' => 'A']]],
                    ['ref' => 'FA-2', 'items' => [['sku' => 'B']]],
                ],
            ],
        ];

        self::assertSame('FA-2', $this->resolver->resolve('{{ $json.rows[1].ref }}', $context));
        self::assertSame('B', $this->resolver->resolve('{{ $json.rows[1].items[0].sku }}', $context));
    }
}
