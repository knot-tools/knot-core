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

export function validateWorkflow(
  nodes: KnotNodeLike[],
  _edges: KnotEdgeLike[],
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
  }

  return issues;
}

export function hasCriticalErrors(issues: ValidationIssue[]): boolean {
  return issues.some((i) => i.severity === 'error');
}
