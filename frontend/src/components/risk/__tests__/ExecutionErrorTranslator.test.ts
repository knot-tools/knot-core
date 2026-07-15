import { describe, expect, it, beforeAll } from 'vitest';
import { i18n } from '../../../i18n';
import {
  translateError,
  extractKnotPayloadFromUnknown,
  translateExecutionError,
  extractExtensionIdFromLicenseError,
  safeExecutionDocHref,
} from '../ExecutionErrorTranslator';

beforeAll(() => {
  i18n.global.locale.value = 'en_US';
});

describe('translateError', () => {
  it('falls back to "unknown" when input is empty', () => {
    expect(translateError(null).bucket).toBe('unknown');
    expect(translateError(undefined).bucket).toBe('unknown');
    expect(translateError('').bucket).toBe('unknown');
  });

  it('shows English title for unknown when locale is en_US', () => {
    expect(translateError('xyz-untyped').title).toBe('Execution error');
  });

  it('detects HTTP 401 / unauthorized as auth', () => {
    expect(translateError('Server returned 401').bucket).toBe('auth');
    expect(translateError('UNAUTHORIZED').bucket).toBe('auth');
    expect(translateError('401').title).toMatch(/authentication/i);
  });

  it('detects rate-limit', () => {
    expect(translateError('429 Too Many Requests').bucket).toBe('rate-limit');
  });

  it('detects connectivity', () => {
    expect(translateError('connect ECONNREFUSED 1.2.3.4').bucket).toBe('connectivity');
    expect(translateError('getaddrinfo ENOTFOUND api.example.com').bucket).toBe('connectivity');
  });

  it('detects SQL constraint', () => {
    expect(
      translateError("Duplicate entry '42' for key 'PRIMARY'").bucket,
    ).toBe('sql-constraint');
  });

  it('detects license errors', () => {
    expect(translateError('extension_expired knot-pro-pack').bucket).toBe('license');
  });

  it('preserves the raw technical message', () => {
    const t = translateError('foo bar baz');
    expect(t.technical).toBe('foo bar baz');
  });
});

describe('extractKnotPayloadFromUnknown', () => {
  it('reads details.knot from Error-shaped objects', () => {
    const err = new Error('wrapped');
    (err as Error & { details?: unknown }).details = {
      knot: {
        code: 'KNOT_EXECUTION_FAILED',
        user_message: 'Stopped',
        technical_message: 'x',
        doc_link: 'https://example.com',
      },
    };
    const k = extractKnotPayloadFromUnknown(err);
    expect(k?.code).toBe('KNOT_EXECUTION_FAILED');
    expect(k?.user_message).toBe('Stopped');
  });
});

describe('translateExecutionError', () => {
  it('uses Knot payload when present', () => {
    const u = translateExecutionError({
      code: 'KNOT_X',
      user_message: 'Hello',
      technical_message: 'T',
      suggestion: 'Do Y',
      doc_link: null,
    });
    expect(u.source).toBe('knot_payload');
    expect(u.title).toBe('Hello');
    expect(u.hint).toContain('Do Y');
  });
});

describe('safeExecutionDocHref', () => {
  it('rejects javascript: URLs', () => {
    expect(safeExecutionDocHref('javascript:alert(1)')).toBeNull();
    expect(safeExecutionDocHref('/custom/knot/docs/x')).toBe('/custom/knot/docs/x');
    expect(safeExecutionDocHref('https://knot.tools/x')).toBe('https://knot.tools/x');
  });
});

describe('extractExtensionIdFromLicenseError', () => {
  it('reads extension id from license error strings', () => {
    expect(extractExtensionIdFromLicenseError('Extension "knot-pro-pack" license check failed')).toBe('knot-pro-pack');
    expect(extractExtensionIdFromLicenseError('no extension here')).toBeNull();
  });

  it('defaults to knot-pro-pack for LicenseGate license_required prefix', () => {
    expect(
      extractExtensionIdFromLicenseError('[license_required] Extension license missing'),
    ).toBe('knot-pro-pack');
  });
});

describe('translateError license_required', () => {
  it('maps [license_required] to license bucket with simulate hint', () => {
    const t = translateError('[license_required] licence missing');
    expect(t.bucket).toBe('license');
    expect(t.hint).toMatch(/simulate|Pro Pack|activate/i);
  });
});
