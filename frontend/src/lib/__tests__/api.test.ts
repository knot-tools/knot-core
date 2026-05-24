import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { i18n } from '../../i18n';
import {
  formatWorkflowImportWarningLine,
  knotApi,
  knotApiErrorMessage,
  type KnotApiEnvelope,
} from '../api';

function envelope<T>(data: T): KnotApiEnvelope<T> {
  return { success: true, data, error: null, meta: {} };
}

function mockFetchJson(body: unknown, status = 200): void {
  globalThis.fetch = vi.fn(async () =>
    new Response(JSON.stringify(body), {
      status,
      headers: { 'Content-Type': 'application/json' },
    }),
  ) as unknown as typeof fetch;
}

describe('knotApiErrorMessage', () => {
  beforeEach(() => {
    i18n.global.locale.value = 'en_US';
  });

  it('returns a translated message when the API code is known', () => {
    const msg = knotApiErrorMessage({
      code: 'csrf_invalid',
      message: 'Raw server message',
    });
    expect(msg).toBe('Invalid or missing CSRF token. Refresh the page and try again.');
  });

  it('falls back to the server message for unknown codes', () => {
    const msg = knotApiErrorMessage({
      code: 'totally_unknown_code',
      message: 'Operator-visible detail',
    });
    expect(msg).toBe('Operator-visible detail');
  });
});

describe('formatWorkflowImportWarningLine', () => {
  beforeEach(() => {
    i18n.global.locale.value = 'en_US';
  });

  it('prefers i18n messageKey when present', () => {
    const line = formatWorkflowImportWarningLine({
      severity: 'warning',
      code: 'module_inactive',
      messageKey: 'module_inactive',
      messageParams: {
        workflowLabel: 'Billing sync',
        objectType: 'facture',
        module: 'facture',
      },
    });
    expect(line).toContain('facture');
    expect(line).toContain('Billing sync');
  });

  it('falls back to legacy message then code', () => {
    expect(
      formatWorkflowImportWarningLine({
        severity: 'warning',
        code: 'legacy_only',
        message: 'Legacy warning text',
      }),
    ).toBe('Legacy warning text');

    expect(
      formatWorkflowImportWarningLine({
        severity: 'error',
        code: 'bare_code',
      }),
    ).toBe('bare_code');
  });
});

describe('knotApi HTTP client', () => {
  beforeEach(() => {
    (window as unknown as Record<string, unknown>).KNOT_API_BASE = '/custom/knot/api';
    (window as unknown as Record<string, unknown>).KNOT_CSRF_TOKEN = 'csrf-test-token';
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('builds listWorkflows query string and parses success envelopes', async () => {
    mockFetchJson(
      envelope({
        workflows: [{ id: 7, label: 'Demo', status: 'draft' }],
        counts: { draft: 1 },
      }),
    );

    const result = await knotApi.listWorkflows({ status: 'draft', limit: 10, offset: 5 });

    expect(result.workflows).toHaveLength(1);
    expect(globalThis.fetch).toHaveBeenCalledWith(
      '/custom/knot/api/workflows.php?status=draft&limit=10&offset=5',
      expect.objectContaining({ credentials: 'same-origin' }),
    );
  });

  it('sends CSRF header on mutating requests', async () => {
    mockFetchJson(envelope({ workflow: { id: 1, label: 'Saved', status: 'draft' } }));

    await knotApi.saveWorkflow({ id: 1, label: 'Saved' });

    const init = (globalThis.fetch as ReturnType<typeof vi.fn>).mock.calls[0]?.[1] as RequestInit;
    expect(init.method).toBe('POST');
    expect((init.headers as Record<string, string>)['X-Csrf-Token']).toBe('csrf-test-token');
  });

  it('surfaces API envelope errors with translated messages', async () => {
    mockFetchJson({
      success: false,
      data: null,
      error: { code: 'permission_denied', message: 'Denied' },
      meta: {},
    });

    await expect(knotApi.getWorkflow(99)).rejects.toMatchObject({
      message: 'You do not have permission for this action.',
      code: 'permission_denied',
    });
  });

  it('detects Dolibarr login HTML instead of invalid JSON', async () => {
    globalThis.fetch = vi.fn(async () =>
      new Response('<html><title>Identifiant</title></html>', {
        status: 200,
        headers: { 'Content-Type': 'text/html' },
      }),
    ) as unknown as typeof fetch;

    await expect(knotApi.getWorkflow(1)).rejects.toThrow(/Session Dolibarr expirée/);
  });
});
