<?php

/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

namespace Knot\Updates;

/**
 * Slug validation for {@see api/updates_apply.php} apply flows.
 */
final class UpdatesApplyPolicy
{
    /** @var list<string> */
    public const ALLOWED_SLUGS = ['knot', 'knot-pro-pack', 'knot-migration'];

    /**
     * @return array{ok: true, slug: string}|array{ok: false, code: 'invalid_slug'|'slug_not_supported'}
     */
    public static function validateSlug(mixed $raw): array
    {
        $slug = self::parseSlug($raw);
        if ($slug === null) {
            return ['ok' => false, 'code' => 'invalid_slug'];
        }
        if (!self::isAllowedSlug($slug)) {
            return ['ok' => false, 'code' => 'slug_not_supported'];
        }

        return ['ok' => true, 'slug' => $slug];
    }

    public static function parseSlug(mixed $raw): ?string
    {
        $slug = strtolower(trim((string) $raw));
        if ($slug === '' || !preg_match('/^[a-z0-9-]{2,64}$/', $slug)) {
            return null;
        }

        return $slug;
    }

    public static function isAllowedSlug(string $slug): bool
    {
        return in_array($slug, self::ALLOWED_SLUGS, true);
    }
}
