/**
 * Regression guard: CommandPalette builds workflow hits immutably (V2.8 audit).
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 */
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

const __dirname = dirname(fileURLToPath(import.meta.url));

describe('Vue immutability patterns', () => {
  it('CommandPalette assigns results from a fresh array (no push onto results.value)', () => {
    const path = join(__dirname, '..', 'components', 'CommandPalette.vue');
    const src = readFileSync(path, 'utf8');
    expect(src).not.toMatch(/results\.value\.push\s*\(/);
    expect(src).toMatch(/results\.value\s*=\s*matched/);
  });
});
