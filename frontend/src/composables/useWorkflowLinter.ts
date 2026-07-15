/**
 * Debounced server lint (api/workflows.php action=lint) + merge with local checks.
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 */

import { knotApi, type WorkflowDefinition } from '../lib/api';
import type { KnotEdgeLike, KnotNodeLike, ValidationIssue } from '../lib/validator';
import { validateWorkflow } from '../lib/validator';

export interface WorkflowLintCounts {
  valid: boolean;
  errorCount: number;
  warningCount: number;
}

/** Normalize DSL codes so PHP `KNOT_DSL_*` and TS short codes collapse. */
function lintDedupeKey(issue: ValidationIssue): string {
  const raw = String(issue.code ?? '');
  const code = raw.startsWith('KNOT_DSL_')
    ? raw.slice('KNOT_DSL_'.length).toLowerCase()
    : raw.toLowerCase();
  const params = issue.messageParams
    ? JSON.stringify(issue.messageParams)
    : '';
  return `${issue.nodeId ?? ''}|${code}|${issue.messageKey ?? ''}|${params}`;
}

export function mergeLocalAndRemoteLint(
  remote: ValidationIssue[],
  local: ValidationIssue[],
): ValidationIssue[] {
  const seen = new Set<string>();
  const out: ValidationIssue[] = [];
  for (const issue of [...remote, ...local]) {
    const key = lintDedupeKey(issue);
    if (seen.has(key)) {
      continue;
    }
    seen.add(key);
    out.push(issue);
  }
  return out;
}

export function createWorkflowLinter(debounceMs = 300) {
  let timer: ReturnType<typeof setTimeout> | undefined;
  let generation = 0;

  return {
    schedule(
      getDefinition: () => WorkflowDefinition,
      getLocalGraph: () => { nodes: KnotNodeLike[]; edges: KnotEdgeLike[] },
      onMerged: (issues: ValidationIssue[]) => void,
    ): void {
      if (timer) {
        clearTimeout(timer);
      }
      const gen = ++generation;
      timer = setTimeout(() => {
        void (async () => {
          const { nodes, edges } = getLocalGraph();
          const local = validateWorkflow(nodes, edges);
          let remote: ValidationIssue[] = [];
          try {
            const data = await knotApi.lintWorkflowDefinition(getDefinition());
            if (gen !== generation) {
              return;
            }
            remote = (data.issues ?? []) as ValidationIssue[];
          } catch {
            if (gen !== generation) {
              return;
            }
            remote = [];
          }
          if (gen !== generation) {
            return;
          }
          onMerged(mergeLocalAndRemoteLint(remote, local));
        })();
      }, debounceMs);
    },
  };
}
