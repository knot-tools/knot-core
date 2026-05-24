<?php

declare(strict_types=1);

namespace Knot\Tests\Engine;

use Knot\Connectors\ConnectorRegistry;
use Knot\Engine\EngineConnectorResolver;
use Knot\Tests\Repository\InMemoryConfigDb;
use PHPUnit\Framework\TestCase;

final class EngineConnectorResolverTest extends TestCase
{
    protected function tearDown(): void
    {
        EngineConnectorResolver::resetCacheForTests();
        parent::tearDown();
    }

    public function testResolveReturnsCoreConnectorsAtMinimum(): void
    {
        $db = new InMemoryConfigDb();
        $bundle = EngineConnectorResolver::resolve($db, 1);

        self::assertIsArray($bundle['connectors']);
        self::assertIsArray($bundle['allowlist']);
        self::assertContains('trigger.manual', $bundle['allowlist']);
        self::assertGreaterThanOrEqual(count((new ConnectorRegistry())->all()), count($bundle['connectors']));
    }

    public function testResolveMemoizesPerEntityWithinProcess(): void
    {
        $db = new InMemoryConfigDb();
        $first = EngineConnectorResolver::resolve($db, 42);
        $second = EngineConnectorResolver::resolve($db, 42);

        self::assertSame($first, $second);
    }

    public function testResolveUsesSeparateCacheEntriesPerEntity(): void
    {
        $db = new InMemoryConfigDb();
        $entityOne = EngineConnectorResolver::resolve($db, 1);
        $entityTwo = EngineConnectorResolver::resolve($db, 2);

        self::assertSame($entityOne['allowlist'], $entityTwo['allowlist']);
    }

    public function testResolveFallsBackToCoreOnlyWhenRegistryFails(): void
    {
        EngineConnectorResolver::simulateRegistryFailureForTests(true);
        $db = new InMemoryConfigDb();
        $bundle = EngineConnectorResolver::resolve($db, 1);
        $coreKeys = array_keys((new ConnectorRegistry())->all());

        self::assertTrue($bundle['degraded']);
        self::assertSame($coreKeys, $bundle['allowlist']);
    }
}
