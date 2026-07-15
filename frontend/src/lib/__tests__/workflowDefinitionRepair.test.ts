import { describe, expect, it } from 'vitest';

import {
  repairWorkflowDefinitionNodes,
  repairEdges,
  deduplicateNodeIds,
  ensureNodeDefaults,
  repairWorkflowDefinition,
  type RepairEntry,
} from '../workflowDefinitionRepair';

describe('workflowDefinitionRepair', () => {
  it('repairs if operator, sql key, objectType alias, and email body escapes', () => {
    const nodes = repairWorkflowDefinitionNodes([
      {
        id: 'read1',
        type: 'dolibarr.read_object',
        config: { objectType: 'invoice', objectId: '{{$json.objectId}}' },
      },
      {
        id: 'sql1',
        type: 'dolibarr.sql_query',
        config: { sql: 'SELECT 1' },
      },
      {
        id: 'if1',
        type: 'logic.if',
        config: {
          conditions: [{ left: '{{$json.amount}}', operator: '>=', right: '500' }],
        },
      },
      {
        id: 'mail1',
        type: 'action.email',
        config: { to: 'a@b.test', subject: 'S', body: 'Hi\\n\\nIBAN: {{$json.rows[0].iban}}' },
      },
    ]);

    expect(nodes[0]).toMatchObject({ config: { objectType: 'facture' } });
    expect(nodes[1]).toMatchObject({ config: { query: 'SELECT 1' } });
    expect((nodes[1] as { config: Record<string, unknown> }).config.sql).toBeUndefined();
    expect(nodes[2]).toMatchObject({
      config: { conditions: [{ operator: 'greater_equal' }] },
    });
    const body = (nodes[3] as { config: { body: string } }).config.body;
    expect(body).toContain('\n\n');
    expect(body).not.toContain('\\n');
  });
});

describe('repairEdges', () => {
  it('converts from/to to source/target', () => {
    const repairs: RepairEntry[] = [];
    const edges = repairEdges(
      [{ id: 'e1', from: 'a', to: 'b', sourceHandle: 'main', targetHandle: 'main', type: 'knot' }],
      repairs,
    );
    expect(edges[0]).toMatchObject({ source: 'a', target: 'b' });
    expect((edges[0] as Record<string, unknown>).from).toBeUndefined();
    expect((edges[0] as Record<string, unknown>).to).toBeUndefined();
    expect(repairs.some((r) => r.type === 'edge_from_to_source_target')).toBe(true);
  });

  it('defaults missing edge fields', () => {
    const repairs: RepairEntry[] = [];
    const edges = repairEdges(
      [{ source: 'a', target: 'b' }],
      repairs,
    );
    const edge = edges[0] as Record<string, unknown>;
    expect(edge.id).toBeTruthy();
    expect(edge.sourceHandle).toBe('main');
    expect(edge.targetHandle).toBe('main');
    expect(edge.type).toBe('knot');
    expect(repairs.length).toBeGreaterThanOrEqual(3);
  });
});

describe('deduplicateNodeIds', () => {
  it('renames duplicate node ids', () => {
    const repairs: RepairEntry[] = [];
    const { nodes } = deduplicateNodeIds(
      [
        { id: 'n1', type: 'logic.set', config: {} },
        { id: 'n1', type: 'logic.noop', config: {} },
        { id: 'n1', type: 'logic.delay', config: {} },
      ],
      [],
      repairs,
    );
    const ids = nodes.map((n) => (n as { id: string }).id);
    expect(new Set(ids).size).toBe(3);
    expect(ids[0]).toBe('n1');
    expect(ids[1]).toBe('n1_2');
    expect(ids[2]).toBe('n1_3');
    expect(repairs.filter((r) => r.type === 'node_duplicate_id')).toHaveLength(2);
  });
});

describe('ensureNodeDefaults', () => {
  it('adds missing label, position, config, credentials', () => {
    const repairs: RepairEntry[] = [];
    const nodes = ensureNodeDefaults(
      [{ id: 'x', type: 'trigger.manual' }],
      repairs,
    );
    const node = nodes[0] as Record<string, unknown>;
    expect(node.label).toBe('trigger.manual');
    expect(node.position).toEqual({ x: 0, y: 0 });
    expect(node.config).toEqual({});
    expect(node.credentials).toBeNull();
    expect(repairs.length).toBeGreaterThanOrEqual(2);
  });
});

describe('repairWorkflowDefinition (full pipeline)', () => {
  it('applies all repairs in one call', () => {
    const { nodes, edges, repairs } = repairWorkflowDefinition(
      [
        { id: 'a', type: 'trigger.manual' },
        { id: 'a', type: 'logic.set', config: { values: { x: 1 } } },
      ],
      [
        { from: 'a', to: 'a_2' },
      ],
    );

    expect(nodes).toHaveLength(2);
    const ids = nodes.map((n) => (n as { id: string }).id);
    expect(new Set(ids).size).toBe(2);

    expect(edges).toHaveLength(1);
    const e = edges[0] as Record<string, unknown>;
    expect(e.source).toBeTruthy();
    expect(e.target).toBeTruthy();
    expect(e.sourceHandle).toBe('main');
    expect(e.type).toBe('knot');

    expect(repairs.length).toBeGreaterThan(0);
  });
});
