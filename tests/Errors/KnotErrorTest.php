<?php

declare(strict_types=1);

namespace Knot\Tests\Errors;

use Knot\Errors\ErrorCodes;
use Knot\Errors\KnotError;
use PHPUnit\Framework\TestCase;

final class KnotErrorTest extends TestCase
{
    public function testToArrayShape(): void
    {
        $e = new KnotError(
            'KNOT_TEST_DUMMY',
            'Hello user',
            'Hello tech',
            'https://example.invalid/doc',
            ['k' => 1],
            'Try again',
            'warning'
        );
        $a = $e->toArray();
        $this->assertSame('KNOT_TEST_DUMMY', $a['code']);
        $this->assertSame('Hello user', $a['user_message']);
        $this->assertSame('warning', $a['severity']);
        $this->assertSame(['k' => 1], $a['context']);
    }
}
