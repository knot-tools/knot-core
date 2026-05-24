<?php

/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

namespace Knot\Tests\Updates;

use Knot\Updates\UpdatesApplyPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Knot\Updates\UpdatesApplyPolicy
 */
final class UpdatesApplyLogicTest extends TestCase
{
    private const API_SOURCE = __DIR__ . '/../../api/updates_apply.php';

    public function testApplyEndpointDelegatesSlugValidationToPolicy(): void
    {
        $src = (string) file_get_contents(self::API_SOURCE);
        self::assertNotSame('', $src);
        self::assertStringContainsString('UpdatesApplyPolicy::validateSlug', $src);
        self::assertStringContainsString('use Knot\Updates\UpdatesApplyPolicy', $src);
    }

    public function testApplyEndpointRequiresAdminRightAndCsrf(): void
    {
        $src = (string) file_get_contents(self::API_SOURCE);
        self::assertStringContainsString("ApiAuth::requireRight('knot', 'admin', 'configure')", $src);
        self::assertStringContainsString('CsrfGuard::verify()', $src);
        self::assertStringNotContainsString("define('NOLOGIN'", $src);
    }

    /**
     * @return array<string, array{0: mixed, 1: string|null}>
     */
    public static function parseSlugProvider(): array
    {
        return [
            'core slug' => ['knot', 'knot'],
            'pro pack' => ['Knot-Pro-Pack', 'knot-pro-pack'],
            'migration' => ['knot-migration', 'knot-migration'],
            'empty' => ['', null],
            'too short' => ['a', null],
            'invalid chars' => ['knot_pro', null],
            'uppercase only invalid' => ['KNOT', 'knot'],
        ];
    }

    #[DataProvider('parseSlugProvider')]
    public function testParseSlug(mixed $raw, ?string $expected): void
    {
        self::assertSame($expected, UpdatesApplyPolicy::parseSlug($raw));
    }

    /**
     * @return array<string, array{0: mixed, 1: bool, 2?: string}>
     */
    public static function validateSlugProvider(): array
    {
        return [
            'allowed core' => ['knot', true],
            'allowed pro pack' => ['knot-pro-pack', true],
            'allowed migration' => ['knot-migration', true],
            'invalid format' => ['!!', false, 'invalid_slug'],
            'unsupported slug' => ['knot-foo', false, 'slug_not_supported'],
            'empty' => ['', false, 'invalid_slug'],
        ];
    }

    #[DataProvider('validateSlugProvider')]
    public function testValidateSlug(mixed $raw, bool $ok, ?string $code = null): void
    {
        $result = UpdatesApplyPolicy::validateSlug($raw);
        self::assertSame($ok, $result['ok']);
        if ($ok) {
            self::assertIsString($result['slug']);
            self::assertSame(UpdatesApplyPolicy::parseSlug($raw), $result['slug']);
        } else {
            self::assertSame($code, $result['code']);
        }
    }

    public function testIsAllowedSlugDocumentsCommercialExtensionBranch(): void
    {
        self::assertSame(
            ['knot', 'knot-pro-pack', 'knot-migration'],
            UpdatesApplyPolicy::ALLOWED_SLUGS,
        );
        self::assertTrue(UpdatesApplyPolicy::isAllowedSlug('knot-pro-pack'));
        self::assertFalse(UpdatesApplyPolicy::isAllowedSlug('knot-unknown'));
    }
}
