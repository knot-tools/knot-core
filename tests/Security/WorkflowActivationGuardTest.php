<?php

declare(strict_types=1);

namespace Knot\Tests\Security;

use Knot\Connectors\RiskLevels;
use Knot\Repository\AuditLogRepository;
use Knot\Security\WorkflowActivationGuard;
use Knot\Security\WorkflowRiskAnalyzer;
use PHPUnit\Framework\TestCase;

final class WorkflowActivationGuardTest extends TestCase
{
    public function testAllowsDraftToDraftWithoutAck(): void
    {
        $guard = $this->guard();
        $check = $guard->checkActivation(
            ['status' => 'draft', 'definition' => ['nodes' => []]],
            ['status' => 'draft'],
            false,
        );
        self::assertFalse($check['blocked']);
    }

    public function testBlocksCriticalActivationWithoutAck(): void
    {
        $guard = $this->guard();
        $definition = [
            'nodes' => [
                [
                    'id' => 'n1',
                    'type' => 'dolibarr.object',
                    'config' => ['operation' => 'delete'],
                ],
            ],
        ];
        $check = $guard->checkActivation(
            ['status' => 'draft', 'definition' => $definition],
            ['status' => 'active', 'definition' => $definition],
            false,
        );
        self::assertTrue($check['blocked']);
        self::assertSame('workflow_activation_requires_acknowledgement', $check['error']);
    }

    public function testAllowsCriticalActivationWithAck(): void
    {
        $guard = $this->guard();
        $definition = [
            'nodes' => [
                [
                    'id' => 'n1',
                    'type' => 'dolibarr.object',
                    'config' => ['operation' => 'delete'],
                ],
            ],
        ];
        $check = $guard->checkActivation(
            ['status' => 'draft', 'definition' => $definition],
            ['status' => 'active', 'definition' => $definition],
            true,
        );
        self::assertFalse($check['blocked']);
    }

    public function testResetsDismissWhenDefinitionBecomesCritical(): void
    {
        $guard = $this->guard();
        $critical = [
            'nodes' => [
                ['id' => 'n1', 'type' => 'dolibarr.object', 'config' => ['operation' => 'delete']],
            ],
        ];
        self::assertSame(
            false,
            $guard->resolveDismissFlag(
                ['activationWarningDismissed' => true],
                ['definition' => $critical],
            ),
        );
    }

    public function testDoesNotBlockWhenAlreadyActive(): void
    {
        $guard = $this->guard();
        $definition = [
            'nodes' => [
                ['id' => 'n1', 'type' => 'dolibarr.object', 'config' => ['operation' => 'delete']],
            ],
        ];
        $check = $guard->checkActivation(
            ['status' => 'active', 'definition' => $definition],
            ['status' => 'active', 'definition' => $definition],
            false,
        );
        self::assertFalse($check['blocked']);
    }

    public function testDoesNotBlockSafeWorkflowActivation(): void
    {
        $guard = $this->guard();
        $definition = [
            'nodes' => [
                ['id' => 't1', 'type' => 'trigger.manual', 'config' => []],
            ],
        ];
        $check = $guard->checkActivation(
            ['status' => 'draft', 'definition' => $definition],
            ['status' => 'active', 'definition' => $definition],
            false,
        );
        self::assertFalse($check['blocked']);
    }

    public function testResolveDismissReturnsNullWhenDefinitionUnchanged(): void
    {
        $guard = $this->guard();
        $def = ['nodes' => [['id' => 't1', 'type' => 'trigger.manual', 'config' => []]]];
        self::assertNull(
            $guard->resolveDismissFlag(
                ['activationWarningDismissed' => true, 'definition' => $def],
                ['definition' => $def],
            ),
        );
    }

    public function testRecordCriticalActivationAuditDoesNotThrow(): void
    {
        $guard = $this->guard();
        $report = (new WorkflowRiskAnalyzer())->analyze([
            'nodes' => [
                ['id' => 'n1', 'type' => 'dolibarr.object', 'config' => ['operation' => 'delete']],
            ],
        ]);
        $guard->recordCriticalActivationAudit(42, 7, 1, $report, false);
        self::assertTrue(true);
    }

    public function testCreateFactoryBuildsGuard(): void
    {
        $guard = WorkflowActivationGuard::create(null, new AuditLogRepository($this->auditDb()));
        self::assertFalse($guard->definitionHasCritical(['nodes' => []]));
    }

    public function testResolveDismissHonoursIncomingFlag(): void
    {
        $guard = $this->guard();
        self::assertTrue(
            $guard->resolveDismissFlag(['activationWarningDismissed' => false], [
                'activation_warning_dismissed' => true,
            ]),
        );
    }

    public function testCheckActivationAllowsDeactivationWithoutAck(): void
    {
        $guard = $this->guard();
        $definition = [
            'nodes' => [
                ['id' => 'n1', 'type' => 'dolibarr.object', 'config' => ['operation' => 'delete']],
            ],
        ];
        $check = $guard->checkActivation(
            ['status' => 'active', 'definition' => $definition],
            ['status' => 'draft', 'definition' => $definition],
            false,
        );
        self::assertFalse($check['blocked']);
    }

    private function auditDb(): \DoliDB
    {
        return new class() extends \DoliDB {
            public function query(string $sql)
            {
                return new \stdClass();
            }

            public function escape(string $string): string
            {
                return addslashes($string);
            }

            public function idate(int $timestamp): string
            {
                return gmdate('Y-m-d H:i:s', $timestamp);
            }
        };
    }

    private function guard(): WorkflowActivationGuard
    {
        return new WorkflowActivationGuard(new WorkflowRiskAnalyzer(), new AuditLogRepository($this->auditDb()));
    }
}
