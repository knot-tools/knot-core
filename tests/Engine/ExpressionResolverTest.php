<?php

declare(strict_types=1);

namespace Knot\Tests\Engine;

use Knot\Engine\ExpressionResolver;
use PHPUnit\Framework\TestCase;

final class ExpressionResolverTest extends TestCase
{
    private ExpressionResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new ExpressionResolver();
    }

    public function testResolvesSimpleScalarPath(): void
    {
        $context = ['json' => ['name' => 'Alice', 'age' => 30]];
        self::assertSame('Alice', $this->resolver->resolve('{{ $json.name }}', $context));
        self::assertSame('30', $this->resolver->resolve('{{ $json.age }}', $context));
    }

    public function testResolvesNestedPath(): void
    {
        $context = ['json' => ['invoice' => ['lines' => [['amount' => 199]]]]];
        self::assertSame('199', $this->resolver->resolve('{{ $json.invoice.lines.0.amount }}', $context));
    }

    public function testResolvesBracketArrayIndexInPath(): void
    {
        $context = ['json' => ['rows' => [['iban' => 'FR7612345678901234567890189']]]];
        self::assertSame(
            'FR7612345678901234567890189',
            $this->resolver->resolve('{{ $json.rows[0].iban }}', $context)
        );
        self::assertSame(
            'FR7612345678901234567890189',
            $this->resolver->resolve('{{ $json.rows.0.iban }}', $context)
        );
    }

    public function testResolvesNodesScopedBracketPath(): void
    {
        $context = [
            'nodes' => [
                'sql_iban' => ['json' => ['rows' => [['iban' => 'FR76DEMO']]]],
            ],
        ];
        self::assertSame(
            'FR76DEMO',
            $this->resolver->resolve('{{ $nodes.sql_iban.json.rows[0].iban }}', $context)
        );
    }

    public function testReturnsEmptyStringWhenPathMissing(): void
    {
        self::assertSame('Hello, ', $this->resolver->resolve('Hello, {{ $json.firstName }}', ['json' => []]));
    }

    public function testKeepsLiteralWhenNoExpression(): void
    {
        self::assertSame('static value', $this->resolver->resolve('static value', ['json' => []]));
    }

    public function testRecursesIntoArrays(): void
    {
        $context = ['json' => ['user' => ['email' => 'a@b.test']]];
        $resolved = $this->resolver->resolve(
            ['to' => '{{ $json.user.email }}', 'subject' => 'Welcome'],
            $context
        );
        self::assertSame(['to' => 'a@b.test', 'subject' => 'Welcome'], $resolved);
    }

    public function testReturnsNonStringValueAsIs(): void
    {
        self::assertSame(42, $this->resolver->resolve(42, ['json' => []]));
        self::assertNull($this->resolver->resolve(null, ['json' => []]));
    }

    public function testEncodesObjectAsJsonWhenInsideTemplate(): void
    {
        $context = ['json' => ['payload' => ['a' => 1, 'b' => 2]]];
        $resolved = $this->resolver->resolve('Body: {{ $json.payload }}', $context);
        self::assertStringContainsString('"a":1', (string) $resolved);
    }

    public function testNowBuiltinReturnsCurrentDateTime(): void
    {
        $resolved = (string) $this->resolver->resolve('{{ $now }}', []);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $resolved);
    }

    public function testNowPlusDaysShiftsForward(): void
    {
        $base = strtotime((string) $this->resolver->resolve('{{ $now }}', [])) ?: time();
        $shifted = strtotime((string) $this->resolver->resolve('{{ $now+30d }}', [])) ?: 0;

        // Allow a small tolerance for the second-boundary that may tick mid-test.
        self::assertGreaterThanOrEqual(30 * 86400 - 5, $shifted - $base);
        self::assertLessThanOrEqual(30 * 86400 + 5, $shifted - $base);
    }

    public function testTodayBuiltinReturnsDateOnly(): void
    {
        $resolved = (string) $this->resolver->resolve('{{ $today }}', []);
        self::assertSame(date('Y-m-d'), $resolved);
    }

    public function testUniqidBuiltinReturnsHexSlug(): void
    {
        $resolved = (string) $this->resolver->resolve('{{ uniqid }}', []);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $resolved);

        $b = (string) $this->resolver->resolve('{{ uniqid }}', []);
        self::assertNotSame($resolved, $b);
    }
}
