import { describe, expect, it, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import UpdatesFloatingBanner from '../UpdatesFloatingBanner.vue';
import { resetUpdatesPollForTests } from '../../../composables/useUpdatesPoll';

vi.mock('../../../composables/useUpdatesPoll', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../../../composables/useUpdatesPoll')>();
  return {
    ...actual,
    useUpdatesPoll: () => ({
      loading: { value: false },
      error: { value: null },
      snapshot: {
        value: {
          checkedAt: 1,
          hasAnyUpdate: true,
          entries: [
            {
              slug: 'knot',
              installedVersion: '2.13.0',
              latestVersion: '2.14.0',
              channel: 'beta',
              hasUpdate: true,
              source: 'live',
            },
          ],
        },
      },
      load: vi.fn(),
    }),
  };
});

const i18n = createI18n({
  legacy: false,
  locale: 'en',
  messages: {
    en: {
      updatesFloatingBanner: {
        oneUpdate: 'Update for {slug} ({version})',
        manyUpdates: '{count} updates',
        viewUpdates: 'View updates',
        snooze: 'Later',
        ignoreVersion: 'Ignore',
        dismissAria: 'Dismiss',
      },
    },
  },
});

describe('UpdatesFloatingBanner', () => {
  beforeEach(() => {
    localStorage.clear();
    resetUpdatesPollForTests();
  });

  it('renders when updates pending and not on updates mode', () => {
    const wrapper = mount(UpdatesFloatingBanner, {
      props: { mode: 'editor' },
      global: { plugins: [i18n], stubs: { Teleport: true } },
    });
    expect(wrapper.find('[data-testid="updates-floating-banner"]').exists()).toBe(true);
  });

  it('hides on updates mode', () => {
    const wrapper = mount(UpdatesFloatingBanner, {
      props: { mode: 'updates' },
      global: { plugins: [i18n], stubs: { Teleport: true } },
    });
    expect(wrapper.find('[data-testid="updates-floating-banner"]').exists()).toBe(false);
  });
});
