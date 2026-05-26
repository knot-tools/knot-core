/**
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';

import MarketplaceShell from '../MarketplaceShell.vue';

describe('MarketplaceShell', () => {
  it('renders slot content when not loading/unavailable', () => {
    const i18n = createI18n({ legacy: false, locale: 'en', messages: { en: {} } });
    const wrapper = mount(MarketplaceShell, {
      props: { loading: false, unavailable: false },
      slots: { default: '<p class="slot-marker">Inside shell</p>' },
      global: { plugins: [i18n] },
    });
    expect(wrapper.find('.slot-marker').exists()).toBe(true);
  });

  it('shows unavailable state', () => {
    const i18n = createI18n({
      legacy: false,
      locale: 'en',
      messages: {
        en: {
          marketplace: {
            unavailableTitle: 'Unavailable',
            blockedTitle: 'Blocked',
            killSwitchTitle: 'Paused',
            unavailableRetry: 'Retry',
            unavailableExternal: 'Pricing',
          },
        },
      },
    });

    const wrapper = mount(MarketplaceShell, {
      props: {
        loading: false,
        unavailable: true,
        unavailableMessage: 'Down',
        killSwitch: true,
      },
      global: { plugins: [i18n] },
    });

    expect(wrapper.text()).toContain('Paused');
    expect(wrapper.text()).toContain('Down');
  });
});
