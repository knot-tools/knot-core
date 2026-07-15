// humanExecutionLog — Vitest
// Copyright (C) 2026 Knot — GPL-3.0-or-later
import { describe, expect, it } from 'vitest';
import { formatHumanExecutionLine } from '../humanExecutionLog';

describe('formatHumanExecutionLine', () => {
  it('prefers label and output hint on success', () => {
    const line = formatHumanExecutionLine({
      nodeId: 'email_1',
      label: 'Send welcome',
      type: 'action.email',
      status: 'success',
      output: { to: 'client@example.com', subject: 'Welcome' },
    });
    expect(line).toContain('Send welcome');
    expect(line).toContain('client@example.com');
  });

  it('surfaces failure reason first', () => {
    const line = formatHumanExecutionLine({
      label: 'Create invoice',
      status: 'error',
      error: 'Third party not found',
    });
    expect(line).toContain('Failed at');
    expect(line).toContain('Create invoice');
    expect(line).toContain('Third party not found');
  });

  it('handles skipped nodes', () => {
    expect(formatHumanExecutionLine({ label: 'Branch B', status: 'skipped' })).toContain('Skipped');
  });

  it('uses typed verbs for common connectors', () => {
    const line = formatHumanExecutionLine({
      label: 'Welcome mail',
      type: 'action.email',
      status: 'success',
      output: { to: 'a@example.com' },
    });
    expect(line).toContain('Sent email');
    expect(line).toContain('Welcome mail');
    expect(line).toContain('a@example.com');
  });

  it('shows running state', () => {
    expect(
      formatHumanExecutionLine({ label: 'Cron', type: 'trigger.cron', status: 'running' }),
    ).toContain('…');
  });
});
