/**
 * Thin re-export seam for progressive EditorView / api.ts split (C4-5).
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 *
 * Keeps workflow CRUD calls in one import surface so EditorView can shrink
 * without a big-bang rewrite. Prefer this over importing `knotApi` ad hoc
 * for editor-only operations.
 */

import { knotApi, type WorkflowDefinition } from '../lib/api';

export function useEditorWorkflowApi() {
  return {
    getWorkflow: knotApi.getWorkflow.bind(knotApi),
    saveWorkflow: knotApi.saveWorkflow.bind(knotApi),
    saveSchedule: knotApi.saveSchedule.bind(knotApi),
    simulateWorkflow: knotApi.simulateWorkflow.bind(knotApi),
    executeWorkflow: knotApi.executeWorkflow.bind(knotApi),
    listWorkflowVersions: knotApi.listWorkflowVersions.bind(knotApi),
    rollbackWorkflow: knotApi.rollbackWorkflow.bind(knotApi),
    lintWorkflowDefinition: knotApi.lintWorkflowDefinition.bind(knotApi),
    health: knotApi.health.bind(knotApi),
    connectors: knotApi.connectors.bind(knotApi),
  };
}

export type { WorkflowDefinition };
