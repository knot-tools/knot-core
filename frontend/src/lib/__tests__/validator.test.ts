/**
 * Vitest suite for the live workflow validator (local-only checks).
 *
 * Structural graph rules (`missing_trigger`, edges, reachability, duplicate ids)
 * are enforced server-side (`WorkflowValidator` via `action=lint`); see
 * `tests/Engine/WorkflowValidatorTest.php`.
 */

import { describe, expect, it } from 'vitest';
import {
  hasCriticalErrors,
  validateWorkflow,
  type KnotNodeLike,
} from '../validator';

const trigger: KnotNodeLike = {
  id: 'trig',
  data: { type: 'trigger.manual', label: 'Manual' },
};

const setNode: KnotNodeLike = {
  id: 'set1',
  data: { type: 'logic.set', label: 'Set' },
};

describe('validateWorkflow', () => {
  it('flags an empty graph with no_nodes', () => {
    const issues = validateWorkflow([], []);
    expect(issues).toHaveLength(1);
    expect(issues[0].code).toBe('no_nodes');
    expect(issues[0].severity).toBe('error');
  });

  it('does not emit structural DSL codes locally (handled by server lint)', () => {
    const issues = validateWorkflow(
      [setNode],
      [{ id: 'e', source: 'trig', target: 'ghost' }],
    );
    const codes = issues.map((i) => i.code);
    expect(codes).not.toContain('missing_trigger');
    expect(codes).not.toContain('duplicate_node_id');
    expect(codes).not.toContain('edge_target_missing');
    expect(codes).not.toContain('unreachable_node');
  });

  it('warns on multiple triggers', () => {
    const issues = validateWorkflow([trigger, { ...trigger, id: 'trig2' }], []);
    expect(issues.some((i) => i.code === 'multiple_triggers')).toBe(true);
  });

  it('flags http node without url', () => {
    const issues = validateWorkflow(
      [trigger, { id: 'h', data: { type: 'action.http', label: 'HTTP' } }],
      [{ source: 'trig', target: 'h' }],
    );
    expect(issues.some((i) => i.code === 'http_url_missing')).toBe(true);
  });

  it('warns on cron trigger without expression', () => {
    const issues = validateWorkflow(
      [{ id: 'c', data: { type: 'trigger.cron', label: 'Cron', config: {} } }],
      [],
    );
    expect(issues.some((i) => i.code === 'cron_expression_missing')).toBe(true);
  });

  it('warns on loop without itemsPath', () => {
    const issues = validateWorkflow(
      [trigger, { id: 'lo', data: { type: 'logic.loop', label: 'Loop', config: {} } }],
      [{ source: 'trig', target: 'lo' }],
    );
    expect(issues.some((i) => i.code === 'loop_items_missing')).toBe(true);
  });

  it('warns on sql_query with common propale table typo', () => {
    const issues = validateWorkflow(
      [
        trigger,
        {
          id: 'sql1',
          data: {
            type: 'dolibarr.sql_query',
            label: 'SQL',
            config: { query: 'SELECT ref FROM llx_propale' },
          },
        },
      ],
      [{ source: 'trig', target: 'sql1' }],
    );
    expect(issues.some((i) => i.code === 'sql_unknown_table_hint')).toBe(true);
  });

  it('hints objectType alias invoice -> facture', () => {
    const issues = validateWorkflow(
      [
        trigger,
        {
          id: 'read1',
          data: {
            type: 'dolibarr.read_object',
            label: 'Read',
            config: { objectType: 'invoice', objectId: 1 },
          },
        },
      ],
      [{ source: 'trig', target: 'read1' }],
    );
    expect(issues.some((i) => i.code === 'object_type_alias_hint')).toBe(true);
  });

  it('warns when $json field follows non-trigger upstream', () => {
    const issues = validateWorkflow(
      [
        trigger,
        { id: 'read1', data: { type: 'dolibarr.read_object', config: { objectType: 'facture', objectId: 1 } } },
        { id: 'sql1', data: { type: 'dolibarr.sql_query', config: { query: 'SELECT 1 WHERE x = {{$json.total_ttc}}' } } },
      ],
      [
        { source: 'trig', target: 'read1' },
        { source: 'read1', target: 'sql1' },
      ],
    );
    expect(issues.some((i) => i.code === 'expression_json_chain')).toBe(true);
  });

  it('returns no issues for a happy-path workflow', () => {
    const issues = validateWorkflow(
      [
        trigger,
        {
          id: 'http',
          data: { type: 'action.http', label: 'HTTP', config: { url: 'https://api.example' } },
        },
      ],
      [{ source: 'trig', target: 'http' }],
    );
    const errors = issues.filter((i) => i.severity === 'error');
    expect(errors).toEqual([]);
  });
});

describe('hasCriticalErrors', () => {
  it('returns true when at least one error exists', () => {
    expect(
      hasCriticalErrors([
        { severity: 'warning', code: 'x', message: 'x' },
        { severity: 'error', code: 'y', message: 'y' },
      ]),
    ).toBe(true);
  });

  it('returns false on warnings only', () => {
    expect(hasCriticalErrors([{ severity: 'warning', code: 'x', message: 'x' }])).toBe(false);
  });
});
