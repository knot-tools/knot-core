<?php

declare(strict_types=1);

namespace Knot\Tests\Version;

use Knot\Version;
use PHPUnit\Framework\TestCase;

final class VersionCurrentTest extends TestCase
{
    public function testCurrentReturnsFallbackWhenModKnotUnavailable(): void
    {
        self::assertSame(Version::FALLBACK, Version::current());
    }
}
