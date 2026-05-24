<?php

/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

namespace Knot;

/**
 * First-party Knot product/extension identifiers mirrored on Dolistore /
 * Marketplace catalog slug / extension manifest `id`.
 *
 * @see docs/architecture/decisions/ADR-018-known-first-party-product-identifiers.md
 */
final class KnownSkus
{
    public const PRO_PACK = 'knot-pro-pack';

    public const MIGRATION = 'knot-migration';

    public const ENTERPRISE = 'knot-enterprise';
}
