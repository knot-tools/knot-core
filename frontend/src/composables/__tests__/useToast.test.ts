/**
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest';
import { provideToast } from '../useToast';

describe('provideToast', () => {
  it('queues and dismisses toasts', () => {
    const api = provideToast();
    api.success('Saved');
    expect(api.toasts.value).toHaveLength(1);
    expect(api.toasts.value[0].level).toBe('success');
    const id = api.toasts.value[0].id;
    api.dismiss(id);
    expect(api.toasts.value).toHaveLength(0);
  });

  it('keeps at most five toasts (FIFO)', () => {
    const api = provideToast();
    for (let i = 1; i <= 7; i++) {
      api.info(`Toast ${i}`);
    }
    expect(api.toasts.value).toHaveLength(5);
    expect(api.toasts.value[0].title).toBe('Toast 3');
    expect(api.toasts.value[4].title).toBe('Toast 7');
  });

  it('accepts options for body and duration', () => {
    const api = provideToast();
    api.error('Failed', { body: 'Details', duration: 100 });
    expect(api.toasts.value[0].body).toBe('Details');
    expect(api.toasts.value[0].duration).toBe(100);
  });
});
