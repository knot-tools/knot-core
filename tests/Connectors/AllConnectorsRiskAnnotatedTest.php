<?php

declare(strict_types=1);

namespace Knot\Tests\Connectors;

use Knot\Connectors\ConnectorInterface;
use Knot\Connectors\ConnectorRegistry;
use Knot\Connectors\DryRunAware;
use Knot\Connectors\RiskLevels;
use PHPUnit\Framework\TestCase;

/**
 * Sanity check enforcing the V2.5 UX-2 contract:
 * every Knot Core connector must declare a valid `riskLevel` in its
 * metadata, and any connector with riskLevel = caution|critical must
 * implement the DryRunAware contract (or the engine cannot offer a
 * truthful "Test à blanc" button).
 */
final class AllConnectorsRiskAnnotatedTest extends TestCase
{
    /**
     * @return array<string, array{0: ConnectorInterface, 1: array<string, mixed>}>
     */
    public static function connectorProvider(): array
    {
        $registry = new ConnectorRegistry();
        $cases = [];
        foreach ($registry->all() as $instance) {
            if (!$instance instanceof ConnectorInterface) {
                continue;
            }
            $meta = $instance->getMetadata();
            $id = (string) ($meta['id'] ?? get_class($instance));
            $cases[$id] = [$instance, $meta];
        }
        return $cases;
    }

    /**
     * @dataProvider connectorProvider
     * @param array<string, mixed> $meta
     */
    public function testRiskLevelDeclared(ConnectorInterface $connector, array $meta): void
    {
        $id = (string) ($meta['id'] ?? '?');
        self::assertArrayHasKey('riskLevel', $meta, "Connector $id must declare riskLevel in getMetadata()");
        self::assertContains(
            $meta['riskLevel'],
            RiskLevels::ALL,
            "Connector $id has invalid riskLevel '{$meta['riskLevel']}'"
        );
        self::assertArrayHasKey('reversible', $meta, "Connector $id must declare reversible in getMetadata()");
        self::assertIsBool($meta['reversible'], "Connector $id reversible must be a boolean");
        self::assertArrayHasKey('sideEffects', $meta, "Connector $id must declare sideEffects in getMetadata()");
        self::assertIsArray($meta['sideEffects'], "Connector $id sideEffects must be an array");
        foreach ($meta['sideEffects'] as $effect) {
            self::assertContains(
                $effect,
                RiskLevels::ALL_SIDE_EFFECTS,
                "Connector $id has invalid sideEffect '$effect'"
            );
        }
    }

    /**
     * @dataProvider connectorProvider
     * @param array<string, mixed> $meta
     */
    public function testCautionAndCriticalConnectorsImplementDryRunAware(
        ConnectorInterface $connector,
        array $meta
    ): void {
        $id = (string) ($meta['id'] ?? '?');
        $level = $meta['riskLevel'] ?? 'safe';

        if ($level === 'safe') {
            self::assertTrue(true);
            return;
        }
        // Polymorphic connectors (riskByConfig) may have caution as their
        // declared static level even though some configs are safe;
        // dry-run is still mandatory because at least one config is
        // caution/critical.
        self::assertInstanceOf(
            DryRunAware::class,
            $connector,
            "Connector $id has riskLevel=$level but does not implement DryRunAware. "
                . "Without simulate(), the 'Test à blanc' button is a lie."
        );
    }

    /**
     * @dataProvider connectorProvider
     * @param array<string, mixed> $meta
     */
    public function testRiskByConfigUsesValidLevels(ConnectorInterface $connector, array $meta): void
    {
        $id = (string) ($meta['id'] ?? '?');
        if (!isset($meta['riskByConfig'])) {
            self::assertTrue(true);
            return;
        }
        self::assertIsArray($meta['riskByConfig'], "Connector $id riskByConfig must be an array");
        foreach ($meta['riskByConfig'] as $configKey => $level) {
            self::assertContains(
                $level,
                RiskLevels::ALL,
                "Connector $id riskByConfig[$configKey]='$level' is not a valid risk level"
            );
        }
    }
}
