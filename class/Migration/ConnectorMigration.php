<?php

/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

namespace Knot\Migration;

use Knot\Repository\WorkflowRepository;

/**
 * Connector migration helper.
 *
 * V2.5.0b removes 20 SaaS / AI connectors from Knot Core (free tier)
 * because they wrap commercial APIs that bill per call. They are now
 * shipped by `knot-pro-pack`, a separate Dolibarr module sold on
 * Dolistore. **V2.8.1** further moves universal HTTP/SFTP/Telegram,
 * cloud + local AI runners, named Stripe/Shopify webhook triggers, and
 * multi-channel alert fan-out into Pro Pack (see ADR-017). Existing workflows that reference the migrated connector
 * ids (`action.stripe`, `action.shopify`, `action.ai_openai`, ...)
 * keep their JSON unchanged: the same id is exposed by Pro Pack via
 * an inheriting subclass, so activating the Pro Pack module is the
 * only step required to keep them running.
 *
 * This helper exposes:
 *  - {@see migratedConnectorIds()} — the canonical list, single
 *    source of truth used by both Knot Core (UI banner, audit log)
 *    and admin tooling.
 *  - {@see scanImpactedWorkflows()} — given an entity, returns every
 *    workflow whose JSON references at least one migrated connector,
 *    with the exact list of impacted nodes. Powers:
 *      * the orange banner in `EditorView.vue`
 *      * the `/admin/setup.php?mode=pro-pack-migration` global view
 *      * the audit hook that emits a deprecation WARN on first
 *        execution of an unmigrated workflow.
 *
 * The helper is read-only: it never rewrites a workflow JSON. The
 * Pro Pack subclass mechanism guarantees backward compatibility, so
 * automatic rewriting would only add churn without any benefit.
 */
final class ConnectorMigration
{
    /**
     * Connectors moved to `knot-pro-pack` in V2.5.0b. KEEP IN SYNC with
     * `pro-pack/knot-extension.json` — the Pro Pack subclasses MUST
     * expose the exact same id via getMetadata() to avoid breaking
     * existing persisted workflows.
     *
     * @var array<int, string>
     */
    public const MIGRATED_TO_PRO_PACK = [
        // Premium SaaS payments / e-commerce
        'action.stripe',
        'action.shopify',
        'action.woocommerce',
        'action.prestashop',

        // Premium SaaS productivity
        'action.notion',
        'action.airtable',

        // Premium SaaS Google workspace
        'action.google_sheets',
        'action.google_drive',
        'action.google_calendar',
        'action.gmail',

        // Premium SaaS messaging (per-call)
        'action.slack',
        'action.discord',
        'action.whatsapp_cloud',
        'action.whatsapp_twilio',
        'action.twilio_sms',
        'action.ovh_sms',

        // Premium SaaS VCS
        'action.github',
        'action.gitlab',

        // Premium AI (cloud + local runner — Pro Pack from V2.8.1)
        'action.ai_openai',
        'action.ai_anthropic',
        'action.ai_mistral',
        'action.ai_gemini',
        'action.ai_ollama',

        // Universal / infra integrations (V2.8.1 slim Core)
        'action.http',
        'action.sftp',
        'action.telegram',

        // Named inbound webhooks (V2.8.1)
        'trigger.stripe_webhook',
        'trigger.shopify_webhook',

        // Multi-channel alert fan-out (V2.8.1)
        'notification.alert_fanout',
    ];

    public function __construct(private readonly WorkflowRepository $workflows)
    {
    }

    /**
     * Canonical list of migrated connector ids.
     *
     * @return array<int, string>
     */
    public static function migratedConnectorIds(): array
    {
        return self::MIGRATED_TO_PRO_PACK;
    }

    /**
     * Return true when the given connector id was migrated to Pro Pack.
     */
    public static function isMigrated(string $connectorId): bool
    {
        return in_array($connectorId, self::MIGRATED_TO_PRO_PACK, true);
    }

    /**
     * Scan every workflow of the entity and return those that reference
     * at least one migrated connector id.
     *
     * Result shape:
     * ```
     * [
     *   [
     *     'workflowId' => 42,
     *     'ref'        => 'WF-2026-001',
     *     'label'      => 'Send Stripe invoice on order paid',
     *     'status'     => 'active',
     *     'updatedAt'  => '2026-04-01 12:00:00',
     *     'impactedNodes' => [
     *       ['nodeId' => 'n_3', 'connectorId' => 'action.stripe'],
     *       ...
     *     ],
     *     'distinctConnectorIds' => ['action.stripe'],
     *   ],
     *   ...
     * ]
     * ```
     *
     * @param list<string>|null $availableConnectorIds When set, migrated connector
     *                                                  ids that are currently
     *                                                  registered (Core + loaded
     *                                                  extensions) are excluded —
     *                                                  the workflow is only
     *                                                  "impacted" when Pro Pack is
     *                                                  missing or unlicensed.
     *
     * @return array<int, array<string, mixed>>
     */
    public function scanImpactedWorkflows(int $entity, ?array $availableConnectorIds = null): array
    {
        $impacted = [];
        // 500 is generous: SMB instances rarely cross 100 workflows. The
        // call is meant to feed an admin screen, not a hot path.
        $workflows = $this->workflows->list($entity, [], 500, 0);
        foreach ($workflows as $wf) {
            $definition = $this->fetchDefinition((int) $wf['id'], $entity);
            $hits = $this->extractMigratedHits($definition, $availableConnectorIds);
            if ($hits === []) {
                continue;
            }
            $impacted[] = [
                'workflowId'           => (int) $wf['id'],
                'ref'                  => (string) ($wf['ref'] ?? ''),
                'label'                => (string) ($wf['label'] ?? ''),
                'status'               => (string) ($wf['status'] ?? ''),
                'updatedAt'            => (string) ($wf['updatedAt'] ?? ''),
                'impactedNodes'        => $hits,
                'distinctConnectorIds' => array_values(array_unique(array_column($hits, 'connectorId'))),
            ];
        }
        return $impacted;
    }

    /**
     * Fetch the raw `json_definition` for a workflow. Returns an empty
     * array when the workflow is missing or the JSON cannot be decoded.
     *
     * @return array<string, mixed>
     */
    private function fetchDefinition(int $workflowId, int $entity): array
    {
        $row = $this->workflows->fetch($workflowId, $entity);
        if ($row === null) {
            return [];
        }
        // WorkflowRepository::fetch() decodes the JSON column eagerly,
        // so $row['definition'] is normally an already-decoded array.
        // We still tolerate a raw string (older repository versions or
        // direct DB access) to keep the helper resilient.
        $value = $row['definition'] ?? $row['json_definition'] ?? null;
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    /**
     * Walk the workflow definition and return one entry per node that
     * references a migrated connector id.
     *
     * @param array<string, mixed> $definition
     * @param list<string>|null $availableConnectorIds
     * @return array<int, array{nodeId: string, connectorId: string}>
     */
    private function extractMigratedHits(array $definition, ?array $availableConnectorIds = null): array
    {
        $hits = [];
        $availableSet = $availableConnectorIds !== null
            ? array_fill_keys($availableConnectorIds, true)
            : null;
        $nodes = is_array($definition['nodes'] ?? null) ? $definition['nodes'] : [];
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            $connectorId = (string) ($node['connector'] ?? $node['connectorId'] ?? $node['type'] ?? '');
            if ($connectorId === '' || !self::isMigrated($connectorId)) {
                continue;
            }
            if ($availableSet !== null && isset($availableSet[$connectorId])) {
                continue;
            }
            $hits[] = [
                'nodeId'      => (string) ($node['id'] ?? '?'),
                'connectorId' => $connectorId,
            ];
        }
        return $hits;
    }
}
