/**
 * Dashboard updates badge — must not claim "up to date" without a snapshot.
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 */
import { flushPromises, mount } from '@vue/test-utils';
import { i18n } from '../../../i18n';
import { knotApi, type UpdatesCheckResponse } from '../../../lib/api';
import { resetUpdatesPollForTests } from '../../../composables/useUpdatesPoll';
import UpdatesBadge from '../UpdatesBadge.vue';

describe('UpdatesBadge', () => {
  beforeEach(() => {
    resetUpdatesPollForTests();
    (window as unknown as Record<string, unknown>).KNOT_BASE_URL = '/custom/knot/workflows/preview.php';
    i18n.global.locale.value = 'en_US';
  });

  afterEach(() => {
    vi.restoreAllMocks();
    resetUpdatesPollForTests();
  });

  it('shows pending Core update from the shared poll (not a false up-to-date)', async () => {
    const snapshot: UpdatesCheckResponse = {
      checkedAt: 1,
      hasAnyUpdate: true,
      entries: [
        {
          slug: 'knot',
          installedVersion: '2.13.23',
          latestVersion: '2.13.24',
          channel: 'beta',
          publishedAt: null,
          notes: '## notes',
          hasUpdate: true,
          source: 'live',
          error: null,
        },
      ],
    };
    vi.spyOn(knotApi, 'updates').mockResolvedValue(snapshot);

    const wrapper = mount(UpdatesBadge, { global: { plugins: [i18n] } });
    await flushPromises();

    expect(wrapper.get('[data-testid="updates-badge"]').text()).toContain('Update available');
    expect(wrapper.text()).not.toContain('All installed products are up to date');
    wrapper.unmount();
  });

  it('shows loadError instead of up-to-date when the check fails with no prior snapshot', async () => {
    vi.spyOn(knotApi, 'updates').mockRejectedValue(new Error('boom'));

    const wrapper = mount(UpdatesBadge, { global: { plugins: [i18n] } });
    await flushPromises();

    expect(wrapper.get('[data-testid="updates-badge"]').text()).toContain('Unable to check for updates');
    expect(wrapper.text()).not.toContain('All installed products are up to date');
    wrapper.unmount();
  });
});
