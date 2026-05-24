<?php

declare(strict_types=1);

namespace Knot\Tests;

use Knot\KnownSkus;
use Knot\Marketplace\TierGate;
use PHPUnit\Framework\TestCase;

final class KnownSkusTest extends TestCase
{
    public function testTierGateExtensionAliasesMatchKnownSkus(): void
    {
        self::assertSame(KnownSkus::PRO_PACK, TierGate::EXTENSION_PRO);
        self::assertSame(KnownSkus::ENTERPRISE, TierGate::EXTENSION_ENTERPRISE);
    }
}
