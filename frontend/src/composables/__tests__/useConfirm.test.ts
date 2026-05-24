/**
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest';
import { provideConfirm } from '../useConfirm';

describe('provideConfirm', () => {
  it('resolves true when user confirms', async () => {
    const api = provideConfirm();
    const pending = api.confirm({ title: 'Delete?', message: 'Sure?' });
    expect(api.state.value.open).toBe(true);
    api.answer(true);
    await expect(pending).resolves.toBe(true);
    expect(api.state.value.open).toBe(false);
  });

  it('resolves false when user cancels', async () => {
    const api = provideConfirm();
    const pending = api.confirm({ title: 'Delete?', danger: true });
    api.answer(false);
    await expect(pending).resolves.toBe(false);
  });
});
