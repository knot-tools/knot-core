/**
 * V2.12 — every locale string must compile with @intlify/message-compiler.
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 */
import deDE from '../i18n/de_DE.json';
import enUS from '../i18n/en_US.json';
import esES from '../i18n/es_ES.json';
import frFR from '../i18n/fr_FR.json';
import itIT from '../i18n/it_IT.json';
import ptPT from '../i18n/pt_PT.json';
import { createRequire } from 'node:module';
import { describe, expect, it } from 'vitest';

const require = createRequire(import.meta.url);
const { baseCompile } = require('@intlify/message-compiler') as {
  baseCompile: (
    message: string,
    options: { location: boolean; jit: boolean; onError: (err: { message?: string }) => void },
  ) => void;
};

const LOCALES: Record<string, Record<string, unknown>> = {
  de_DE: deDE as Record<string, unknown>,
  en_US: enUS as Record<string, unknown>,
  es_ES: esES as Record<string, unknown>,
  fr_FR: frFR as Record<string, unknown>,
  it_IT: itIT as Record<string, unknown>,
  pt_PT: ptPT as Record<string, unknown>,
};

function flatLeaves(obj: Record<string, unknown>, prefix = ''): Array<[string, string]> {
  const out: Array<[string, string]> = [];
  for (const [k, v] of Object.entries(obj)) {
    const path = prefix ? `${prefix}.${k}` : k;
    if (v && typeof v === 'object' && !Array.isArray(v)) {
      out.push(...flatLeaves(v as Record<string, unknown>, path));
    } else {
      out.push([path, String(v)]);
    }
  }
  return out;
}

function compileErrors(message: string): string[] {
  const errors: string[] = [];
  const onError = (err: { message?: string }) => errors.push(err.message ?? String(err));
  try {
    baseCompile(message, { location: false, jit: true, onError });
  } catch (err) {
    errors.push(err instanceof Error ? err.message : String(err));
  }
  return [...new Set(errors)];
}

describe('i18n ICU compile safety (V2.12)', () => {
  for (const [locale, tree] of Object.entries(LOCALES)) {
    it(`${locale} strings compile without ICU placeholder errors`, () => {
      const failures: string[] = [];
      for (const [key, msg] of flatLeaves(tree)) {
        const errors = compileErrors(msg);
        if (errors.length > 0) {
          failures.push(`${key}: ${errors.join('; ')}`);
        }
      }
      expect(failures, failures.join('\n')).toEqual([]);
    });
  }
});
