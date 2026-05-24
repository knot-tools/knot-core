<?php

declare(strict_types=1);

namespace Knot\Tests\Templates;

use Knot\Engine\WorkflowValidator;
use PHPUnit\Framework\TestCase;

final class BundledTemplatesManifestTest extends TestCase
{
    public function testAllBundledTemplateDefinitionsValidateWithoutErrors(): void
    {
        $root = dirname(__DIR__, 2) . '/data/templates';
        $manifestPath = $root . '/index.json';
        self::assertFileExists($manifestPath);
        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        self::assertIsArray($manifest);
        self::assertIsArray($manifest['templates'] ?? null);

        $allow = array_keys((new \Knot\Connectors\ConnectorRegistry())->all());

        foreach ($manifest['templates'] as $entry) {
            self::assertIsArray($entry);
            $file = (string) ($entry['file'] ?? '');
            self::assertNotSame('', $file);
            $path = realpath($root . '/' . $file);
            self::assertNotFalse($path);
            self::assertStringStartsWith(realpath($root), $path);

            $definition = json_decode((string) file_get_contents($path), true);
            self::assertIsArray($definition);

            $validator = new WorkflowValidator($allow);
            $issues = $validator->validateAll($definition);
            $errors = array_values(array_filter($issues, static fn ($i) => ($i['severity'] ?? '') === 'error'));
            self::assertSame(
                [],
                $errors,
                'Bundled template ' . $file . ' must not produce validator errors: ' . json_encode($issues)
            );
        }
    }
}
