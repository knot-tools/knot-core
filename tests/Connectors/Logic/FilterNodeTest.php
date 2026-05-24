<?php

declare(strict_types=1);

namespace Knot\Tests\Connectors\Logic;

use Knot\Connectors\Logic\FilterNode;
use Knot\Connectors\Logic\StopAndErrorNode;
use Knot\Errors\ExecutionError;
use PHPUnit\Framework\TestCase;

final class FilterNodeTest extends TestCase
{
    public function testPassesWhenConditionTrue(): void
    {
        $node = new FilterNode();
        $context = $this->context($node, ['equals', '{{ $json.status }}', 'paid'], ['status' => 'paid']);
        $output = $node->execute($context);
        self::assertTrue($output['_filter']['passed']);
        self::assertSame([], $node->branchesToSkip($context, $output));
    }

    public function testBlocksMainBranchWhenConditionFalse(): void
    {
        $node = new FilterNode();
        $context = $this->context($node, ['equals', '{{ $json.status }}', 'paid'], ['status' => 'draft']);
        $output = $node->execute($context);
        self::assertFalse($output['_filter']['passed']);
        self::assertSame(['main'], $node->branchesToSkip($context, $output));
    }

    public function testGreaterOperator(): void
    {
        $node = new FilterNode();
        $context = $this->context($node, ['greater', '{{ $json.amount }}', '100'], ['amount' => 199]);
        $output = $node->execute($context);
        self::assertTrue($output['_filter']['passed']);
    }

    public function testIsEmptyOperator(): void
    {
        $node = new FilterNode();
        $context = $this->context($node, ['is_empty', '{{ $json.note }}', ''], ['note' => '']);
        $output = $node->execute($context);
        self::assertTrue($output['_filter']['passed']);
    }

    /**
     * @param array{0:string,1:string,2:string} $cfg
     * @param array<string, mixed> $jsonPayload
     * @return array<string, mixed>
     */
    private function context(FilterNode $node, array $cfg, array $jsonPayload): array
    {
        return [
            'node' => ['id' => 'f1', 'type' => 'logic.filter', 'config' => [
                'operator' => $cfg[0],
                'left' => $cfg[1],
                'right' => $cfg[2],
            ]],
            'json' => $jsonPayload,
        ];
    }
}

final class StopAndErrorNodeTest extends TestCase
{
    public function testThrowsWithCustomMessage(): void
    {
        $node = new StopAndErrorNode();
        $this->expectException(ExecutionError::class);
        $this->expectExceptionMessage('Validation business: amount above limit.');
        $node->execute([
            'node' => ['id' => 's1', 'type' => 'logic.stop_error', 'config' => [
                'message' => 'Validation business: amount above limit.',
            ]],
            'json' => [],
        ]);
    }

    public function testValidateRejectsEmptyMessage(): void
    {
        $node = new StopAndErrorNode();
        self::assertFalse($node->validate(['message' => ''])['valid']);
        self::assertTrue($node->validate(['message' => 'oops'])['valid']);
    }
}
