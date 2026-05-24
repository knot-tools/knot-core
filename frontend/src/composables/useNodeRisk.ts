/**
 * useNodeRisk — risk-grammar resolver for canvas nodes.
 *
 * Returns a reactive computed of `riskLevel`, `tone`, `border`,
 * `iconName` and a `permissionsHint` for any Vue Flow node based on
 * the connector metadata + the inspector's current config.
 *
 * Reads from the connector definitions store (loaded from
 * `api/connectors.php`) and the live node config from the editor.
 *
 * Defaults are intentionally `safe` so connectors that have not yet
 * been annotated (V2.5 UX-2 sprint) keep rendering exactly as before.
 *
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 */

import { computed, type ComputedRef } from 'vue';

export type RiskLevel = 'safe' | 'caution' | 'critical';

export type SideEffect =
  | 'db'
  | 'mail'
  | 'http'
  | 'accounting'
  | 'fs'
  | 'external-paid';

export interface ConnectorRiskMetadata {
  id: string;
  riskLevel?: RiskLevel;
  reversible?: boolean;
  sideEffects?: SideEffect[];
  riskByConfig?: Partial<Record<string, RiskLevel>>;
  /** Config key to look up in riskByConfig. Defaults to 'operation'. */
  riskFieldKey?: string;
}

export interface NodeRiskInput {
  /** Live config of the node from the inspector. */
  config: Record<string, unknown>;
  /** Static metadata of the connector (from the catalog API). */
  metadata: ConnectorRiskMetadata | null;
  /** Optional override (when backend returns getRiskFor() result). */
  resolvedRiskLevel?: RiskLevel;
  /** Permissions required for the current config (from backend). */
  requiredPermissions?: string[];
  /** Permissions the current user is granted. */
  userPermissions?: string[];
}

export interface NodeRiskResult {
  riskLevel: RiskLevel;
  reversible: boolean;
  sideEffects: SideEffect[];
  /** Border colour token name (NOT a hex value — design system token). */
  borderToken: 'border' | 'warning' | 'danger';
  /** Background tint token applied subtly on the node body. */
  tintToken: null | 'warning-soft' | 'danger-soft';
  /** Lucide icon name to render at top-right; null when riskLevel = safe. */
  iconName: null | 'alert-triangle' | 'octagon-alert';
  /** Always-on label for critical (rendered uppercase, letter-spaced). */
  badgeLabel: null | 'critical';
  /** True when the current user is missing at least one required permission. */
  hasMissingPermission: boolean;
  /** Permissions the current user is missing for this node. */
  missingPermissions: string[];
}

const RISK_RANK: Record<RiskLevel, number> = {
  safe: 0,
  caution: 1,
  critical: 2,
};

/**
 * Pure function helper — exposed for unit tests.
 */
export function resolveNodeRisk(input: NodeRiskInput): NodeRiskResult {
  const metaLevel: RiskLevel = input.metadata?.riskLevel ?? 'safe';
  const configLevel = readConfigLevel(input.config, input.metadata);
  const expertLevel = readDolibarrExpertRegistryRisk(input.config);
  const mergedFromConfig =
    configLevel === null && expertLevel === null
      ? null
      : pickHighest(configLevel ?? 'safe', expertLevel ?? 'safe');
  const candidate: RiskLevel =
    input.resolvedRiskLevel ?? mergedFromConfig ?? metaLevel;

  const riskLevel = pickHighest(candidate, metaLevel);
  const sideEffects = input.metadata?.sideEffects ?? [];
  const reversible = input.metadata?.reversible ?? riskLevel === 'safe';

  const borderToken =
    riskLevel === 'critical'
      ? 'danger'
      : riskLevel === 'caution'
      ? 'warning'
      : 'border';
  const tintToken =
    riskLevel === 'critical'
      ? 'danger-soft'
      : riskLevel === 'caution'
      ? 'warning-soft'
      : null;
  const iconName =
    riskLevel === 'critical'
      ? 'octagon-alert'
      : riskLevel === 'caution'
      ? 'alert-triangle'
      : null;
  const badgeLabel = riskLevel === 'critical' ? 'critical' : null;

  const required = input.requiredPermissions ?? [];
  const granted = new Set(input.userPermissions ?? []);
  const missingPermissions = required.filter((p) => !granted.has(p));

  return {
    riskLevel,
    reversible,
    sideEffects,
    borderToken,
    tintToken,
    iconName,
    badgeLabel,
    hasMissingPermission: missingPermissions.length > 0,
    missingPermissions,
  };
}

/**
 * Composable wrapper for use inside Vue components.
 */
export function useNodeRisk(
  input: () => NodeRiskInput,
): ComputedRef<NodeRiskResult> {
  return computed(() => resolveNodeRisk(input()));
}

/**
 * Aggregate risk for a whole workflow (worst case wins). Used by
 * the workflow list, the activation modal, and the minimap.
 */
export function aggregateWorkflowRisk(
  nodes: Array<{ riskLevel?: RiskLevel }>,
): RiskLevel {
  let worst: RiskLevel = 'safe';
  for (const node of nodes) {
    const lvl = node.riskLevel ?? 'safe';
    if (RISK_RANK[lvl] > RISK_RANK[worst]) {
      worst = lvl;
    }
  }
  return worst;
}

/** Mini-map / list dot colours aligned with risk-grammar.md §2. */
export function minimapRiskHex(level: RiskLevel): string {
  switch (level) {
    case 'critical':
      return '#ef4444';
    case 'caution':
      return '#f59e0b';
    default:
      return '#64748b';
  }
}

function pickHighest(a: RiskLevel, b: RiskLevel): RiskLevel {
  return RISK_RANK[a] >= RISK_RANK[b] ? a : b;
}

function readConfigLevel(
  config: Record<string, unknown>,
  metadata: ConnectorRiskMetadata | null,
): RiskLevel | null {
  if (!metadata?.riskByConfig) return null;
  const key = metadata.riskFieldKey ?? 'operation';
  const value = String(config[key] ?? '').toLowerCase();
  if (!value) return null;
  return metadata.riskByConfig[value] ?? null;
}

function readDolibarrExpertRegistryRisk(config: Record<string, unknown>): RiskLevel | null {
  if (String(config.objectRegistryMode ?? '') === 'discovery_unverified') {
    return 'caution';
  }
  return null;
}
