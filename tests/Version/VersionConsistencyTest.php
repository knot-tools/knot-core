<?php

declare(strict_types=1);

namespace Knot\Tests\Version;

use Knot\Version;
use PHPUnit\Framework\TestCase;

/**
 * Ensures the shipped semver string cannot drift across PHP and frontend metadata.
 */
final class VersionConsistencyTest extends TestCase
{
    public function testModKnotDescriptorMatchesVersionFallback(): void
    {
        $modPath = dirname(__DIR__, 2) . '/core/modules/modKnot.class.php';
        self::assertFileExists($modPath);
        $contents = (string) file_get_contents($modPath);
        self::assertSame(1, preg_match('/\$this->version\s*=\s*\'([^\']+)\'/m', $contents, $matches));
        self::assertSame(
            Version::FALLBACK,
            $matches[1],
            'modKnot $this->version must match Knot\\Version::FALLBACK'
        );
    }

    public function testFrontendPackageJsonMatchesVersionFallback(): void
    {
        $jsonPath = dirname(__DIR__, 2) . '/frontend/package.json';
        self::assertFileExists($jsonPath);
        /** @var array<string, mixed> $data */
        $data = json_decode((string) file_get_contents($jsonPath), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('version', $data);
        self::assertIsString($data['version']);
        self::assertSame(
            Version::FALLBACK,
            $data['version'],
            'frontend/package.json version must match Knot\\Version::FALLBACK'
        );
    }
}
