/**
 * Human-readable execution log lines (risk-grammar §7).
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 */

export type HumanLogInput = {
  nodeId?: string;
  type?: string;
  status?: string;
  label?: string;
  error?: string | null;
  output?: unknown;
};

const TYPE_VERBS: Record<string, string> = {
  'trigger.manual': 'Started from manual trigger',
  'trigger.cron': 'Started on schedule',
  'trigger.webhook': 'Started from webhook',
  'trigger.dolibarr': 'Started from Dolibarr event',
  'action.email': 'Sent email',
  'notification.alert': 'Raised alert',
  'dolibarr.read_object': 'Read Dolibarr object',
  'dolibarr.object': 'Wrote Dolibarr object',
  'dolibarr.sql_query': 'Ran SQL query',
  'logic.if': 'Evaluated condition',
  'logic.loop': 'Iterated loop',
  'logic.set': 'Set variables',
  'logic.switch': 'Switched branch',
  'logic.merge': 'Merged branches',
  'logic.delay': 'Delayed',
  'logic.noop': 'No-op',
  'action.http': 'Called HTTP endpoint',
  'action.stripe': 'Called Stripe',
  'action.slack': 'Posted to Slack',
};

function pickOutputHint(output: unknown): string | null {
  if (output === null || output === undefined) return null;
  if (typeof output === 'string') {
    const trimmed = output.trim();
    return trimmed.length > 0 ? trimmed.slice(0, 120) : null;
  }
  if (typeof output !== 'object') return String(output);
  const row = output as Record<string, unknown>;
  for (const key of [
    'message',
    'summary',
    'ref',
    'id',
    'email',
    'to',
    'subject',
    'count',
    'rows',
    'status',
    'url',
    'object_type',
    'fk_object',
  ]) {
    const val = row[key];
    if (typeof val === 'string' && val.trim() !== '') {
      return val.trim().slice(0, 120);
    }
    if (typeof val === 'number') {
      return String(val);
    }
    if (typeof val === 'boolean') {
      return val ? 'true' : 'false';
    }
  }
  if (Array.isArray(row.items)) {
    return `${row.items.length} item(s)`;
  }
  return null;
}

function verbForType(type: string): string | null {
  const trimmed = type.trim();
  if (trimmed === '') return null;
  if (TYPE_VERBS[trimmed]) return TYPE_VERBS[trimmed];
  if (trimmed.startsWith('action.')) return `Ran ${trimmed.slice('action.'.length).replace(/_/g, ' ')}`;
  if (trimmed.startsWith('trigger.')) return `Triggered (${trimmed.slice('trigger.'.length)})`;
  if (trimmed.startsWith('logic.')) return `Logic ${trimmed.slice('logic.'.length)}`;
  return null;
}

/**
 * Build a short human sentence for a simulation / execution node log.
 * Technical details stay elsewhere (collapsed JSON).
 */
export function formatHumanExecutionLine(log: HumanLogInput): string {
  const label = (log.label ?? '').trim() || (log.nodeId ?? '').trim() || 'node';
  const status = (log.status ?? '').toLowerCase();
  const hint = pickOutputHint(log.output);
  const type = (log.type ?? '').trim();
  const verb = verbForType(type);

  if (status === 'error' || status === 'failed') {
    const reason = (log.error ?? '').trim() || hint || 'unknown error';
    return `Failed at « ${label} » — ${reason}`;
  }
  if (status === 'skipped') {
    return `Skipped « ${label} »`;
  }
  if (status === 'running' || status === 'pending') {
    return verb ? `${verb} — « ${label} »…` : `Running « ${label} »…`;
  }
  if (verb && hint) {
    return `${verb} « ${label} » — ${hint}`;
  }
  if (verb) {
    return `${verb} « ${label} »`;
  }
  if (hint) {
    return `« ${label} » — ${hint}`;
  }
  if (type !== '') {
    return `« ${label} » completed (${type})`;
  }
  return `« ${label} » completed`;
}
