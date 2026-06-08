import { describe, expect, it } from 'vitest';
import { formatExecutionDuration, resolveExecutionDurationMs } from '../executionFormat';

describe('executionFormat', () => {
  it('prefers stored durationMs', () => {
    expect(resolveExecutionDurationMs(420, '2026-06-01 10:00:00', '2026-06-01 10:00:01')).toBe(420);
  });

  it('falls back to startedAt and endedAt', () => {
    const ms = resolveExecutionDurationMs(null, '2026-06-01T10:00:00.000Z', '2026-06-01T10:00:02.500Z');
    expect(ms).toBe(2500);
  });

  it('formats sub-second and second durations', () => {
    expect(formatExecutionDuration(250)).toBe('250 ms');
    expect(formatExecutionDuration(1500)).toBe('1.50 s');
  });
});
