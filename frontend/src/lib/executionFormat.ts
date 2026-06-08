/**
 * Execution duration helpers — DB column plus started/ended fallback.
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 */

export function resolveExecutionDurationMs(
  durationMs: number | null | undefined,
  startedAt: string | null | undefined,
  endedAt: string | null | undefined,
): number | null {
  if (durationMs != null && durationMs > 0) {
    return durationMs;
  }
  if (!startedAt || !endedAt) {
    return null;
  }
  const start = Date.parse(startedAt);
  const end = Date.parse(endedAt);
  if (!Number.isFinite(start) || !Number.isFinite(end) || end < start) {
    return null;
  }
  return end - start;
}

export function formatExecutionDuration(ms: number | null | undefined): string {
  if (ms == null || ms <= 0) {
    return '—';
  }
  if (ms < 1000) {
    return `${ms} ms`;
  }
  if (ms < 60000) {
    return `${(ms / 1000).toFixed(2)} s`;
  }
  return `${(ms / 60000).toFixed(2)} min`;
}
