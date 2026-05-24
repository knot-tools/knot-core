<?php

declare(strict_types=1);

namespace Knot\Tests\Licensing\Audit;

use Knot\Licensing\Audit\LicenseAuditEvent;
use PHPUnit\Framework\TestCase;

final class LicenseAuditEventTest extends TestCase
{
    public function testAllEventsHaveStableLicensingPrefix(): void
    {
        foreach (LicenseAuditEvent::cases() as $case) {
            self::assertStringStartsWith(
                'licensing.',
                $case->value,
                sprintf('Event %s must use the licensing.* namespace', $case->name)
            );
        }
    }

    public function testNoDuplicateBackingValues(): void
    {
        $values = array_map(static fn (LicenseAuditEvent $c) => $c->value, LicenseAuditEvent::cases());
        self::assertSame(
            count($values),
            count(array_unique($values)),
            'Audit event backing values must be unique (they are persisted in DB)'
        );
    }

    public function testCriticalEventsArePresent(): void
    {
        // These cases are referenced by name from the Bootstrap composer
        // and runbooks; renaming them silently breaks audit log queries.
        $required = [
            'LICENSE_ACTIVATED',
            'LICENSE_REFRESH_FAILED',
            'LICENSE_TAMPERED',
            'LICENSE_REVOKED',
            'LICENSE_BINDING_CHANGED',
            'LICENSE_GRACE_ENTERED',
            'LICENSE_GRACE_EXHAUSTED',
            'LICENSE_FORK_DETECTED',
        ];
        foreach ($required as $name) {
            self::assertNotNull(
                constant(LicenseAuditEvent::class . '::' . $name),
                "Required audit event $name is missing"
            );
        }
    }
}
