/**
 * Derive executed edges and branch dimming from simulation / execution logs.
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 */

export interface SimLogEntry {
  nodeId?: string;
  nodeType?: string;
  status?: string;
  output?: unknown;
  sequenceOrder?: number;
}

export interface ExecutionPathEdge {
  id: string;
  source: string;
  target: string;
  sourceHandle?: string | null;
}

export interface ExecutionVisualization {
  executedEdgeIds: Set<string>;
  statusByNode: Record<string, string>;
  branchDimmedByNode: Record<string, boolean>;
  dimmedHandlesByNode: Record<string, string[]>;
}

const BRANCH_OUTPUTS: Record<string, string[]> = {
  'logic.if': ['true', 'false'],
  'logic.switch': ['case_1', 'case_2', 'case_3', 'default'],
};

function sortLogs(logs: SimLogEntry[]): SimLogEntry[] {
  return [...logs].sort((a, b) => {
    const ao = typeof a.sequenceOrder === 'number' ? a.sequenceOrder : 0;
    const bo = typeof b.sequenceOrder === 'number' ? b.sequenceOrder : 0;
    return ao - bo;
  });
}

/**
 * Resolve the source handle taken leaving a node (branch-aware connectors).
 */
export function takenSourceHandle(log: SimLogEntry): string | null {
  const output = log.output;
  if (output === null || output === undefined || typeof output !== 'object' || Array.isArray(output)) {
    return 'main';
  }
  const out = output as Record<string, unknown>;
  if (typeof out.branch === 'string' && out.branch !== '') {
    return out.branch;
  }
  const filter = out._filter;
  if (filter !== null && filter !== undefined && typeof filter === 'object' && !Array.isArray(filter)) {
    const passed = (filter as { passed?: boolean }).passed;
    return passed ? 'main' : null;
  }
  return 'main';
}

function findExecutedEdge(
  edges: ExecutionPathEdge[],
  sourceId: string,
  targetId: string,
  preferredHandle: string | null,
): ExecutionPathEdge | undefined {
  if (preferredHandle) {
    const exact = edges.find(
      (e) =>
        e.source === sourceId
        && e.target === targetId
        && (e.sourceHandle ?? 'main') === preferredHandle,
    );
    if (exact) return exact;
  }
  return edges.find((e) => e.source === sourceId && e.target === targetId);
}

/**
 * Compute executed edge ids and per-node execution visuals from engine logs.
 */
export function computeExecutionVisualization(
  logs: SimLogEntry[],
  edges: ExecutionPathEdge[],
): ExecutionVisualization {
  const executedEdgeIds = new Set<string>();
  const statusByNode: Record<string, string> = {};
  const branchDimmedByNode: Record<string, boolean> = {};
  const dimmedHandlesByNode: Record<string, string[]> = {};

  const sorted = sortLogs(logs);
  for (const log of sorted) {
    const id = log.nodeId;
    const status = log.status;
    if (!id || !status) continue;
    statusByNode[id] = status;
    if (status === 'skipped') {
      branchDimmedByNode[id] = true;
    }
  }

  const executedSequence = sorted.filter(
    (log) => log.nodeId && log.status !== 'skipped',
  );

  for (let i = 1; i < executedSequence.length; i += 1) {
    const prev = executedSequence[i - 1];
    const curr = executedSequence[i];
    const prevId = prev.nodeId!;
    const currId = curr.nodeId!;
    const handle = takenSourceHandle(prev);
    if (handle === null) continue;
    const match = findExecutedEdge(edges, prevId, currId, handle);
    if (match?.id) executedEdgeIds.add(match.id);
  }

  for (const log of executedSequence) {
    const nodeType = log.nodeType ?? '';
    const outputs = BRANCH_OUTPUTS[nodeType];
    if (!outputs || !log.nodeId) continue;
    const taken = takenSourceHandle(log);
    if (!taken) continue;
    const dimmed = outputs.filter((h) => h !== taken);
    if (dimmed.length > 0) {
      dimmedHandlesByNode[log.nodeId] = dimmed;
    }
  }

  return {
    executedEdgeIds,
    statusByNode,
    branchDimmedByNode,
    dimmedHandlesByNode,
  };
}
