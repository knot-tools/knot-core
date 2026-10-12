<?php

declare(strict_types=1);

namespace Knot\Tests\Engine;

use Knot\Connectors\ConnectorRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Every shipped starter node type must resolve in {@see ConnectorRegistry}
 * (prevents Simulate/launch "Connector not registered" crashes).
 */
final class StarterExamplesConnectorRegistryTest extends TestCase
{
    public function testAllStarterNodeTypesAreRegistered(): void
    {
        $root = dirname(__DIR__, 2) . '/examples/starter';
        self::assertDirectoryExists($root);

        $files = glob($root . '/*.knot.json');
        self::assertIsArray($files);
        self::assertNotEmpty($files);

        $registered = array_keys((new ConnectorRegistry())->all());
        $failures = [];

        foreach ($files as $path) {
            $json = file_get_contents($path);
            self::assertNotFalse($json, 'Unreadable: ' . $path);
            /** @var array<string, mixed> $envelope */
            $envelope = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            $definition = $envelope['workflow']['definition'] ?? null;
            self::assertIsArray($definition, 'Missing workflow.definition in ' . basename($path));

            $unknown = [];
            foreach ($definition['nodes'] ?? [] as $node) {
                if (!is_array($node)) {
                    continue;
                }
                $type = (string) ($node['type'] ?? '');
                if ($type === '' || in_array($type, $registered, true)) {
                    continue;
                }
                $unknown[] = $type;
            }

            if ($unknown !== []) {
                $failures[basename($path)] = array_values(array_unique($unknown));
            }
        }

        self::assertSame(
            [],
            $failures,
            'Starter examples reference unregistered connectors: ' . json_encode($failures, JSON_PRETTY_PRINT)
        );
    }
}
