/**
 * Knot — resolveConnectorSchema unit tests.
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 */
import { describe, expect, it } from 'vitest';

import type { ConnectorDescriptor } from '@/lib/api';
import {
  buildConnectorSchemaIndex,
  canonicalConnectorId,
  LEGACY_NODE_TYPE_TO_CONNECTOR_ID,
  resolveConnectorConfigSchema,
} from '@/lib/resolveConnectorSchema';

function descriptor(
  id: string,
  schema: Record<string, unknown> | null,
  overrides: Partial<ConnectorDescriptor> = {},
): ConnectorDescriptor {
  return {
    metadata: { id, label: id, category: 'logic' },
    configSchema: schema,
    credentialType: null,
    credentialSchema: null,
    inputs: [],
    outputs: [],
    ...overrides,
  };
}

describe('resolveConnectorSchema', () => {
  it('maps legacy node types to canonical connector ids', () => {
    expect(canonicalConnectorId('trigger.dolibarr.event')).toBe('trigger.dolibarr_event');
    expect(canonicalConnectorId('dolibarr.event')).toBe('trigger.dolibarr_event');
    expect(canonicalConnectorId('loop')).toBe('logic.loop');
    expect(canonicalConnectorId('workflow.execute')).toBe('logic.execute_workflow');
    expect(canonicalConnectorId('http')).toBe('action.http');
    expect(canonicalConnectorId('logic.if')).toBe('logic.if');
    expect(Object.keys(LEGACY_NODE_TYPE_TO_CONNECTOR_ID).length).toBeGreaterThan(0);
  });

  it('resolves schema from index using canonical id', () => {
    const schema = { type: 'object', properties: { mode: { type: 'string' } } };
    const idx = buildConnectorSchemaIndex([descriptor('logic.if', schema)]);
    expect(resolveConnectorConfigSchema('logic.if', idx)).toEqual(schema);
  });

  it('resolves schema via legacy alias', () => {
    const schema = { type: 'object', properties: { cronExpression: { type: 'string' } } };
    const idx = buildConnectorSchemaIndex([descriptor('trigger.dolibarr_event', schema)]);
    expect(resolveConnectorConfigSchema('trigger.dolibarr.event', idx)).toEqual(schema);
  });

  it('returns null for unknown connector id', () => {
    const idx = buildConnectorSchemaIndex([descriptor('logic.if', { type: 'object', properties: {} })]);
    expect(resolveConnectorConfigSchema('unknown.node', idx)).toBeNull();
  });

  it('returns null when connector exists but configSchema is null', () => {
    const idx = buildConnectorSchemaIndex([descriptor('action.stripe', null)]);
    expect(resolveConnectorConfigSchema('action.stripe', idx)).toBeNull();
  });

  it('skips descriptors without metadata.id when building index', () => {
    const broken = {
      metadata: { label: 'x', category: 'logic' as const },
      configSchema: { type: 'object' },
      credentialType: null,
      credentialSchema: null,
      inputs: [],
      outputs: [],
    } as unknown as ConnectorDescriptor;
    const idx = buildConnectorSchemaIndex([broken]);
    expect(idx.size).toBe(0);
  });
});
