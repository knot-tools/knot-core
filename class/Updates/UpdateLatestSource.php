<?php

/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

namespace Knot\Updates;

/**
 * Notify-only source for {@see UpdateChecker}.
 */
interface UpdateLatestSource
{
    /**
     * @return array{
     *     payload: array{
     *         slug: string,
     *         version: string,
     *         channel: string,
     *         publishedAt: string,
     *         zipSize: int,
     *         zipSha256: string,
     *         signatureKid: string
     *     }|null,
     *     source: string,
     *     error: ?string
     * }
     */
    public function fetchLatest(string $slug): array;
}
