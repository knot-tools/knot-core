import { describe, expect, it } from 'vitest';
import { mergeLocalAndRemoteLint } from '../useWorkflowLinter';
import type { ValidationIssue } from '../../lib/validator';

describe('mergeLocalAndRemoteLint', () => {
  it('concatenates remote then local issues', () => {
    const remote: ValidationIssue[] = [
      { severity: 'warning', code: 'KNOT_DSL_GRAPH_CYCLE', messageKey: 'graph_cycle' },
    ];
    const local: ValidationIssue[] = [
      { severity: 'error', code: 'http_url_missing', message: 'URL', nodeId: 'n1' },
    ];
    expect(mergeLocalAndRemoteLint(remote, local)).toEqual([...remote, ...local]);
  });

  it('dedupes PHP KNOT_DSL_* with matching local short codes', () => {
    const remote: ValidationIssue[] = [
      {
        severity: 'warning',
        code: 'KNOT_DSL_EXPRESSION_JSON_CHAIN',
        messageKey: 'expression_json_chain',
        nodeId: 'js',
        messageParams: { field: 'raw', upstreamId: 'raw' },
      },
    ];
    const local: ValidationIssue[] = [
      {
        severity: 'warning',
        code: 'expression_json_chain',
        messageKey: 'expression_json_chain',
        nodeId: 'js',
        messageParams: { field: 'raw', upstreamId: 'raw' },
      },
    ];
    expect(mergeLocalAndRemoteLint(remote, local)).toHaveLength(1);
  });
});
