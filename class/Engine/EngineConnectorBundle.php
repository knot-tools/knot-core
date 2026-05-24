<?php

/* Copyright (C) 2026 Sébastien Audel (EXIATIS) — Knot Tools™ Core, GPL-3.0-or-later */

declare(strict_types=1);

namespace Knot\Engine;

/**
 * @internal Value object returned by {@see EngineConnectorResolver}.
 */
final class EngineConnectorBundle
{
    /**
     * @param array<string, mixed> $connectors
     * @param list<string> $allowlist
     */
    public function __construct(
        private readonly array $connectors,
        private readonly array $allowlist,
        private readonly bool $degraded,
    ) {
    }

    /**
     * @return array{
     *     connectors: array<string, mixed>,
     *     allowlist: list<string>,
     *     degraded: bool
     * }
     */
    public function toArray(): array
    {
        return [
            'connectors' => $this->connectors,
            'allowlist' => $this->allowlist,
            'degraded' => $this->degraded,
        ];
    }
}
