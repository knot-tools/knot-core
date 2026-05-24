/**
 * Resolve connector copy from API-provided vue-i18n keys.
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 */
import { i18n } from '@/i18n';

/**
 * Build a message key under `connectors.<id segments>.*`.
 * Example: id `action.email`, suffix `label` → `connectors.action.email.label`
 */
export function connectorMessageKey(connectorId: string, suffix: string): string {
  const segments = connectorId.split('.').filter(Boolean);
  return ['connectors', ...segments, suffix].join('.');
}

/**
 * Translate a connector-related key, falling back to legacy English (or another) string
 * when the key is missing from all locales in the fallback chain.
 */
export function resolveConnectorLabel(key: string, fallback?: string): string {
  const resolved = i18n.global.t(key);
  if (resolved === key && fallback !== undefined && fallback !== '') {
    return fallback;
  }
  return String(resolved);
}
