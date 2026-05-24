import { describe, expect, it, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import KDemoBanner from '../KDemoBanner.vue';

const healthMock = vi.fn();

vi.mock('../../../lib/api', () => ({
  knotApi: {
    health: () => healthMock(),
  },
}));

const i18n = createI18n({
  legacy: false,
  locale: 'en',
  messages: {
    en: {
      shell: {
        demoBanner: {
          message: 'Demo environment',
          learnMore: 'Learn more',
        },
      },
    },
  },
});

describe('KDemoBanner', () => {
  beforeEach(() => {
    healthMock.mockReset();
  });

  it('renders only when demoMode is true', async () => {
    healthMock.mockResolvedValue({ demoMode: true });
    const wrapper = mount(KDemoBanner, { global: { plugins: [i18n] } });
    await flushPromises();
    expect(wrapper.find('[data-testid="knot-demo-banner"]').exists()).toBe(true);
  });

  it('stays hidden when demoMode is false', async () => {
    healthMock.mockResolvedValue({ demoMode: false });
    const wrapper = mount(KDemoBanner, { global: { plugins: [i18n] } });
    await flushPromises();
    expect(wrapper.find('[data-testid="knot-demo-banner"]').exists()).toBe(false);
  });

  it('stays hidden when demoMode is undefined', async () => {
    healthMock.mockResolvedValue({});
    const wrapper = mount(KDemoBanner, { global: { plugins: [i18n] } });
    await flushPromises();
    expect(wrapper.find('[data-testid="knot-demo-banner"]').exists()).toBe(false);
  });
});
