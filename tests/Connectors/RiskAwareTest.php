<?php

declare(strict_types=1);

namespace Knot\Tests\Connectors;

use Knot\Connectors\RiskAware;
use Knot\Connectors\RiskLevels;
use PHPUnit\Framework\TestCase;

final class RiskAwareTest extends TestCase
{
    public function testDefaultsAreSafeAndReversible(): void
    {
        $c = new class () {
            use RiskAware;
        };
        self::assertSame('safe', $c->riskLevel());
        self::assertSame('safe', $c->riskFor(['op' => 'whatever']));
        self::assertTrue($c->reversible());
        self::assertSame([], $c->sideEffects());
        self::assertSame([], $c->requiredPermissionsFor([]));
    }

    public function testDecorateMetadataAddsRiskFieldsButPreservesAuthorValues(): void
    {
        $c = new class () {
            use RiskAware;

            public function riskLevel(): string
            {
                return RiskLevels::CRITICAL;
            }

            public function sideEffects(): array
            {
                return [RiskLevels::SIDE_EFFECT_DB];
            }

            public function reversible(): bool
            {
                return false;
            }

            public function callDecorate(): array
            {
                return $this->decorateMetadata([
                    'id' => 'foo.bar',
                    'label' => 'Foo Bar',
                    'reversible' => true,
                ]);
            }
        };
        $meta = $c->callDecorate();
        self::assertSame('critical', $meta['riskLevel']);
        self::assertSame(['db'], $meta['sideEffects']);
        self::assertTrue($meta['reversible'], 'Author-provided value must win over trait default');
    }

    public function testRiskForCanBeOverriddenForPolymorphicConnectors(): void
    {
        $c = new class () {
            use RiskAware;

            public function riskFor(array $config): string
            {
                return match ($config['operation'] ?? null) {
                    'read', 'list' => RiskLevels::SAFE,
                    'create', 'update' => RiskLevels::CAUTION,
                    'delete', 'validate' => RiskLevels::CRITICAL,
                    default => RiskLevels::CAUTION,
                };
            }
        };
        self::assertSame('safe', $c->riskFor(['operation' => 'read']));
        self::assertSame('caution', $c->riskFor(['operation' => 'create']));
        self::assertSame('critical', $c->riskFor(['operation' => 'delete']));
        self::assertSame('caution', $c->riskFor([]));
    }

    public function testCanonicalRiskLevelsListsAreComplete(): void
    {
        self::assertContains('safe', RiskLevels::ALL);
        self::assertContains('caution', RiskLevels::ALL);
        self::assertContains('critical', RiskLevels::ALL);
        self::assertContains('db', RiskLevels::ALL_SIDE_EFFECTS);
        self::assertContains('external-paid', RiskLevels::ALL_SIDE_EFFECTS);
    }

    public function testCanonicalRiskLevelsClassMatchesTrait(): void
    {
        self::assertSame(\Knot\Connectors\RiskLevels::ALL, [
            \Knot\Connectors\RiskLevels::SAFE,
            \Knot\Connectors\RiskLevels::CAUTION,
            \Knot\Connectors\RiskLevels::CRITICAL,
        ]);
    }
}
