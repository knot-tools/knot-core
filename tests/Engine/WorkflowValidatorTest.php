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
}
