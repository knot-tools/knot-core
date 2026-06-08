import { describe, expect, it } from 'vitest';
import { computeExecutionVisualization, takenSourceHandle } from '../executionPath';

describe('executionPath', () => {
  it('picks branch handle for logic.if output', () => {
    expect(
      takenSourceHandle({
        output: { branch: 'true', result: true },
      }),
    ).toBe('true');
  });

  it('marks executed edge matching branch handle', () => {
    const logs = [
      { nodeId: 't1', nodeType: 'trigger.manual', status: 'success', sequenceOrder: 0, output: {} },
      { nodeId: 'if1', nodeType: 'logic.if', status: 'success', sequenceOrder: 1, output: { branch: 'true' } },
      { nodeId: 'n2', nodeType: 'action.email', status: 'success', sequenceOrder: 2, output: {} },
    ];
    const edges = [
      { id: 'e1', source: 't1', target: 'if1', sourceHandle: 'main' },
      { id: 'e2', source: 'if1', target: 'n2', sourceHandle: 'true' },
      { id: 'e3', source: 'if1', target: 'n3', sourceHandle: 'false' },
    ];
    const viz = computeExecutionVisualization(logs, edges);
    expect(viz.executedEdgeIds.has('e1')).toBe(true);
    expect(viz.executedEdgeIds.has('e2')).toBe(true);
    expect(viz.executedEdgeIds.has('e3')).toBe(false);
    expect(viz.dimmedHandlesByNode.if1).toEqual(['false']);
  });

  it('dims skipped branch nodes', () => {
    const logs = [
      { nodeId: 'if1', nodeType: 'logic.if', status: 'success', sequenceOrder: 0, output: { branch: 'false' } },
      { nodeId: 'n3', nodeType: 'action.email', status: 'skipped', sequenceOrder: 1, output: {} },
    ];
    const edges = [{ id: 'e3', source: 'if1', target: 'n3', sourceHandle: 'false' }];
    const viz = computeExecutionVisualization(logs, edges);
    expect(viz.branchDimmedByNode.n3).toBe(true);
    expect(viz.dimmedHandlesByNode.if1).toEqual(['true']);
  });
});
