/**
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';

import MarketplaceUnavailable from '../MarketplaceUnavailable.vue';

describe('MarketplaceUnavailable', () => {
  it('shows kill-switch title when editorial paused', () => {
    const i18n = createI18n({
      legacy: false,
      locale: 'en',
      messages: {
        en: {
          marketplace: {
            killSwitchTitle: 'Editorial paused',
            blockedTitle: 'Disabled',
            unavailableTitle: 'Unavailable',
            unavailableRetry: 'Retry',
            unavailableExternal: 'Pricing',
          },
        },
      },
    });

    const wrapper = mount(MarketplaceUnavailable, {
      props: { message: 'Operator halt', killSwitch: true },
      global: { plugins: [i18n] },
    });

    expect(wrapper.text()).toContain('Editorial paused');
    expect(wrapper.find('button').exists()).toBe(false);
  });

  it('shows retry on generic errors', () => {
    const i18n = createI18n({
      legacy: false,
      locale: 'en',
      messages: {
        en: {
          marketplace: {
            unavailableTitle: 'Unavailable',
            blockedTitle: 'Disabled',
            killSwitchTitle: 'Paused',
            unavailableRetry: 'Retry',
            unavailableExternal: 'Pricing',
          },
        },
      },
    });

    const wrapper = mount(MarketplaceUnavailable, {
      props: { message: 'Network down' },
      global: { plugins: [i18n] },
    });

    expect(wrapper.text()).toContain('Retry');
  });
});
