/**
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';

import ProductPricingTable from '../ProductPricingTable.vue';

describe('ProductPricingTable', () => {
  it('highlights plan matching highlight prop', () => {
    const i18n = createI18n({
      legacy: false,
      locale: 'en',
      messages: {
        en: {
          marketplace: {
            pricingTableTitle: 'Pricing',
            pricingHighlighted: 'Recommended',
            comparePlansEmpty: 'Empty',
            compareChoose: 'Choose',
          },
        },
      },
    });

    const wrapper = mount(ProductPricingTable, {
      props: {
        highlight: 'pro',
        plans: [
          { name: 'Free', price: '0' },
          { name: 'Pro Pack', price: '19 €', highlighted: true, features: ['A'] },
        ],
      },
      global: { plugins: [i18n] },
    });

    expect(wrapper.text()).toContain('Pro Pack');
    expect(wrapper.text()).toContain('Recommended');
  });
});
