<?php

declare(strict_types=1);

namespace Knot\Tests\Engine;

use Knot\Engine\WorkflowValidationException;
use Knot\Engine\WorkflowValidator;
use PHPUnit\Framework\TestCase;

final class WorkflowValidatorCoverageTest extends TestCase
{
    private WorkflowValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new WorkflowValidator();
    }

    public function testRejectsUnsupportedSchemaVersion(): void
    {
        $issues = $this->validator->validateAll(['schemaVersion' => '2.0', 'nodes' => [], 'edges' => []]);
        $codes = array_column($issues, 'code');
        self::assertContains('KNOT_DSL_INVALID_STRUCTURE', $codes);
    }

    public function testRejectsNodesNotArray(): void
    {
        $issues = $this->validator->validateAll([
            'schemaVersion' => '1.0',
            'nodes' => 'bad',
            'edges' => [],
        ]);
        self::assertNotEmpty($issues);
    }

    public function testRejectsEdgesNotArray(): void
    {
        $issues = $this->validator->validateAll([
            'schemaVersion' => '1.0',
            'nodes' => [],
            'edges' => null,
        ]);
        self::assertNotEmpty($issues);
    }

    public function testRejectsNodeWithoutId(): void
    {
        $issues = $this->validator->validateAll([
            'schemaVersion' => '1.0',
            'nodes' => [['type' => 'trigger.manual']],
            'edges' => [],
        ]);
        self::assertNotEmpty($issues);
    }

    public function testRejectsNodeWithoutType(): void
    {
        $issues = $this->validator->validateAll([
            'schemaVersion' => '1.0',
            'nodes' => [['id' => 'n1']],
            'edges' => [],
        ]);
        self::assertNotEmpty($issues);
    }

    public function testWarnsOnUnknownConnectorType(): void
    {
        $issues = $this->validator->validateAll([
            'schemaVersion' => '1.0',
            'nodes' => [
                ['id' => 't1', 'type' => 'trigger.manual'],
                ['id' => 'x1', 'type' => 'connector.unknown.xyz'],
            ],
            'edges' => [],
        ]);
        $codes = array_column($issues, 'code');
        self::assertContains('KNOT_DSL_UNKNOWN_CONNECTOR', $codes);
    }

    public function testWarnsOnDirectedCycle(): void
    {
        $issues = $this->validator->validateAll([
            'schemaVersion' => '1.0',
            'nodes' => [
                ['id' => 't1', 'type' => 'trigger.manual'],
                ['id' => 'a1', 'type' => 'logic.set'],
                ['id' => 'b1', 'type' => 'logic.set'],
            ],
            'edges' => [
                ['source' => 't1', 'target' => 'a1'],
                ['source' => 'a1', 'target' => 'b1'],
                ['source' => 'b1', 'target' => 'a1'],
            ],
        ]);
        $codes = array_column($issues, 'code');
        self::assertContains('KNOT_DSL_GRAPH_CYCLE', $codes);
    }

    public function testWarnsOnOrphanNode(): void
    {
        $issues = $this->validator->validateAll([
            'schemaVersion' => '1.0',
            'nodes' => [
                ['id' => 't1', 'type' => 'trigger.manual'],
                ['id' => 'orphan', 'type' => 'action.http'],
            ],
            'edges' => [],
        ]);
        $codes = array_column($issues, 'code');
        self::assertContains('KNOT_DSL_ORPHAN_NODE', $codes);
    }

    public function testRejectsInvalidEdgeSource(): void
    {
        $issues = $this->validator->validateAll([
            'schemaVersion' => '1.0',
            'nodes' => [['id' => 't1', 'type' => 'trigger.manual']],
            'edges' => [['source' => 'missing', 'target' => 't1']],
        ]);
        $codes = array_column($issues, 'code');
        self::assertContains('KNOT_DSL_EDGE_INVALID', $codes);
    }

    public function testValidateThrowsOnFirstError(): void
    {
        $this->expectException(WorkflowValidationException::class);
        $this->validator->validate([
            'schemaVersion' => '9.9',
            'nodes' => [],
            'edges' => [],
        ]);
    }

    public function testEmptyNodesAndEdgesProduceNoErrors(): void
    {
        $issues = $this->validator->validateAll([
            'schemaVersion' => '1.0',
            'nodes' => [],
            'edges' => [],
        ]);
        $errors = array_filter($issues, static fn ($i) => ($i['severity'] ?? '') === 'error');
        self::assertSame([], array_values($errors));
    }

    public function testSkipsNonArrayNodeEntries(): void
    {
        $issues = $this->validator->validateAll([
            'schemaVersion' => '1.0',
            'nodes' => ['not-a-node', ['id' => 't1', 'type' => 'trigger.manual']],
            'edges' => [],
        ]);
        $errors = array_filter($issues, static fn ($i) => ($i['severity'] ?? '') === 'error');
        self::assertCount(0, $errors);
    }

    public function testSkipsNonArrayEdgeEntries(): void
    {
        $issues = $this->validator->validateAll([
            'schemaVersion' => '1.0',
            'nodes' => [['id' => 't1', 'type' => 'trigger.manual']],
            'edges' => [null, ['source' => 't1', 'target' => 't1']],
        ]);
        self::assertIsArray($issues);
    }
}
