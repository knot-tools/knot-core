<?php

declare(strict_types=1);

namespace Knot\Tests\Engine;

use Knot\Engine\WorkflowValidationException;
use Knot\Engine\WorkflowValidator;
use PHPUnit\Framework\TestCase;

final class WorkflowValidatorTest extends TestCase
{
    private WorkflowValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new WorkflowValidator();
    }

    public function testAcceptsValidDefinition(): void
    {
        $this->expectNotToPerformAssertions();
        $this->validator->validate([
            'schemaVersion' => '1.0',
            'nodes' => [
                ['id' => 't1', 'type' => 'trigger.manual'],
                ['id' => 'a1', 'type' => 'action.http'],
            ],
            'edges' => [
                ['source' => 't1', 'target' => 'a1'],
            ],
        ]);
    }

    public function testRejectsMissingSchemaVersion(): void
    {
        $this->expectException(WorkflowValidationException::class);
        $this->validator->validate(['nodes' => [], 'edges' => []]);
    }

    public function testWarnsWorkflowWithoutTrigger(): void
    {
        $issues = $this->validator->validateAll([
            'schemaVersion' => '1.0',
            'nodes' => [['id' => 'a', 'type' => 'action.http']],
            'edges' => [],
        ]);
        $codes = array_map(static fn ($i) => $i['code'] ?? '', $issues);
        self::assertContains('no_trigger', $codes);
        $this->validator->validate([
            'schemaVersion' => '1.0',
            'nodes' => [['id' => 'a', 'type' => 'action.http']],
            'edges' => [],
        ]);
    }

    public function testRejectsDuplicateNodeIds(): void
    {
        $this->expectException(WorkflowValidationException::class);
        $this->validator->validate([
            'schemaVersion' => '1.0',
            'nodes' => [
                ['id' => 't1', 'type' => 'trigger.manual'],
                ['id' => 't1', 'type' => 'action.http'],
            ],
            'edges' => [],
        ]);
    }

    public function testRejectsEdgeReferencingMissingNode(): void
    {
        $this->expectException(WorkflowValidationException::class);
        $this->validator->validate([
            'schemaVersion' => '1.0',
            'nodes' => [['id' => 't1', 'type' => 'trigger.manual']],
            'edges' => [['source' => 't1', 'target' => 'ghost']],
        ]);
    }

    public function testWarnsOnSqlQueryWithUnknownTableTypo(): void
    {
        $issues = $this->validator->validateAll([
            'schemaVersion' => '1.0',
            'nodes' => [
                ['id' => 't1', 'type' => 'trigger.manual'],
                [
                    'id' => 'sql1',
                    'type' => 'dolibarr.sql_query',
                    'config' => ['query' => 'SELECT ref FROM llx_propale'],
                ],
            ],
            'edges' => [['source' => 't1', 'target' => 'sql1']],
        ]);

        $keys = array_map(static fn (array $i): string => (string) ($i['messageKey'] ?? ''), $issues);
        self::assertContains('sql_unknown_table_hint', $keys);
    }

    public function testWarnsOnUnknownObjectTypeWithSuggestion(): void
    {
        $issues = $this->validator->validateAll([
            'schemaVersion' => '1.0',
            'nodes' => [
                ['id' => 't1', 'type' => 'trigger.dolibarr_event'],
                [
                    'id' => 'r1',
                    'type' => 'dolibarr.read_object',
                    'config' => ['objectType' => 'invoice', 'objectId' => 1],
                ],
            ],
            'edges' => [['source' => 't1', 'target' => 'r1']],
        ]);

        $match = null;
        foreach ($issues as $issue) {
            if (($issue['messageKey'] ?? '') === 'object_type_unknown_hint') {
                $match = $issue;
                break;
            }
        }
        self::assertNotNull($match);
        self::assertSame('warning', $match['severity']);
        self::assertSame('facture', $match['messageParams']['suggestion'] ?? null);
    }

    public function testWarnsOnDollarJsonExpressionAfterNonTriggerUpstream(): void
    {
        $issues = $this->validator->validateAll([
            'schemaVersion' => '1.0',
            'nodes' => [
                ['id' => 't1', 'type' => 'trigger.dolibarr_event'],
                ['id' => 'r1', 'type' => 'dolibarr.read_object', 'config' => ['objectType' => 'facture', 'objectId' => 1]],
                [
                    'id' => 'sql1',
                    'type' => 'dolibarr.sql_query',
                    'config' => ['query' => 'SELECT 1 WHERE x = {{$json.total_ttc}}'],
                ],
            ],
            'edges' => [
                ['source' => 't1', 'target' => 'r1'],
                ['source' => 'r1', 'target' => 'sql1'],
            ],
        ]);

        $keys = array_map(static fn (array $i): string => (string) ($i['messageKey'] ?? ''), $issues);
        self::assertContains('expression_json_chain', $keys);
    }

    public function testWarnsOnInvalidIfOperatorWithSuggestion(): void
    {
        $issues = $this->validator->validateAll([
            'schemaVersion' => '1.0',
            'nodes' => [
                ['id' => 't1', 'type' => 'trigger.manual'],
                [
                    'id' => 'if1',
                    'type' => 'logic.if',
                    'config' => [
                        'conditions' => [
                            ['left' => '{{$json.amount}}', 'operator' => '>=', 'right' => '100'],
                        ],
                    ],
                ],
            ],
            'edges' => [['source' => 't1', 'target' => 'if1']],
        ]);

        $match = null;
        foreach ($issues as $issue) {
            if (($issue['messageKey'] ?? '') === 'if_operator_invalid_hint') {
                $match = $issue;
                break;
            }
        }
        self::assertNotNull($match);
        self::assertSame('greater_equal', $match['messageParams']['suggestion'] ?? null);
    }
}
