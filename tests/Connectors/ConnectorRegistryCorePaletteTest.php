<?php

declare(strict_types=1);

namespace Knot\Tests\Connectors;

use Knot\Connectors\ConnectorRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Guard: Core registry size and ids must match docs/connectors-inventory.md (V2.8.1 slim palette).
 */
final class ConnectorRegistryCorePaletteTest extends TestCase
{
    public function testCorePaletteCountAndIds(): void
    {
        $ids = array_keys((new ConnectorRegistry())->all());
        sort($ids);

        $expected = [
            'action.email',
            'dolibarr.object',
            'dolibarr.read_object',
            'dolibarr.specialized',
            'dolibarr.sql_query',
            'logic.approval_wait',
            'logic.array',
            'logic.crypto',
            'logic.date',
            'logic.execute_workflow',
            'logic.filter',
            'logic.html',
            'logic.if',
            'logic.json',
            'logic.loop',
            'logic.merge',
            'logic.number',
            'logic.respond_webhook',
            'logic.set',
            'logic.split',
            'logic.stop_error',
            'logic.string',
            'logic.switch',
            'logic.wait',
            'logic.while',
            'logic.xml',
            'notification.alert',
            'trigger.cron',
            'trigger.dolibarr_event',
            'trigger.manual',
            'trigger.webhook',
        ];

        self::assertSame($expected, $ids, 'Update docs/connectors-inventory.md and this test together when the Core palette changes.');
    }
}
