/**
 * Map workflow node `data.type` values to connector ids and resolve API configSchema.
 * Keeps canonical ids aligned with NodeInspectorBody panel routing.
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 */

import type { ConnectorDescriptor } from '@/lib/api';

/** Legacy or shorthand node types stored in older workflows → connector metadata.id */
export const LEGACY_NODE_TYPE_TO_CONNECTOR_ID: Readonly<Record<string, string>> = {
  'trigger.dolibarr.event': 'trigger.dolibarr_event',
  'dolibarr.event': 'trigger.dolibarr_event',
  loop: 'logic.loop',
  'workflow.execute': 'logic.execute_workflow',
  http: 'action.http',
  'communication.slack': 'action.slack',
  'communication.discord': 'action.discord',
  'communication.email': 'action.email',
  'ai.openai_chat': 'action.ai_openai',
};

export function canonicalConnectorId(nodeType: string): string {
  const t = nodeType.trim();
  if (!t) return t;
  return LEGACY_NODE_TYPE_TO_CONNECTOR_ID[t] ?? t;
}

export function buildConnectorSchemaIndex(
  connectors: readonly ConnectorDescriptor[],
): Map<string, Record<string, unknown> | null> {
  const m = new Map<string, Record<string, unknown> | null>();
  for (const c of connectors) {
    const id = c.metadata?.id;
    if (typeof id === 'string' && id.length > 0) {
      m.set(id, c.configSchema);
    }
  }
  return m;
}

export function resolveConnectorConfigSchema(
  nodeType: string,
  schemaByConnectorId: ReadonlyMap<string, Record<string, unknown> | null>,
): Record<string, unknown> | null {
  const id = canonicalConnectorId(nodeType);
  if (!id) return null;
  if (!schemaByConnectorId.has(id)) return null;
  return schemaByConnectorId.get(id) ?? null;
}
