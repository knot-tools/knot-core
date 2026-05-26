/**
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 */
import { describe, expect, it } from 'vitest';
import { createMessageContext } from '@intlify/core-base';

import { cspSafeMessageCompiler } from '../cspSafeMessageCompiler';

describe('cspSafeMessageCompiler', () => {
  it('returns constant strings without placeholders', () => {
    const fn = cspSafeMessageCompiler('Hello Knot', { onError: () => undefined });
    expect(fn(createMessageContext())).toBe('Hello Knot');
  });

  it('interpolates named placeholders without eval', () => {
    const fn = cspSafeMessageCompiler('{nodes} nœuds · {edges} liens', {
      onError: () => undefined,
    });
    const ctx = createMessageContext({
      named: { nodes: 3, edges: 2 },
    });
    expect(fn(ctx)).toBe('3 nœuds · 2 liens');
  });
});
