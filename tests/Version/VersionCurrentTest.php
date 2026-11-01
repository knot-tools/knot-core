<?php

declare(strict_types=1);

namespace Knot\Tests\Version;

use Knot\Version;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class VersionCurrentTest extends TestCase
{
    private const DESCRIPTOR = __DIR__ . '/../../core/modules/modKnot.class.php';

    /** @var list<string> */
    private array $tmpFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $file) {
            @unlink($file);
        }
    }

    public function testCurrentReadsThisTreeDescriptor(): void
    {
        $descriptorVersion = Version::fromDescriptor(self::DESCRIPTOR);

        self::assertNotNull($descriptorVersion);
        self::assertSame($descriptorVersion, Version::current());
    }

    #[RunInSeparateProcess]
    public function testCurrentIgnoresModKnotClassAlreadyInMemory(): void
    {
        require __DIR__ . '/../fixtures/Version/stale_modKnot.php';

        self::assertSame(Version::fromDescriptor(self::DESCRIPTOR), Version::current());
    }

    public function testFromDescriptorParsesVersionAssignment(): void
    {
        $path = $this->writeTmp("<?php\nclass modKnot {\n    public function __construct() {\n        \$this->version = '9.8.7';\n    }\n}\n");

        self::assertSame('9.8.7', Version::fromDescriptor($path));
    }

    public function testFromDescriptorReturnsNullWithoutVersion(): void
    {
        $path = $this->writeTmp("<?php\nclass modKnot {}\n");

        self::assertNull(Version::fromDescriptor($path));
    }

    public function testFromDescriptorReturnsNullWhenMissing(): void
    {
        self::assertNull(Version::fromDescriptor(sys_get_temp_dir() . '/knot-missing-' . bin2hex(random_bytes(4)) . '.php'));
    }

    private function writeTmp(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'knot-version-');
        self::assertIsString($path);
        file_put_contents($path, $contents);
        $this->tmpFiles[] = $path;

        return $path;
    }
}
