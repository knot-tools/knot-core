<?php

/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

namespace Knot\Marketplace;

/**
 * Result of validating a marketplace editorial document.
 */
final class EditorialValidationResult
{
    /**
     * @param list<string> $errors Stable, machine-readable diagnostics ( dotted paths ).
     */
    public function __construct(
        private readonly bool $valid,
        private readonly array $errors = [],
    ) {
    }

    public function isValid(): bool
    {
        return $this->valid;
    }

    /**
     * @return list<string>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
