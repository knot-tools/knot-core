<?php

declare(strict_types=1);

namespace Knot\Tests\Api\Doc;

use Knot\Api\Doc\OpenApiGenerator;
use Knot\Api\Doc\Operation;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \Knot\Api\Doc\OpenApiGenerator
 */
final class OpenApiGeneratorTest extends TestCase
{
    /** @var list<string> */
    private array $tmpDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpDirs as $dir) {
            $this->removeDirectory($dir);
        }
        $this->tmpDirs = [];
        parent::tearDown();
    }

    public function testGenerateAgainstRealApiDocFiles(): void
    {
        $root = dirname(__DIR__, 3);
        $generator = new OpenApiGenerator(
            $root . '/api',
            $root . '/class/Api/Doc/Schemas'
        );

        $doc = $generator->generate('2.12.0');

        self::assertSame('3.1.0', $doc['openapi']);
        self::assertSame('2.12.0', $doc['info']['version']);
        self::assertArrayHasKey('/api/health.php', $doc['paths']);
        self::assertArrayHasKey('get', $doc['paths']['/api/health.php']);
        self::assertArrayHasKey('Envelope', $doc['components']['schemas']);
        self::assertArrayHasKey('dolibarrSession', $doc['components']['securitySchemes']);
    }

    public function testGenerateBuildsRequestAndResponseRefsFromFixture(): void
    {
        $apiDir = $this->makeTempDir();
        $schemasDir = $this->makeTempDir();

        file_put_contents($apiDir . '/sample.php', "<?php\n// endpoint stub\n");
        file_put_contents($apiDir . '/sample.doc.php', <<<'PHP'
<?php
use Knot\Api\Doc\Operation;

#[Operation(
    method: 'POST',
    path: '/api/sample.php',
    summary: 'Sample endpoint',
    tags: ['samples'],
    description: 'Creates a sample row',
    requestSchema: 'SampleRequest',
    responseSchema: 'SampleResponse',
    authRequired: true,
    permission: 'knot->workflow->write',
    responseStatuses: [200, 400, 403],
)]
final class KnotApiDoc_SampleCreate
{
}
PHP);
        file_put_contents($schemasDir . '/SampleRequest.json', '{"type":"object","properties":{"name":{"type":"string"}}}');
        file_put_contents($schemasDir . '/SampleResponse.json', '{"type":"object","properties":{"id":{"type":"integer"}}}');

        $doc = (new OpenApiGenerator($apiDir, $schemasDir))->generate();

        $operation = $doc['paths']['/api/sample.php']['post'];
        self::assertSame('Sample endpoint', $operation['summary']);
        self::assertSame('Creates a sample row', $operation['description']);
        self::assertSame('#/components/schemas/SampleRequest', $operation['requestBody']['content']['application/json']['schema']['$ref']);
        self::assertSame('#/components/schemas/SampleResponse', $operation['responses']['200']['content']['application/json']['schema']['$ref']);
        self::assertSame('Bad request — payload invalid or schema mismatch', $operation['responses']['400']['description']);
        self::assertSame('knot->workflow->write', $operation['x-knot-permission']);
        self::assertSame([['dolibarrSession' => [], 'csrfToken' => []]], $operation['security']);
    }

    public function testGenerateMapsDefaultStatusLabel(): void
    {
        $method = new \ReflectionMethod(OpenApiGenerator::class, 'statusLabel');
        $generator = new OpenApiGenerator($this->makeTempDir(), $this->makeTempDir());
        self::assertSame('HTTP 418', $method->invoke($generator, 418));
    }

    public function testGenerateReturnsEmptyPathsWhenApiDirMissing(): void
    {
        $doc = (new OpenApiGenerator($this->makeTempDir() . '/missing-api', $this->makeTempDir()))->generate();
        self::assertSame([], $doc['paths']);
        self::assertSame([], $doc['components']['schemas']);
    }

    public function testGenerateThrowsWhenSchemaFileIsInvalidJson(): void
    {
        $schemasDir = $this->makeTempDir();
        file_put_contents($schemasDir . '/Broken.json', '{not-json');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Schema file is not valid JSON');
        (new OpenApiGenerator($this->makeTempDir(), $schemasDir))->generate();
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/knot-openapi-' . uniqid('', true);
        self::assertTrue(mkdir($dir, 0755, true));
        $this->tmpDirs[] = $dir;

        return $dir;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
                continue;
            }
            unlink($path);
        }
        rmdir($dir);
    }
}
