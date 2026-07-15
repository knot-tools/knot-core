/**
 * Normalizes assistant / file-import JSON into a WorkflowSavePayload shape.
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 */

import type { WorkflowDefinition, WorkflowSavePayload } from './api';
import {
  repairWorkflowDefinition,
  type RepairEntry,
} from './workflowDefinitionRepair';

export type { RepairEntry } from './workflowDefinitionRepair';

export type WorkflowImportStatus = 'draft' | 'active' | 'disabled' | 'archived' | 'error';

export interface WorkflowImportDefaults {
  label: string;
  status?: WorkflowImportStatus;
}

export class WorkflowImportFormatError extends Error {
  constructor() {
    super('WorkflowImportFormatError');
    this.name = 'WorkflowImportFormatError';
  }
}

/** Zapier/Make-style { trigger, steps } payloads are not Knot import format. */
export class WorkflowImportLegacyStepsError extends Error {
  constructor() {
    super('WorkflowImportLegacyStepsError');
    this.name = 'WorkflowImportLegacyStepsError';
  }
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function normalizeEdge(edge: unknown): Record<string, unknown> {
  if (!isRecord(edge)) {
    return {};
  }
  const source = edge.source ?? edge.from;
  const target = edge.target ?? edge.to;
  return {
    ...edge,
    ...(source !== undefined ? { source } : {}),
    ...(target !== undefined ? { target } : {}),
    sourceHandle: edge.sourceHandle ?? edge.source_handle ?? 'main',
    targetHandle: edge.targetHandle ?? edge.target_handle ?? 'main',
  };
}

export function normalizeDefinition(input: unknown): WorkflowDefinition & { _repairs?: RepairEntry[] } {
  const def = isRecord(input) ? input : {};
  const rawNodes = Array.isArray(def.nodes) ? def.nodes : [];
  const rawEdges = Array.isArray(def.edges) ? def.edges.map(normalizeEdge) : [];
  const workflowMeta = isRecord(def.workflow) ? def.workflow : {};
  const metadata = isRecord(def.metadata) ? def.metadata : undefined;

  const repaired = repairWorkflowDefinition(rawNodes, rawEdges);

  return {
    schemaVersion: String(def.schemaVersion ?? '1.0'),
    workflow: workflowMeta,
    nodes: repaired.nodes as Record<string, unknown>[],
    edges: repaired.edges as Record<string, unknown>[],
    ...(metadata !== undefined ? { metadata } : {}),
    _repairs: repaired.repairs.length > 0 ? repaired.repairs : undefined,
  };
}

function normalizeStatus(value: unknown, fallback: WorkflowImportStatus): WorkflowImportStatus {
  if (value === 'draft' || value === 'active' || value === 'disabled' || value === 'archived' || value === 'error') {
    return value;
  }
  return fallback;
}

function normalizeFromWorkflowRecord(
  wf: Record<string, unknown>,
  defaults: WorkflowImportDefaults,
): WorkflowSavePayload {
  let definition: WorkflowDefinition;
  if (isRecord(wf.definition)) {
    definition = normalizeDefinition(wf.definition);
  } else if (Array.isArray(wf.nodes) || Array.isArray(wf.edges)) {
    definition = normalizeDefinition(wf);
  } else {
    definition = normalizeDefinition({});
  }

  const label =
    typeof wf.label === 'string' && wf.label.trim() !== '' ? wf.label.trim() : defaults.label;
  const description = typeof wf.description === 'string' ? wf.description : '';
  const status = normalizeStatus(wf.status, defaults.status ?? 'draft');

  return {
    label,
    description,
    status,
    definition,
  };
}

/**
 * Parses raw pasted text, stripping optional Markdown fences.
 */
export function parseWorkflowImportText(raw: string): unknown {
  let text = raw.trim();
  if (text === '') {
    throw new Error('Empty JSON');
  }
  const fence = text.match(/```(?:json)?\s*([\s\S]*?)\s*```/i);
  if (fence) {
    text = fence[1].trim();
  }
  return JSON.parse(text);
}

/**
 * Converts parsed JSON (assistant export, Knot export, or bare definition) into save payload.
 */
export function normalizeWorkflowImport(
  parsed: unknown,
  defaults: WorkflowImportDefaults,
): WorkflowSavePayload {
  if (!isRecord(parsed)) {
    throw new Error('Invalid JSON: expected an object');
  }

  if (
    ('trigger' in parsed && parsed.trigger !== undefined)
    || ('steps' in parsed && Array.isArray(parsed.steps))
  ) {
    throw new WorkflowImportLegacyStepsError();
  }

  if (parsed.knotExport && isRecord(parsed.workflow)) {
    return normalizeFromWorkflowRecord(parsed.workflow, defaults);
  }

  if (isRecord(parsed.workflow) && !Array.isArray(parsed.workflow)) {
    const inner = parsed.workflow;
    if (isRecord(inner.definition) || typeof inner.label === 'string' || Array.isArray(inner.nodes)) {
      return normalizeFromWorkflowRecord(inner, defaults);
    }
  }

  if (isRecord(parsed.definition) || typeof parsed.label === 'string') {
    return normalizeFromWorkflowRecord(parsed, defaults);
  }

  if (Array.isArray(parsed.nodes) || Array.isArray(parsed.edges)) {
    return normalizeFromWorkflowRecord({ definition: parsed }, defaults);
  }

  throw new WorkflowImportFormatError();
}

/**
 * Extract auto-repair entries from a definition that went through normalizeDefinition.
 * Returns empty array if no repairs were applied.
 */
export function extractRepairs(definition: WorkflowDefinition | undefined): RepairEntry[] {
  if (!definition) return [];
  return (definition as WorkflowDefinition & { _repairs?: RepairEntry[] })._repairs ?? [];
}
