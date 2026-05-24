<?php

declare(strict_types=1);

namespace Knot\Tests\Errors;

use Knot\Errors\ErrorCodes;
use PHPUnit\Framework\TestCase;

final class ErrorCatalogTest extends TestCase
{
    public function testCanonicalCodesAreUnique(): void
    {
        $codes = ErrorCodes::all();
        $this->assertSame(count($codes), count(array_unique($codes)), 'Duplicate codes in ErrorCodes::all()');
    }

    public function testCatalogDocumentsEveryCanonicalCode(): void
    {
        $path = dirname(__DIR__, 2) . '/docs/errors/catalog.md';
        $this->assertFileExists($path);
        $catalog = (string) file_get_contents($path);
        foreach (ErrorCodes::all() as $code) {
            $slug = strtolower(str_replace('_', '-', $code));
            $needle = '## ' . $slug;
            $this->assertStringContainsString(
                $needle,
                $catalog,
                'Missing catalogue section for ' . $code
            );
        }
    }
}
