import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';
import {
  normalizeDefinition,
  normalizeWorkflowImport,
  parseWorkflowImportText,
  extractRepairs,
  WorkflowImportFormatError,
  WorkflowImportLegacyStepsError,
} from '../normalizeWorkflowImport';

describe('normalizeWorkflowImport', () => {
  it('parses markdown fenced JSON', () => {
    const parsed = parseWorkflowImportText('```json\n{"nodes":[]}\n```');
    expect(parsed).toEqual({ nodes: [] });
  });

  it('normalizes knot export envelope', () => {
    const payload = normalizeWorkflowImport(
      {
        knotExport: '1.0',
        workflow: {
          label: 'Demo',
          definition: {
            nodes: [{ id: 'n1', type: 'trigger.manual' }],
            edges: [],
          },
        },
      },
      { label: 'Fallback' },
    );
    expect(payload.label).toBe('Demo');
    expect(payload.definition?.nodes).toHaveLength(1);
  });

  it('normalizes bare nodes/edges with sourceHandle', () => {
    const payload = normalizeWorkflowImport(
      {
        label: 'From assistant',
        nodes: [{ id: 'loop1', type: 'logic.loop' }],
        edges: [
          {
            id: 'e5',
            source: 'loop1',
            target: 'set2',
            sourceHandle: 'iteration',
            targetHandle: 'main',
          },
        ],
      },
      { label: 'Imported' },
    );
    expect(payload.definition?.edges[0]).toMatchObject({
      source: 'loop1',
      target: 'set2',
      sourceHandle: 'iteration',
      targetHandle: 'main',
    });
  });

  it('maps legacy from/to edge keys', () => {
    const def = normalizeDefinition({
      nodes: [],
      edges: [{ id: 'e1', from: 'a', to: 'b' }],
    });
    expect(def.edges[0]).toMatchObject({
      source: 'a',
      target: 'b',
      sourceHandle: 'main',
      targetHandle: 'main',
    });
  });

  it('throws legacy steps error for Zapier-like shape', () => {
    expect(() =>
      normalizeWorkflowImport(
        { trigger: { type: 'manual' }, steps: [{ type: 'email' }] },
        { label: 'Fallback' },
      ),
    ).toThrow(WorkflowImportLegacyStepsError);
  });

  it('throws format error for unrecognized JSON shape', () => {
    expect(() => normalizeWorkflowImport({ foo: 'bar' }, { label: 'Fallback' })).toThrow(
      WorkflowImportFormatError,
    );
  });

  it('imports starter 03 invoice email workflow', () => {
    const raw = readFileSync(
      resolve(__dirname, '../../../../examples/starter/03-facture-validee-email-bancaire.knot.json'),
      'utf8',
    );
    const parsed = parseWorkflowImportText(raw);
    const payload = normalizeWorkflowImport(parsed, { label: 'Imported starter 03' });

    expect(payload.label).toContain('Facture');
    const nodes = payload.definition?.nodes ?? [];
    const types = nodes.map((n) => n.type);
    expect(types).toContain('trigger.dolibarr_event');
    expect(types).toContain('action.email');
    const trigger = nodes.find((n) => n.type === 'trigger.dolibarr_event');
    expect(trigger?.config?.events).toContain('BILL_VALIDATE');
  });

  it('applies full repair pipeline and exposes repairs via extractRepairs', () => {
    const payload = normalizeWorkflowImport(
      {
        nodes: [
          { id: 'n1', type: 'trigger.manual' },
          { id: 'n1', type: 'logic.set' },
        ],
        edges: [
          { id: 'e1', source: 'n1', target: 'n1_2', sourceHandle: 'main', targetHandle: 'main' },
        ],
      },
      { label: 'Repair test' },
    );

    const repairs = extractRepairs(payload.definition);
    expect(repairs.length).toBeGreaterThan(0);
    const types = repairs.map((r) => r.type);
    expect(types).toContain('node_duplicate_id');
    expect(types).toContain('node_default_label');
  });

  it('extractRepairs returns empty for clean workflow', () => {
    const payload = normalizeWorkflowImport(
      {
        nodes: [
          { id: 'n1', type: 'trigger.manual', label: 'Start', position: { x: 0, y: 0 }, config: {}, credentials: null },
        ],
        edges: [],
      },
      { label: 'Clean' },
    );
    const repairs = extractRepairs(payload.definition);
    expect(repairs).toHaveLength(0);
  });
});
