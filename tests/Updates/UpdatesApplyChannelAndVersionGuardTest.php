<?php

/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

namespace Knot\Tests\Updates;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the two C2-1b hardening guards present in api/updates_apply.php:
 *   1. Body-level channel override accepted and forwarded
 *   2. release_version_mismatch rejection when resolved != requested
 *
 * @covers \Knot\Updates\UpdatesApplyPolicy
 */
final class UpdatesApplyChannelAndVersionGuardTest extends TestCase
{
    private const API_SOURCE = __DIR__ . '/../../api/updates_apply.php';

    public function testChannelOverrideFromRequestBody(): void
    {
        $src = (string) file_get_contents(self::API_SOURCE);
        self::assertStringContainsString(
            "\$bodyChannel = strtolower(trim((string) (\$body['channel'] ?? '')))",
            $src,
            'updates_apply.php must read channel from POST body',
        );
        self::assertStringContainsString(
            '$releaseChannel = $bodyChannel',
            $src,
            'updates_apply.php must assign body channel override to releaseChannel',
        );
    }

    public function testChannelOverrideRequiresAlphanumericFormat(): void
    {
        $src = (string) file_get_contents(self::API_SOURCE);
        self::assertStringContainsString(
            "preg_match('/^[a-z0-9-]{2,32}$/', \$bodyChannel)",
            $src,
            'Body channel must be validated against a safe pattern',
        );
    }

    public function testVersionMismatchGuardPresent(): void
    {
        $src = (string) file_get_contents(self::API_SOURCE);
        self::assertStringContainsString(
            "'release_version_mismatch'",
            $src,
            'updates_apply.php must emit release_version_mismatch error code',
        );
        self::assertStringContainsString(
            '$releaseVersion !== $targetVersion',
            $src,
            'Version mismatch comparison must be present',
        );
    }

    public function testVersionMismatchOnlyWhenTargetProvided(): void
    {
        $src = (string) file_get_contents(self::API_SOURCE);
        self::assertStringContainsString(
            "\$targetVersion !== '' && \$releaseVersion !== ''",
            $src,
            'Version mismatch guard must be conditional on both versions being non-empty',
        );
    }

    public function testVersionMismatchHttp409(): void
    {
        $src = (string) file_get_contents(self::API_SOURCE);
        self::assertMatchesRegularExpression(
            '/release_version_mismatch.*409/s',
            $src,
            'Version mismatch must respond with HTTP 409',
        );
    }
}
