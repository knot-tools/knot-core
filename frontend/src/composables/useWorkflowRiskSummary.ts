/**
 * Aggregates workflow-level risk from canvas nodes + connector catalog.
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 */

import { computed, type ComputedRef, type Ref } from 'vue';
import type { Node } from '@vue-flow/core';
import type { ConnectorDescriptor } from '../lib/api';
import {
  aggregateWorkflowRisk,
  resolveNodeRisk,
  type ConnectorRiskMetadata,
  type RiskLevel,
  type SideEffect,
} from './useNodeRisk';

export interface CriticalNodeSummary {
  nodeId: string;
  nodeType: string;
  nodeLabel: string;
  riskReason: string;
  riskLevel: RiskLevel;
  sideEffects: SideEffect[];
}

export interface WorkflowRiskSummary {
  worstLevel: RiskLevel;
  hasCritical: boolean;
  criticalNodes: CriticalNodeSummary[];
  sideEffects: SideEffect[];
  /** vue-i18n path (see `risk.summary.*` locale messages). */
  summaryKey: string;
  /** Interpolation params for {@link summaryKey}. */
  summaryParams: Record<string, string | number>;
}

function connectorMeta(descriptor: ConnectorDescriptor | undefined): ConnectorRiskMetadata | null {
  if (!descriptor) return null;
  const m = descriptor.metadata;
  return {
    id: String(m.id ?? ''),
    riskLevel: m.riskLevel as RiskLevel | undefined,
    reversible: m.reversible as boolean | undefined,
    sideEffects: m.sideEffects as SideEffect[] | undefined,
    riskByConfig: m.riskByConfig as ConnectorRiskMetadata['riskByConfig'],
    riskFieldKey: m.riskFieldKey as string | undefined,
  };
}

/**
 * Build a workflow risk summary from Vue Flow nodes and the connector catalog.
 */
export function buildWorkflowRiskSummary(
  nodes: Array<{ id: string; data?: { type?: string; label?: string; config?: Record<string, unknown> } }>,
  connectorsById: Map<string, ConnectorDescriptor>,
): WorkflowRiskSummary {
  const criticalNodes: CriticalNodeSummary[] = [];
  const sideSet = new Set<SideEffect>();
  const perNodeLevels: Array<{ riskLevel?: RiskLevel }> = [];

  for (const node of nodes) {
    const nodeType = String(node.data?.type ?? '');
    const config = (node.data?.config ?? {}) as Record<string, unknown>;
    const descriptor = connectorsById.get(nodeType);
    const meta = connectorMeta(descriptor);
    const resolved = resolveNodeRisk({ config, metadata: meta });
    perNodeLevels.push({ riskLevel: resolved.riskLevel });
    for (const eff of resolved.sideEffects) {
      sideSet.add(eff);
    }
    if (resolved.riskLevel === 'critical') {
      const op = String(config.operation ?? config.mode ?? '');
      criticalNodes.push({
        nodeId: node.id,
        nodeType,
        nodeLabel: String(node.data?.label ?? nodeType),
        riskReason: op !== '' ? `operation:${op}` : nodeType,
        riskLevel: resolved.riskLevel,
        sideEffects: resolved.sideEffects,
      });
    }
  }

  const worstLevel = aggregateWorkflowRisk(perNodeLevels);
  const sideEffects = [...sideSet];
  const hasCritical = criticalNodes.length > 0 || worstLevel === 'critical';

  let summaryKey: string;
  let summaryParams: Record<string, string | number>;
  if (hasCritical && criticalNodes.length === 1) {
    summaryKey = 'risk.summary.critical_single';
    summaryParams = { label: criticalNodes[0].nodeLabel };
  } else if (hasCritical && criticalNodes.length !== 1) {
    summaryKey = 'risk.summary.critical_many';
    summaryParams = { count: criticalNodes.length };
  } else if (worstLevel === 'caution') {
    summaryKey = 'risk.summary.caution_writes';
    summaryParams = {};
  } else {
    summaryKey = 'risk.summary.no_critical';
    summaryParams = {};
  }

  return {
    worstLevel,
    hasCritical,
    criticalNodes,
    sideEffects,
    summaryKey,
    summaryParams,
  };
}

export function useWorkflowRiskSummary(
  nodes: Ref<Node[]>,
  connectors: Ref<ConnectorDescriptor[]>,
): ComputedRef<WorkflowRiskSummary> {
  return computed(() => {
    const map = new Map<string, ConnectorDescriptor>();
    for (const c of connectors.value) {
      map.set(String(c.metadata.id), c);
    }
    return buildWorkflowRiskSummary(nodes.value, map);
  });
}
