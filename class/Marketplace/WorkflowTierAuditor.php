<?php

/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

namespace Knot\Marketplace;

/**
 * V2.5.0d — Workflow tier auditor.
 *
 * Walks a workflow definition and reports which connectors require a
 * paid tier (Pro / Enterprise) that is currently not licensed on this
 * instance. Used by `api/workflows.php` to refuse imports that would
 * otherwise create unrunnable workflows pointing at locked connectors.
 *
 * The mapping is intentionally pinned to a constant list rather than
 * derived from `ExtensionRegistry::loadedConnectors()` so we can still
 * detect a Pro Pack workflow even when the Pro Pack module is *not
 * installed at all* on the target instance — discovery would otherwise
 * be silent and let the import succeed (the runtime would later fail
 * with a confusing "connector not found" message).
 *
 * The list mirrors the V2.5.0b migration from Knot Core to the Pro
 * Pack add-on (`modKnotProPack`). When new connectors move tier, they
 * must be added here and to the corresponding pack's manifest.
 *
 * Pure / idempotent — no I/O, no DB, no network.
 */
final class WorkflowTierAuditor
{
    /**
     * Connector ids that live in `knot-pro-pack`. Migrated from Core
     * in V2.5.0b. Source of truth: `pro-pack/manifest/connectors.json`.
     */
    private const PRO_NODE_TYPES = [
        // Premium SaaS (commerce)
        'action.stripe',
        'action.shopify',
        'action.woocommerce',
        'action.prestashop',
        // Premium SaaS (productivity)
        'action.notion',
        'action.airtable',
        'action.github',
        'action.gitlab',
        // Premium SaaS (Google)
        'action.google_sheets',
        'action.google_drive',
        'action.gmail',
        'action.google_calendar',
        // Premium SMS / WhatsApp (paid per call)
        'action.twilio_sms',
        'action.ovh_sms',
        'action.whatsapp_twilio',
        'action.whatsapp_cloud',
        // Premium AI (paid per token)
        'ai.openai_chat',
        'ai.anthropic_chat',
        'ai.mistral_chat',
        'ai.gemini_chat',
        // Premium Stripe / Shopify webhook triggers
        'trigger.stripe_webhook',
        'trigger.shopify_webhook',
    ];

    public function __construct(private readonly TierGate $gate)
    {
    }

    /**
     * Audit a workflow definition for tier-gated connectors. The
     * result is shaped for direct JSON serialisation in API responses.
     *
     * @param array<string, mixed> $definition Knot workflow definition (`{schemaVersion, nodes, edges, ...}`)
     * @return array{
     *     blocked: bool,
     *     missing: array<int, array{nodeId: string, connectorId: string, tier: string, reason: string}>
     * }
     */
    public function audit(array $definition): array
    {
        $missing = [];
        $nodes = is_array($definition['nodes'] ?? null) ? $definition['nodes'] : [];

        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            $type = (string) ($node['type'] ?? '');
            if ($type === '') {
                continue;
            }
            $tier = self::tierForNodeType($type);
            if ($tier === null) {
                continue;
            }
            if ($this->gate->canUseTier($tier)) {
                continue;
            }
            $missing[] = [
                'nodeId' => (string) ($node['id'] ?? ''),
                'connectorId' => $type,
                'tier' => $tier,
                'reason' => $tier === TierGate::TIER_PRO
                    ? 'requires_pro_pack'
                    : 'requires_enterprise',
            ];
        }

        return [
            'blocked' => $missing !== [],
            'missing' => $missing,
        ];
    }

    /**
     * @return string|null Tier slug (`pro` / `enterprise`) or `null` if
     *                     the connector is free (Knot Core).
     */
    private static function tierForNodeType(string $type): ?string
    {
        if (in_array($type, self::PRO_NODE_TYPES, true)) {
            return TierGate::TIER_PRO;
        }

        return null;
    }
}
