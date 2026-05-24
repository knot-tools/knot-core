<?php

declare(strict_types=1);

namespace Knot\Tests\Marketplace;

use Knot\Extension\LicenseValidator;
use Knot\Marketplace\TierGate;
use Knot\Marketplace\WorkflowTierAuditor;
use Knot\Tests\Marketplace\Fixtures\StubExtensionRegistry;
use PHPUnit\Framework\TestCase;

/**
 * V2.5.0d — protects api/workflows.php?action=import_bulk and the
 * single `knotExport` import path against arriving with workflows
 * that target premium connectors the instance has no licence for.
 *
 * The auditor must:
 *  - leave free Core-only workflows untouched
 *  - flag Pro Pack connectors when the licence is missing/expired
 *  - allow the same workflow once a valid Pro licence is detected
 *  - ignore unrelated third-party extensions (different category)
 *  - tolerate empty / malformed definitions without crashing
 */
final class WorkflowTierAuditorTest extends TestCase
{
    public function testFreeWorkflowPasses(): void
    {
        $auditor = new WorkflowTierAuditor(new TierGate(new StubExtensionRegistry([])));
        $result = $auditor->audit([
            'nodes' => [
                ['id' => 'a', 'type' => 'trigger.manual'],
                ['id' => 'b', 'type' => 'action.http'],
                ['id' => 'c', 'type' => 'logic.if'],
            ],
        ]);
        self::assertFalse($result['blocked']);
        self::assertSame([], $result['missing']);
    }

    public function testProWorkflowWithValidLicensePasses(): void
    {
        $registry = new StubExtensionRegistry([
            TierGate::EXTENSION_PRO => [
                'licenseInfo' => ['status' => LicenseValidator::STATUS_VALID],
            ],
        ]);
        $auditor = new WorkflowTierAuditor(new TierGate($registry));
        $result = $auditor->audit([
            'nodes' => [
                ['id' => 'a', 'type' => 'trigger.manual'],
                ['id' => 'b', 'type' => 'action.stripe'],
                ['id' => 'c', 'type' => 'ai.openai_chat'],
            ],
        ]);
        self::assertFalse($result['blocked']);
        self::assertSame([], $result['missing']);
    }

    public function testProWorkflowWithoutLicenseIsBlocked(): void
    {
        $auditor = new WorkflowTierAuditor(new TierGate(new StubExtensionRegistry([])));
        $result = $auditor->audit([
            'nodes' => [
                ['id' => 'a', 'type' => 'trigger.manual'],
                ['id' => 'b', 'type' => 'action.stripe'],
                ['id' => 'c', 'type' => 'ai.openai_chat'],
                ['id' => 'd', 'type' => 'action.http'],
            ],
        ]);
        self::assertTrue($result['blocked']);
        self::assertCount(2, $result['missing']);
        self::assertSame('action.stripe', $result['missing'][0]['connectorId']);
        self::assertSame(TierGate::TIER_PRO, $result['missing'][0]['tier']);
        self::assertSame('requires_pro_pack', $result['missing'][0]['reason']);
        self::assertSame('ai.openai_chat', $result['missing'][1]['connectorId']);
    }

    public function testProWorkflowWithExpiredLicenseIsBlocked(): void
    {
        $registry = new StubExtensionRegistry([
            TierGate::EXTENSION_PRO => [
                'licenseInfo' => ['status' => LicenseValidator::STATUS_EXPIRED],
            ],
        ]);
        $auditor = new WorkflowTierAuditor(new TierGate($registry));
        $result = $auditor->audit([
            'nodes' => [
                ['id' => 'a', 'type' => 'action.stripe'],
            ],
        ]);
        self::assertTrue($result['blocked']);
        self::assertSame('action.stripe', $result['missing'][0]['connectorId']);
    }

    public function testThirdPartyConnectorIsIgnored(): void
    {
        $auditor = new WorkflowTierAuditor(new TierGate(new StubExtensionRegistry([])));
        $result = $auditor->audit([
            'nodes' => [
                ['id' => 'a', 'type' => 'action.acmeindustry.specialthing'],
            ],
        ]);
        self::assertFalse($result['blocked']);
        self::assertSame([], $result['missing']);
    }

    public function testEmptyDefinitionDoesNotCrash(): void
    {
        $auditor = new WorkflowTierAuditor(new TierGate(new StubExtensionRegistry([])));
        self::assertFalse($auditor->audit([])['blocked']);
        self::assertFalse($auditor->audit(['nodes' => []])['blocked']);
        self::assertFalse($auditor->audit(['nodes' => 'oops'])['blocked']);
        self::assertFalse($auditor->audit(['nodes' => [['type' => '']]])['blocked']);
    }
}

