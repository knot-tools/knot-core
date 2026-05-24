/**
 * ExecutionErrorTranslator — V2.5 UX-3 + ADR-007 rich Knot payloads
 *
 * Maps raw runtime errors (SQL, HTTP, PHP, Knot internals) into
 * human-readable messages. Supports structured `details.knot` from API
 * envelopes and legacy regex fallbacks on plain strings.
 *
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 */

import { i18n } from '../../i18n';

export type ErrorBucket =
  | 'permission'
  | 'validation'
  | 'connectivity'
  | 'auth'
  | 'rate-limit'
  | 'not-found'
  | 'sql-constraint'
  | 'sql-syntax'
  | 'expression'
  | 'license'
  | 'timeout'
  | 'unknown';

export interface TranslatedError {
  bucket: ErrorBucket;
  /** Human title in the user's language. */
  title: string;
  /** Short suggestion / next action. */
  hint: string;
  /** The raw, unredacted, technical message (for the collapsed panel). */
  technical: string;
}

export interface KnotErrorPayload {
  code: string;
  user_message: string;
  technical_message?: string;
  doc_link?: string | null;
  severity?: string;
  suggestion?: string | null;
  context?: Record<string, unknown>;
}

export interface UnifiedExecutionError extends TranslatedError {
  source: 'knot_payload' | 'legacy_regex';
  knotCode?: string;
  docLink?: string | null;
  severity?: string;
}

interface PatternDef {
  bucket: ErrorBucket;
  re: RegExp;
  titleKey: string;
  hintKey: string;
}

function tt(key: string): string {
  return String(i18n.global.t(key));
}

const PATTERNS: PatternDef[] = [
  {
    bucket: 'license',
    re: /extension_(?:expired|missing|unlicensed|tampered)/i,
    titleKey: 'errors.execution.license.title',
    hintKey: 'errors.execution.license.hint',
  },
  {
    bucket: 'license',
    re: /license check failed|license_invalid/i,
    titleKey: 'errors.execution.license.title',
    hintKey: 'errors.execution.license.hintActivate',
  },
  {
    bucket: 'auth',
    re: /\b(401|403|unauthorized|forbidden|invalid[_ ]?token)\b/i,
    titleKey: 'errors.execution.auth.title',
    hintKey: 'errors.execution.auth.hint',
  },
  {
    bucket: 'rate-limit',
    re: /\b(429|rate[_ ]?limit|too many requests)\b/i,
    titleKey: 'errors.execution.rate_limit.title',
    hintKey: 'errors.execution.rate_limit.hint',
  },
  {
    bucket: 'not-found',
    re: /\b(404|not[_ ]?found|introuvable)\b/i,
    titleKey: 'errors.execution.not_found.title',
    hintKey: 'errors.execution.not_found.hint',
  },
  {
    bucket: 'connectivity',
    re: /\b(econnrefused|enotfound|getaddrinfo|connection refused|timed? ?out)\b/i,
    titleKey: 'errors.execution.connectivity.title',
    hintKey: 'errors.execution.connectivity.hint',
  },
  {
    bucket: 'timeout',
    re: /\b(execution exceeded|maximum execution time|deadline)\b/i,
    titleKey: 'errors.execution.timeout.title',
    hintKey: 'errors.execution.timeout.hint',
  },
  {
    bucket: 'sql-constraint',
    re: /(duplicate entry|foreign key constraint|cannot delete or update a parent row|unique constraint)/i,
    titleKey: 'errors.execution.sql_constraint.title',
    hintKey: 'errors.execution.sql_constraint.hint',
  },
  {
    bucket: 'sql-syntax',
    re: /(sqlstate|you have an error in your sql syntax)/i,
    titleKey: 'errors.execution.sql_syntax.title',
    hintKey: 'errors.execution.sql_syntax.hint',
  },
  {
    bucket: 'permission',
    re: /(permission|forbidden|access denied|droit insuffisant|not allowed)/i,
    titleKey: 'errors.execution.permission.title',
    hintKey: 'errors.execution.permission.hint',
  },
  {
    bucket: 'expression',
    re: /(undefined (?:variable|index|key)|cannot read prop|template syntax)/i,
    titleKey: 'errors.execution.expression.title',
    hintKey: 'errors.execution.expression.hint',
  },
  {
    bucket: 'validation',
    re: /(validation|invalid (?:value|input|payload))/i,
    titleKey: 'errors.execution.validation.title',
    hintKey: 'errors.execution.validation.hint',
  },
];

export function translateError(raw: string | null | undefined): TranslatedError {
  const technical = (raw ?? '').toString();
  for (const p of PATTERNS) {
    if (p.re.test(technical)) {
      const title = tt(p.titleKey);
      const resolvedTitle = title !== p.titleKey ? title : tt('errors.execution.unknown.title');
      const hintRaw = tt(p.hintKey);
      const hint = hintRaw !== p.hintKey ? hintRaw : tt('errors.execution.unknown.hint');
      return {
        bucket: p.bucket,
        title: resolvedTitle,
        hint,
        technical,
      };
    }
  }
  return {
    bucket: 'unknown',
    title: tt('errors.execution.unknown.title'),
    hint: tt('errors.execution.unknown.hint'),
    technical,
  };
}

export function isKnotErrorPayload(v: unknown): v is KnotErrorPayload {
  if (!v || typeof v !== 'object') return false;
  const o = v as Record<string, unknown>;
  return typeof o.code === 'string' && typeof o.user_message === 'string';
}

/** Reads ADR-007 knot block from a thrown API Error (`error.details.knot`). */
export function extractKnotPayloadFromUnknown(err: unknown): KnotErrorPayload | null {
  if (!err || typeof err !== 'object') return null;
  const details = (err as Error & { details?: unknown }).details;
  if (!details || typeof details !== 'object') return null;
  const knot = (details as Record<string, unknown>).knot;
  return isKnotErrorPayload(knot) ? knot : null;
}

export function extractExtensionIdFromLicenseError(raw: string): string | null {
  const match = /Extension\s+"([^"]+)"\s+license check failed/i.exec(raw);
  return match?.[1] ?? null;
}

/** Allows only http(s) or same-origin module documentation paths in clickable links. */
export function safeExecutionDocHref(raw: string | null | undefined): string | null {
  if (raw == null || typeof raw !== 'string') return null;
  const t = raw.trim();
  if (t === '') return null;
  if (t.startsWith('/custom/')) return t;
  if (t.startsWith('https://') || t.startsWith('http://')) return t;
  return null;
}

export function translateExecutionError(payload: unknown): UnifiedExecutionError {
  if (payload === null || payload === undefined) {
    return {
      bucket: 'unknown',
      title: '',
      hint: '',
      technical: '',
      source: 'legacy_regex',
    };
  }
  if (typeof payload === 'string') {
    const t = translateError(payload);
    return { ...t, source: 'legacy_regex' };
  }
  if (isKnotErrorPayload(payload)) {
    const technical = String(payload.technical_message ?? payload.user_message ?? '');
    const regexFallback = translateError(technical);
    const suggestionTrim = (payload.suggestion ?? '').trim();
    return {
      bucket: regexFallback.bucket,
      title: payload.user_message,
      hint: suggestionTrim || regexFallback.hint,
      technical,
      source: 'knot_payload',
      knotCode: payload.code,
      docLink: payload.doc_link ?? null,
      severity: payload.severity ?? 'error',
    };
  }
  const t = translateError('');
  return { ...t, technical: '', source: 'legacy_regex' };
}
