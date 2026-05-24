import { describe, expect, it, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import KBetaBadge from '../KBetaBadge.vue';

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
        betaBadge: 'Public beta · Knot Core {version}',
        betaFeedback: 'Send feedback',
        betaFeedbackAria: 'Send beta feedback by email',
      },
    },
  },
});

describe('KBetaBadge', () => {
  beforeEach(() => {
    healthMock.mockReset();
  });

  it('renders when releaseChannel is beta', async () => {
    healthMock.mockResolvedValue({ releaseChannel: 'beta', version: '2.14.0' });
    const wrapper = mount(KBetaBadge, { global: { plugins: [i18n] } });
    await flushPromises();
    expect(wrapper.find('[data-testid="knot-beta-badge"]').exists()).toBe(true);
    expect(wrapper.text()).toContain('2.14.0');
  });

  it('is hidden when releaseChannel is stable', async () => {
    healthMock.mockResolvedValue({ releaseChannel: 'stable', version: '2.14.0' });
    const wrapper = mount(KBetaBadge, { global: { plugins: [i18n] } });
    await flushPromises();
    expect(wrapper.find('[data-testid="knot-beta-badge"]').exists()).toBe(false);
  });
});
