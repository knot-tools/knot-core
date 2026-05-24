<?php

declare(strict_types=1);

namespace Knot\Tests\Licensing\Audit;

use Knot\Licensing\Audit\LicenseAuditEvent;
use Knot\Licensing\Audit\LicenseAuditWriter;
use Knot\Repository\AuditLogRepository;
use Knot\Security\SecretMasker;
use PHPUnit\Framework\TestCase;

final class LicenseAuditWriterTest extends TestCase
{
    public function testRecordPersistsWithKnotLicenseEntityTypeAndExtensionPayload(): void
    {
        $db = new CapturingDb();
        $writer = new LicenseAuditWriter(new AuditLogRepository($db), new SecretMasker());

        $writer->record(
            LicenseAuditEvent::LICENSE_ACTIVATED,
            'knot-pro-pack',
            ['plan' => 'standard']
        );

        self::assertCount(1, $db->queries, 'one INSERT must hit the audit table');
        $sql = $db->queries[0];
        self::assertStringContainsString('llx_knot_audit_log', $sql);
        self::assertStringContainsString("'licensing.license.activated'", $sql);
        self::assertStringContainsString("'knot_license'", $sql);
        self::assertStringContainsString('knot-pro-pack', $sql);
        self::assertStringContainsString('standard', $sql);
    }

    public function testSecretsAreMaskedInAuditPayload(): void
    {
        $db = new CapturingDb();
        $writer = new LicenseAuditWriter(new AuditLogRepository($db), new SecretMasker());

        $writer->record(
            LicenseAuditEvent::LICENSE_REFRESH_FAILED,
            'knot-pro-pack',
            [
                'authorization' => 'Bearer xyz123secret',
                'apiKey' => 'sk_live_AAAAAAAAAAAAAAAAAA',
                'safe' => 'visible',
            ]
        );

        self::assertCount(1, $db->queries);
        $sql = $db->queries[0];
        self::assertStringNotContainsString('Bearer xyz123secret', $sql);
        self::assertStringNotContainsString('sk_live_AAAAAAAAAAAAAAAAAA', $sql);
        self::assertStringContainsString(SecretMasker::REDACTED, $sql);
        self::assertStringContainsString('visible', $sql);
    }

    public function testNeverThrowsWhenDbReportsFailure(): void
    {
        $db = new CapturingDb();
        $db->forceFailure = true;
        $writer = new LicenseAuditWriter(new AuditLogRepository($db));
        $writer->record(LicenseAuditEvent::LICENSE_TAMPERED, 'knot-pro-pack');
        self::assertCount(1, $db->queries, 'a write was attempted');
    }
}
