<?php

declare(strict_types=1);

namespace Knot\Tests\Connectors\Logic;

use Knot\Connectors\Logic\ApprovalWaitNode;
use Knot\Connectors\Logic\ArrayNode;
use Knot\Connectors\Logic\CryptoNode;
use Knot\Connectors\Logic\DateNode;
use Knot\Connectors\Logic\HtmlNode;
use Knot\Connectors\Logic\IfElseNode;
use Knot\Connectors\Logic\JsonNode;
use Knot\Connectors\Logic\LoopNode;
use Knot\Connectors\Logic\MergeNode;
use Knot\Connectors\Logic\NumberNode;
use Knot\Connectors\Logic\RespondToWebhookNode;
use Knot\Connectors\Logic\SetNode;
use Knot\Connectors\Logic\SplitNode;
use Knot\Connectors\Logic\StringNode;
use Knot\Connectors\Logic\SwitchNode;
use Knot\Connectors\Logic\WaitNode;
use Knot\Connectors\Logic\WhileNode;
use Knot\Connectors\Logic\XmlNode;
use PHPUnit\Framework\TestCase;

/**
 * Execute-path smoke tests for Core logic connectors (palette V2.8).
 */
final class CoreLogicNodesExecuteTest extends TestCase
{
    public function testSetNodeMergesValuesIntoJson(): void
    {
        $out = (new SetNode())->execute([
            'node' => ['config' => ['values' => ['status' => 'ready']]],
            'json' => ['id' => 1],
        ]);
        self::assertSame('ready', $out['status']);
        self::assertSame(1, $out['id']);
    }

    public function testMergeNodeShallowStrategy(): void
    {
        $out = (new MergeNode())->execute([
            'node' => ['config' => ['strategy' => 'shallow']],
            'nodes' => [
                ['json' => ['a' => 1, 'shared' => 'left']],
                ['json' => ['b' => 2, 'shared' => 'right']],
            ],
        ]);
        self::assertSame(1, $out['a']);
        self::assertSame(2, $out['b']);
        self::assertSame('right', $out['shared']);
    }

    public function testMergeNodeDeepStrategy(): void
    {
        $out = (new MergeNode())->execute([
            'node' => ['config' => ['strategy' => 'deep']],
            'nodes' => [
                ['json' => ['meta' => ['x' => 1]]],
                ['json' => ['meta' => ['y' => 2]]],
            ],
        ]);
        self::assertSame(['x' => 1, 'y' => 2], $out['meta']);
    }

    public function testJsonNodeParseStringifyAndExtract(): void
    {
        $node = new JsonNode();
        $parsed = $node->execute([
            'node' => ['config' => ['operation' => 'parse', 'input' => '{"k":1}']],
            'json' => [],
        ]);
        self::assertTrue($parsed['valid']);
        self::assertSame(['k' => 1], $parsed['value']);

        $stringified = $node->execute([
            'node' => ['config' => ['operation' => 'stringify', 'pretty' => true]],
            'json' => ['k' => 2],
        ]);
        self::assertStringContainsString('"k"', $stringified['value']);

        $extracted = $node->execute([
            'node' => ['config' => ['operation' => 'extract', 'path' => '$json.name']],
            'json' => ['name' => 'Knot'],
        ]);
        self::assertSame('Knot', $extracted['value']);
    }

    public function testArrayNodeOperations(): void
    {
        $node = new ArrayNode();
        $base = ['node' => ['config' => []], 'json' => ['items' => ['a', 'b', 'a']]];

        self::assertSame(3, $node->execute(['node' => ['config' => ['operation' => 'length']], 'json' => ['items' => ['a', 'b', 'a']]])['result']);
        self::assertSame(['a', 'b', 'a'], $node->execute(['node' => ['config' => ['operation' => 'reverse']], 'json' => ['items' => ['a', 'b', 'a']]])['result']);
        self::assertSame(['a', 'b'], $node->execute(['node' => ['config' => ['operation' => 'unique']], 'json' => ['items' => ['a', 'b', 'a']]])['result']);
        self::assertSame('a', $node->execute(['node' => ['config' => ['operation' => 'first']], 'json' => ['items' => ['a', 'b', 'a']]])['result']);
        self::assertSame('a', $node->execute(['node' => ['config' => ['operation' => 'last']], 'json' => ['items' => ['a', 'b', 'a']]])['result']);
    }

    public function testCryptoNodeOperations(): void
    {
        $node = new CryptoNode();
        $sha = $node->execute(['node' => ['config' => ['operation' => 'sha256', 'value' => 'abc']], 'json' => []]);
        self::assertSame(hash('sha256', 'abc'), $sha['result']);

        $hmac = $node->execute(['node' => ['config' => ['operation' => 'hmac_sha256', 'value' => 'msg', 'secret' => 'key']], 'json' => []]);
        self::assertSame(hash_hmac('sha256', 'msg', 'key'), $hmac['result']);

        $uuid = $node->execute(['node' => ['config' => ['operation' => 'uuidv4']], 'json' => []]);
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            (string) $uuid['result'],
        );
    }

    public function testStringNumberDateHtmlXmlNodes(): void
    {
        $stringOut = (new StringNode())->execute([
            'node' => ['config' => ['operation' => 'upper', 'input' => 'knot']],
            'json' => [],
        ]);
        self::assertSame('KNOT', $stringOut['value']);

        $numberOut = (new NumberNode())->execute([
            'node' => ['config' => ['operation' => 'add', 'a' => '2', 'b' => '3']],
            'json' => [],
        ]);
        self::assertSame(5.0, $numberOut['value']);

        $dateOut = (new DateNode())->execute([
            'node' => ['config' => ['operation' => 'format', 'input' => '2026-05-19', 'format' => 'Y']],
            'json' => [],
        ]);
        self::assertSame('2026', $dateOut['value']);

        $htmlOut = (new HtmlNode())->execute([
            'node' => ['config' => ['html' => '<b>x</b>', 'xpath' => '//text()']],
            'json' => [],
        ]);
        self::assertSame(['x'], $htmlOut['values']);

        $xmlOut = (new XmlNode())->execute([
            'node' => ['config' => ['xml' => '<root><item>a</item></root>', 'xpath' => '/root/item']],
            'json' => [],
        ]);
        self::assertSame(['a'], $xmlOut['values']);
    }

    public function testIfElseAndSwitchRouting(): void
    {
        $ifNode = new IfElseNode();
        $ifOut = $ifNode->execute([
            'node' => [
                'config' => [
                    'conditions' => [
                        ['left' => '{{ $json.flag }}', 'operator' => 'equals', 'right' => 'yes'],
                    ],
                ],
            ],
            'json' => ['flag' => 'yes'],
        ]);
        self::assertTrue($ifOut['result']);

        $switch = new SwitchNode();
        $switchOut = $switch->execute([
            'node' => [
                'config' => [
                    'expression' => '{{ $json.tier }}',
                    'cases' => [
                        ['value' => 'pro', 'output' => 'case_1'],
                    ],
                ],
            ],
            'json' => ['tier' => 'pro'],
        ]);
        self::assertSame('case_1', $switchOut['branch']);
    }

    public function testSplitWaitLoopWhileAndApprovalDryRun(): void
    {
        $split = (new SplitNode())->execute([
            'node' => ['config' => ['chunkSize' => 2]],
            'json' => ['items' => [1, 2, 3, 4]],
        ]);
        self::assertCount(2, $split['chunks']);

        $wait = (new WaitNode())->execute([
            'node' => ['config' => ['seconds' => 0]],
            'json' => [],
        ]);
        self::assertSame(0.0, $wait['waitedSeconds']);

        $loop = (new LoopNode())->execute([
            'node' => ['config' => ['itemsPath' => '{{ $json.items }}']],
            'json' => ['items' => ['a', 'b']],
        ]);
        self::assertSame(2, $loop['count']);

        $while = (new WhileNode())->execute([
            'node' => ['config' => ['maxIterations' => 5]],
            'json' => [],
        ]);
        self::assertSame(5, $while['maxIterations']);

        $approval = (new ApprovalWaitNode())->simulate([
            'node' => ['id' => 'ap1', 'config' => ['message' => 'Approve?']],
            'json' => [],
        ]);
        self::assertTrue($approval['_dryRun']);
        self::assertSame('Approve?', $approval['message']);
    }

    public function testRespondToWebhookStoresPayload(): void
    {
        $out = (new RespondToWebhookNode())->execute([
            'node' => ['config' => ['statusCode' => 202, 'body' => '{"ok":true}']],
            'json' => ['requestId' => 'abc'],
        ]);
        self::assertSame(202, $out['_webhookResponse']['statusCode']);
        self::assertSame('{"ok":true}', $out['_webhookResponse']['body']);
    }
}
