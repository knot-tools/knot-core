<?php

declare(strict_types=1);

namespace Knot\Tests\Connectors\Notification;

use Knot\Connectors\Notification\AlertAction;
use PHPUnit\Framework\TestCase;

final class AlertActionTest extends TestCase
{
    public function testValidateRequiresTitleMessageAndAuditChannel(): void
    {
        $action = new AlertAction();
        $verdict = $action->validate([]);
        self::assertFalse($verdict['valid']);
        self::assertContains('title is required', $verdict['errors']);
        self::assertContains('message is required', $verdict['errors']);
        self::assertContains('channels.audit must be configured', $verdict['errors']);
    }

    public function testValidateRejectsNonAuditChannels(): void
    {
        $action = new AlertAction();
        $verdict = $action->validate([
            'title' => 't',
            'message' => 'm',
            'channels' => [
                'slack' => ['webhookUrl' => 'https://example.invalid/hook'],
                'audit' => ['enabled' => true],
            ],
        ]);
        self::assertFalse($verdict['valid']);
        self::assertTrue(
            count(array_filter(
                $verdict['errors'],
                fn (string $e) => str_contains($e, 'notification.alert_fanout')
            )) >= 1
        );
    }

    public function testValidateRejectsUnknownSeverity(): void
    {
        $action = new AlertAction();
        $verdict = $action->validate([
            'title' => 't',
            'message' => 'm',
            'severity' => 'meltdown',
            'channels' => ['audit' => ['enabled' => true]],
        ]);
        self::assertFalse($verdict['valid']);
        self::assertNotEmpty(array_filter($verdict['errors'], fn ($e) => str_contains($e, 'severity')));
    }

    public function testSimulateReturnsAuditOnly(): void
    {
        $action = new AlertAction();
        $report = $action->simulate([
            'node' => [
                'config' => [
                    'title' => 'x',
                    'message' => 'y',
                    'channels' => ['audit' => ['enabled' => true]],
                ],
            ],
        ]);
        self::assertTrue($report['_dryRun']);
        self::assertSame(['audit'], $report['channelsThatWouldFire']);
    }

    public function testExecuteWritesAuditWhenEnabled(): void
    {
        $GLOBALS['db'] = new class extends \DoliDB {
            public function query(string $sql)
            {
                return str_contains($sql, 'llx_knot_audit_log');
            }
        };
        $GLOBALS['user'] = new \FakeUser();
        $GLOBALS['conf'] = (object) ['entity' => 1];

        $action = new AlertAction();
        $out = $action->execute([
            'node' => [
                'config' => [
                    'title' => 'Alert title',
                    'message' => 'Something happened',
                    'severity' => 'error',
                    'channels' => ['audit' => ['enabled' => true]],
                ],
            ],
            '_workflowId' => 12,
            '_executionId' => 34,
        ]);

        self::assertTrue($out['allOk']);
        self::assertSame(1, $out['delivered']);
        self::assertSame('error', $out['severity']);
    }

    public function testExecuteReportsFailureWhenAuditDisabled(): void
    {
        $action = new AlertAction();
        $out = $action->execute([
            'node' => [
                'config' => [
                    'title' => 't',
                    'message' => 'm',
                    'channels' => ['audit' => ['enabled' => false]],
                ],
            ],
        ]);

        self::assertFalse($out['allOk']);
        self::assertSame(0, $out['delivered']);
        self::assertSame('audit channel disabled', $out['channels']['audit']['error']);
    }

    public function testMetadataAndTestHook(): void
    {
        $action = new AlertAction();
        self::assertSame('notification.alert', $action->getMetadata()['id']);
        self::assertNull($action->getCredentialType());
        self::assertTrue($action->test([
            'title' => 'x',
            'message' => 'y',
            'channels' => ['audit' => ['enabled' => true]],
        ])['success']);
    }
}
