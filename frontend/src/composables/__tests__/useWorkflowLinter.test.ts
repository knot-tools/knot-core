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
});
