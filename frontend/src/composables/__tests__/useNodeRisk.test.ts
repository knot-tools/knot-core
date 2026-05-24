/**
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest';
import {
  aggregateWorkflowRisk,
  minimapRiskHex,
  resolveNodeRisk,
} from '../useNodeRisk';

describe('resolveNodeRisk', () => {
  it('defaults to safe when no metadata is provided', () => {
    const r = resolveNodeRisk({ config: {}, metadata: null });
    expect(r.riskLevel).toBe('safe');
    expect(r.borderToken).toBe('border');
    expect(r.iconName).toBeNull();
    expect(r.badgeLabel).toBeNull();
    expect(r.hasMissingPermission).toBe(false);
  });

  it('honours metadata.riskLevel for static connectors', () => {
    const r = resolveNodeRisk({
      config: {},
      metadata: { id: 'http.request', riskLevel: 'caution', sideEffects: ['http'] },
    });
    expect(r.riskLevel).toBe('caution');
    expect(r.borderToken).toBe('warning');
    expect(r.iconName).toBe('alert-triangle');
    expect(r.tintToken).toBe('warning-soft');
  });

  it('escalates to critical via riskByConfig for polymorphic connectors', () => {
    const meta = {
      id: 'dolibarr.object',
      riskLevel: 'caution' as const,
      riskByConfig: { read: 'safe', create: 'caution', delete: 'critical' } as const,
    };
    expect(resolveNodeRisk({ config: { operation: 'read' }, metadata: meta }).riskLevel).toBe(
      'caution', // metadata.riskLevel wins because pickHighest is upper bound
    );
    expect(resolveNodeRisk({ config: { operation: 'delete' }, metadata: meta }).riskLevel).toBe(
      'critical',
    );
  });

  it('escalates to caution for Dolibarr discovery_unverified registry mode', () => {
    const r = resolveNodeRisk({
      config: { objectRegistryMode: 'discovery_unverified', operation: 'fetch' },
      metadata: { id: 'dolibarr.object', riskLevel: 'safe' },
    });
    expect(r.riskLevel).toBe('caution');
  });

  it('respects an explicit resolvedRiskLevel override', () => {
    const r = resolveNodeRisk({
      config: {},
      metadata: { id: 'x', riskLevel: 'safe' },
      resolvedRiskLevel: 'critical',
    });
    expect(r.riskLevel).toBe('critical');
    expect(r.badgeLabel).toBe('critical');
  });

  it('flags missing permissions when user lacks one', () => {
    const r = resolveNodeRisk({
      config: {},
      metadata: { id: 'x', riskLevel: 'caution' },
      requiredPermissions: ['facture.creer', 'facture.valider'],
      userPermissions: ['facture.creer'],
    });
    expect(r.hasMissingPermission).toBe(true);
    expect(r.missingPermissions).toEqual(['facture.valider']);
  });

  it('returns no missing perms when all granted', () => {
    const r = resolveNodeRisk({
      config: {},
      metadata: { id: 'x', riskLevel: 'caution' },
      requiredPermissions: ['p1'],
      userPermissions: ['p1', 'p2'],
    });
    expect(r.hasMissingPermission).toBe(false);
  });
});

describe('aggregateWorkflowRisk', () => {
  it('returns safe when workflow has only safe nodes', () => {
    expect(
      aggregateWorkflowRisk([{ riskLevel: 'safe' }, { riskLevel: 'safe' }]),
    ).toBe('safe');
  });

  it('escalates to caution when any node is caution', () => {
    expect(
      aggregateWorkflowRisk([
        { riskLevel: 'safe' },
        { riskLevel: 'caution' },
        { riskLevel: 'safe' },
      ]),
    ).toBe('caution');
  });

  it('escalates to critical when any node is critical', () => {
    expect(
      aggregateWorkflowRisk([
        { riskLevel: 'safe' },
        { riskLevel: 'caution' },
        { riskLevel: 'critical' },
      ]),
    ).toBe('critical');
  });

  it('treats missing riskLevel as safe', () => {
    expect(aggregateWorkflowRisk([{}, {}])).toBe('safe');
  });

  it('minimapRiskHex maps risk levels to list/minimap colours', () => {
    expect(minimapRiskHex('safe')).toBe('#64748b');
    expect(minimapRiskHex('caution')).toBe('#f59e0b');
    expect(minimapRiskHex('critical')).toBe('#ef4444');
  });
});
