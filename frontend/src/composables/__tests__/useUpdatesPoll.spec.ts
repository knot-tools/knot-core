/**
 * Shared updates poll — single source for badge / banner / Updates / suite health.
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 */
import { flushPromises } from '@vue/test-utils';
import { knotApi, type UpdatesCheckResponse } from '../../lib/api';
import { resetUpdatesPollForTests, useUpdatesPoll } from '../useUpdatesPoll';

function pendingSnapshot(): UpdatesCheckResponse {
  return {
    checkedAt: 1,
    hasAnyUpdate: true,
    entries: [
      {
        slug: 'knot',
        installedVersion: '2.13.23',
        latestVersion: '2.13.24',
        channel: 'beta',
        publishedAt: '2026-10-12T00:00:00+00:00',
        notes: '## [2.13.24]\n\n- notes',
        hasUpdate: true,
        source: 'live',
        error: null,
      },
    ],
  };
}

describe('useUpdatesPoll', () => {
  beforeEach(() => {
    resetUpdatesPollForTests();
  });

  afterEach(() => {
    vi.restoreAllMocks();
    resetUpdatesPollForTests();
  });

  it('shares one snapshot across consumers and updates it after force refresh', async () => {
    const upToDate: UpdatesCheckResponse = {
      checkedAt: 1,
      hasAnyUpdate: false,
      entries: [
        {
          slug: 'knot',
          installedVersion: '2.13.23',
          latestVersion: '2.13.23',
          channel: 'beta',
          publishedAt: null,
          notes: '',
          hasUpdate: false,
          source: 'cache',
          error: null,
        },
      ],
    };
    const spy = vi.spyOn(knotApi, 'updates').mockResolvedValueOnce(upToDate);

    const a = useUpdatesPoll();
    const b = useUpdatesPoll();
    expect(a).toBe(b);
    await flushPromises();

    expect(spy).toHaveBeenCalledTimes(1);
    expect(spy).toHaveBeenCalledWith({ force: false });
    expect(a.snapshot.value?.hasAnyUpdate).toBe(false);

    spy.mockResolvedValueOnce(pendingSnapshot());
    await a.load(true);
    await flushPromises();

    expect(spy).toHaveBeenLastCalledWith({ force: true });
    expect(b.snapshot.value?.hasAnyUpdate).toBe(true);
    expect(b.snapshot.value?.entries[0]?.notes).toContain('2.13.24');
  });

  it('keeps the last good snapshot when a later refresh fails', async () => {
    const spy = vi.spyOn(knotApi, 'updates').mockResolvedValueOnce(pendingSnapshot());
    const poll = useUpdatesPoll();
    await flushPromises();
    expect(poll.snapshot.value?.hasAnyUpdate).toBe(true);

    spy.mockRejectedValueOnce(new Error('network down'));
    await poll.load(true);
    await flushPromises();

    expect(poll.error.value).toContain('network down');
    expect(poll.snapshot.value?.hasAnyUpdate).toBe(true);
    expect(poll.snapshot.value?.entries[0]?.latestVersion).toBe('2.13.24');
  });
});
