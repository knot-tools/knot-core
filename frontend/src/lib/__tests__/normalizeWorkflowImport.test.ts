import { describe, expect, it } from 'vitest';
import {
  normalizeDefinition,
  normalizeWorkflowImport,
  parseWorkflowImportText,
  WorkflowImportFormatError,
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

  it('throws format error for unrecognized JSON shape', () => {
    expect(() => normalizeWorkflowImport({ foo: 'bar' }, { label: 'Fallback' })).toThrow(
      WorkflowImportFormatError,
    );
  });
});
