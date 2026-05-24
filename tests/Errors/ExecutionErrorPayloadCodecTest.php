<?php

declare(strict_types=1);

namespace Knot\Tests\Errors;

use Knot\Errors\ExecutionError;
use Knot\Errors\ExecutionErrorPayloadCodec;
use PHPUnit\Framework\TestCase;

final class ExecutionErrorPayloadCodecTest extends TestCase
{
    public function testFromThrowablePreservesKnotFields(): void
    {
        $err = new ExecutionError(
            'KNOT_TEST_CODE',
            'User-facing',
            'Technical detail',
            'https://example.com/doc',
            ['k' => 'v'],
            'Try again',
            'warning'
        );
        $arr = ExecutionErrorPayloadCodec::fromThrowable($err);
        self::assertSame('KNOT_TEST_CODE', $arr['code']);
        self::assertSame('User-facing', $arr['user_message']);
        self::assertArrayHasKey('context', $arr);
    }

    public function testEncodeFitsWithinMaxStorageBytes(): void
    {
        $payload = [
            'code' => 'KNOT_X',
            'user_message' => str_repeat('u', 100),
            'technical_message' => str_repeat('t', 80000),
            'doc_link' => null,
            'severity' => 'error',
            'suggestion' => null,
            'context' => [],
        ];
        $json = ExecutionErrorPayloadCodec::encode($payload);
        self::assertNotNull($json);
        self::assertLessThanOrEqual(ExecutionErrorPayloadCodec::MAX_STORAGE_BYTES, strlen($json));
        $decoded = json_decode($json, true);
        self::assertIsArray($decoded);
        self::assertSame('KNOT_X', $decoded['code']);
    }

    public function testEncodeReturnsNullForInvalidUtf8Edge(): void
    {
        $payload = [
            'code' => 'KNOT_X',
            'user_message' => "OK",
            'technical_message' => '',
            'context' => [],
        ];
        $json = ExecutionErrorPayloadCodec::encode($payload);
        self::assertNotNull($json);
    }

    public function testEncodeReturnsNullForNullPayload(): void
    {
        self::assertNull(ExecutionErrorPayloadCodec::encode(null));
    }

    public function testFromThrowableTranslatesGenericException(): void
    {
        $arr = ExecutionErrorPayloadCodec::fromThrowable(new \RuntimeException('db timeout'));
        self::assertArrayHasKey('code', $arr);
        self::assertArrayHasKey('user_message', $arr);
        self::assertArrayHasKey('technical_message', $arr);
    }

    public function testEncodeUsesMinimalFallbackWhenPayloadTooLarge(): void
    {
        $payload = [
            'code' => 'KNOT_OVERSIZE',
            'user_message' => str_repeat('u', 5000),
            'technical_message' => str_repeat('t', 80000),
            'doc_link' => 'https://example.com/doc',
            'severity' => 'error',
            'suggestion' => str_repeat('s', 5000),
            'context' => array_fill_keys(range('a', 'z'), str_repeat('x', 3000)),
        ];
        $json = ExecutionErrorPayloadCodec::encode($payload);
        self::assertNotNull($json);
        self::assertLessThanOrEqual(ExecutionErrorPayloadCodec::MAX_STORAGE_BYTES, strlen($json));
        $decoded = json_decode($json, true);
        self::assertIsArray($decoded);
        self::assertTrue($decoded['context']['truncated'] ?? false);
    }

    public function testEncodeTrimsDeepContextValues(): void
    {
        $payload = [
            'code' => 'KNOT_CTX',
            'user_message' => 'msg',
            'technical_message' => 'tech',
            'context' => [
                'nested' => ['a' => 1, 'b' => 2],
                'long' => str_repeat('z', 5000),
            ],
        ];
        $json = ExecutionErrorPayloadCodec::encode($payload);
        self::assertNotNull($json);
        $decoded = json_decode($json, true);
        self::assertIsArray($decoded);
        self::assertLessThan(5000, strlen((string) $decoded['context']['long']));
    }
}
