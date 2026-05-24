<?php

declare(strict_types=1);

namespace Knot\Connectors;

/**
 * Enum-like holder of the risk-grammar constants.
 *
 * Canonical lists live here. The {@see RiskAware} trait references these
 * values (it cannot declare constants without requiring PHP 8.2+). External
 * code (tests, frontend bridge, manifest validation) should use this class.
 */
final class RiskLevels
{
    public const SAFE = 'safe';
    public const CAUTION = 'caution';
    public const CRITICAL = 'critical';

    public const ALL = [self::SAFE, self::CAUTION, self::CRITICAL];

    public const SIDE_EFFECT_DB = 'db';
    public const SIDE_EFFECT_MAIL = 'mail';
    public const SIDE_EFFECT_HTTP = 'http';
    public const SIDE_EFFECT_ACCOUNTING = 'accounting';
    public const SIDE_EFFECT_FS = 'fs';
    public const SIDE_EFFECT_EXTERNAL_PAID = 'external-paid';

    public const ALL_SIDE_EFFECTS = [
        self::SIDE_EFFECT_DB,
        self::SIDE_EFFECT_MAIL,
        self::SIDE_EFFECT_HTTP,
        self::SIDE_EFFECT_ACCOUNTING,
        self::SIDE_EFFECT_FS,
        self::SIDE_EFFECT_EXTERNAL_PAID,
    ];

    private function __construct()
    {
        // Static-only; not instantiable.
    }
}
