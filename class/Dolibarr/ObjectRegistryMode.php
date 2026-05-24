<?php

declare(strict_types=1);

namespace Knot\Dolibarr;

/**
 * Persisted Dolibarr-object inspector registry filter (workflow node config).
 */
final class ObjectRegistryMode
{
    /** MAP / curated slugs only (default for new nodes). */
    public const ALL_EXCEPT_UNVERIFIED = 'all_except_unverified';

    /** MAP + introspection-discovered slugs. */
    public const ALL = 'all';

    /** MAP slugs only (explicit shortcut; may equal ALL_EXCEPT_UNVERIFIED for API list data). */
    public const CURATED = 'curated';

    /**
     * Discovered slugs only (fromMap false), standard inspector schema and validation.
     */
    public const DISCOVERY = 'discovery';

    /**
     * Same slug set as DISCOVERY; expert inspector: full field_view schema + relaxed payload validation.
     */
    public const DISCOVERY_UNVERIFIED = 'discovery_unverified';

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::ALL_EXCEPT_UNVERIFIED,
            self::ALL,
            self::CURATED,
            self::DISCOVERY,
            self::DISCOVERY_UNVERIFIED,
        ];
    }

    public static function isDiscoveryUnverifiedExpert(string $mode): bool
    {
        return $mode === self::DISCOVERY_UNVERIFIED;
    }
}
