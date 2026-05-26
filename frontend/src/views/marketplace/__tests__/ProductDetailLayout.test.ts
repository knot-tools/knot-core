/**
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 */

import { describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import { ref } from 'vue';

import ProductDetailLayout from '../ProductDetailLayout.vue';
import { knotMarketplaceBlockContextKey } from '../contextKey';

vi.mock('@/lib/marketplaceTrack', () => ({
  trackMarketplaceEvent: vi.fn(),
}));

describe('ProductDetailLayout', () => {
  it('renders pack hero from catalog', () => {
    const i18n = createI18n({
      legacy: false,
      locale: 'en',
      messages: {
        en: {
          marketplace: {
            proBadgeLabel: 'Pro',
            pricingTableTitle: 'Pricing',
            pricingHighlighted: 'Recommended',
            comparePlansEmpty: 'Empty',
            compareChoose: 'Choose',
            priceDash: '—',
            packDefaultDescription: 'Pack',
            productBlockEmpty: 'Empty',
            productTabsAria: 'Tabs',
            tabOverview: 'Overview',
            galleryTitle: 'Gallery',
            changelogTitle: 'Changelog',
            richTextTitle: 'Details',
            featuresTitle: 'Features',
          },
        },
      },
    });

    const wrapper = mount(ProductDetailLayout, {
      global: {
        plugins: [i18n],
        provide: {
          [knotMarketplaceBlockContextKey as symbol]: {
            route: ref({ kind: 'product', slug: 'knot-pro-pack' }),
            marketplace: ref({
              packs: [{
                slug: 'knot-pro-pack',
                label: 'Pro Pack',
                description: 'Connectors',
                tier: 'pro',
                priceMonthlyCents: 1990,
                priceYearlyCents: null,
                popular: true,
                installCount: 4,
              }],
              templates: [],
              editorial: { productPages: {} },
            }),
          },
        },
      },
    });

    expect(wrapper.text()).toContain('Pro Pack');
    expect(wrapper.text()).toContain('Connectors');
  });
});
