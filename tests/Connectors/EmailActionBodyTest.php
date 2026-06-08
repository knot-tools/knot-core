<?php

declare(strict_types=1);

namespace Knot\Tests\Connectors;

use Knot\Connectors\Communication\EmailAction;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class EmailActionBodyTest extends TestCase
{
    public function testNormalizeHtmlBodyConvertsLiteralNewlines(): void
    {
        $method = new ReflectionMethod(EmailAction::class, 'normalizeHtmlBody');
        $method->setAccessible(true);
        $action = new EmailAction();

        $html = (string) $method->invoke($action, 'Bonjour,\\n\\nMontant TTC : 500 EUR.');

        self::assertStringContainsString('Bonjour,', $html);
        self::assertStringContainsString('<br', $html);
        self::assertStringNotContainsString('\\n', $html);
    }
}
