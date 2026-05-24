<?php

declare(strict_types=1);

namespace Knot\Tests\Api;

use Knot\Api\JsonResponse;
use Knot\Errors\DolibarrRecordNotFoundError;
use Knot\Errors\ExecutionError;
use Knot\Errors\KnotError;
use Knot\Errors\PermissionError;
use Knot\Errors\RateLimitError;
use Knot\Errors\SystemError;
use Knot\Errors\ValidationError;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * @covers \Knot\Api\JsonResponse
 */
final class JsonResponseTest extends TestCase
{
    public function testSuccessEnvelopeShape(): void
    {
        $envelope = JsonResponse::successEnvelope(['items' => [1, 2]]);
        self::assertTrue($envelope['success']);
        self::assertSame([1, 2], $envelope['data']['items']);
        self::assertNull($envelope['error']);
    }

    public function testErrorEnvelopeUsesErrorBody(): void
    {
        $envelope = JsonResponse::errorEnvelope('bad_request', 'Invalid input', ['field' => 'name']);
        self::assertFalse($envelope['success']);
        self::assertSame('bad_request', $envelope['error']['code']);
        self::assertSame(['field' => 'name'], $envelope['error']['details']);
    }

    public function testErrorBodyHelperDuplicatesCodeField(): void
    {
        $body = JsonResponse::errorBody('KNOT_X', 'Message', ['k' => 'v']);
        self::assertSame('KNOT_X', $body['code']);
        self::assertSame('KNOT_X', $body['error_code']);
    }

    public function testEncodePayloadUsesStableJsonFlags(): void
    {
        $json = JsonResponse::encodePayload(['success' => true, 'data' => ['ok' => true], 'error' => null, 'meta' => []]);
        self::assertSame('{"success":true,"data":{"ok":true},"error":null,"meta":[]}', $json);
    }

    public function testHttpStatusForKnotError(): void
    {
        $method = new ReflectionMethod(JsonResponse::class, 'httpStatusForKnotError');

        $cases = [
            [new PermissionError('KNOT_PERM_X', 'Denied', 'tech'), 403],
            [new DolibarrRecordNotFoundError('KNOT_NOT_FOUND_X', 'Missing', 'tech'), 404],
            [new ValidationError('KNOT_VALIDATION_X', 'Bad', 'tech'), 422],
            [new RateLimitError('KNOT_RATE_X', 'Slow down', 'tech'), 429],
            [new SystemError('KNOT_SYSTEM_X', 'Broken', 'tech'), 500],
            [new ExecutionError('KNOT_GENERIC', 'Oops', 'detail'), 400],
            [new ExecutionError('KNOT_SCHEMA_X', 'Schema', 'detail'), 422],
            [new ExecutionError('KNOT_PERM_X', 'Perm', 'detail'), 403],
            [new ExecutionError('KNOT_NOT_FOUND_X', 'Missing', 'detail'), 404],
        ];

        foreach ($cases as [$error, $expectedStatus]) {
            /** @var KnotError $error */
            self::assertSame($expectedStatus, $method->invoke(null, $error), $error->knotCode);
        }
    }

    public function testKnotErrorEnvelopeIncludesKnotPayload(): void
    {
        $error = new ValidationError('KNOT_VALIDATION_X', 'Bad input', 'tech detail');
        $envelope = JsonResponse::knotErrorEnvelope($error);

        self::assertSame('KNOT_VALIDATION_X', $envelope['error']['code']);
        self::assertSame('Bad input', $envelope['error']['message']);
        self::assertSame('KNOT_VALIDATION_X', $envelope['error']['details']['knot']['code']);
    }

    public function testInstallFatalHandlerIsIdempotent(): void
    {
        JsonResponse::installFatalHandler();
        JsonResponse::installFatalHandler();
        self::assertTrue(true);
    }
}
