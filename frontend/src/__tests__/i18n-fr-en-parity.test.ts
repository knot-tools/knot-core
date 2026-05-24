/**
 * V2.8 — FR and EN locale JSON must expose the same flat key set (product-critical UI).
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 */
import en from '../i18n/en_US.json';
import fr from '../i18n/fr_FR.json';
import { describe, expect, it } from 'vitest';

function flatKeys(obj: Record<string, unknown>, prefix = ''): string[] {
  const out: string[] = [];
  for (const [k, v] of Object.entries(obj)) {
    const path = prefix ? `${prefix}.${k}` : k;
    if (v && typeof v === 'object' && !Array.isArray(v)) {
      out.push(...flatKeys(v as Record<string, unknown>, path));
    } else {
      out.push(path);
    }
  }
  return out;
}

describe('i18n FR/EN parity (V2.8)', () => {
  it('exposes identical key trees for fr_FR and en_US', () => {
    const fk = new Set(flatKeys(fr as Record<string, unknown>));
    const ek = new Set(flatKeys(en as Record<string, unknown>));
    const onlyFr = [...fk].filter((k) => !ek.has(k));
    const onlyEn = [...ek].filter((k) => !fk.has(k));
    expect(onlyFr, `Missing in EN: ${onlyFr.join(', ')}`).toEqual([]);
    expect(onlyEn, `Missing in FR: ${onlyEn.join(', ')}`).toEqual([]);
  });
});
