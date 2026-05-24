<?php

declare(strict_types=1);

namespace Knot\Tests\Engine;

use Knot\Engine\ErrorTranslator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ErrorTranslatorTest extends TestCase
{
    private ErrorTranslator $translator;

    protected function setUp(): void
    {
        $this->translator = new ErrorTranslator();
    }

    public function testTranslatesPermissionDenied(): void
    {
        $result = $this->translator->translate(new RuntimeException('Permission denied for user'));
        self::assertSame('permission_denied', $result['code']);
        self::assertSame('errors.engine.permission_denied.message', $result['messageKey']);
        self::assertSame('errors.engine.permission_denied.suggestedFix', $result['suggestedFixKey']);
    }

    public function testTranslatesTimeout(): void
    {
        $result = $this->translator->translate('connection timed out');
        self::assertSame('timeout', $result['code']);
        self::assertSame('errors.engine.timeout.message', $result['messageKey']);
        self::assertSame('errors.engine.timeout.suggestedFix', $result['suggestedFixKey']);
    }

    public function testTranslatesNotFound(): void
    {
        self::assertSame(
            'not_found',
            $this->translator->translate('Object not found.')['code'],
        );
    }

    public function testTranslatesAuthFailure(): void
    {
        self::assertSame('auth_failed', $this->translator->translate('API key rejected')['code']);
        self::assertSame('auth_failed', $this->translator->translate('401 Unauthorized')['code']);
    }

    public function testTranslatesExpressionResolutionFailure(): void
    {
        $result = $this->translator->translate('Invalid JSON in node config');
        self::assertSame('expression_resolution_failed', $result['code']);
        self::assertSame('errors.engine.expression_resolution_failed.message', $result['messageKey']);
        self::assertSame('errors.engine.expression_resolution_failed.suggestedFix', $result['suggestedFixKey']);
    }

    public function testFallsBackToUnknownError(): void
    {
        $result = $this->translator->translate('Random failure');
        self::assertSame('unknown_error', $result['code']);
        self::assertSame('errors.engine.unknown_error.message', $result['messageKey']);
        self::assertSame('errors.engine.unknown_error.suggestedFix', $result['suggestedFixKey']);
    }

    public function testOmitsLocalizedProsePayloadFields(): void
    {
        $result = $this->translator->translate('Random failure');
        self::assertArrayNotHasKey('message', $result);
        self::assertArrayNotHasKey('suggestedFix', $result);
    }
}
