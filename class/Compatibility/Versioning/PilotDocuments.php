<?php

declare(strict_types=1);

namespace Knot\Compatibility\Versioning;

/**
 * Pilot documents for schema snapshots (ADR-010) vs dynamic state-machine enrichment.
 *
 * Compatibility snapshots default to a small stable trio. Capabilities `states_known`
 * are filled for every active mapped object that is not excluded and exposes STATUS_*
 * constants discoverable by {@see \Knot\StateMachine\StateMachineEngine}.
 */
final class PilotDocuments
{
    /**
     * Default slugs for bundled schema snapshot tooling (ADR-011).
     *
     * @var list<string>
     */
    public const SCHEMA_SNAPSHOT_SLUGS = ['facture', 'commande', 'propal'];

    /**
     * @deprecated Use SCHEMA_SNAPSHOT_SLUGS (same values).
     * @var list<string>
     */
    public const SLUGS = self::SCHEMA_SNAPSHOT_SLUGS;

    /**
     * Objects skipped for automatic state extraction in capabilities (side effects, no workflow statuses, etc.).
     *
     * @var list<string>
     */
    public const STATE_MACHINE_EXCLUDED_SLUGS = [
        'user',
        'usergroup',
        'bookmark',
    ];

    public static function isStateMachineCapabilityExcluded(string $slug): bool
    {
        $slug = trim($slug);

        return $slug === '' || in_array($slug, self::STATE_MACHINE_EXCLUDED_SLUGS, true);
    }
}
