/**
 * Knot — Live workflow validator (TS port).
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 *
 * Local-only checks for the editor (config fields, multi-trigger). Structural
 * graph rules run on the server (`WorkflowValidator::validateAll` via
 * `action=lint`); both sets are merged in `useWorkflowLinter`.
 */

import { i18n } from '../i18n';

export interface ValidationIssue {
  severity: 'error' | 'warning' | 'info';
  code: string;
  /** Local checks only — server lint uses messageKey */
  message?: string;
  messageKey?: string;
  messageParams?: Record<string, string | number>;
  nodeId?: string;
}

export function formatValidationIssueMessage(issue: ValidationIssue): string {
  if (issue.messageKey) {
    const key = `errors.validation.${issue.messageKey}`;
    const translated = i18n.global.t(key, issue.messageParams ?? {});
    if (translated !== key) {
      return String(translated);
    }
  }
  if (issue.message) return issue.message;
  return issue.code;
}

export interface KnotNodeLike {
  id: string;
  type?: string;
  data?: { type?: string; label?: string; config?: Record<string, unknown> } & Record<string, unknown>;
}

export interface KnotEdgeLike {
  id?: string;
  source: string;
  target: string;
  sourceHandle?: string | null;
}

const TRIGGER_PREFIX = 'trigger.';

const OBJECT_TYPE_ALIASES: Record<string, string> = {
  invoice: 'facture',
  order: 'commande',
  devis: 'propal',
  proposal: 'propal',
  societe: 'thirdparty',
  company: 'thirdparty',
  tiers: 'thirdparty',
};

const TRIGGER_PAYLOAD_FIELDS = new Set(['objectId', 'objectRef', 'objectType', 'event']);

function collectConfigStrings(value: unknown, out: string[]): void {
  if (typeof value === 'string') {
    out.push(value);
    return;
  }
  if (Array.isArray(value)) {
    for (const item of value) collectConfigStrings(item, out);
    return;
  }
  if (value && typeof value === 'object') {
    for (const v of Object.values(value as Record<string, unknown>)) {
      collectConfigStrings(v, out);
    }
  }
}

export function validateWorkflow(
  nodes: KnotNodeLike[],
  edges: KnotEdgeLike[],
): ValidationIssue[] {
  const issues: ValidationIssue[] = [];

  if (!Array.isArray(nodes) || nodes.length === 0) {
    issues.push({
      severity: 'error',
      code: 'no_nodes',
      message: String(i18n.global.t('validator.no_nodes')),
    });
    return issues;
  }

  const triggerNodes = nodes.filter((n) => (n.data?.type || n.type || '').startsWith(TRIGGER_PREFIX));
  if (triggerNodes.length > 1) {
    issues.push({
      severity: 'warning',
      code: 'multiple_triggers',
      message: String(
        i18n.global.t('validator.multiple_triggers', {
          count: triggerNodes.length,
        }),
      ),
    });
  }

  for (const n of nodes) {
    const type = (n.data?.type || n.type || '') as string;
    const config = (n.data?.config || {}) as Record<string, unknown>;
    if (type === 'action.http' && !config.url) {
      issues.push({
        severity: 'error',
        code: 'http_url_missing',
        message: String(i18n.global.t('validator.http_url_missing')),
        nodeId: n.id,
      });
    }
    if (type === 'logic.loop' && !config.itemsPath) {
      issues.push({
        severity: 'warning',
        code: 'loop_items_missing',
        message: String(i18n.global.t('validator.loop_items_missing')),
        nodeId: n.id,
      });
    }
    if (type === 'trigger.cron' && !config.cron && !config.expression && !config.cronExpression) {
      issues.push({
        severity: 'warning',
        code: 'cron_expression_missing',
        message: String(i18n.global.t('validator.cron_expression_missing')),
        nodeId: n.id,
      });
    }
    if (type === 'dolibarr.sql_query') {
      issues.push(...sqlQueryLintIssues(n.id, String(config.query ?? '')));
    }
    if (type === 'dolibarr.read_object' || type === 'dolibarr.object') {
      const raw = String(config.objectType ?? '').trim();
      if (raw && !raw.includes('{{')) {
        const suggestion = OBJECT_TYPE_ALIASES[raw.toLowerCase()];
        if (suggestion) {
          issues.push({
            severity: 'warning',
            code: 'object_type_alias_hint',
            messageKey: 'object_type_unknown_hint',
            messageParams: { objectType: raw, suggestion },
            nodeId: n.id,
          });
        }
      }
    }
  }

  const typesById = new Map<string, string>();
  for (const n of nodes) {
    typesById.set(n.id, String(n.data?.type || n.type || ''));
  }
  const upstream = new Map<string, string[]>();
  for (const n of nodes) upstream.set(n.id, []);
  for (const e of edges) {
    const list = upstream.get(e.target);
    if (list) list.push(e.source);
  }

  for (const n of nodes) {
    const sources = upstream.get(n.id) ?? [];
    if (sources.length === 0) continue;
    const upstreamId = sources[0];
    const upstreamType = typesById.get(upstreamId) ?? '';
    const isTriggerUpstream = upstreamType.startsWith('trigger.');
    const blobs: string[] = [];
    collectConfigStrings(n.data?.config ?? {}, blobs);
    const blob = blobs.join('\n');
    const matches = blob.matchAll(/\{\{\$json\.([a-zA-Z0-9_]+)\}\}/g);
    for (const match of matches) {
      const field = match[1];
      if (!field) continue;
      if (isTriggerUpstream && TRIGGER_PAYLOAD_FIELDS.has(field)) continue;
      if (!isTriggerUpstream) {
        issues.push({
          severity: 'warning',
          code: 'expression_json_chain',
          messageKey: 'expression_json_chain',
          messageParams: {
            field,
            upstreamId,
            suggestion: `{{$nodes.${upstreamId}.json.${field}}}`,
          },
          nodeId: n.id,
        });
      }
    }
  }

  return issues;
}

export function hasCriticalErrors(issues: ValidationIssue[]): boolean {
  return issues.some((i) => i.severity === 'error');
}

const SQL_TYPO_TABLES: Record<string, string> = {
  llx_propale: 'llx_propal',
  llx_propales: 'llx_propal',
  llx_thirdparty: 'llx_societe',
  llx_proposal: 'llx_propal',
};

function sqlQueryLintIssues(nodeId: string, query: string): ValidationIssue[] {
  const issues: ValidationIssue[] = [];
  const trimmed = query.trim();
  if (trimmed === '') {
    return issues;
  }
  const matches = trimmed.matchAll(/\b(llx_[a-z0-9_]+)\b/gi);
  const seen = new Set<string>();
  for (const match of matches) {
    const table = String(match[1] ?? '').toLowerCase();
    if (seen.has(table)) {
      continue;
    }
    seen.add(table);
    const hint = SQL_TYPO_TABLES[table];
    if (hint) {
      issues.push({
        severity: 'warning',
        code: 'sql_unknown_table_hint',
        messageKey: 'sql_unknown_table_hint',
        messageParams: { table, hint },
        nodeId,
      });
    }
  }
  return issues;
}
